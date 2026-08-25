<?php
declare(strict_types=1);

/**
 * Ein einzelner "Tick" der selbstauslösenden Medikamenten-Erinnerungs-
 * kette. Wird nicht direkt von Cron aufgerufen, sondern von
 * bin/kickoff_medication_chain.php gestartet und danach von sich
 * selbst immer wieder neu ausgelöst – jeder einzelne Aufruf lebt nur
 * wenige Sekunden, kein Prozess läuft durchgehend über die Stunde.
 *
 * Sicherheit: nur mit korrektem Token aufrufbar (app.cron_token in
 * config.php), sonst würde jeder mit der URL beliebig oft Erinnerungen
 * auslösen können. Der Token steht zwar in der URL und damit z.B. in
 * Server-Logs – das genügt hier trotzdem, weil ein Auslösen ohne
 * Berechtigung höchstens unnötige, aber harmlose (durch
 * medication_push_log ohnehin doppelt abgesicherte) Prüfungen bewirkt,
 * keine Daten preisgibt.
 */

require __DIR__ . '/_init.php';
require __DIR__ . '/bin/_medication_push_core.php';

use Health\App;
use Health\WebPush;

// Läuft weiter, auch wenn die auslösende Verbindung (von
// fire_and_forget_get() aus dem vorherigen Tick) längst geschlossen
// wurde – ohne das würde der Webserver diesen Tick sofort abbrechen.
ignore_user_abort(true);

$app = App::boot();

$token = (string)($app->config['app']['cron_token'] ?? '');
$given = (string)($_GET['token'] ?? '');
if ($token === '' || !hash_equals($token, $given)) {
    http_response_code(403);
    exit;
}

// Herzschlag, wie bei den anderen Cron-Einstiegspunkten auch - bewusst
// erst NACH der Token-Prüfung, sonst würde ein x-beliebiger Aufruf mit
// falschem Token schon einen "Cron läuft"-Eindruck erzeugen.
@file_put_contents(__DIR__ . '/bin/cron_heartbeat.txt',
    date('Y-m-d H:i:s') . ' ' . basename(__FILE__) . "\n", FILE_APPEND | LOCK_EX);

header('Content-Type: text/plain; charset=utf-8');

// --- Sperre gegen eine außer Kontrolle geratene zweite Kette ------------
$lockFile = rtrim($app->config['paths']['storage'], '/') . '/medication_push_chain.lock';
$maxLockAgeSeconds = 65 * 60;

if (is_file($lockFile) && (time() - (int)filemtime($lockFile)) < $maxLockAgeSeconds) {
    // Normalfall: die eigene, bereits laufende Kette hält die Sperre.
    // touch() verlängert sie, damit sie nicht mitten in der Kette abläuft.
    @touch($lockFile);
} else {
    file_put_contents($lockFile, (string)time());
}

$intervalSeconds = 55; // deutlich unter typischen Ausführungszeit-Limits für Webanfragen
$stopBuffer       = 90; // muss größer als $intervalSeconds sein, sonst Überlappung mit dem nächsten Stunden-Kickoff

$nextHour = (int)(ceil(time() / 3600) * 3600);
if ($nextHour <= time()) $nextHour += 3600;

$subject = 'mailto:' . ($app->config['app']['vapid_subject'] ?? 'noreply@example.com');
$push    = new WebPush($app->keyDir(), $subject);

$r = run_medication_push_check($app, $push);
echo "Tick: erinnert {$r['sent']}, fehlgeschlagen {$r['failed']}\n";

if (time() + $intervalSeconds >= $nextHour - $stopBuffer) {
    echo "Nahe der vollen Stunde - Kette endet hier, nächster Kickoff übernimmt.\n";
    @unlink($lockFile);
    exit;
}

sleep($intervalSeconds);

$tickUrl = App::absUrl('/cron_tick_medication.php') . '?token=' . urlencode($token);
fire_and_forget_get($tickUrl);

echo "Nächster Tick ausgelöst.\n";
