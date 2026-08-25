<?php
declare(strict_types=1);

/**
 * Kopie als config.php anlegen und anpassen.
 * Diese Datei NIEMALS ins Git-Repo committen.
 */
return [
    'app' => [
        'name'       => 'Gesundheitsdashboard',
        'base_url'   => 'https://gesundheit.example.at',
        // Unterverzeichnis im Webroot, ohne Slash am Ende.
        // Leer lassen, wenn die App direkt unter der Domain liegt.
        'base_path'  => '',
        'env'        => 'production',        // 'production' | 'dev'
        'timezone'   => 'Europe/Vienna',
        'force_https'=> true,
        // Absenderadresse für Termin-Erinnerungen. Ohne diesen Wert
        // verschickt bin/send_reminders.php keine E-Mails.
        'mail_from'  => 'erinnerung@gesundheit.example.at',
        // Kontaktadresse für Web-Push-Erinnerungen (VAPID "sub"-Claim,
        // ohne "mailto:" davor). Push-Dienste können diese Adresse bei
        // Problemen kontaktieren, z.B. bei zu hohem Sendevolumen.
        'vapid_subject' => 'erinnerung@gesundheit.example.at',
        // Geheimtoken für die selbstauslösende Medikamenten-Erinnerungs-
        // kette (bin/kickoff_medication_chain.php +
        // public/cron_tick_medication.php), nur nötig, falls der Hoster
        // keinen Cron-Takt unter einer Stunde erlaubt. Zufälligen Wert
        // eintragen, z.B. mit: php -r "echo bin2hex(random_bytes(24));"
        'cron_token' => '',
        // Nur nötig, wenn ein Reverse-Proxy TLS beendet.
        // Ausschließlich die tatsächlichen IP-Adressen des Proxys eintragen.
        'trusted_proxies' => [],
    ],

    'db' => [
        'host'    => 'localhost',
        'port'    => 3306,
        'name'    => 'health',
        'user'    => 'health_app',
        'pass'    => 'HIER_AENDERN',
        'charset' => 'utf8mb4',
    ],

    'paths' => [
        // Alle drei MÜSSEN außerhalb des Webroots liegen.
        'keys'     => __DIR__ . '/../keys',
        'storage'  => __DIR__ . '/../storage',
        'sessions' => __DIR__ . '/../storage/sessions',
    ],

    'security' => [
        'session_name'        => 'HDSID',
        'session_lifetime'    => 3600,      // Sekunden Inaktivität
        'session_absolute'    => 43200,     // harte Obergrenze (12 h)
        'login_max_attempts'  => 5,         // pro Fenster
        'recovery_max_attempts' => 5,       // Wiederherstellungscode
        'recovery_window'      => 900,      // 15 min
        'login_window'        => 900,       // 15 min
        'lockout_seconds'     => 900,
        'password_min_length' => 12,
        'totp_issuer'         => 'Gesundheitsdashboard',
        'totp_window'         => 1,         // ±30 s Toleranz
        'require_2fa'         => true,      // 2FA für alle Accounts erzwingen
        'trusted_device_days' => 30,        // "Angemeldet bleiben" - Gültigkeit in Tagen
        'trusted_device_cookie' => 'HDTRUST',
    ],
];
