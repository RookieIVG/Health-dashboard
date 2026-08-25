<?php
declare(strict_types=1);

namespace Health;

/**
 * Zentraler Bootstrap. Wird von jeder Seite im public/ als erstes eingebunden:
 *   require __DIR__ . '/../app/src/App.php';
 *   $app = \Health\App::boot();
 */
final class App
{
    private static ?App $instance = null;

    public array $config;
    public Db $db;
    public Crypto $crypto;
    public Audit $audit;
    public Auth $auth;

    private ?TimelineService $timeline = null;
    private ?TagService $tags = null;
    private ?AttachmentService $attachments = null;
    private ?ModuleRegistry $modules = null;

    /** Entpackte DEKs, nur für die Dauer des Requests. */
    private array $dekCache = [];

    private function __construct(array $config)
    {
        $this->config = $config;
        $this->db     = new Db($config['db']);
        $this->crypto = new Crypto($config['paths']['keys']);
        $this->audit  = new Audit($this->db, $this->crypto);
        $this->auth   = new Auth($this->db, $this->crypto, $this->audit, $config['security'], $config['app']);
    }

    public static function boot(?string $configPath = null): self
    {
        if (self::$instance !== null) {
            return self::$instance;
        }

        // Bewusst als Allererstes: fängt jede Ausgabe ab, die vor einem
        // möglichen Fehler schon begonnen hat (Kopfzeile, Navigation,
        // Teile einer Kachel). Ohne das würde ein Fehler mitten im
        // Seitenaufbau zu einer halb gerenderten Seite plus angehängtem
        // Fehlertext führen, UND http_response_code(500) im Fehlerfall
        // scheitern ("headers already sent") - genau das ist einmal
        // live passiert. Im Normalfall (kein Fehler) unsichtbar: PHP
        // gibt gepufferte Ausgabe am Skriptende automatisch aus.
        ob_start();

        spl_autoload_register(static function (string $class): void {
            if (!str_starts_with($class, 'Health\\')) return;
            $file = __DIR__ . '/' . str_replace('\\', '/', substr($class, 7)) . '.php';
            if (is_file($file)) require_once $file;
        });

        $configPath ??= __DIR__ . '/../config/config.php';
        if (!is_file($configPath)) {
            http_response_code(500);
            exit('Konfiguration fehlt. Bitte config.example.php nach config.php kopieren.');
        }
        $config = require $configPath;

        date_default_timezone_set('UTC');   // intern UTC, Ausgabe konvertiert
        mb_internal_encoding('UTF-8');

        self::enforceHttps($config);
        self::sendSecurityHeaders($config);
        self::startSession($config);
        self::configureErrors($config);

        return self::$instance = new self($config);
    }

    // -----------------------------------------------------------------

    private static function enforceHttps(array $config): void
    {
        if (empty($config['app']['force_https']) || PHP_SAPI === 'cli') {
            return;
        }
        $https = (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off')
              || ((int)($_SERVER['SERVER_PORT'] ?? 0) === 443);

        // X-Forwarded-Proto darf nur von explizit vertrauenswürdigen
        // Reverse-Proxies berücksichtigt werden. Ein beliebiger Client
        // kann HTTP-Header selbst setzen und so den HTTPS-Zwang umgehen.
        if (!$https && self::isTrustedProxy($config)) {
            $https = strtolower(trim((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''))) === 'https';
        }

        if (!$https) {
            $url = 'https://' . ($_SERVER['HTTP_HOST'] ?? '') . ($_SERVER['REQUEST_URI'] ?? '/');
            header('Location: ' . $url, true, 301);
            exit;
        }
    }

    /** Prüft, ob der direkte Peer ein explizit erlaubter Reverse-Proxy ist. */
    private static function isTrustedProxy(array $config): bool
    {
        $peer = $_SERVER['REMOTE_ADDR'] ?? '';
        if (!is_string($peer) || $peer === '') {
            return false;
        }

        $trusted = $config['app']['trusted_proxies'] ?? [];
        if (!is_array($trusted)) {
            return false;
        }

        return in_array($peer, $trusted, true);
    }

    private static function sendSecurityHeaders(array $config): void
    {
        if (PHP_SAPI === 'cli') return;

        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: DENY');
        header('Referrer-Policy: no-referrer');
        header('Permissions-Policy: geolocation=(), camera=(), microphone=(), interest-cohort=()');
        header('Cross-Origin-Opener-Policy: same-origin');
        header('Cache-Control: no-store, no-cache, must-revalidate, private');

        if (!empty($config['app']['force_https'])) {
            header('Strict-Transport-Security: max-age=31536000; includeSubDomains; preload');
        }

        // Kein 'unsafe-inline' für Skripte – Inline-JS wird per Nonce erlaubt.
        $nonce = base64_encode(random_bytes(16));
        $GLOBALS['csp_nonce'] = $nonce;
        header(
            "Content-Security-Policy: default-src 'self'; "
            . "script-src 'self' 'nonce-{$nonce}'; "
            . "style-src 'self' 'unsafe-inline'; "
            . "img-src 'self' data:; "
            . "font-src 'self'; "
            . "connect-src 'self'; "
            . "object-src 'none'; base-uri 'none'; form-action 'self'; frame-ancestors 'none'"
        );
    }

    public static function nonce(): string
    {
        return $GLOBALS['csp_nonce'] ?? '';
    }

    private static function startSession(array $config): void
    {
        if (PHP_SAPI === 'cli' || session_status() === PHP_SESSION_ACTIVE) {
            return;
        }
        $dir = $config['paths']['sessions'];
        if (is_dir($dir) && is_writable($dir)) {
            session_save_path($dir);          // nicht im gemeinsamen /tmp des Hosters
        }
        session_name($config['security']['session_name']);
        session_set_cookie_params([
            'lifetime' => 0,                   // Session-Cookie
            'path'     => ($config['app']['base_path'] ?? '') . '/',
            'domain'   => '',
            'secure'   => (bool)$config['app']['force_https'],
            'httponly' => true,
            'samesite' => 'Lax',               // Strict bricht Rücksprünge aus Mails
        ]);
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        ini_set('session.cookie_httponly', '1');
        ini_set('session.gc_maxlifetime', (string)$config['security']['session_absolute']);
        session_start();
    }

    private static function configureErrors(array $config): void
    {
        $dev = ($config['app']['env'] ?? 'production') === 'dev';
        ini_set('display_errors', $dev ? '1' : '0');
        ini_set('log_errors', '1');
        error_reporting($dev ? E_ALL : E_ALL & ~E_DEPRECATED & ~E_NOTICE);

        if (!$dev) {
            set_exception_handler(static function (\Throwable $e): void {
                error_log('[health] ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
                // Alles bisher Gepufferte verwerfen (Kopfzeile, angefangene
                // Kachel, …) - sonst hängt die saubere Meldung unten an
                // einer halb gerenderten Seite, und http_response_code()
                // scheitert zusätzlich mit "headers already sent".
                while (ob_get_level() > 0) { ob_end_clean(); }
                http_response_code(500);
                echo 'Es ist ein Fehler aufgetreten. Der Vorfall wurde protokolliert.';
            });
        }
    }

    // -----------------------------------------------------------------
    // Dienste (lazy, damit ungenutzte nichts kosten)
    // -----------------------------------------------------------------

    public function timeline(): TimelineService
    {
        return $this->timeline ??= new TimelineService($this);
    }

    public function tags(): TagService
    {
        return $this->tags ??= new TagService($this);
    }

    public function attachments(): AttachmentService
    {
        return $this->attachments ??= new AttachmentService($this);
    }

    public function modules(): ModuleRegistry
    {
        return $this->modules ??= new ModuleRegistry($this);
    }

    /**
     * DEK eines Benutzers, pro Request zwischengespeichert.
     *
     * Ohne Cache würde jedes einzelne verschlüsselte Feld eine
     * Datenbankabfrage plus eine Entschlüsselung auslösen – bei einer
     * Timeline mit 200 Einträgen wären das 400 überflüssige Operationen.
     * Der Cache lebt nur bis zum Ende des Requests; in der Session landet
     * weiterhin nichts.
     */
    public function dekFor(?int $userId = null): string
    {
        $userId ??= $this->auth->userId();
        if ($userId === null) {
            throw new \RuntimeException('Kein Benutzerkontext für den Schlüssel.');
        }
        return $this->dekCache[$userId] ??= $this->auth->dek($userId);
    }

    /** Verzeichnis der Schlüsseldateien (master.key.php, vapid_private.key.php, ...). */
    public function keyDir(): string
    {
        return $this->config['paths']['keys'];
    }

    /** Nach Logout oder Benutzerwechsel aufrufen. */
    public function clearDekCache(): void
    {
        foreach ($this->dekCache as $k => $v) {
            // Best effort – PHP garantiert kein sicheres Überschreiben,
            // aber die Referenz ist damit weg.
            $this->dekCache[$k] = str_repeat("\0", strlen($v));
        }
        $this->dekCache = [];
    }

    // -----------------------------------------------------------------
    // View-Helfer
    // -----------------------------------------------------------------

    /** Baut eine URL inklusive base_path (z. B. "/health"). */
    public static function url(string $path = '/'): string
    {
        $base = rtrim(self::$instance?->config['app']['base_path'] ?? '', '/');
        return $base . $path;
    }

    /**
     * Vollständige URL mit Schema und Host statt nur des Pfads.
     *
     * App::url() reicht für Links innerhalb einer HTML-Seite, aber
     * nicht überall: der Service Worker vergleicht Fenster-URLs beim
     * Klick auf eine Push-Benachrichtigung gegen echte, vollständige
     * URLs (window.location.href liefert immer eine vollständige URL,
     * nie nur einen Pfad) – ein reiner Pfad würde dort nie passen.
     */
    public static function absUrl(string $path = '/'): string
    {
        $base = self::$instance?->config['app']['base_url'] ?? '';
        return rtrim($base, '/') . self::url($path);
    }

    public static function e(?string $s): string
    {
        return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /** UTC-DATETIME aus der DB in lokale Anzeige umrechnen. */
    public function local(?string $utc, string $format = 'd.m.Y H:i'): string
    {
        if (!$utc) return '–';
        $dt = new \DateTimeImmutable($utc, new \DateTimeZone('UTC'));
        return $dt->setTimezone(new \DateTimeZone($this->config['app']['timezone']))->format($format);
    }
}
