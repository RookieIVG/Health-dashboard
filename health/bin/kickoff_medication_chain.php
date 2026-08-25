<?php
declare(strict_types=1);

/**
 * Startet die selbstauslösende Medikamenten-Erinnerungskette. Läuft
 * selbst nur einen Sekundenbruchteil, unabhängig davon, wie lange die
 * Kette danach über die Stunde läuft – umgeht damit jedes Zeitlimit für
 * einzelne Prozesse, weil kein einzelner Prozess lange lebt. Für
 * Hoster, die weder einen Cron-Takt unter einer Stunde noch lang
 * laufende Prozesse erlauben (z.B. World4You).
 *
 * Cron-Eintrag:
 *   0 * * * *  php-Interpreter-Pfad /projektpfad/bin/kickoff_medication_chain.php
 *
 * Funktionsweise: ein Tick (public/cron_tick_medication.php) prüft auf
 * fällige Erinnerungen, schläft kurz, löst dann per HTTP den nächsten
 * Tick auf sich selbst aus und beendet sich sofort – ohne auf dessen
 * Antwort zu warten. Dieses Skript hier erledigt den ersten Tick direkt
 * per CLI (kein Web-Umweg nötig) und stößt danach den zweiten Tick per
 * HTTP an; von da an läuft die Kette ausschließlich über kurze,
 * unabhängige Web-Aufrufe weiter, bis kurz vor die nächste volle
 * Stunde – dann startet der nächste Cron-Tick die Kette neu.
 *
 * Braucht app.cron_token in config.php (Zufallswert, s. config.example.php).
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

$token = (string)($app->config['app']['cron_token'] ?? '');
if ($token === '') {
    exit("app.cron_token ist in config.php nicht gesetzt - Kette kann nicht gestartet werden.\n");
}

$subject = 'mailto:' . ($app->config['app']['vapid_subject'] ?? 'noreply@example.com');
$push    = new WebPush($app->keyDir(), $subject);

$r = run_medication_push_check($app, $push);
echo "Erster Tick (CLI): erinnert {$r['sent']}, fehlgeschlagen {$r['failed']}\n";

$tickUrl = App::absUrl('/cron_tick_medication.php') . '?token=' . urlencode($token);
fire_and_forget_get($tickUrl);

echo "Kette gestartet.\n";
