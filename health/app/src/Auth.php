<?php
declare(strict_types=1);

namespace Health;

use PDO;
use RuntimeException;

final class Auth
{
    public const STAGE_NONE    = 0;   // nicht angemeldet
    public const STAGE_PENDING = 1;   // Passwort ok, 2FA offen
    public const STAGE_FULL    = 2;   // vollständig angemeldet

    private ?array $userCache = null;

    public function __construct(
        private Db $db,
        private Crypto $crypto,
        private Audit $audit,
        private array $cfg,          // $config['security']
        private array $appCfg = []   // $config['app'] - nur für base_path/force_https beim Cookie
    ) {}

    // =================================================================
    // Login-Ablauf
    // =================================================================

    /**
     * Schritt 1: Benutzername + Passwort.
     * Rückgabe: ['ok'=>bool, 'stage'=>int, 'error'=>?string, 'user_id'=>?int]
     */
    public function attemptLogin(string $username, string $password): array
    {
        $username = trim($username);
        $ipKey    = 'ip:' . ($_SERVER['REMOTE_ADDR'] ?? 'cli');

        if ($this->isThrottled($username) || $this->isThrottled($ipKey)) {
            return ['ok' => false, 'stage' => self::STAGE_NONE,
                    'error' => 'Zu viele Fehlversuche. Bitte in einigen Minuten erneut versuchen.'];
        }

        $user = $this->db->one(
            'SELECT * FROM users WHERE username = :u AND deleted_at IS NULL',
            [':u' => $username]
        );

        // Timing angleichen, damit "User existiert nicht" nicht messbar ist.
        $hash = $user['password_hash'] ?? '$argon2id$v=19$m=65536,t=4,p=1$AAAAAAAAAAAAAAAAAAAAAA$'
                                        . str_repeat('A', 43);
        $passwordOk = password_verify($password, $hash);

        if (!$user || !$passwordOk) {
            $this->recordAttempt($username, false);
            $this->recordAttempt($ipKey, false);
            $this->audit->log('login.failed', $user['id'] ?? null, null, 'auth', null,
                              ['username' => $username]);
            return ['ok' => false, 'stage' => self::STAGE_NONE,
                    'error' => 'Benutzername oder Passwort ist falsch.'];
        }

        if ($user['status'] !== 'active') {
            $this->audit->log('login.blocked', (int)$user['id'], null, 'auth', null,
                              ['status' => $user['status']]);
            return ['ok' => false, 'stage' => self::STAGE_NONE,
                    'error' => 'Dieser Zugang ist nicht freigeschaltet.'];
        }

        if ($user['locked_until'] !== null && strtotime($user['locked_until'] . ' UTC') > time()) {
            return ['ok' => false, 'stage' => self::STAGE_NONE,
                    'error' => 'Der Zugang ist vorübergehend gesperrt.'];
        }

        // Passwort-Hash bei geänderten Parametern transparent erneuern
        if (password_needs_rehash($hash, self::hashAlgo(), self::hashOptions())) {
            $this->db->run('UPDATE users SET password_hash = :h WHERE id = :id', [
                ':h'  => password_hash($password, self::hashAlgo(), self::hashOptions()),
                ':id' => $user['id'],
            ]);
        }

        $this->recordAttempt($username, true);
        $this->db->run('UPDATE users SET failed_logins = 0, locked_until = NULL WHERE id = :id',
                       [':id' => $user['id']]);

        $needs2fa = (int)$user['totp_enabled'] === 1;
        $trustedDevice = $needs2fa ? $this->matchTrustedDevice((int)$user['id']) : null;

        $this->startSession((int)$user['id'], mfaPassed: !$needs2fa || $trustedDevice !== null);

        if ($needs2fa && $trustedDevice === null) {
            $_SESSION['auth_stage'] = self::STAGE_PENDING;
            $this->audit->log('login.password_ok', (int)$user['id'], (int)$user['id'], 'auth');
            return ['ok' => true, 'stage' => self::STAGE_PENDING, 'user_id' => (int)$user['id']];
        }

        if ($trustedDevice !== null) {
            $this->db->run('UPDATE trusted_devices SET last_used_at = UTC_TIMESTAMP() WHERE id = :id',
                           [':id' => $trustedDevice['id']]);
            $this->audit->log('login.trusted_device', (int)$user['id'], (int)$user['id'], 'auth',
                              (int)$trustedDevice['id']);
        }

        // Kein 2FA aktiv (oder vertrautes Gerät): bei erzwungenem 2FA
        // muss der User es ohne vertrautes Gerät trotzdem einrichten.
        $_SESSION['auth_stage'] = self::STAGE_FULL;
        $this->finalizeLogin((int)$user['id']);
        return ['ok' => true, 'stage' => self::STAGE_FULL, 'user_id' => (int)$user['id']];
    }

    /** Schritt 2: TOTP-Code prüfen. */
    public function verifyTotp(string $code): array
    {
        $userId = $_SESSION['auth_user_id'] ?? null;
        if (!$userId || ($_SESSION['auth_stage'] ?? 0) !== self::STAGE_PENDING) {
            return ['ok' => false, 'error' => 'Keine offene Anmeldung.'];
        }

        $key = 'totp:' . $userId;
        if ($this->isThrottled($key)) {
            return ['ok' => false, 'error' => 'Zu viele Fehlversuche. Bitte kurz warten.'];
        }

        $user = $this->db->one('SELECT * FROM users WHERE id = :id', [':id' => $userId]);
        if (!$user || !$user['totp_secret_enc']) {
            return ['ok' => false, 'error' => 'Zwei-Faktor ist für diesen Zugang nicht eingerichtet.'];
        }

        $dek    = $this->crypto->unwrapDek($this->blob($user['dek_wrapped']));
        $secret = $this->crypto->dec($dek, $user['totp_secret_enc'], 'totp:' . $user['id']);

        $step = Totp::verify((string)$secret, $code, (int)$this->cfg['totp_window']);

        if ($step === null) {
            $this->recordAttempt($key, false);
            $this->audit->log('login.totp_failed', (int)$userId, (int)$userId, 'auth');
            return ['ok' => false, 'error' => 'Der Code ist ungültig.'];
        }

        // Replay-Schutz: derselbe Code darf nicht zweimal gelten.
        try {
            $this->db->run('INSERT INTO totp_used_codes (user_id, time_step) VALUES (:u, :s)',
                           [':u' => $userId, ':s' => $step]);
        } catch (\PDOException $e) {
            if ($e->getCode() === '23000') {
                return ['ok' => false, 'error' => 'Dieser Code wurde bereits verwendet.'];
            }
            throw $e;
        }

        $this->recordAttempt($key, true);
        $_SESSION['auth_stage'] = self::STAGE_FULL;
        $this->db->run('UPDATE user_sessions SET mfa_passed = 1 WHERE sid_hash = :s',
                       [':s' => hash('sha256', session_id())]);
        $this->finalizeLogin((int)$userId);
        return ['ok' => true];
    }

    /** Alternative zu TOTP: einmaliger Wiederherstellungscode. */
    public function verifyRecoveryCode(string $code): array
    {
        $userId = $_SESSION['auth_user_id'] ?? null;
        if (!$userId || ($_SESSION['auth_stage'] ?? 0) !== self::STAGE_PENDING) {
            return ['ok' => false, 'error' => 'Keine offene Anmeldung.'];
        }
        $code = strtoupper(preg_replace('/[^A-Z0-9]/i', '', $code) ?? '');

        $userKey = 'recovery:user:' . (int)$userId;
        $ipKey   = 'recovery:ip:' . (string)($_SERVER['REMOTE_ADDR'] ?? 'cli');
        $max     = (int)($this->cfg['recovery_max_attempts'] ?? $this->cfg['login_max_attempts']);
        $window  = (int)($this->cfg['recovery_window'] ?? $this->cfg['login_window']);

        if ($this->isThrottled($userKey, $max, $window) || $this->isThrottled($ipKey, $max, $window)) {
            return ['ok' => false, 'error' => 'Zu viele Fehlversuche. Bitte in einigen Minuten erneut versuchen.'];
        }

        $rows = $this->db->all(
            'SELECT id, code_hash FROM user_recovery_codes WHERE user_id = :u AND used_at IS NULL',
            [':u' => $userId]
        );
        foreach ($rows as $row) {
            if (password_verify($code, $row['code_hash'])) {
                // rowCount() gibt es nur auf dem Statement, nicht auf PDO.
                // Der Rückgabewert von run() ist genau dieses Statement.
                $st = $this->db->run(
                    'UPDATE user_recovery_codes SET used_at = UTC_TIMESTAMP()
                     WHERE id = :id AND user_id = :u AND used_at IS NULL',
                    [':id' => $row['id'], ':u' => $userId]
                );
                if ($st->rowCount() !== 1) {
                    $this->recordAttempt($userKey, false);
                    $this->recordAttempt($ipKey, false);
                    return ['ok' => false, 'error' => 'Der Wiederherstellungscode ist ungültig.'];
                }
                $this->recordAttempt($userKey, true);
                $_SESSION['auth_stage'] = self::STAGE_FULL;
                $this->db->run('UPDATE user_sessions SET mfa_passed = 1 WHERE sid_hash = :s',
                               [':s' => hash('sha256', session_id())]);
                $this->audit->log('login.recovery_used', (int)$userId, (int)$userId, 'auth');
                $this->finalizeLogin((int)$userId);
                $remaining = (int)$this->db->value(
                    'SELECT COUNT(*) FROM user_recovery_codes WHERE user_id = :u AND used_at IS NULL',
                    [':u' => $userId]
                );
                return ['ok' => true, 'remaining' => $remaining];
            }
        }
        $this->recordAttempt($userKey, false);
        $this->recordAttempt($ipKey, false);
        $this->audit->log('login.recovery_failed', (int)$userId, (int)$userId, 'auth');
        return ['ok' => false, 'error' => 'Der Wiederherstellungscode ist ungültig.'];
    }

    // =================================================================
    // "Angemeldet bleiben" - vertraute Geräte
    // =================================================================

    /**
     * Prüft das Cookie des aktuellen Browsers gegen die gespeicherten
     * vertrauten Geräte dieses Nutzers. Der Token selbst steht nur
     * gehasht in der Datenbank (wie bei Wiederherstellungscodes) - ein
     * Datenbank-Leck allein reicht nicht, um sich als vertrautes Gerät
     * auszugeben, dafür bräuchte man zusätzlich das Cookie selbst.
     */
    private function matchTrustedDevice(int $userId): ?array
    {
        $raw = $_COOKIE[$this->trustedDeviceCookieName()] ?? '';
        if (!str_contains($raw, '.')) return null;
        [$id, $token] = explode('.', $raw, 2);
        if (!ctype_digit($id) || $token === '') return null;

        $row = $this->db->one(
            'SELECT * FROM trusted_devices
             WHERE id = :id AND user_id = :u AND revoked_at IS NULL AND expires_at > UTC_TIMESTAMP()',
            [':id' => (int)$id, ':u' => $userId]
        );
        if (!$row || !password_verify($token, $row['token_hash'])) return null;

        return $row;
    }

    /**
     * Legt für den aktuellen Browser ein vertrautes Gerät an und setzt
     * das zugehörige Cookie. Wird nach erfolgreicher 2FA aufgerufen,
     * wenn "dieses Gerät merken" angehakt war.
     */
    public function trustThisDevice(int $userId): void
    {
        $token = bin2hex(random_bytes(32));
        $days  = (int)($this->cfg['trusted_device_days'] ?? 30);

        $st = $this->db->pdo()->prepare(
            'INSERT INTO trusted_devices (user_id, token_hash, label, ip, expires_at)
             VALUES (:u, :h, :l, :ip, DATE_ADD(UTC_TIMESTAMP(), INTERVAL :d DAY))'
        );
        $st->bindValue(':u', $userId, PDO::PARAM_INT);
        $st->bindValue(':h', password_hash($token, self::hashAlgo(), self::hashOptions()));
        $st->bindValue(':l', mb_substr($this->guessDeviceLabel(), 0, 255));
        $st->bindValue(':ip', Audit::ipBinary(), PDO::PARAM_LOB);
        $st->bindValue(':d', $days, PDO::PARAM_INT);
        $st->execute();

        $deviceId = (int)$this->db->pdo()->lastInsertId();
        $this->setTrustedDeviceCookie($deviceId . '.' . $token, $days);
        $this->audit->log('login.trusted_device_added', $userId, $userId, 'auth', $deviceId);
    }

    public function listTrustedDevices(int $userId): array
    {
        return $this->db->all(
            'SELECT * FROM trusted_devices WHERE user_id = :u AND revoked_at IS NULL AND expires_at > UTC_TIMESTAMP()
             ORDER BY last_used_at DESC, created_at DESC',
            [':u' => $userId]
        );
    }

    public function revokeTrustedDevice(int $userId, int $deviceId): bool
    {
        $st = $this->db->run(
            'UPDATE trusted_devices SET revoked_at = UTC_TIMESTAMP()
             WHERE id = :id AND user_id = :u AND revoked_at IS NULL',
            [':id' => $deviceId, ':u' => $userId]
        );
        return $st->rowCount() === 1;
    }

    private function setTrustedDeviceCookie(string $value, int $days): void
    {
        setcookie($this->trustedDeviceCookieName(), $value, [
            'expires'  => time() + $days * 86400,
            'path'     => ($this->appCfg['base_path'] ?? '') . '/',
            'domain'   => '',
            'secure'   => (bool)($this->appCfg['force_https'] ?? true),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }

    /**
     * Mit Fallback statt direktem Array-Zugriff: fehlt der Konfigurations-
     * wert (z.B. weil in config.php vergessen zu ergänzen), würde
     * setcookie() sonst null statt eines Strings bekommen – bei
     * declare(strict_types=1) ist das ein harter Fehler, kein sanftes
     * Ausweichen. Mit Fallback bleibt die Funktion nutzbar, auch wenn
     * die Konfiguration unvollständig ist.
     */
    private function trustedDeviceCookieName(): string
    {
        return (string)($this->cfg['trusted_device_cookie'] ?? 'HDTRUST');
    }

    /** Grobe, rein kosmetische Kennzeichnung für die Geräteliste im Konto. */
    private function guessDeviceLabel(): string
    {
        $ua = (string)($_SERVER['HTTP_USER_AGENT'] ?? '');
        foreach (['iPhone', 'iPad', 'Android', 'Macintosh', 'Windows', 'Linux'] as $needle) {
            if (str_contains($ua, $needle)) {
                $browser = match (true) {
                    str_contains($ua, 'Edg/')     => 'Edge',
                    str_contains($ua, 'Chrome/')  => 'Chrome',
                    str_contains($ua, 'Firefox/') => 'Firefox',
                    str_contains($ua, 'Safari/')  => 'Safari',
                    default => '',
                };
                return trim($needle . ' · ' . $browser, ' ·');
            }
        }
        return 'Unbekanntes Gerät';
    }

    private function finalizeLogin(int $userId): void
    {
        $st = $this->db->pdo()->prepare(
            'UPDATE users SET last_login_at = UTC_TIMESTAMP(), last_login_ip = :ip WHERE id = :id'
        );
        $st->bindValue(':ip', Audit::ipBinary(), PDO::PARAM_LOB);
        $st->bindValue(':id', $userId, PDO::PARAM_INT);
        $st->execute();
        $this->audit->log('login.success', $userId, $userId, 'auth');
    }

    // =================================================================
    // Session-Handling
    // =================================================================

    private function startSession(int $userId, bool $mfaPassed): void
    {
        session_regenerate_id(true);              // Session Fixation verhindern
        $_SESSION['auth_user_id'] = $userId;
        $_SESSION['auth_started'] = time();
        $_SESSION['auth_seen']    = time();
        Csrf::rotate();

        $this->db->run(
            'INSERT INTO user_sessions (user_id, sid_hash, ip, user_agent, mfa_passed, expires_at)
             VALUES (:u, :s, :ip, :ua, :mfa, DATE_ADD(UTC_TIMESTAMP(), INTERVAL :abs SECOND))
             ON DUPLICATE KEY UPDATE last_seen_at = UTC_TIMESTAMP()',
            [
                ':u'   => $userId,
                ':s'   => hash('sha256', session_id()),
                ':ip'  => Audit::ipBinary(),
                ':ua'  => mb_substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255),
                ':mfa' => $mfaPassed ? 1 : 0,
                ':abs' => (int)$this->cfg['session_absolute'],
            ]
        );
    }

    /** Prüft Gültigkeit und aktualisiert den Zeitstempel. */
    public function currentStage(): int
    {
        if (empty($_SESSION['auth_user_id'])) {
            return self::STAGE_NONE;
        }
        $idle     = time() - (int)($_SESSION['auth_seen'] ?? 0);
        $absolute = time() - (int)($_SESSION['auth_started'] ?? 0);
        if ($idle > (int)$this->cfg['session_lifetime'] || $absolute > (int)$this->cfg['session_absolute']) {
            $this->logout('expired');
            return self::STAGE_NONE;
        }

        $row = $this->db->one(
            'SELECT revoked_at FROM user_sessions WHERE sid_hash = :s',
            [':s' => hash('sha256', session_id())]
        );
        if (!$row || $row['revoked_at'] !== null) {
            $this->logout('revoked');
            return self::STAGE_NONE;
        }

        $_SESSION['auth_seen'] = time();
        $this->db->run('UPDATE user_sessions SET last_seen_at = UTC_TIMESTAMP() WHERE sid_hash = :s',
                       [':s' => hash('sha256', session_id())]);

        return (int)($_SESSION['auth_stage'] ?? self::STAGE_NONE);
    }

    public function check(): bool
    {
        return $this->currentStage() === self::STAGE_FULL;
    }

    /** Guard für geschützte Seiten. */
    public function requireLogin(string $loginUrl = '/login.php'): void
    {
        $stage = $this->currentStage();
        if ($stage === self::STAGE_FULL) {
            return;
        }
        header('Location: ' . App::url($stage === self::STAGE_PENDING ? '/login_2fa.php' : $loginUrl));
        exit;
    }

    public function requireAdmin(): void
    {
        $this->requireLogin();
        if (($this->user()['role'] ?? '') !== 'admin') {
            http_response_code(403);
            exit('Kein Zugriff.');
        }
    }

    public function logout(string $reason = 'user'): void
    {
        $userId = $_SESSION['auth_user_id'] ?? null;
        if (session_id()) {
            $this->db->run('UPDATE user_sessions SET revoked_at = UTC_TIMESTAMP() WHERE sid_hash = :s',
                           [':s' => hash('sha256', session_id())]);
        }
        if ($userId) {
            $this->audit->log('logout', (int)$userId, (int)$userId, 'auth', null, ['reason' => $reason]);
        }
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
        }
        session_destroy();
        $this->userCache = null;
    }

    /** Alle anderen Sitzungen abmelden (z. B. nach Passwortwechsel). */
    public function revokeOtherSessions(int $userId): void
    {
        $this->db->run(
            'UPDATE user_sessions SET revoked_at = UTC_TIMESTAMP()
             WHERE user_id = :u AND sid_hash <> :s AND revoked_at IS NULL',
            [':u' => $userId, ':s' => hash('sha256', session_id())]
        );
    }

    /**
     * Eine bestimmte Sitzung des Benutzers gezielt beenden – etwa ein
     * verlorenes Gerät oder eine Sitzung, die nicht mehr gebraucht wird.
     * Auf die eigene, gerade aktive Sitzung angewendet meldet das den
     * Benutzer sofort ab; die Aufrufstelle leitet danach entsprechend um.
     *
     * Scope über user_id: ohne diesen Filter könnte ein Benutzer über
     * eine erratene ID fremde Sitzungen beenden.
     */
    public function revokeSession(int $userId, int $sessionId): bool
    {
        $st = $this->db->run(
            'UPDATE user_sessions SET revoked_at = UTC_TIMESTAMP()
             WHERE id = :id AND user_id = :u AND revoked_at IS NULL',
            [':id' => $sessionId, ':u' => $userId]
        );
        return $st->rowCount() === 1;
    }

    /** Ob die übergebene Sitzungs-ID die aktuell aktive Sitzung ist. */
    public function isCurrentSession(int $sessionId): bool
    {
        $row = $this->db->one('SELECT id FROM user_sessions WHERE sid_hash = :s',
                              [':s' => hash('sha256', session_id())]);
        return $row !== null && (int)$row['id'] === $sessionId;
    }

    // =================================================================
    // Benutzerkontext
    // =================================================================

    public function userId(): ?int
    {
        return isset($_SESSION['auth_user_id']) ? (int)$_SESSION['auth_user_id'] : null;
    }

    public function user(): ?array
    {
        if ($this->userCache !== null) return $this->userCache;
        $id = $this->userId();
        if (!$id) return null;
        $this->userCache = $this->db->one('SELECT * FROM users WHERE id = :id', [':id' => $id]);
        return $this->userCache;
    }

    /** Klartext-DEK des angemeldeten Users – nur im Speicher, nie in der Session. */
    public function dek(?int $userId = null): string
    {
        $userId ??= $this->userId();
        if (!$userId) throw new RuntimeException('Nicht angemeldet.');
        $wrapped = $this->db->value('SELECT dek_wrapped FROM users WHERE id = :id', [':id' => $userId]);
        if (!$wrapped) throw new RuntimeException('Kein Schlüssel für diesen Benutzer.');
        return $this->crypto->unwrapDek($this->blob($wrapped));
    }

    /** Darf $actor auf die Daten von $owner zugreifen? */
    public function mayAccess(int $ownerId, string $module = '*', string $need = 'read'): bool
    {
        $actor = $this->userId();
        if ($actor === null) return false;
        if ($actor === $ownerId) return true;

        $rank = ['read' => 1, 'write' => 2, 'admin' => 3];
        $grant = $this->db->one(
            'SELECT permission FROM account_grants
             WHERE owner_user_id = :o AND grantee_user_id = :g
               AND (scope = :m OR scope = "*")
               AND (expires_at IS NULL OR expires_at > UTC_TIMESTAMP())
             ORDER BY FIELD(permission, "admin","write","read") LIMIT 1',
            [':o' => $ownerId, ':g' => $actor, ':m' => $module]
        );
        return $grant && ($rank[$grant['permission']] ?? 0) >= ($rank[$need] ?? 99);
    }

    // =================================================================
    // Benutzerverwaltung
    // =================================================================

    public function createUser(
        string $username,
        string $password,
        ?string $email = null,
        ?string $displayName = null,
        string $role = 'user',
        string $status = 'active'
    ): int {
        $err = self::validatePassword($password, (int)$this->cfg['password_min_length']);
        if ($err) throw new RuntimeException($err);
        if (!preg_match('/^[a-zA-Z0-9._-]{3,64}$/', $username)) {
            throw new RuntimeException('Ungültiger Benutzername (3–64 Zeichen, a–z 0–9 . _ -).');
        }

        [$dek, $dekWrapped] = $this->crypto->createDek();

        $st = $this->db->pdo()->prepare(
            'INSERT INTO users (uuid, username, password_hash, password_changed_at,
                                email_enc, email_bidx, display_name_enc,
                                dek_wrapped, role, status)
             VALUES (:uuid, :u, :ph, UTC_TIMESTAMP(), :em, :eb, :dn, :dw, :role, :status)'
        );
        $st->bindValue(':uuid',  self::uuid4());
        $st->bindValue(':u',     $username);
        $st->bindValue(':ph',    password_hash($password, self::hashAlgo(), self::hashOptions()));
        $st->bindValue(':em',    $email ? $this->crypto->enc($dek, $email, 'user.email') : null, PDO::PARAM_LOB);
        $st->bindValue(':eb',    $email ? $this->crypto->blindIndex('user.email', $email) : null, PDO::PARAM_LOB);
        $st->bindValue(':dn',    $displayName ? $this->crypto->enc($dek, $displayName, 'user.name') : null, PDO::PARAM_LOB);
        $st->bindValue(':dw',    $dekWrapped, PDO::PARAM_LOB);
        $st->bindValue(':role',  $role);
        $st->bindValue(':status',$status);
        $st->execute();

        $id = (int)$this->db->pdo()->lastInsertId();
        $this->audit->log('user.created', $id, $this->userId(), 'auth', $id, ['username' => $username]);
        return $id;
    }

    public function changePassword(int $userId, string $newPassword, ?string $currentPassword = null): void
    {
        $user = $this->db->one('SELECT password_hash FROM users WHERE id = :id', [':id' => $userId]);
        if (!$user) throw new RuntimeException('Benutzer nicht gefunden.');

        if ($currentPassword !== null && !password_verify($currentPassword, $user['password_hash'])) {
            throw new RuntimeException('Das aktuelle Passwort ist falsch.');
        }
        $err = self::validatePassword($newPassword, (int)$this->cfg['password_min_length']);
        if ($err) throw new RuntimeException($err);

        $this->db->run(
            'UPDATE users SET password_hash = :h, password_changed_at = UTC_TIMESTAMP(),
                              must_change_password = 0 WHERE id = :id',
            [':h' => password_hash($newPassword, self::hashAlgo(), self::hashOptions()), ':id' => $userId]
        );
        $this->revokeOtherSessions($userId);
        $this->audit->log('user.password_changed', $userId, $this->userId(), 'auth', $userId);
        // Der DEK bleibt unverändert – deshalb kostet ein Passwortwechsel
        // hier keine Neuverschlüsselung der Daten.
    }

    // -----------------------------------------------------------------
    // 2FA-Einrichtung
    // -----------------------------------------------------------------

    /** Erzeugt ein Secret, speichert es aber erst nach Bestätigung als aktiv. */
    public function beginTotpSetup(int $userId): array
    {
        $secret = Totp::generateSecret();
        $dek    = $this->dek($userId);
        $enc    = $this->crypto->enc($dek, $secret, 'totp:' . $userId);

        $st = $this->db->pdo()->prepare(
            'UPDATE users SET totp_secret_enc = :s, totp_enabled = 0, totp_confirmed_at = NULL WHERE id = :id'
        );
        $st->bindValue(':s', $enc, PDO::PARAM_LOB);
        $st->bindValue(':id', $userId, PDO::PARAM_INT);
        $st->execute();

        $user  = $this->db->one('SELECT username FROM users WHERE id = :id', [':id' => $userId]);
        return [
            'secret'    => $secret,
            'formatted' => Totp::formatSecret($secret),
            'uri'       => Totp::provisioningUri($secret, $user['username'], (string)$this->cfg['totp_issuer']),
        ];
    }

    /** Bestätigt die Einrichtung und liefert die Wiederherstellungscodes zurück. */
    public function confirmTotpSetup(int $userId, string $code): array
    {
        $user = $this->db->one('SELECT dek_wrapped, totp_secret_enc FROM users WHERE id = :id', [':id' => $userId]);
        if (!$user || !$user['totp_secret_enc']) {
            throw new RuntimeException('Es läuft keine Einrichtung.');
        }
        $dek    = $this->crypto->unwrapDek($this->blob($user['dek_wrapped']));
        $secret = $this->crypto->dec($dek, $user['totp_secret_enc'], 'totp:' . $userId);

        if (Totp::verify((string)$secret, $code, (int)$this->cfg['totp_window']) === null) {
            throw new RuntimeException('Der Code stimmt nicht. Bitte Uhrzeit am Gerät prüfen.');
        }

        $this->db->run(
            'UPDATE users SET totp_enabled = 1, totp_confirmed_at = UTC_TIMESTAMP() WHERE id = :id',
            [':id' => $userId]
        );
        $this->audit->log('user.2fa_enabled', $userId, $this->userId(), 'auth', $userId);
        return $this->regenerateRecoveryCodes($userId);
    }

    public function disableTotp(int $userId): void
    {
        $this->db->run(
            'UPDATE users SET totp_enabled = 0, totp_secret_enc = NULL, totp_confirmed_at = NULL WHERE id = :id',
            [':id' => $userId]
        );
        $this->db->run('DELETE FROM user_recovery_codes WHERE user_id = :id', [':id' => $userId]);
        // Ohne 2FA hat "vertrautes Gerät" keine Bedeutung mehr - sonst
        // blieben stille Karteileichen zurück, die bei erneuter
        // Aktivierung von 2FA unerwartet wieder greifen würden.
        $this->db->run('DELETE FROM trusted_devices WHERE user_id = :id', [':id' => $userId]);
        $this->audit->log('user.2fa_disabled', $userId, $this->userId(), 'auth', $userId);
    }

    /** @return string[] Klartextcodes – werden genau einmal angezeigt. */
    public function regenerateRecoveryCodes(int $userId, int $count = 10): array
    {
        $this->db->run('DELETE FROM user_recovery_codes WHERE user_id = :id', [':id' => $userId]);
        $codes = [];
        for ($i = 0; $i < $count; $i++) {
            $plain = strtoupper(bin2hex(random_bytes(5)));       // 10 Zeichen
            $codes[] = substr($plain, 0, 5) . '-' . substr($plain, 5);
            $this->db->run(
                'INSERT INTO user_recovery_codes (user_id, code_hash) VALUES (:u, :h)',
                [':u' => $userId, ':h' => password_hash($plain, PASSWORD_BCRYPT)]
            );
        }
        $this->audit->log('user.recovery_codes_generated', $userId, $this->userId(), 'auth', $userId);
        return $codes;
    }

    // =================================================================
    // Persönliche Daten (Konto)
    // =================================================================

    public const SEX_LABELS = [
        'm'       => 'männlich',
        'w'       => 'weiblich',
        'd'       => 'divers',
        'unknown' => 'keine Angabe',
    ];

    /** Entschlüsselte Profildaten für die Kontoseite. */
    public function profile(int $userId): array
    {
        $row = $this->db->one('SELECT * FROM users WHERE id = :id', [':id' => $userId]);
        if (!$row) throw new RuntimeException('Benutzer nicht gefunden.');

        $dek = $this->dek($userId);
        return [
            'first_name' => $this->crypto->dec($dek, $row['first_name_enc'], 'user.first_name'),
            'last_name'  => $this->crypto->dec($dek, $row['last_name_enc'], 'user.last_name'),
            'birthdate'  => $row['birthdate'],
            'sex'        => $row['sex'],
            'email'      => $this->crypto->dec($dek, $row['email_enc'], 'user.email'),
        ];
    }

    /**
     * Vor-/Nachname, Geburtsdatum und Geschlecht. Alle Felder optional –
     * niemand soll gezwungen sein, das eigene Geburtsdatum preiszugeben,
     * nur weil ein einzelnes Modul (Impfempfehlungen) davon profitiert.
     */
    public function updateProfile(int $userId, ?string $firstName, ?string $lastName, ?string $birthdate, string $sex, ?string $email = null): void
    {
        if (!isset(self::SEX_LABELS[$sex])) $sex = 'unknown';

        $bd = null;
        $birthdate = trim((string)$birthdate);
        if ($birthdate !== '') {
            $dt = \DateTimeImmutable::createFromFormat('!Y-m-d', $birthdate);
            if ($dt === false || $dt > new \DateTimeImmutable('today')) {
                throw new RuntimeException('Das Geburtsdatum ist ungültig oder liegt in der Zukunft.');
            }
            $bd = $dt->format('Y-m-d');
        }

        $dek = $this->dek($userId);
        $firstName = trim((string)$firstName);
        $lastName  = trim((string)$lastName);
        $fEnc = $firstName !== '' ? $this->crypto->enc($dek, $firstName, 'user.first_name') : null;
        $lEnc = $lastName  !== '' ? $this->crypto->enc($dek, $lastName, 'user.last_name') : null;

        $email = trim((string)$email);
        $eEnc = $bidx = null;
        if ($email !== '') {
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new RuntimeException('Die E-Mail-Adresse ist ungültig.');
            }
            $eEnc = $this->crypto->enc($dek, $email, 'user.email');
            $bidx = $this->crypto->blindIndex('user.email', $email);
        }

        $st = $this->db->pdo()->prepare(
            'UPDATE users SET first_name_enc = :f, last_name_enc = :l, birthdate = :b, sex = :s,
                              email_enc = :e, email_bidx = :eb WHERE id = :id'
        );
        $st->bindValue(':f', $fEnc, $fEnc === null ? PDO::PARAM_NULL : PDO::PARAM_LOB);
        $st->bindValue(':l', $lEnc, $lEnc === null ? PDO::PARAM_NULL : PDO::PARAM_LOB);
        $st->bindValue(':b', $bd, $bd === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        $st->bindValue(':s', $sex);
        $st->bindValue(':e', $eEnc, $eEnc === null ? PDO::PARAM_NULL : PDO::PARAM_LOB);
        $st->bindValue(':eb', $bidx, $bidx === null ? PDO::PARAM_NULL : PDO::PARAM_LOB);
        $st->bindValue(':id', $userId, PDO::PARAM_INT);

        try {
            $st->execute();
        } catch (\PDOException $e) {
            // Doppelter Blind Index = dieselbe E-Mail hängt schon an einem
            // anderen Konto (z.B. einem Familienmitglied).
            if ($e->getCode() === '23000') {
                throw new RuntimeException('Diese E-Mail-Adresse ist bereits einem anderen Konto zugeordnet.');
            }
            throw $e;
        }

        $this->userCache = null;
        $this->audit->log('user.profile_updated', $userId, $userId, 'auth', $userId);
    }

    // =================================================================
    // Hilfsfunktionen
    // =================================================================

    private function isThrottled(string $identifier, ?int $maxAttempts = null, ?int $windowSeconds = null): bool
    {
        $maxAttempts ??= (int)$this->cfg['login_max_attempts'];
        $windowSeconds ??= (int)$this->cfg['login_window'];

        $count = (int)$this->db->value(
            'SELECT COUNT(*) FROM login_attempts
             WHERE identifier = :i AND successful = 0
               AND attempted_at > DATE_SUB(UTC_TIMESTAMP(), INTERVAL :w SECOND)',
            [':i' => $identifier, ':w' => $windowSeconds]
        );
        return $count >= $maxAttempts;
    }

    private function recordAttempt(string $identifier, bool $success): void
    {
        $st = $this->db->pdo()->prepare(
            'INSERT INTO login_attempts (identifier, ip, successful) VALUES (:i, :ip, :s)'
        );
        $st->bindValue(':i', mb_substr($identifier, 0, 190));
        $st->bindValue(':ip', Audit::ipBinary(), PDO::PARAM_LOB);
        $st->bindValue(':s', $success ? 1 : 0, PDO::PARAM_INT);
        $st->execute();

        if ($success) {
            $this->db->run('DELETE FROM login_attempts WHERE identifier = :i AND successful = 0',
                           [':i' => $identifier]);
        }
    }

    /** Aufräumen – per Cron einmal täglich. */
    public function purgeExpired(): void
    {
        $this->db->run('DELETE FROM login_attempts WHERE attempted_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL 30 DAY)');
        $this->db->run('DELETE FROM user_sessions  WHERE expires_at   < DATE_SUB(UTC_TIMESTAMP(), INTERVAL 7 DAY)');
        $this->db->run('DELETE FROM totp_used_codes WHERE used_at     < DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 DAY)');
        $this->db->run('DELETE FROM user_tokens    WHERE expires_at   < DATE_SUB(UTC_TIMESTAMP(), INTERVAL 7 DAY)');
    }

    public static function validatePassword(string $pw, int $min = 12): ?string
    {
        if (mb_strlen($pw) < $min) {
            return "Das Passwort muss mindestens {$min} Zeichen haben.";
        }
        if (mb_strlen($pw) > 200) {
            return 'Das Passwort ist zu lang (max. 200 Zeichen).';
        }
        // Passphrasen sind erwünscht – daher keine Zeichenklassen-Pflicht,
        // aber offensichtlich Schwaches wird abgelehnt.
        $weak = ['passwort', 'password', '12345678', 'qwertz', 'gesundheit123'];
        foreach ($weak as $w) {
            if (stripos($pw, $w) !== false) {
                return 'Das Passwort enthält ein zu gängiges Muster.';
            }
        }
        return null;
    }

    private static function hashAlgo(): string
    {
        return defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_BCRYPT;
    }

    private static function hashOptions(): array
    {
        return defined('PASSWORD_ARGON2ID')
            ? ['memory_cost' => 65536, 'time_cost' => 4, 'threads' => 1]
            : ['cost' => 12];
    }

    private function blob($v): string
    {
        return is_resource($v) ? (string)stream_get_contents($v) : (string)$v;
    }

    public static function uuid4(): string
    {
        $d = random_bytes(16);
        $d[6] = chr((ord($d[6]) & 0x0f) | 0x40);
        $d[8] = chr((ord($d[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($d), 4));
    }
}
