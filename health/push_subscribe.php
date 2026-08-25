<?php
declare(strict_types=1);
require __DIR__ . '/_init.php';

use Health\App;
use Health\Csrf;

$app = App::boot();
$app->auth->requireLogin();

header('Content-Type: application/json');
ob_start(); // Schutz: eventuelle PHP-Warnungen vor der JSON-Ausgabe abfangen

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    ob_clean(); echo json_encode(['error' => 'Methode nicht erlaubt.']);
    exit;
}

$raw = file_get_contents('php://input');
$in  = json_decode($raw, true);

// Der Browser schickt hier JSON, nicht ein Formular – das CSRF-Token
// kommt deshalb im JSON-Body statt in $_POST.
if (!is_array($in) || !Csrf::checkToken((string)($in['_csrf'] ?? ''))) {
    http_response_code(419);
    ob_clean(); echo json_encode(['error' => 'Sitzung abgelaufen.']);
    exit;
}

$sub = $in['subscription'] ?? null;
if (!is_array($sub) || empty($sub['endpoint']) || empty($sub['keys']['p256dh']) || empty($sub['keys']['auth'])) {
    http_response_code(400);
    ob_clean(); echo json_encode(['error' => 'Ungültige Anmeldung.']);
    exit;
}

$userId = $app->auth->userId();
$dek    = $app->dekFor($userId);
$hash   = hash('sha256', $sub['endpoint']);
$label  = mb_substr((string)($in['label'] ?? ''), 0, 120) ?: null;

$st = $app->db->pdo()->prepare(
    'INSERT INTO push_subscriptions (user_id, endpoint_hash, endpoint_enc, p256dh_enc, auth_enc, device_label, last_used_at)
     VALUES (:u, :h, :e, :p, :a, :l, UTC_TIMESTAMP())
     ON DUPLICATE KEY UPDATE p256dh_enc = VALUES(p256dh_enc), auth_enc = VALUES(auth_enc),
                             device_label = VALUES(device_label), last_used_at = UTC_TIMESTAMP()'
);
$st->bindValue(':u', $userId, PDO::PARAM_INT);
$st->bindValue(':h', $hash);
$st->bindValue(':e', $app->crypto->enc($dek, $sub['endpoint'], 'push_subscriptions.endpoint'), PDO::PARAM_LOB);
$st->bindValue(':p', $app->crypto->enc($dek, $sub['keys']['p256dh'], 'push_subscriptions.p256dh'), PDO::PARAM_LOB);
$st->bindValue(':a', $app->crypto->enc($dek, $sub['keys']['auth'], 'push_subscriptions.auth'), PDO::PARAM_LOB);
$st->bindValue(':l', $label);
$st->execute();

$app->audit->log('push.subscribed', $userId, $userId, 'push');
ob_clean(); echo json_encode(['ok' => true]);
