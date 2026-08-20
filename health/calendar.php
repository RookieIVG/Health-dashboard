<?php
declare(strict_types=1);
require __DIR__ . '/_init.php';

use Health\App;
use Health\AppointmentsRepository;
use Health\Ics;

/**
 * Kalender-Abo. Bewusst ohne Anmeldung, weil Kalender-Clients keine
 * Sitzung mitbringen – Zugang allein über das Token in der Adresse.
 */
$app = App::boot();

$token  = (string)($_GET['t'] ?? '');
$userId = Ics::userForToken($app, $token);

if ($userId === null) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    exit('Not found.');
}

$repo = new AppointmentsRepository($app, $userId);
$ics  = Ics::build($repo->forExport(), 'Gesundheit', $app->config['app']['timezone']);

header('Content-Type: text/calendar; charset=utf-8');
header('Content-Disposition: inline; filename="gesundheit.ics"');
header('Cache-Control: private, max-age=900');
header('X-Robots-Tag: noindex, nofollow');
echo $ics;
