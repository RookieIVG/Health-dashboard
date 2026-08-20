<?php
declare(strict_types=1);

namespace Health;

/**
 * Kalenderdatei nach RFC 5545.
 *
 * Bewusst nur ein Abo-Feed und kein CalDAV-Server: CalDAV verlangt
 * WebDAV-Methoden (PROPFIND, REPORT, MKCALENDAR), die auf Shared Hosting
 * teils gar nicht durchgereicht werden, und wäre ein eigenes Projekt.
 * Ein abonnierter Feed reicht für den Zweck – Termine am iPhone sehen –
 * und ist einseitig, also ungefährlich: was hier steht, gilt.
 */
final class Ics
{
    public static function build(array $appointments, string $calendarName, string $tz): string
    {
        $out = [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//Health-Dashboard//DE',
            'CALSCALE:GREGORIAN',
            'METHOD:PUBLISH',
            'X-WR-CALNAME:' . self::esc($calendarName),
            'X-WR-TIMEZONE:' . $tz,
            // Ohne diese Angabe fragen manche Clients im Minutentakt nach
            'X-PUBLISHED-TTL:PT6H',
            'REFRESH-INTERVAL;VALUE=DURATION:PT6H',
        ];

        foreach ($appointments as $a) {
            $start = strtotime($a['starts_at'] . ' UTC');
            $end   = $a['ends_at'] ? strtotime($a['ends_at'] . ' UTC') : $start + 3600;

            $desc = [];
            if (!empty($a['contact']['name'])) $desc[] = $a['contact']['name'];
            if (!empty($a['purpose'])) $desc[] = 'Anlass: ' . $a['purpose'];
            if (!empty($a['prep']))    $desc[] = 'Vorbereitung: ' . $a['prep'];

            $out[] = 'BEGIN:VEVENT';
            $out[] = 'UID:' . $a['uid'] . '@health-dashboard';
            $out[] = 'DTSTAMP:' . gmdate('Ymd\THis\Z');
            // SEQUENCE über den Änderungszeitpunkt: sonst übernehmen
            // manche Clients spätere Korrekturen nicht.
            $out[] = 'SEQUENCE:' . (int)((strtotime($a['updated_at'] . ' UTC') - 1700000000) / 60);

            if (!empty($a['all_day'])) {
                $out[] = 'DTSTART;VALUE=DATE:' . gmdate('Ymd', $start);
                $out[] = 'DTEND;VALUE=DATE:' . gmdate('Ymd', $start + 86400);
            } else {
                $out[] = 'DTSTART:' . gmdate('Ymd\THis\Z', $start);
                $out[] = 'DTEND:'   . gmdate('Ymd\THis\Z', $end);
            }

            $out[] = 'SUMMARY:' . self::esc((string)$a['title']);
            if (!empty($a['location'])) $out[] = 'LOCATION:' . self::esc((string)$a['location']);
            if ($desc) $out[] = 'DESCRIPTION:' . self::esc(implode("\n", $desc));
            $out[] = 'STATUS:' . (($a['status'] ?? '') === 'done' ? 'CONFIRMED' : 'TENTATIVE');

            if (!empty($a['reminder_min'])) {
                $out[] = 'BEGIN:VALARM';
                $out[] = 'ACTION:DISPLAY';
                $out[] = 'DESCRIPTION:' . self::esc((string)$a['title']);
                $out[] = 'TRIGGER:-PT' . (int)$a['reminder_min'] . 'M';
                $out[] = 'END:VALARM';
            }

            $out[] = 'END:VEVENT';
        }

        $out[] = 'END:VCALENDAR';

        return implode("\r\n", array_map([self::class, 'fold'], $out)) . "\r\n";
    }

    /** Sonderzeichen nach RFC 5545 maskieren. */
    private static function esc(string $s): string
    {
        $s = str_replace(["\\", "\r\n", "\n", "\r", ';', ','],
                         ['\\\\', '\\n', '\\n', '\\n', '\\;', '\\,'], $s);
        return preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $s) ?? '';
    }

    /**
     * Zeilen über 75 Oktett umbrechen. Der Umbruch muss zwischen ganzen
     * UTF-8-Zeichen liegen – bricht man mitten in eine Mehrbyte-Folge,
     * verwirft der Client den Eintrag oder zeigt Kauderwelsch.
     */
    private static function fold(string $line): string
    {
        if (strlen($line) <= 75) return $line;

        $out = '';
        $cur = '';
        foreach (preg_split('//u', $line, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $ch) {
            if (strlen($cur) + strlen($ch) > ($out === '' ? 75 : 74)) {
                $out .= ($out === '' ? '' : "\r\n ") . $cur;
                $cur = '';
            }
            $cur .= $ch;
        }
        return $out . ($out === '' ? '' : "\r\n ") . $cur;
    }

    /**
     * Abo-Token je Benutzer. Wer die URL hat, sieht die Termine –
     * deshalb ein langes Zufallstoken und die Möglichkeit, es neu zu
     * erzeugen, falls es einmal in falsche Hände gerät.
     */
    public static function token(App $app, ?int $userId = null, bool $regenerate = false): string
    {
        $userId ??= $app->auth->userId();
        $key = 'ics_token';

        if (!$regenerate) {
            $raw = $app->db->value(
                'SELECT value_enc FROM user_settings WHERE user_id = :u AND skey = :k',
                [':u' => $userId, ':k' => $key]
            );
            if ($raw !== null) {
                $t = $app->crypto->dec($app->dekFor($userId), $raw, 'settings.' . $key);
                if ($t) return (string)$t;
            }
        }

        $token = bin2hex(random_bytes(24));
        $enc = $app->crypto->enc($app->dekFor($userId), $token, 'settings.' . $key);

        $st = $app->db->pdo()->prepare(
            'INSERT INTO user_settings (user_id, skey, value_enc) VALUES (:u, :k, :v)
             ON DUPLICATE KEY UPDATE value_enc = VALUES(value_enc)'
        );
        $st->bindValue(':u', $userId, \PDO::PARAM_INT);
        $st->bindValue(':k', $key);
        $st->bindValue(':v', $enc, \PDO::PARAM_LOB);
        $st->execute();

        return $token;
    }

    /**
     * Sucht den Benutzer zum Token. Es gibt keinen Blind Index darauf,
     * also werden die wenigen gespeicherten Token durchgesehen – bei
     * einer Handvoll Familienkonten ist das unkritisch.
     */
    public static function userForToken(App $app, string $token): ?int
    {
        if (strlen($token) !== 48 || !ctype_xdigit($token)) return null;

        foreach ($app->db->all(
            'SELECT user_id, value_enc FROM user_settings WHERE skey = "ics_token"'
        ) as $row) {
            try {
                $stored = $app->crypto->dec($app->dekFor((int)$row['user_id']),
                                            $row['value_enc'], 'settings.ics_token');
            } catch (\Throwable) {
                continue;
            }
            if ($stored !== null && hash_equals((string)$stored, $token)) {
                return (int)$row['user_id'];
            }
        }
        return null;
    }
}
