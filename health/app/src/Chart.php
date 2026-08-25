<?php
declare(strict_types=1);

namespace Health;

/**
 * Diagramme als reines SVG, serverseitig erzeugt.
 *
 * Keine Chart-Bibliothek: die würde entweder von einem CDN nachgeladen
 * (was die Content-Security-Policy unterbindet und außerdem verrät, dass
 * hier jemand ein Gesundheitsdashboard betreibt) oder als Inline-Skript
 * laufen. SVG skaliert über viewBox von selbst und funktioniert auch,
 * wenn JavaScript aus ist.
 */
final class Chart
{
    /**
     * Verlaufsdiagramm.
     *
     * @param array $points  [['t' => Unix-Zeit, 'v' => float, 'v2' => ?float], …]
     */
    public static function line(
        array $points,
        array $opt = []
    ): string {
        $w   = (int)($opt['width'] ?? 700);
        $h   = (int)($opt['height'] ?? 220);
        $padL = 42; $padR = 12; $padT = 12; $padB = 26;

        if (count($points) < 1) {
            return '<p class="empty">Zu wenige Werte für eine Kurve.</p>';
        }

        $vals = [];
        foreach ($points as $p) {
            $vals[] = (float)$p['v'];
            if (isset($p['v2']) && $p['v2'] !== null) $vals[] = (float)$p['v2'];
        }

        $refLow  = $opt['ref_low']  ?? null;
        $refHigh = $opt['ref_high'] ?? null;
        $refLow2 = $opt['ref_low2'] ?? null;
        $refHigh2= $opt['ref_high2'] ?? null;

        // Referenzband mit in die Skala nehmen, sonst liegt es außerhalb
        foreach ([$refLow, $refHigh, $refLow2, $refHigh2] as $r) {
            if ($r !== null) $vals[] = (float)$r;
        }

        $min = min($vals); $max = max($vals);
        if ($max - $min < 0.0001) { $min -= 1; $max += 1; }
        $pad = ($max - $min) * 0.08;
        $min -= $pad; $max += $pad;

        $t0 = (int)$points[0]['t'];
        $t1 = (int)$points[count($points) - 1]['t'];
        if ($t1 <= $t0) $t1 = $t0 + 1;

        $x = fn(int $t): float => $padL + ($w - $padL - $padR) * (($t - $t0) / ($t1 - $t0));
        $y = fn(float $v): float => $padT + ($h - $padT - $padB) * (1 - (($v - $min) / ($max - $min)));

        $svg  = '<svg viewBox="0 0 ' . $w . ' ' . $h . '" class="chart" '
              . 'preserveAspectRatio="none" xmlns="http://www.w3.org/2000/svg" role="img">';

        // Referenzband
        foreach ([[$refLow, $refHigh], [$refLow2, $refHigh2]] as [$lo, $hi]) {
            if ($lo === null || $hi === null) continue;
            $yTop = $y((float)$hi); $yBot = $y((float)$lo);
            $svg .= sprintf(
                '<rect x="%.1f" y="%.1f" width="%.1f" height="%.1f" class="chart-band"/>',
                $padL, $yTop, $w - $padL - $padR, max(1, $yBot - $yTop)
            );
        }

        // Waagerechte Hilfslinien und Achsenbeschriftung
        $dec = (int)($opt['decimals'] ?? 0);
        for ($i = 0; $i <= 3; $i++) {
            $v  = $min + ($max - $min) * $i / 3;
            $yy = $y($v);
            $svg .= sprintf('<line x1="%.1f" y1="%.1f" x2="%.1f" y2="%.1f" class="chart-grid"/>',
                            $padL, $yy, $w - $padR, $yy);
            $svg .= sprintf('<text x="%.1f" y="%.1f" class="chart-label" text-anchor="end">%s</text>',
                            $padL - 6, $yy + 3.5, App::e(number_format($v, $dec, ',', '.')));
        }

        // Kurven
        foreach (['v' => 'chart-line', 'v2' => 'chart-line2'] as $key => $class) {
            $d = ''; $has = false;
            foreach ($points as $p) {
                if (!isset($p[$key]) || $p[$key] === null) continue;
                $d .= ($d === '' ? 'M' : 'L')
                    . sprintf('%.1f %.1f ', $x((int)$p['t']), $y((float)$p[$key]));
                $has = true;
            }
            if ($has) {
                $svg .= '<path d="' . trim($d) . '" class="' . $class . '" fill="none"/>';
            }
        }

        // Punkte nur bei überschaubarer Menge, sonst wird es Brei
        if (count($points) <= 60) {
            foreach ($points as $p) {
                $svg .= sprintf('<circle cx="%.1f" cy="%.1f" r="2.5" class="chart-dot"/>',
                                $x((int)$p['t']), $y((float)$p['v']));
                if (isset($p['v2']) && $p['v2'] !== null) {
                    $svg .= sprintf('<circle cx="%.1f" cy="%.1f" r="2.5" class="chart-dot2"/>',
                                    $x((int)$p['t']), $y((float)$p['v2']));
                }
            }
        }

        // Zeitachse: nur Anfang und Ende, alles andere wird auf dem Telefon unlesbar
        $tz = new \DateTimeZone($opt['timezone'] ?? 'Europe/Vienna');
        $fmt = fn(int $t) => (new \DateTimeImmutable('@' . $t))->setTimezone($tz)->format('d.m.y');
        $svg .= sprintf('<text x="%.1f" y="%.1f" class="chart-label">%s</text>',
                        $padL, $h - 8, App::e($fmt($t0)));
        $svg .= sprintf('<text x="%.1f" y="%.1f" class="chart-label" text-anchor="end">%s</text>',
                        $w - $padR, $h - 8, App::e($fmt($t1)));

        return $svg . '</svg>';
    }

    /** Kleine Verlaufslinie ohne Achsen, für Übersichtskacheln. */
    public static function spark(array $values, int $w = 120, int $h = 28): string
    {
        $values = array_values(array_map('floatval', $values));
        if (count($values) < 2) return '';

        $min = min($values); $max = max($values);
        if ($max - $min < 0.0001) { $min -= 1; $max += 1; }

        $n = count($values);
        $d = '';
        foreach ($values as $i => $v) {
            $x = ($w - 2) * $i / ($n - 1) + 1;
            $y = 2 + ($h - 4) * (1 - (($v - $min) / ($max - $min)));
            $d .= ($i === 0 ? 'M' : 'L') . sprintf('%.1f %.1f ', $x, $y);
        }

        return '<svg viewBox="0 0 ' . $w . ' ' . $h . '" class="spark" '
             . 'xmlns="http://www.w3.org/2000/svg" aria-hidden="true">'
             . '<path d="' . trim($d) . '" fill="none" class="chart-line"/></svg>';
    }

    /**
     * Kompakte gestapelte Balken-Zeitleiste über N Tage – für die
     * Timeline-Kachel auf der Übersichtsseite. Je Tag ein Balken,
     * gestapelt aus einem Segment pro Modul mit Einträgen an dem Tag,
     * Segmenthöhe = Anzahl Einträge dieses Moduls, Farbe = feste
     * Modulfarbe (Modules::color()).
     *
     * @param array $days [['date'=>'YYYY-MM-DD', 'byModule'=>['medication'=>2, …]], …] älteste zuerst
     */
    public static function dayBars(array $days, int $w = 700, int $h = 90): string
    {
        $n = count($days);
        if ($n < 1) return '<p class="empty">Keine Ereignisse in diesem Zeitraum.</p>';

        $padB = 16; // Platz für die Wochentags-Beschriftung unten
        $barAreaH = $h - $padB;

        $totals = array_map(fn($d) => array_sum($d['byModule']), $days);
        $maxTotal = max(1, max($totals));

        $gap = 3;
        $barW = ($w - $gap * ($n - 1)) / $n;
        $wdInitials = ['Mo','Di','Mi','Do','Fr','Sa','So'];

        $bars = '';
        foreach ($days as $i => $d) {
            $x = $i * ($barW + $gap);
            $total = array_sum($d['byModule']);

            if ($total > 0) {
                $totalH = max(4, $barAreaH * $total / $maxTotal);
                $yCursor = $barAreaH; // von unten nach oben stapeln
                foreach ($d['byModule'] as $module => $count) {
                    if ($count <= 0) continue;
                    $segH = $totalH * $count / $total;
                    $yCursor -= $segH;
                    $title = App::e($d['date'] . ': ' . Modules::label($module) . ' (' . $count . ')');
                    $bars .= sprintf(
                        '<rect x="%.1f" y="%.1f" width="%.1f" height="%.1f" fill="%s"><title>%s</title></rect>',
                        $x, $yCursor, $barW, $segH + 0.5, Modules::color($module), $title
                    );
                }
            } else {
                // leerer Tag: dünner grauer Strich statt unsichtbar
                $bars .= sprintf(
                    '<rect x="%.1f" y="%.1f" width="%.1f" height="1.5" fill="var(--line)"/>',
                    $x, $barAreaH - 1.5, $barW
                );
            }

            if ($i % 2 === 0 || $n <= 7) {
                $wd = $wdInitials[(int)date('N', strtotime($d['date'])) - 1];
                $bars .= sprintf(
                    '<text x="%.1f" y="%d" font-size="8" fill="var(--muted)" text-anchor="middle">%s</text>',
                    $x + $barW / 2, $h - 3, App::e($wd)
                );
            }
        }

        return '<svg viewBox="0 0 ' . $w . ' ' . $h . '" class="daybars" preserveAspectRatio="none" '
             . 'xmlns="http://www.w3.org/2000/svg" role="img" aria-label="Ereignisse der letzten ' . $n . ' Tage">'
             . $bars . '</svg>';
    }
}
