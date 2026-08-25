<?php
declare(strict_types=1);

/**
 * Einmalige Einrichtung – auf der Kommandozeile ausführen:
 *
 *   php bin/setup.php keys              Schlüsseldateien erzeugen
 *   php bin/setup.php admin <user>      Ersten Administrator anlegen
 *   php bin/setup.php check             Umgebung prüfen
 *   php bin/setup.php purge             Abgelaufene Datensätze löschen (Cron)
 *
 * Falls kein SSH verfügbar ist: siehe docs/INSTALL.md, Abschnitt
 * "Ohne Kommandozeile".
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Dieses Skript darf nur auf der Kommandozeile laufen.\n");
}

require __DIR__ . '/../app/src/App.php';

use Health\App;
use Health\Auth;
use Health\Crypto;

$cmd = $argv[1] ?? 'help';

// ---------------------------------------------------------------------
if ($cmd === 'keys') {
    $dir = __DIR__ . '/../app/keys';
    if (!is_dir($dir) && !mkdir($dir, 0700, true)) {
        exit("Verzeichnis {$dir} kann nicht angelegt werden.\n");
    }
    foreach (['master', 'index'] as $name) {
        if (file_exists($dir . '/' . $name . '.key.php') || file_exists($dir . '/' . $name . '.key')) {
            echo "übersprungen (existiert bereits): {$name}\n";
            continue;
        }
        if (!Crypto::writeKeyFile($dir, $name, Crypto::generateKeyFileContent())) {
            exit("FEHLER: {$name}.key.php konnte nicht geschrieben werden.\n");
        }
        echo "erzeugt: {$name}.key.php\n";
    }
    echo "\nWICHTIG: Diese Dateien jetzt sichern.\n"
       . "Ohne master.key sind alle verschlüsselten Daten unwiederbringlich verloren.\n"
       . "Das Backup gehört an einen anderen Ort als das Datenbank-Backup –\n"
       . "sonst hebelt ein einziger gestohlener Backup-Ordner die Verschlüsselung aus.\n";
    exit(0);
}

// ---------------------------------------------------------------------
$app = App::boot();

if ($cmd === 'check') {
    $ok = true;
    $line = function (bool $good, string $text) use (&$ok): void {
        echo ($good ? '  [ok]   ' : '  [FEHL] ') . $text . "\n";
        if (!$good) $GLOBALS['checkFailed'] = true;
    };

    echo "PHP: " . PHP_VERSION . "\n";
    $line(version_compare(PHP_VERSION, '8.1', '>='), 'PHP >= 8.1');
    $line(extension_loaded('openssl'), 'OpenSSL-Erweiterung');
    $line(extension_loaded('pdo_mysql'), 'PDO MySQL');
    $line(extension_loaded('mbstring'), 'mbstring');
    $line(in_array('aes-256-gcm', openssl_get_cipher_methods(), true), 'AES-256-GCM verfügbar');
    $line(defined('PASSWORD_ARGON2ID'), 'Argon2id (sonst Fallback auf bcrypt)');
    $keyInfo = Crypto::keyFileInfo($app->config['paths']['keys']);
    foreach ($keyInfo as $name => $i) {
        $line($i['format'] !== 'fehlt', "Schlüssel {$name} vorhanden (Format: {$i['format']})");
        if ($i['format'] === 'plain') {
            echo "         Hinweis: Altformat. Umstellung auf {$name}.key.php empfohlen.\n";
        }
        if ($i['php_exists'] && $i['old_exists']) {
            echo "         Hinweis: alte Datei {$name}.key liegt noch daneben und sollte weg.\n";
        }
    }
    $line(is_writable($app->config['paths']['storage']), 'storage/ beschreibbar');
    $line(is_writable($app->config['paths']['sessions']), 'storage/sessions/ beschreibbar');

    try {
        $app->db->value('SELECT 1');
        $line(true, 'Datenbankverbindung');
        $tables = (int)$app->db->value(
            'SELECT COUNT(*) FROM information_schema.tables
             WHERE table_schema = DATABASE() AND table_name = "users"'
        );
        $line($tables === 1, 'Schema eingespielt (Tabelle users)');
    } catch (\Throwable $e) {
        $line(false, 'Datenbank: ' . $e->getMessage());
    }

    // Round-Trip-Test der Verschlüsselung
    try {
        [$dek, $wrapped] = $app->crypto->createDek();
        $ct = $app->crypto->enc($dek, 'Testwert äöüß', 'test');
        $line($app->crypto->dec($app->crypto->unwrapDek($wrapped), $ct, 'test') === 'Testwert äöüß',
              'Verschlüsselung Round-Trip');
    } catch (\Throwable $e) {
        $line(false, 'Verschlüsselung: ' . $e->getMessage());
    }

    exit(empty($GLOBALS['checkFailed']) ? 0 : 1);
}

// ---------------------------------------------------------------------
if ($cmd === 'admin') {
    $username = $argv[2] ?? null;
    if (!$username) exit("Aufruf: php bin/setup.php admin <benutzername>\n");

    echo "Passwort (Eingabe bleibt sichtbar): ";
    $pw = trim((string)fgets(STDIN));
    $err = Auth::validatePassword($pw, (int)$app->config['security']['password_min_length']);
    if ($err) exit($err . "\n");

    echo "Anzeigename (optional): ";
    $name = trim((string)fgets(STDIN));
    echo "E-Mail (optional): ";
    $mail = trim((string)fgets(STDIN));

    $id = $app->auth->createUser($username, $pw, $mail ?: null, $name ?: null, 'admin', 'active');
    echo "\nAdministrator angelegt (ID {$id}).\n"
       . "Jetzt anmelden und unter Sicherheit die Zwei-Faktor-Authentifizierung einrichten.\n";
    exit(0);
}

// ---------------------------------------------------------------------
if ($cmd === 'purge') {
    $app->auth->purgeExpired();
    echo "Aufräumen abgeschlossen.\n";
    exit(0);
}

echo "Befehle: keys | check | admin <benutzername> | purge\n";
