<?php
declare(strict_types=1);
require __DIR__ . '/_init.php';

use Health\App;

$app  = App::boot();
$auth = $app->auth;
$auth->requireLogin();

$id       = (int)($_GET['id'] ?? 0);
$download = isset($_GET['download']);

if ($id <= 0) {
    http_response_code(400);
    exit('Kein Anhang angegeben.');
}

try {
    // Alles Weitere – Eigentümerprüfung, Entschlüsselung, Integritätstest,
    // Header – erledigt der AttachmentService.
    $app->attachments()->stream($id, $download);
} catch (\Throwable $e) {
    // Ab hier sind eventuell schon Header raus; kurz und ohne Details.
    if (!headers_sent()) {
        http_response_code(404);
        header('Content-Type: text/plain; charset=utf-8');
    }
    error_log('[health] Anhang ' . $id . ': ' . $e->getMessage());
    echo 'Die Datei ist nicht verfügbar.';
}
