<?php
declare(strict_types=1);

namespace Health;

use RuntimeException;

/**
 * Envelope Encryption.
 *
 * Schlüsselhierarchie:
 *   master.key  (32 Byte, Datei außerhalb Webroot)
 *     └─ wrapped DEK je User (users.dek_wrapped)
 *          └─ Feldverschlüsselung der Nutzdaten
 *   index.key   (32 Byte)  -> HMAC für Blind Indexes
 *
 * Format eines Ciphertexts (binär):
 *   [1 Byte Version][12 Byte IV][16 Byte GCM-Tag][N Byte Ciphertext]
 */
final class Crypto
{
    private const VERSION   = 1;
    private const CIPHER    = 'aes-256-gcm';
    private const IV_LEN    = 12;
    private const TAG_LEN   = 16;
    private const KEY_LEN   = 32;

    private string $masterKey;
    private string $indexKey;

    public function __construct(string $keyDir)
    {
        $this->masterKey = self::loadKey($keyDir, 'master');
        $this->indexKey  = self::loadKey($keyDir, 'index');
    }

    /**
     * Lädt einen Schlüssel. Bevorzugt wird das PHP-Format:
     *
     *   master.key.php   ->  <?php return 'BASE64...';
     *
     * Grund: Muss der Schlüsselordner im Webroot liegen (bei manchen Hostern
     * erzwingt open_basedir das), ist die .htaccess die einzige Barriere.
     * Greift sie einmal nicht – Serverumzug, nginx statt Apache, Tippfehler
     * beim Deployment – liefert der Webserver eine .key-Datei im Klartext aus.
     * Eine .php-Datei wird stattdessen ausgeführt und gibt nach außen nichts
     * zurück. Zweite Schicht, die nicht an einer Konfigurationszeile hängt.
     *
     * Das alte Format wird weiter gelesen, damit bestehende Installationen
     * nicht brechen.
     */
    private static function loadKey(string $dir, string $name): string
    {
        $candidates = [
            $dir . '/' . $name . '.key.php',   // bevorzugt
            $dir . '/' . $name . '.key',       // Altformat
        ];

        foreach ($candidates as $path) {
            if (!is_file($path) || !is_readable($path)) {
                continue;
            }
            $raw = str_ends_with($path, '.php')
                ? (string)(include $path)
                : trim((string)file_get_contents($path));

            $key = base64_decode($raw, true);
            if ($key === false || strlen($key) !== self::KEY_LEN) {
                throw new RuntimeException("Ungültige Schlüsseldatei: {$path}");
            }
            return $key;
        }

        throw new RuntimeException(
            "Kein lesbarer Schlüssel '{$name}' in {$dir} "
            . '(erwartet: ' . $name . '.key.php oder ' . $name . '.key)'
        );
    }

    /** Welches Format liegt vor? Für die Diagnoseseite. */
    public static function keyFileInfo(string $dir): array
    {
        $info = [];
        foreach (['master', 'index'] as $name) {
            $php = $dir . '/' . $name . '.key.php';
            $old = $dir . '/' . $name . '.key';
            $info[$name] = [
                'php_exists' => is_file($php),
                'old_exists' => is_file($old),
                'format'     => is_file($php) ? 'php' : (is_file($old) ? 'plain' : 'fehlt'),
            ];
        }
        return $info;
    }

    /** Schreibt einen Schlüssel im PHP-Format. */
    public static function writeKeyFile(string $dir, string $name, string $base64): bool
    {
        $path = $dir . '/' . $name . '.key.php';
        $content = "<?php\n// Schlüsselmaterial. Diese Datei niemals ins Repository, "
                 . "niemals gemeinsam mit dem Datenbank-Backup ablegen.\n"
                 . "return '" . $base64 . "';\n";
        if (file_put_contents($path, $content, LOCK_EX) === false) {
            return false;
        }
        @chmod($path, 0400);
        return true;
    }

    // -----------------------------------------------------------------
    // Low-Level
    // -----------------------------------------------------------------

    /** Verschlüsselt mit explizitem Schlüssel. $aad bindet den Ciphertext an einen Kontext. */
    public function encryptWith(string $key, string $plaintext, string $aad = ''): string
    {
        $iv  = random_bytes(self::IV_LEN);
        $tag = '';
        $ct  = openssl_encrypt(
            $plaintext, self::CIPHER, $key,
            OPENSSL_RAW_DATA, $iv, $tag, $aad, self::TAG_LEN
        );
        if ($ct === false) {
            throw new RuntimeException('Verschlüsselung fehlgeschlagen.');
        }
        return chr(self::VERSION) . $iv . $tag . $ct;
    }

    public function decryptWith(string $key, string $blob, string $aad = ''): string
    {
        if (strlen($blob) < 1 + self::IV_LEN + self::TAG_LEN) {
            throw new RuntimeException('Ciphertext zu kurz.');
        }
        $version = ord($blob[0]);
        if ($version !== self::VERSION) {
            throw new RuntimeException("Unbekannte Ciphertext-Version: {$version}");
        }
        $iv  = substr($blob, 1, self::IV_LEN);
        $tag = substr($blob, 1 + self::IV_LEN, self::TAG_LEN);
        $ct  = substr($blob, 1 + self::IV_LEN + self::TAG_LEN);

        $pt = openssl_decrypt($ct, self::CIPHER, $key, OPENSSL_RAW_DATA, $iv, $tag, $aad);
        if ($pt === false) {
            // Auch bei manipulierten Daten – GCM erkennt das über den Tag.
            throw new RuntimeException('Entschlüsselung fehlgeschlagen (Tag ungültig?).');
        }
        return $pt;
    }

    // -----------------------------------------------------------------
    // DEK-Verwaltung
    // -----------------------------------------------------------------

    /** Erzeugt einen neuen User-DEK und liefert [dekPlain, dekWrapped]. */
    public function createDek(): array
    {
        $dek = random_bytes(self::KEY_LEN);
        return [$dek, $this->encryptWith($this->masterKey, $dek, 'dek')];
    }

    public function unwrapDek(string $wrapped): string
    {
        return $this->decryptWith($this->masterKey, $wrapped, 'dek');
    }

    // -----------------------------------------------------------------
    // Komfort
    // -----------------------------------------------------------------

    /** Feldverschlüsselung mit User-DEK. $aad z.B. "findings.title:42". */
    public function enc(string $dek, ?string $plaintext, string $aad = ''): ?string
    {
        if ($plaintext === null || $plaintext === '') {
            return null;
        }
        return $this->encryptWith($dek, $plaintext, $aad);
    }

    public function dec(string $dek, $blob, string $aad = ''): ?string
    {
        if ($blob === null || $blob === '') {
            return null;
        }
        if (is_resource($blob)) {           // PDO liefert BLOBs teils als Stream
            $blob = stream_get_contents($blob);
        }
        return $this->decryptWith($dek, (string)$blob, $aad);
    }

    /** Systemdaten (Audit-Details) – direkt mit Master-Key. */
    public function encSystem(?string $plaintext): ?string
    {
        return $plaintext === null ? null : $this->encryptWith($this->masterKey, $plaintext, 'system');
    }

    public function decSystem($blob): ?string
    {
        if ($blob === null || $blob === '') return null;
        if (is_resource($blob)) $blob = stream_get_contents($blob);
        return $this->decryptWith($this->masterKey, (string)$blob, 'system');
    }

    // -----------------------------------------------------------------
    // Blind Index
    // -----------------------------------------------------------------

    /**
     * Deterministischer, gekürzter HMAC für Gleichheitssuche auf
     * verschlüsselten Spalten. Kürzung auf 16 Byte ist Absicht:
     * genug für Eindeutigkeit, reduziert Korrelationsrisiko.
     */
    public function blindIndex(string $context, string $value): string
    {
        $norm = mb_strtolower(trim($value), 'UTF-8');
        return substr(hash_hmac('sha256', $context . "\0" . $norm, $this->indexKey, true), 0, 16);
    }

    // -----------------------------------------------------------------
    // Hilfsfunktionen
    // -----------------------------------------------------------------

    public static function generateKeyFileContent(): string
    {
        return base64_encode(random_bytes(self::KEY_LEN));
    }

    /** Zeitkonstanter Vergleich. */
    public static function equals(string $a, string $b): bool
    {
        return hash_equals($a, $b);
    }
}
