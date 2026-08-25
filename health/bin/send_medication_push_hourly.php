<?php
declare(strict_types=1);

/**
 * Für Hoster, die keinen minütlichen Cron erlauben (z.B. World4You nur
 * stündlich). Dieses Skript läuft einmal pro Stunde per Cron, schläft
 * aber INTERN zwischen den Prüfungen und wiederholt sie bis kurz vor
 * die nächste volle Stunde. Effekt: ähnliche Reaktionszeit wie ein
 * 2-Minuten-Cron, obwohl der Cron selbst nur stündlich feuert.
 *
 * Cron-Eintrag (ersetzt den 2-Minuten-Eintrag, den World4You nicht
 * zulässt):
 *
 *   0 * * * *  php-Interpreter-Pfad /projektpfad/bin/send_medication_push_hourly.php
 *
 * Eine Sperrdatei verhindert überlappende Läufe, falls der vorherige
 * Lauf beim nächsten Stunden-Takt noch aktiv wäre (z.B. weil der Hoster
 * den Prozess vorzeitig beendet hat und die Sperrdatei dadurch stehen
 * geblieben ist) – die Sperre läuft nach gut einer Stunde von selbst
 * ab, damit ein einzelner abgebrochener Lauf nicht dauerhaft blockiert.
 *
 * Falls der Hoster den Prozess schon vor Ablauf der Stunde beendet
 * (manche Shared-Hoster tun das unabhängig vom Cron-Takt): dann wirkt
 * dieses Skript effektiv wie ein Einzeldurchlauf pro Stunde, genau wie
 * send_medication_push.php es wäre – kein Fehlerfall, nur weniger
 * engmaschig als erhofft. Das lässt sich nur durch Ausprobieren beim
 * jeweiligen Hoster klären.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Dieses Skript darf nur auf der Kommandozeile laufen.\n");
}

// Herzschlag - bewusst als ALLERERSTES, vor jedem require: ohne
// Abhängigkeit von App.php, Konfiguration oder Datenbank. Damit
// beweist allein das Vorhandensein/Alter dieser Datei, dass der
// Cron-Job den PHP-Interpreter überhaupt erreicht hat - unabhängig
// davon, ob der Rest des Skripts danach erfolgreich durchläuft.
@file_put_contents(__DIR__ . '/cron_heartbeat.txt',
    date('Y-m-d H:i:s') . ' ' . basename(__FILE__) . "\n", FILE_APPEND | LOCK_EX);

require __DIR__ . '/../app/src/App.php';
require __DIR__ . '/_medication_push_core.php';

use Health\App;
use Health\WebPush;

$app = App::boot();

// --- Sperre gegen überlappende Läufe ------------------------------------
$lockFile = rtrim($app->config['paths']['storage'], '/') . '/medication_push_hourly.lock';
$maxLockAgeSeconds = 65 * 60; // etwas mehr als eine Stunde, s.o.

if (is_file($lockFile) && (time() - (int)filemtime($lockFile)) < $maxLockAgeSeconds) {
    echo "Läuft bereits (Sperrdatei jünger als " . (int)($maxLockAgeSeconds / 60) . " Minuten) – abgebrochen.\n";
    exit;
}
file_put_contents($lockFile, (string)time());
register_shutdown_function(static function () use ($lockFile): void {
    if (is_file($lockFile)) @unlink($lockFile);
});

// --- VAPID-Schlüssel nur einmal laden, nicht bei jeder Wiederholung ----
$subject = 'mailto:' . ($app->config['app']['vapid_subject'] ?? 'noreply@example.com');
$push    = new WebPush($app->keyDir(), $subject);

// --- Innerhalb der Stunde wiederholt prüfen -----------------------------
$intervalSeconds = 90; // Abstand zwischen den Prüfungen
$stopBuffer      = 30; // Sicherheitsabstand vor dem Stundenwechsel

$nextHour = (int)(ceil(time() / 3600) * 3600);
if ($nextHour <= time()) $nextHour += 3600; // falls exakt auf der vollen Stunde gestartet

$totals = ['sent' => 0, 'skippedNotDue' => 0, 'skippedTaken' => 0, 'skippedLogged' => 0, 'failed' => 0];
$iterations = 0;

while (true) {
    $iterations++;
    $r = run_medication_push_check($app, $push);
    foreach ($totals as $k => $v) $totals[$k] += $r[$k];

    if (time() >= $nextHour - $stopBuffer) break;
    sleep($intervalSeconds);
}

echo "Durchläufe: {$iterations}\n";
echo "Erinnert: {$totals['sent']}, nicht fällig: {$totals['skippedNotDue']}, "
   . "bereits genommen: {$totals['skippedTaken']}, bereits erinnert: {$totals['skippedLogged']}, "
   . "fehlgeschlagen: {$totals['failed']}\n";
