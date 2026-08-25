<?php
declare(strict_types=1);

/**
 * Verschickt fällige Medikamenten-Erinnerungen per Web-Push – ein
 * einzelner Durchlauf pro Aufruf. Für Hoster, die einen minütlichen
 * oder Zwei-Minuten-Cron erlauben:
 *
 *   */2 * * * * php-Interpreter-Pfad /projektpfad/bin/send_medication_push.php
 *
 * Erlaubt der Hoster nur einen stündlichen Cron (z.B. World4You),
 * stattdessen bin/send_medication_push_hourly.php verwenden – ruft
 * dieselbe Kernlogik innerhalb der Stunde selbst wiederholt auf.
 *
 * Je Plan-Slot sind bis zu drei Erinnerungen möglich: immer eine zum
 * Einnahmezeitpunkt selbst, danach zwei einstellbare Nachfass-
 * Erinnerungen NACH diesem Zeitpunkt (users.med_reminder_offset1/2,
 * Minuten danach, je NULL = deaktiviert), falls bis dahin nicht
 * abgezeichnet wurde. Jede wird höchstens einmal pro Slot und Tag
 * verschickt (medication_push_log, eindeutig je schedule_id+Datum+
 * Minutenabstand).
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

$subject = 'mailto:' . ($app->config['app']['vapid_subject'] ?? 'noreply@example.com');
$push    = new WebPush($app->keyDir(), $subject);

$r = run_medication_push_check($app, $push);

echo "Erinnert: {$r['sent']}, nicht fällig: {$r['skippedNotDue']}, bereits genommen: {$r['skippedTaken']}, "
   . "bereits erinnert: {$r['skippedLogged']}, fehlgeschlagen: {$r['failed']}\n";
