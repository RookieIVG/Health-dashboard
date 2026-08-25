<?php
declare(strict_types=1);

/**
 * Einstiegspunkt für alle Seiten im Webroot.
 *
 * Hier steht als EINZIGER Stelle der absolute Pfad zum app/-Verzeichnis.
 * Wenn du app/ verschiebst, änderst du nur diese Zeile.
 */

const APP_ROOT = '/home/.sites/31/site7850162/web/health/app';

if (!is_file(APP_ROOT . '/src/App.php')) {
    http_response_code(500);
    error_log('[health] APP_ROOT in _init.php zeigt ins Leere: ' . APP_ROOT);
    exit('Interner Serverfehler.');
}

require_once APP_ROOT . '/src/App.php';
