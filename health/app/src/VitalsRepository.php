<?php
declare(strict_types=1);

namespace Health;

use InvalidArgumentException;
use RuntimeException;

/**
 * Vitalwerte.
 *
 * Generisch über eine Metrik-Tabelle: neue Messgrößen sind ein Datensatz,
 * kein Schemaeingriff. Zwei Werte pro Messung sind vorgesehen, damit
 * Blutdruck nicht künstlich in zwei Zeilen zerfällt – systolisch und
 * diastolisch gehören zusammen und werden auch gemeinsam beurteilt.
 */
final class VitalsRepository extends Repository
{
    protected function table(): string  { return 'vital_measurements'; }
    protected function module(): string { return Modules::VITALS; }
    protected function encryptedFields(): array { return ['note' => true]; }
    protected function dateColumn(): string { return 'measured_at'; }

    /** Erfassungssituation – beeinflusst die Bewertung erheblich. */
    public const CONTEXTS = [
        ''              => '—',
        'morning'       => 'morgens',
        'evening'       => 'abends',
        'rest'          => 'in Ruhe',
        'after_exercise'=> 'nach Belastung',
        'before_meal'   => 'vor dem Essen',
        'after_meal'    => 'nach dem Essen',
        'lying'         => 'liegend',
        'sitting'       => 'sitzend',
        'standing'      => 'stehend',
    ];

    // =================================================================
    // Metriken
    // =================================================================

    /** Mitgelieferte plus eigene Metriken. */
    public function metrics(bool $onlyActive = true): array
    {
        $sql = 'SELECT * FROM vital_metrics WHERE (user_id IS NULL OR user_id = :u)';
        if ($onlyActive) $sql .= ' AND is_active = 1';
        $sql .= ' ORDER BY sort_order, label';
        return $this->db->all($sql, [':u' => $this->ownerId]);
    }

    public function metric(int $metricId): ?array
    {
        return $this->db->one(
            'SELECT * FROM vital_metrics WHERE id = :id AND (user_id IS NULL OR user_id = :u)',
            [':id' => $metricId, ':u' => $this->ownerId]
        );
    }

    public function metricByKey(string $key): ?array
    {
        return $this->db->one(
            'SELECT * FROM vital_metrics WHERE mkey = :k AND (user_id IS NULL OR user_id = :u)
             ORDER BY user_id IS NULL LIMIT 1',
            [':k' => $key, ':u' => $this->ownerId]
        );
    }

    public function createMetric(array $d): int
    {
        $key = preg_replace('/[^a-z0-9_]/', '', strtolower(trim((string)($d['mkey'] ?? '')))) ?: '';
        if ($key === '' || trim((string)($d['label'] ?? '')) === '') {
            throw new InvalidArgumentException('Kurzname und Bezeichnung sind Pflicht.');
        }
        if ($this->db->one('SELECT id FROM vital_metrics WHERE mkey = :k AND (user_id IS NULL OR user_id = :u)',
                           [':k' => $key, ':u' => $this->ownerId])) {
            throw new RuntimeException("Der Kurzname \"{$key}\" ist schon vergeben.");
        }

        return $this->db->insert(
            'INSERT INTO vital_metrics
                (user_id, mkey, label, unit, decimals, has_second, label_first, label_second,
                 ref_low, ref_high, ref_low2, ref_high2, plaus_min, plaus_max, sort_order)
             VALUES (:u,:k,:l,:un,:dec,:hs,:l1,:l2,:rl,:rh,:rl2,:rh2,:pmin,:pmax,:so)',
            [
                ':u' => $this->ownerId, ':k' => $key,
                ':l' => mb_substr(trim((string)$d['label']), 0, 96),
                ':un' => mb_substr(trim((string)($d['unit'] ?? '')), 0, 24),
                ':dec' => max(0, min(3, (int)($d['decimals'] ?? 0))),
                ':hs' => !empty($d['has_second']) ? 1 : 0,
                ':l1' => $d['label_first'] ?: null,
                ':l2' => $d['label_second'] ?: null,
                ':rl' => self::num($d['ref_low'] ?? null),
                ':rh' => self::num($d['ref_high'] ?? null),
                ':rl2' => self::num($d['ref_low2'] ?? null),
                ':rh2' => self::num($d['ref_high2'] ?? null),
                ':pmin' => self::num($d['plaus_min'] ?? null),
                ':pmax' => self::num($d['plaus_max'] ?? null),
                ':so' => (int)($d['sort_order'] ?? 500),
            ]
        );
    }

    /**
     * Vollständige Bearbeitung einer eigenen Metrik. Mitgelieferte
     * bleiben inhaltlich fest – nur ihre Sichtbarkeit lässt sich über
     * toggleMetricActive() ändern.
     */
    public function updateMetric(int $metricId, array $d): void
    {
        $m = $this->metric($metricId);
        if (!$m) throw new InvalidArgumentException('Metrik nicht gefunden.');
        if ($m['user_id'] === null) {
            throw new RuntimeException(
                'Mitgelieferte Metriken lassen sich nicht ändern. '
                . 'Lege eine eigene Metrik an, wenn du andere Werte brauchst.'
            );
        }

        $label = trim((string)($d['label'] ?? ''));
        if ($label === '') throw new InvalidArgumentException('Bezeichnung fehlt.');
        $hasSecond = !empty($d['has_second']);

        $this->db->run(
            'UPDATE vital_metrics SET
                label=:l, unit=:un, decimals=:dec, has_second=:hs, label_first=:l1, label_second=:l2,
                ref_low=:rl, ref_high=:rh, ref_low2=:rl2, ref_high2=:rh2,
                plaus_min=:pmin, plaus_max=:pmax
             WHERE id = :id AND user_id = :u',
            [
                ':l' => mb_substr($label, 0, 96),
                ':un' => mb_substr(trim((string)($d['unit'] ?? '')), 0, 24),
                ':dec' => max(0, min(3, (int)($d['decimals'] ?? 0))),
                ':hs' => $hasSecond ? 1 : 0,
                ':l1' => $hasSecond ? (trim((string)($d['label_first'] ?? '')) ?: null) : null,
                ':l2' => $hasSecond ? (trim((string)($d['label_second'] ?? '')) ?: null) : null,
                ':rl' => self::num($d['ref_low'] ?? null), ':rh' => self::num($d['ref_high'] ?? null),
                ':rl2' => $hasSecond ? self::num($d['ref_low2'] ?? null) : null,
                ':rh2' => $hasSecond ? self::num($d['ref_high2'] ?? null) : null,
                ':pmin' => self::num($d['plaus_min'] ?? null), ':pmax' => self::num($d['plaus_max'] ?? null),
                ':id' => $metricId, ':u' => $this->ownerId,
            ]
        );
    }

    /** Blendet eine Metrik aus – auch mitgelieferte, das ändert keine Inhalte. */
    public function toggleMetricActive(int $metricId): void
    {
        $m = $this->metric($metricId);
        if (!$m) throw new RuntimeException('Metrik nicht gefunden.');
        $this->db->run(
            'UPDATE vital_metrics SET is_active = 1 - is_active WHERE id = :id AND (user_id IS NULL OR user_id = :u)',
            [':id' => $metricId, ':u' => $this->ownerId]
        );
    }

    public function metricUsageCount(int $metricId): int
    {
        return (int)$this->db->value(
            'SELECT COUNT(*) FROM vital_measurements WHERE metric_id = :m AND user_id = :u',
            [':m' => $metricId, ':u' => $this->ownerId]
        );
    }

    public function deleteMetric(int $metricId): void
    {
        $m = $this->metric($metricId);
        if (!$m) return;
        if ($m['user_id'] === null) {
            throw new RuntimeException('Mitgelieferte Metriken lassen sich nicht löschen, nur ausblenden.');
        }
        if ($this->metricUsageCount($metricId) > 0) {
            throw new RuntimeException('Für diese Messgröße liegen bereits Werte vor. Bitte stattdessen ausblenden.');
        }
        $this->db->run('DELETE FROM vital_metrics WHERE id = :id AND user_id = :u',
                       [':id' => $metricId, ':u' => $this->ownerId]);
    }

    // =================================================================
    // Messwerte erfassen
    // =================================================================

    /**
     * Legt eine Messung an und meldet sie an die Timeline.
     *
     * @param string $measuredAtLocal  lokale Zeit, z. B. "2026-08-19 07:30"
     */
    public function record(
        int $metricId,
        string $measuredAtLocal,
        float $value,
        ?float $value2 = null,
        string $context = '',
        ?string $note = null,
        string $source = 'manual'
    ): int {
        $metric = $this->metric($metricId);
        if (!$metric) throw new InvalidArgumentException('Unbekannte Metrik.');

        $this->assertPlausible($metric, $value, $value2);

        if ((int)$metric['has_second'] === 1 && $value2 === null) {
            throw new InvalidArgumentException(
                'Für ' . $metric['label'] . ' werden beide Werte gebraucht.'
            );
        }
        if ((int)$metric['has_second'] === 0) {
            $value2 = null;
        }
        if (!array_key_exists($context, self::CONTEXTS)) {
            $context = '';
        }

        $utc = $this->toUtc($measuredAtLocal);

        $id = $this->create([
            'metric_id'   => $metricId,
            'measured_at' => $utc,
            'value'       => $value,
            'value2'      => $value2,
            'context'     => $context,
            'source'      => $source,
            'note'        => $note,
        ]);

        $this->touchTimeline(
            refId:      $id,
            occurredAt: $utc,
            title:      $metric['label'] . ' ' . self::formatValue($metric, $value, $value2),
            summary:    $this->timelineSummary($context, $note),
            severity:   $this->severity($metric, $value, $value2)
        );

        return $id;
    }

    public function updateMeasurement(int $id, array $d): void
    {
        $row = $this->find($id);
        if (!$row) throw new RuntimeException('Messung nicht gefunden.');
        $metric = $this->metric((int)$row['metric_id']);

        $value  = self::num($d['value'] ?? $row['value']);
        $value2 = (int)$metric['has_second'] === 1 ? self::num($d['value2'] ?? $row['value2']) : null;
        $this->assertPlausible($metric, (float)$value, $value2 === null ? null : (float)$value2);

        $utc = isset($d['measured_at_local'])
            ? $this->toUtc((string)$d['measured_at_local'])
            : $row['measured_at'];

        $context = array_key_exists((string)($d['context'] ?? ''), self::CONTEXTS)
            ? (string)$d['context'] : $row['context'];

        $this->update($id, [
            'measured_at' => $utc,
            'value'       => $value,
            'value2'      => $value2,
            'context'     => $context,
            'note'        => $d['note'] ?? $row['note'],
        ]);

        $this->touchTimeline(
            refId:      $id,
            occurredAt: $utc,
            title:      $metric['label'] . ' ' . self::formatValue($metric, (float)$value, $value2 === null ? null : (float)$value2),
            summary:    $this->timelineSummary($context, $d['note'] ?? $row['note']),
            severity:   $this->severity($metric, (float)$value, $value2 === null ? null : (float)$value2)
        );
    }

    // =================================================================
    // Abfragen
    // =================================================================

    /** Messreihe einer Metrik, älteste zuerst – so wie eine Kurve läuft. */
    public function series(int $metricId, ?string $fromUtc = null, int $limit = 500): array
    {
        $sql = 'SELECT * FROM vital_measurements WHERE user_id = :u AND metric_id = :m';
        $par = [':u' => $this->ownerId, ':m' => $metricId];
        if ($fromUtc) { $sql .= ' AND measured_at >= :f'; $par[':f'] = $fromUtc; }
        $sql .= ' ORDER BY measured_at ASC LIMIT ' . max(1, min($limit, 2000));

        return $this->hydrateAll($this->db->all($sql, $par));
    }

    public function latest(int $metricId): ?array
    {
        return $this->hydrate($this->db->one(
            'SELECT * FROM vital_measurements WHERE user_id = :u AND metric_id = :m
             ORDER BY measured_at DESC LIMIT 1',
            [':u' => $this->ownerId, ':m' => $metricId]
        ));
    }

    /**
     * Kennzahlen einer Metrik über einen Zeitraum.
     * Der Median steht bewusst neben dem Mittelwert: eine einzelne
     * Fehlmessung verzerrt den Mittelwert, den Median kaum.
     */
    public function stats(int $metricId, int $days = 30): array
    {
        $from = gmdate('Y-m-d H:i:s', time() - $days * 86400);
        $rows = $this->db->all(
            'SELECT value, value2 FROM vital_measurements
             WHERE user_id = :u AND metric_id = :m AND measured_at >= :f',
            [':u' => $this->ownerId, ':m' => $metricId, ':f' => $from]
        );
        if (!$rows) {
            return ['n' => 0];
        }
        $v  = array_map(fn($r) => (float)$r['value'], $rows);
        $v2 = array_values(array_filter(array_map(
            fn($r) => $r['value2'] === null ? null : (float)$r['value2'], $rows
        ), fn($x) => $x !== null));

        return [
            'n'      => count($v),
            'min'    => min($v),
            'max'    => max($v),
            'avg'    => array_sum($v) / count($v),
            'median' => self::median($v),
            'avg2'   => $v2 ? array_sum($v2) / count($v2) : null,
            'median2'=> $v2 ? self::median($v2) : null,
        ];
    }

    /** Alle Metriken mit einem letzten Wert – für die Übersichtsseite. */
    public function overview(): array
    {
        $out = [];
        foreach ($this->metrics() as $m) {
            $last = $this->latest((int)$m['id']);
            if ($last === null) { continue; }
            $prev = $this->db->one(
                'SELECT value FROM vital_measurements
                 WHERE user_id = :u AND metric_id = :m AND measured_at < :t
                 ORDER BY measured_at DESC LIMIT 1',
                [':u' => $this->ownerId, ':m' => $m['id'], ':t' => $last['measured_at']]
            );
            $out[] = [
                'metric'   => $m,
                'last'     => $last,
                'delta'    => $prev ? (float)$last['value'] - (float)$prev['value'] : null,
                'severity' => $this->severity($m, (float)$last['value'],
                                              $last['value2'] === null ? null : (float)$last['value2']),
                'spark'    => array_map(
                    fn($r) => (float)$r['value'],
                    $this->db->all(
                        'SELECT value FROM (
                            SELECT value, measured_at FROM vital_measurements
                            WHERE user_id = :u AND metric_id = :m
                            ORDER BY measured_at DESC LIMIT 30
                         ) t ORDER BY measured_at ASC',
                        [':u' => $this->ownerId, ':m' => $m['id']]
                    )
                ),
            ];
        }
        return $out;
    }

    /** Metriken ohne jeden Messwert – für die Erfassungsauswahl. */
    public function unusedMetrics(): array
    {
        $used = array_column($this->db->all(
            'SELECT DISTINCT metric_id FROM vital_measurements WHERE user_id = :u',
            [':u' => $this->ownerId]
        ), 'metric_id');

        return array_values(array_filter(
            $this->metrics(),
            fn($m) => !in_array((int)$m['id'], array_map('intval', $used), true)
        ));
    }

    // =================================================================
    // Bewertung und Formatierung
    // =================================================================

    /**
     * 0 = im Orientierungsbereich, 1 = knapp daneben, 2 = deutlich daneben.
     * Das ist eine Darstellungshilfe, keine Beurteilung – ohne Kontext,
     * Vorerkrankungen und Messsituation sagt ein einzelner Wert wenig.
     */
    public function severity(array $metric, float $value, ?float $value2 = null): int
    {
        $s = self::rate($value, self::num($metric['ref_low']), self::num($metric['ref_high']));
        if ($value2 !== null) {
            $s = max($s, self::rate($value2, self::num($metric['ref_low2']), self::num($metric['ref_high2'])));
        }
        return $s;
    }

    private static function rate(float $v, ?float $low, ?float $high): int
    {
        if ($low === null && $high === null) return 0;
        $span = ($high !== null && $low !== null) ? ($high - $low) : max(abs($high ?? $low ?? 1), 1);
        $tol  = max($span * 0.15, 0.001);

        if ($low !== null && $v < $low)   return $v < $low - $tol ? 2 : 1;
        if ($high !== null && $v > $high) return $v > $high + $tol ? 2 : 1;
        return 0;
    }

    private function assertPlausible(array $metric, float $value, ?float $value2): void
    {
        foreach ([[$value, 'Wert'], [$value2, 'zweiter Wert']] as [$v, $name]) {
            if ($v === null) continue;
            $min = self::num($metric['plaus_min']);
            $max = self::num($metric['plaus_max']);
            if (($min !== null && $v < $min) || ($max !== null && $v > $max)) {
                $range = $min !== null && $max !== null
                    ? self::trimNum($min) . ' bis ' . self::trimNum($max)
                    : ($min !== null ? 'ab ' . self::trimNum($min) : 'bis ' . self::trimNum((float)$max));
                throw new InvalidArgumentException(sprintf(
                    '%s liegt außerhalb des möglichen Bereichs (%s %s). Vertippt?',
                    $name, $range, $metric['unit']
                ));
            }
        }
        if ((int)$metric['has_second'] === 1 && $value2 !== null
            && $metric['mkey'] === 'blood_pressure' && $value2 >= $value) {
            throw new InvalidArgumentException(
                'Der diastolische Wert liegt über dem systolischen – Zahlen vertauscht?'
            );
        }
    }

    public static function formatValue(array $metric, float $value, ?float $value2 = null): string
    {
        $d = (int)$metric['decimals'];
        $s = number_format($value, $d, ',', '.');
        if ($value2 !== null) {
            $s .= '/' . number_format($value2, $d, ',', '.');
        }
        return trim($s . ' ' . $metric['unit']);
    }

    private function timelineSummary(string $context, ?string $note): ?string
    {
        $parts = [];
        if ($context !== '' && isset(self::CONTEXTS[$context])) $parts[] = self::CONTEXTS[$context];
        if ($note) $parts[] = $note;
        return $parts ? implode(' · ', $parts) : null;
    }

    // =================================================================

    public static function num($v): ?float
    {
        if ($v === null || $v === '') return null;
        // Deutsche Eingabe mit Komma zulassen
        $v = str_replace(',', '.', (string)$v);
        return is_numeric($v) ? (float)$v : null;
    }

    public static function trimNum(float $v): string
    {
        return rtrim(rtrim(number_format($v, 3, ',', ''), '0'), ',');
    }

    private static function median(array $v): float
    {
        sort($v);
        $n = count($v);
        $m = intdiv($n, 2);
        return $n % 2 ? $v[$m] : ($v[$m - 1] + $v[$m]) / 2;
    }
}
