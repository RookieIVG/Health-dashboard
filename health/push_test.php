<?php
declare(strict_types=1);
require __DIR__ . '/_init.php';

use Health\App;
use Health\Csrf;
use Health\WebPush;

$app = App::boot();
$app->auth->requireLogin();

header('Content-Type: application/json');
ob_start(); // Schutz: eventuelle PHP-Warnungen vor der JSON-Ausgabe abfangen

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    ob_clean(); echo json_encode(['error' => 'Methode nicht erlaubt.']);
    exit;
}

$in = json_decode(file_get_contents('php://input'), true);
if (!is_array($in) || !Csrf::checkToken((string)($in['_csrf'] ?? ''))) {
    http_response_code(419);
    ob_clean(); echo json_encode(['error' => 'Sitzung abgelaufen.']);
    exit;
}

$userId = $app->auth->userId();
$dek    = $app->dekFor($userId);

$rows = $app->db->all(
    'SELECT * FROM push_subscriptions WHERE user_id = :u', [':u' => $userId]
);
if (!$rows) {
    ob_clean(); echo json_encode(['error' => 'Kein Gerät angemeldet.']);
    exit;
}

$subject = 'mailto:' . ($app->config['app']['vapid_subject'] ?? 'noreply@example.com');
$push    = new WebPush($app->keyDir(), $subject);

$payload = json_encode([
    'title' => 'Test-Benachrichtigung',
    'body'  => 'Wenn du das hier siehst, funktioniert Push auf diesem Gerät.',
    'url'   => App::absUrl('/profile/security.php'),
    'tag'   => 'health-test',
], JSON_UNESCAPED_SLASHES);

$sent = $failed = 0;
foreach ($rows as $row) {
    $sub = [
        'endpoint' => $app->crypto->dec($dek, $row['endpoint_enc'], 'push_subscriptions.endpoint'),
        'p256dh'   => $app->crypto->dec($dek, $row['p256dh_enc'], 'push_subscriptions.p256dh'),
        'auth'     => $app->crypto->dec($dek, $row['auth_enc'], 'push_subscriptions.auth'),
    ];
    $result = $push->send($sub, $payload, 3600, 'high');
    if ($result['ok']) {
        $sent++;
    } else {
        $failed++;
        if ($result['gone']) {
            $app->db->run('DELETE FROM push_subscriptions WHERE id = :id', [':id' => $row['id']]);
        }
    }
}

ob_clean(); echo json_encode(['ok' => $sent > 0, 'sent' => $sent, 'failed' => $failed]);
