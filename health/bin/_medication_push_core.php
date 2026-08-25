<?php
declare(strict_types=1);

/**
 * Kernlogik der Medikamenten-Push-Erinnerung als Funktion statt als
 * direkt ausgeführtes Skript – wird sowohl von send_medication_push.php
 * (ein Durchlauf pro Aufruf, für Hoster mit minütlichem Cron) als auch
 * von send_medication_push_hourly.php (mehrere Durchläufe pro Aufruf,
 * für Hoster mit nur stündlichem Cron wie World4You) verwendet. Diese
 * Datei selbst tut beim Einbinden nichts – nur die Funktionsdefinition,
 * kein Seiteneffekt.
 */

use Health\App;
use Health\MedicationRepository;
use Health\WebPush;

/**
 * Ein einzelner Durchlauf: prüft alle Benutzer auf fällige
 * Medikamenten-Erinnerungen und verschickt sie. Wird von den beiden
 * aufrufenden Skripten in einer Schleife wiederholt aufgerufen, prüft
 * also bei jedem Aufruf den aktuellen Zeitpunkt neu – kein interner
 * Zustand zwischen den Aufrufen nötig, da medication_push_log auf der
 * Datenbank ohnehin schon verhindert, dass etwas doppelt verschickt wird.
 *
 * @return array{sent:int, skippedNotDue:int, skippedTaken:int, skippedLogged:int, failed:int}
 */
/**
 * Löst einen HTTP-Aufruf aus, wartet aber NICHT auf die Antwort –
 * schreibt den Request auf den Socket und schließt ihn sofort wieder.
 * Kernstück der selbstauslösenden Kette: so kann ein Skript den
 * nächsten "Tick" anstoßen und sich selbst sofort danach beenden,
 * statt (wie bei einem normalen HTTP-Aufruf) auf die volle Antwort zu
 * warten und dadurch selbst länger am Leben zu bleiben als nötig.
 *
 * Setzt voraus, dass das Zielskript seinerseits ignore_user_abort(true)
 * setzt – sonst würde der Webserver die Anfrage abbrechen, sobald diese
 * Funktion die Verbindung schließt, bevor das Ziel fertig ist.
 *
 * Kein Ersatz für einen echten Job-Queue-Mechanismus, aber ohne exec()/
 * proc_open() auf gewöhnlichem Shared Hosting kaum eleganter lösbar.
 */
function fire_and_forget_get(string $url): void
{
    $parts = parse_url($url);
    if (!$parts || empty($parts['host'])) return;

    $isHttps = ($parts['scheme'] ?? 'https') === 'https';
    $host    = $parts['host'];
    $port    = $parts['port'] ?? ($isHttps ? 443 : 80);
    $target  = ($isHttps ? 'ssl://' : '') . $host;

    $fp = @fsockopen($target, $port, $errno, $errstr, 3);
    if (!$fp) return; // z.B. ausgehende Verbindungen gesperrt - dann bleibt es bei einem Tick

    $path = ($parts['path'] ?? '/') . (isset($parts['query']) ? '?' . $parts['query'] : '');
    $req  = "GET {$path} HTTP/1.1\r\n"
          . "Host: {$host}\r\n"
          . "Connection: Close\r\n\r\n";
    @fwrite($fp, $req);
    @fclose($fp); // bewusst ohne die Antwort zu lesen
}

function run_medication_push_check(App $app, WebPush $push): array
{
    $rows = $app->db->all(
        "SELECT s.id AS schedule_id, s.intake_time, s.cycle_type, s.weekdays, s.interval_days, s.anchor_date,
                s.dose_enc, m.id AS medication_id, m.user_id, m.name_enc, m.strength_enc
         FROM medication_schedule s
         JOIN medications m ON m.id = s.medication_id
         WHERE m.status = 'active' AND m.is_prn = 0
           AND (m.end_date IS NULL OR m.end_date >= CURDATE())
           AND EXISTS (SELECT 1 FROM push_subscriptions p WHERE p.user_id = m.user_id)"
    );

    $sent = $skippedNotDue = $skippedTaken = $skippedLogged = $failed = 0;

    $byUser = [];
    foreach ($rows as $row) {
        $byUser[(int)$row['user_id']][] = $row;
    }

    foreach ($byUser as $userId => $userRows) {
        try {
            $repo = new MedicationRepository($app, $userId);
            $tz   = new DateTimeZone($app->config['app']['timezone']);
            $now  = new DateTimeImmutable('now', $tz);
            $dek  = $app->dekFor($userId);

            $subs = $app->db->all('SELECT * FROM push_subscriptions WHERE user_id = :u', [':u' => $userId]);
            if (!$subs) continue;

            $userRow = $app->db->one('SELECT med_reminder_offset1, med_reminder_offset2 FROM users WHERE id = :u',
                                     [':u' => $userId]);
            $tiers = array_filter([
                0,
                (int)($userRow['med_reminder_offset1'] ?? 0) ?: null,
                (int)($userRow['med_reminder_offset2'] ?? 0) ?: null,
            ], fn($v) => $v !== null);

            foreach ($userRows as $row) {
                $scheduleRow = [
                    'cycle_type' => $row['cycle_type'], 'weekdays' => $row['weekdays'],
                    'interval_days' => $row['interval_days'], 'anchor_date' => $row['anchor_date'],
                ];
                if (!MedicationRepository::matchesDate($scheduleRow, $now)) { $skippedNotDue++; continue; }

                if ($repo->takenOn((int)$row['schedule_id'], $now)) { $skippedTaken++; continue; }

                $intakeAt = new DateTimeImmutable($now->format('Y-m-d') . ' ' . $row['intake_time'], $tz);
                $name = null; $strength = null; $dose = null;

                foreach ($tiers as $minutesAfter) {
                    $triggerAt = $intakeAt->modify("+{$minutesAfter} minutes");
                    if ($now < $triggerAt) continue;

                    $already = $app->db->value(
                        'SELECT COUNT(*) FROM medication_push_log WHERE schedule_id = :s AND sent_date = :d AND minutes_before = :m',
                        [':s' => $row['schedule_id'], ':d' => $now->format('Y-m-d'), ':m' => $minutesAfter]
                    );
                    if ($already) { $skippedLogged++; continue; }

                    if ($name === null) {
                        $name     = $app->crypto->dec($dek, $row['name_enc'], 'medications.name');
                        $strength = $row['strength_enc'] !== null ? $app->crypto->dec($dek, $row['strength_enc'], 'medications.strength') : null;
                        $dose     = $app->crypto->dec($dek, $row['dose_enc'], 'medication_schedule.dose');
                    }

                    $when = $minutesAfter === 0
                        ? 'jetzt fällig'
                        : 'seit ' . $minutesAfter . ' Min. überfällig (' . substr((string)$row['intake_time'], 0, 5) . ' Uhr)';

                    $payload = json_encode([
                        'title' => 'Medikament ' . $when,
                        'body'  => $name . ($strength ? ' ' . $strength : '') . ' – ' . $dose,
                        'url'   => App::absUrl('/medications.php'),
                        'tag'   => 'med-' . $row['schedule_id'] . '-' . $minutesAfter,
                    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

                    $anySent = false;
                    foreach ($subs as $sub) {
                        $subscription = [
                            'endpoint' => $app->crypto->dec($dek, $sub['endpoint_enc'], 'push_subscriptions.endpoint'),
                            'p256dh'   => $app->crypto->dec($dek, $sub['p256dh_enc'], 'push_subscriptions.p256dh'),
                            'auth'     => $app->crypto->dec($dek, $sub['auth_enc'], 'push_subscriptions.auth'),
                        ];
                        $result = $push->send($subscription, $payload, 3600, 'high');
                        if ($result['ok']) {
                            $anySent = true;
                        } else {
                            $failed++;
                            if ($result['gone']) {
                                $app->db->run('DELETE FROM push_subscriptions WHERE id = :id', [':id' => $sub['id']]);
                            } else {
                                error_log('[health] Push an Präparat ' . $row['medication_id'] . ': ' . $result['error']);
                            }
                        }
                    }

                    $app->db->run(
                        'INSERT IGNORE INTO medication_push_log (schedule_id, user_id, sent_date, minutes_before)
                         VALUES (:s, :u, :d, :m)',
                        [':s' => $row['schedule_id'], ':u' => $userId, ':d' => $now->format('Y-m-d'), ':m' => $minutesAfter]
                    );
                    if ($anySent) $sent++;
                }
            }
        } catch (\Throwable $e) {
            error_log('[health] Medikamenten-Push für Benutzer ' . $userId . ': ' . $e->getMessage());
        }
    }

    $result = [
        'sent' => $sent, 'skippedNotDue' => $skippedNotDue, 'skippedTaken' => $skippedTaken,
        'skippedLogged' => $skippedLogged, 'failed' => $failed,
    ];
    log_medication_push_run($app, $result);
    return $result;
}

/**
 * Schreibt eine Zeile pro Durchlauf in eine Protokolldatei, die sich
 * unter public/cron_status.php im Browser ansehen lässt - ohne das
 * hätte man bei World4You (nur ein Eingabefeld für den Cron-Befehl,
 * kein Log einsehbar) keine Möglichkeit zu prüfen, ob der Cron
 * überhaupt läuft. Bewusst NICHT nur error_log(): das landet in einer
 * PHP-Fehlerdatei, auf die man beim Shared Hosting oft ebenfalls
 * keinen einfachen Zugriff hat.
 */
function log_medication_push_run(App $app, array $result): void
{
    $file = rtrim($app->config['paths']['storage'], '/') . '/cron_log.txt';
    $line = '[' . date('Y-m-d H:i:s') . '] erinnert=' . $result['sent']
          . ' nicht_fällig=' . $result['skippedNotDue']
          . ' bereits_genommen=' . $result['skippedTaken']
          . ' bereits_erinnert=' . $result['skippedLogged']
          . ' fehlgeschlagen=' . $result['failed'] . "\n";

    @file_put_contents($file, $line, FILE_APPEND | LOCK_EX);

    // Datei klein halten statt unbegrenzt wachsen zu lassen - bei einer
    // Prüfung alle 1-2 Minuten kämen sonst über Monate hinweg
    // hunderttausende Zeilen zusammen.
    $maxLines = 500;
    $content = @file_get_contents($file);
    if ($content !== false) {
        $lines = explode("\n", rtrim($content, "\n"));
        if (count($lines) > $maxLines) {
            $trimmed = implode("\n", array_slice($lines, -$maxLines)) . "\n";
            @file_put_contents($file, $trimmed, LOCK_EX);
        }
    }
}
