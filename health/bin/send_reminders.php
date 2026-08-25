<?php
declare(strict_types=1);

/**
 * Verschickt fällige Termin-Erinnerungen per E-Mail und/oder Push, je
 * nachdem, was der Benutzer im Konto eingestellt hat
 * (users.notify_appt_mail / notify_appt_push).
 *
 * Cron-Eintrag (alle 15 Minuten, damit auch kurze Vorlaufzeiten wie
 * "1 Stunde vorher" nicht erst mit halbstündiger Verspätung auslösen):
 *
 *   php-Interpreter-Pfad /projektpfad/bin/send_reminders.php
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Dieses Skript darf nur auf der Kommandozeile laufen.\n");
}

require __DIR__ . '/../app/src/App.php';

use Health\App;
use Health\AppointmentsRepository;
use Health\Mailer;
use Health\WebPush;

$app  = App::boot();
$mail = new Mailer($app);

$subject = 'mailto:' . ($app->config['app']['vapid_subject'] ?? 'noreply@example.com');
$push    = new WebPush($app->keyDir(), $subject);

$rows = $app->db->all(
    "SELECT id, user_id, uid FROM appointments
     WHERE status = 'planned'
       AND reminder_min IS NOT NULL
       AND reminder_sent_at IS NULL
       AND starts_at > UTC_TIMESTAMP()
       AND DATE_SUB(starts_at, INTERVAL reminder_min MINUTE) <= UTC_TIMESTAMP()
     ORDER BY user_id, starts_at"
);

$sentMail = $sentPush = $skipped = $failed = 0;

foreach ($rows as $row) {
    $userId = (int)$row['user_id'];

    try {
        $repo = new AppointmentsRepository($app, $userId);
        $appt = $repo->detail((int)$row['id']);
        if (!$appt) continue;

        $user = $app->db->one('SELECT notify_appt_mail, notify_appt_push FROM users WHERE id = :u', [':u' => $userId]);
        $wantMail = (bool)($user['notify_appt_mail'] ?? true);
        $wantPush = (bool)($user['notify_appt_push'] ?? false);

        $when = (new DateTimeImmutable($appt['starts_at'] . ' UTC'))
            ->setTimezone(new DateTimeZone($app->config['app']['timezone']));

        $anyAttempted = false;

        if ($wantMail) {
            $profile = $app->auth->profile($userId);
            $email   = $profile['email'] ?? null;
            if ($email) {
                $anyAttempted = true;
                $lines = [
                    'Erinnerung an einen bevorstehenden Termin:', '', $appt['title'],
                    $when->format('d.m.Y \u\m H:i \U\h\r'),
                ];
                if (!empty($appt['location'])) $lines[] = (string)$appt['location'];
                $lines[] = ''; $lines[] = '-- '; $lines[] = $app->config['app']['name'];

                if ($mail->send($email, 'Termin-Erinnerung: ' . $appt['title'], implode("\n", $lines))) {
                    $sentMail++;
                } else {
                    $failed++;
                }
            }
        }

        if ($wantPush) {
            $subs = $app->db->all('SELECT * FROM push_subscriptions WHERE user_id = :u', [':u' => $userId]);
            if ($subs) {
                $anyAttempted = true;
                $dek = $app->dekFor($userId);
                $payload = json_encode([
                    'title' => 'Termin-Erinnerung',
                    'body'  => $appt['title'] . ' – ' . $when->format('d.m.Y H:i') . ' Uhr'
                             . (!empty($appt['location']) ? ' – ' . $appt['location'] : ''),
                    'url'   => App::absUrl('/appointment.php?id=' . (int)$row['id']),
                    'tag'   => 'appt-' . $row['id'],
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
                    } elseif ($result['gone']) {
                        $app->db->run('DELETE FROM push_subscriptions WHERE id = :id', [':id' => $sub['id']]);
                    } else {
                        error_log('[health] Termin-Push ' . $row['id'] . ': ' . $result['error']);
                    }
                }
                if ($anySent) $sentPush++; else $failed++;
            }
        }

        if (!$anyAttempted) $skipped++;

        // Immer als erledigt markieren, sobald verarbeitet – unabhängig
        // vom Erfolg. Ein endloses erneutes Versuchen bei dauerhaft
        // falscher E-Mail-Adresse oder totem Gerät wäre nur Lärm; das
        // entspricht demselben Muster wie beim Medikamenten-Push.
        $app->db->run('UPDATE appointments SET reminder_sent_at = UTC_TIMESTAMP() WHERE id = :id',
                      [':id' => $row['id']]);
    } catch (\Throwable $e) {
        $failed++;
        error_log('[health] Erinnerung für Termin ' . $row['id'] . ': ' . $e->getMessage());
    }
}

echo "Per Mail: {$sentMail}, per Push: {$sentPush}, ohne aktivierten Kanal: {$skipped}, fehlgeschlagen: {$failed}\n";
