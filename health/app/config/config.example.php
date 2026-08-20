<?php
declare(strict_types=1);

/**
 * Kopie als config.php anlegen und anpassen.
 * Diese Datei NIEMALS ins Git-Repo committen.
 */
return [
    'app' => [
        'name'       => 'Health-Dashboard',
        'base_url'   => 'XXXXXXXXXX',
        // Unterverzeichnis im Webroot, ohne Slash am Ende.
        // Leer lassen, wenn die App direkt unter der Domain liegt.
        'base_path'  => '',
        'env'        => 'production',        // 'production' | 'dev'
        'timezone'   => 'Europe/Vienna',
        'force_https'=> true,
        'trusted_proxies' => [],
    ],

    'db' => [
        'host'    => 'XXXXXXXXXX',
        'port'    => 3306,
        'name'    => 'XXXXXXXXXX',
        'user'    => 'XXXXXXXXXX',
        'pass'    => 'XXXXXXXXXX',
        'charset' => 'utf8mb4',
    ],

    'paths' => [
        'keys'     => __DIR__ . '/../keys',
        'storage'  => __DIR__ . '/../storage',
        'sessions' => 'XXXXXXXXXX',
    ],

    'security' => [
        'session_name'        => 'HDSID',
        'session_lifetime'    => 3600,      // Sekunden Inaktivität
        'session_absolute'    => 43200,     // harte Obergrenze (12 h)
        'login_max_attempts'  => 5,         // pro Fenster
        'login_window'        => 900,       // 15 min
        'lockout_seconds'     => 900,
        'password_min_length' => 12,
        'totp_issuer'         => 'Health-Dashboard',
        'totp_window'         => 1,         // ±30 s Toleranz
        'require_2fa'         => true,      // 2FA für alle Accounts erzwingen
    ],
];
