<?php
declare(strict_types=1);

/**
 * Einmaliges Setup über den Browser.
 *
 * NUR verwenden, wenn kein SSH verfügbar ist. Diese Datei kann Schlüssel
 * erzeugen und Administratoren anlegen – sie gehört nach dem Setup gelöscht.
 *
 * 1. SETUP_TOKEN unten auf einen eigenen Zufallswert ändern.
 * 2. Datei neben index.php in den Webroot legen.
 * 3. Aufrufen: https://deinedomain.at/health/web-setup.php?token=DEIN_TOKEN
 * 4. Nach dem letzten Schritt "Löschen" klicken.
 */

const SETUP_TOKEN = 'HIER_EINEN_ZUFALLSWERT_EINTRAGEN';

// Pfad zum app/-Verzeichnis. Anpassen, falls app/ woanders liegt.
const APP_DIR = __DIR__ . '/app';

// ---------------------------------------------------------------------

if (SETUP_TOKEN === 'HIER_EINEN_ZUFALLSWERT_EINTRAGEN') {
    exit('Bitte zuerst SETUP_TOKEN in dieser Datei ändern.');
}
if (!hash_equals(SETUP_TOKEN, (string)($_GET['token'] ?? ''))) {
    http_response_code(404);
    exit('Not found.');
}

$token = rawurlencode(SETUP_TOKEN);
$step  = (string)($_GET['step'] ?? 'menu');
$msg   = [];
$err   = null;

function h(?string $s): string {
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

// =====================================================================
// Schritt 1: Schlüssel erzeugen – läuft OHNE App-Bootstrap,
// weil Crypto sonst genau diese Dateien schon bräuchte.
// =====================================================================
if ($step === 'keys') {
    $dir = APP_DIR . '/keys';

    if (!is_dir($dir) && !@mkdir($dir, 0700, true)) {
        $err = "Verzeichnis {$dir} existiert nicht und konnte nicht angelegt werden. "
             . 'Bitte per FTP anlegen (Rechte 0755) und erneut versuchen.';
    } elseif (!is_writable($dir)) {
        $err = "Verzeichnis {$dir} ist nicht beschreibbar. Rechte per FTP auf 0755 setzen.";
    } else {
        foreach (['master', 'index'] as $name) {
            $path = $dir . '/' . $name . '.key.php';
            if (file_exists($path) || file_exists($dir . '/' . $name . '.key')) {
                $msg[] = "{$name}: existiert bereits, unverändert gelassen.";
                continue;
            }
            // 32 Byte base64, als PHP-Datei – wird bei direktem Aufruf
            // ausgeführt statt ausgeliefert.
            $content = "<?php\nreturn '" . base64_encode(random_bytes(32)) . "';\n";
            if (file_put_contents($path, $content, LOCK_EX) === false) {
                $err = "{$name}.key.php konnte nicht geschrieben werden.";
                break;
            }
            @chmod($path, 0400);
            $msg[] = "{$name}.key.php: erzeugt.";
        }
    }
}

// =====================================================================
// Ab hier wird der App-Bootstrap gebraucht
// =====================================================================
$app = null;
if (in_array($step, ['check', 'admin', 'admin_save'], true)) {
    $bootstrap = APP_DIR . '/src/App.php';
    if (!is_file($bootstrap)) {
        $err = "Bootstrap nicht gefunden: {$bootstrap} – APP_DIR in dieser Datei anpassen.";
    } else {
        require_once $bootstrap;
        try {
            $app = \Health\App::boot(APP_DIR . '/config/config.php');
        } catch (\Throwable $e) {
            $err = 'Bootstrap fehlgeschlagen: ' . $e->getMessage();
        }
    }
}

// ---------------------------------------------------------------------
if ($step === 'check' && $app) {
    $checks = [];
    $add = function (string $label, bool $ok, string $note = '') use (&$checks): void {
        $checks[] = [$label, $ok, $note];
    };

    $add('PHP >= 8.1 (' . PHP_VERSION . ')', version_compare(PHP_VERSION, '8.1', '>='));
    $add('OpenSSL', extension_loaded('openssl'));
    $add('PDO MySQL', extension_loaded('pdo_mysql'));
    $add('mbstring', extension_loaded('mbstring'));
    $add('AES-256-GCM', in_array('aes-256-gcm', openssl_get_cipher_methods(), true));
    $add('Argon2id', defined('PASSWORD_ARGON2ID'), defined('PASSWORD_ARGON2ID') ? '' : 'Fallback auf bcrypt');
    foreach (\Health\Crypto::keyFileInfo(APP_DIR . '/keys') as $kn => $ki) {
        $add("Schlüssel {$kn}", $ki['format'] !== 'fehlt',
             'Format: ' . $ki['format'] . ($ki['old_exists'] && $ki['php_exists'] ? ', Altdatei noch vorhanden' : ''));
    }
    $add('storage/ beschreibbar', is_writable($app->config['paths']['storage']));
    $add('storage/sessions/ beschreibbar', is_writable($app->config['paths']['sessions']));

    try {
        $app->db->value('SELECT 1');
        $add('Datenbankverbindung', true);
        $hasUsers = (int)$app->db->value(
            'SELECT COUNT(*) FROM information_schema.tables
             WHERE table_schema = DATABASE() AND table_name = "users"'
        ) === 1;
        $add('Schema eingespielt', $hasUsers, $hasUsers ? '' : 'db/01_core_schema.sql fehlt noch');
    } catch (\Throwable $e) {
        $add('Datenbankverbindung', false, $e->getMessage());
    }

    try {
        [$dek, $wrapped] = $app->crypto->createDek();
        $ct = $app->crypto->enc($dek, 'Testwert äöüß', 'test');
        $ok = $app->crypto->dec($app->crypto->unwrapDek($wrapped), $ct, 'test') === 'Testwert äöüß';
        $add('Verschlüsselung Round-Trip', $ok);
    } catch (\Throwable $e) {
        $add('Verschlüsselung', false, $e->getMessage());
    }

    // Erreichbarkeitstest: darf der Schlüssel per URL geladen werden?
    $keyUrl = null;
    $docRoot = realpath($_SERVER['DOCUMENT_ROOT'] ?? '');
    $keyPath = realpath(APP_DIR . '/keys/master.key.php') ?: realpath(APP_DIR . '/keys/master.key');
    if ($docRoot && $keyPath && str_starts_with($keyPath, $docRoot)) {
        $keyUrl = 'https://' . ($_SERVER['HTTP_HOST'] ?? '') . substr($keyPath, strlen($docRoot));
    }
}

// ---------------------------------------------------------------------
if ($step === 'admin_save' && $app) {
    try {
        $id = $app->auth->createUser(
            trim((string)($_POST['username'] ?? '')),
            (string)($_POST['password'] ?? ''),
            trim((string)($_POST['email'] ?? '')) ?: null,
            trim((string)($_POST['display_name'] ?? '')) ?: null,
            'admin',
            'active'
        );
        $msg[] = "Administrator angelegt (ID {$id}).";
        $step = 'done';
    } catch (\Throwable $e) {
        $err  = $e->getMessage();
        $step = 'admin';
    }
}

// ---------------------------------------------------------------------
if ($step === 'selfdestruct') {
    if (@unlink(__FILE__)) {
        exit('<!doctype html><meta charset="utf-8"><p style="font:16px sans-serif">'
           . 'Setup-Datei gelöscht. <a href="/health/login.php">Zur Anmeldung</a></p>');
    }
    $err = 'Löschen fehlgeschlagen – bitte die Datei ' . h(basename(__FILE__))
         . ' jetzt manuell per FTP entfernen.';
}
?>
<!doctype html>
<html lang="de">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Setup</title>
<style>
body{font:16px/1.55 -apple-system,BlinkMacSystemFont,"Segoe UI",Arial,sans-serif;
     background:#f4f6f8;color:#17212b;margin:0}
.wrap{max-width:680px;margin:0 auto;padding:32px 20px}
.panel{background:#fff;border:1px solid #dfe5ea;border-radius:10px;padding:24px;margin-bottom:20px}
h1{font-size:1.3rem;margin:0 0 16px} h2{font-size:1.05rem;margin:0 0 12px}
a.btn,button{display:inline-block;padding:10px 16px;border:0;border-radius:7px;background:#0f6e63;
     color:#fff;font-size:.95rem;font-weight:600;text-decoration:none;cursor:pointer;margin-right:8px}
a.btn.sec{background:transparent;color:#0f6e63;border:1px solid #dfe5ea}
.msg{padding:11px 14px;border-radius:7px;font-size:.92rem;margin-bottom:14px}
.msg.err{background:#fdeceb;color:#a6302a} .msg.ok{background:#e9f5ed;color:#1f6b3b}
.msg.warn{background:#fff6e5;color:#8a5a00}
label{display:block;font-size:.88rem;font-weight:600;margin:14px 0 5px}
input{width:100%;padding:10px 12px;border:1px solid #dfe5ea;border-radius:7px;font-size:1rem;box-sizing:border-box}
table{width:100%;border-collapse:collapse;font-size:.92rem}
td{padding:7px 6px;border-bottom:1px solid #eef2f5}
code{background:#f4f6f8;padding:1px 5px;border-radius:4px;font-size:.9em}
.ok{color:#1f6b3b;font-weight:600} .no{color:#a6302a;font-weight:600}
</style>
</head>
<body><div class="wrap">

<?php if ($err): ?><div class="msg err"><?= h($err) ?></div><?php endif; ?>
<?php foreach ($msg as $m): ?><div class="msg ok"><?= h($m) ?></div><?php endforeach; ?>

<?php if ($step === 'menu'): ?>
  <div class="panel">
    <h1>Setup</h1>
    <p>Die Schritte der Reihe nach ausführen.</p>
    <p>
      <a class="btn" href="?token=<?= $token ?>&step=keys">1. Schlüssel erzeugen</a>
      <a class="btn sec" href="?token=<?= $token ?>&step=check">2. Prüfen</a>
      <a class="btn sec" href="?token=<?= $token ?>&step=admin">3. Administrator</a>
    </p>
  </div>

<?php elseif ($step === 'keys'): ?>
  <div class="panel">
    <h2>Schlüssel</h2>
    <div class="msg warn">
      <strong>Jetzt sichern:</strong> <code>app/keys/master.key.php</code> und
      <code>index.key.php</code> per FTP herunterladen und getrennt vom
      Datenbank-Backup aufbewahren. Ohne <code>master.key.php</code> sind alle
      verschlüsselten Daten unwiederbringlich verloren – auch für dich.
    </div>
    <a class="btn" href="?token=<?= $token ?>&step=check">Weiter zur Prüfung</a>
  </div>

<?php elseif ($step === 'check' && $app): ?>
  <div class="panel">
    <h2>Umgebung</h2>
    <table>
      <?php foreach ($checks as [$label, $ok, $note]): ?>
      <tr>
        <td><?= h($label) ?><?= $note ? ' <small>(' . h($note) . ')</small>' : '' ?></td>
        <td style="width:60px;text-align:right" class="<?= $ok ? 'ok' : 'no' ?>">
          <?= $ok ? 'ok' : 'fehlt' ?></td>
      </tr>
      <?php endforeach; ?>
    </table>
    <?php if (!empty($keyUrl)): ?>
      <div class="msg err" style="margin-top:16px">
        <strong>Der Schlüsselordner liegt im Webroot.</strong> Bitte jetzt prüfen, ob
        <code><?= h($keyUrl) ?></code> im Browser einen 403 liefert. Kommt stattdessen
        der Schlüsselinhalt, ist die Verschlüsselung wertlos – dann <code>app/</code>
        aus dem Webroot verschieben, bevor echte Daten eingetragen werden.
      </div>
    <?php endif; ?>
    <p style="margin-top:16px"><a class="btn" href="?token=<?= $token ?>&step=admin">Weiter</a></p>
  </div>

<?php elseif ($step === 'admin' && $app): ?>
  <div class="panel">
    <h2>Administrator anlegen</h2>
    <form method="post" action="?token=<?= $token ?>&step=admin_save">
      <label for="u">Benutzername</label>
      <input id="u" name="username" required autocapitalize="none" spellcheck="false"
             value="<?= h($_POST['username'] ?? '') ?>">
      <label for="d">Anzeigename</label>
      <input id="d" name="display_name" value="<?= h($_POST['display_name'] ?? '') ?>">
      <label for="e">E-Mail (optional)</label>
      <input id="e" type="email" name="email" value="<?= h($_POST['email'] ?? '') ?>">
      <label for="p">Passwort (mind. 12 Zeichen)</label>
      <input id="p" type="password" name="password" required autocomplete="new-password">
      <p><button type="submit">Anlegen</button></p>
    </form>
  </div>

<?php elseif ($step === 'done'): ?>
  <div class="panel">
    <h2>Fertig</h2>
    <p>Jetzt anmelden und unter <strong>Sicherheit</strong> die
       Zwei-Faktor-Authentifizierung einrichten.</p>
    <div class="msg warn">
      Diese Setup-Datei kann Schlüssel erzeugen und Administratoren anlegen.
      Sie muss jetzt weg.
    </div>
    <a class="btn" href="?token=<?= $token ?>&step=selfdestruct">Setup-Datei löschen</a>
    <a class="btn sec" href="/health/login.php">Zur Anmeldung</a>
  </div>

<?php else: ?>
  <div class="panel">
    <p><a class="btn sec" href="?token=<?= $token ?>">Zurück zum Menü</a></p>
  </div>
<?php endif; ?>

</div></body></html>
