<?php
declare(strict_types=1);

namespace Health;

use InvalidArgumentException;
use RuntimeException;

/**
 * Medikationsverwaltung.
 *
 * Der Einnahmeplan folgt dem klassischen Blisterschema – morgens,
 * mittags, abends, nachts – statt exakter Uhrzeiten. So wird ein Plan
 * in der Praxis auch tatsächlich notiert, und es lässt sich damit ohne
 * Zusatzaufwand ein Tagesplan zusammenstellen.
 */
final class MedicationRepository extends Repository
{
    protected function table(): string  { return 'medications'; }
    protected function module(): string { return Modules::MEDICATION; }
    protected function dateColumn(): string { return 'start_date'; }

    protected function encryptedFields(): array
    {
        return ['name' => true, 'strength' => true, 'purpose' => true, 'doctor' => true, 'note' => true];
    }

    public const FORMS = [
        'tablet'    => 'Tablette', 'drops' => 'Tropfen', 'capsule' => 'Kapsel',
        'injection' => 'Injektion', 'spray' => 'Spray', 'cream' => 'Creme/Salbe',
        'patch'     => 'Pflaster', 'inhaler' => 'Inhalator', 'other' => 'Sonstiges',
    ];

    public const STATUS = ['active' => 'aktiv', 'paused' => 'pausiert', 'stopped' => 'abgesetzt'];

    public const PERIODS = [
        'morning' => 'morgens', 'noon' => 'mittags', 'evening' => 'abends', 'night' => 'nachts',
    ];

    public const WEEKDAYS = [1 => 'Mo', 2 => 'Di', 3 => 'Mi', 4 => 'Do', 5 => 'Fr', 6 => 'Sa', 7 => 'So'];

    public const PERIOD_TIMES = [
        'morning' => '08:00', 'noon' => '12:00', 'evening' => '18:00', 'night' => '22:00',
    ];

    public const CYCLES = [
        'daily'    => 'täglich',
        'weekly'   => 'wöchentlich, bestimmte Tage',
        'interval' => 'im Abstand von X Tagen',
    ];

    // =================================================================
    // Präparate
    // =================================================================

    public function store(array $d, ?int $id = null): int
    {
        $name = trim((string)($d['name'] ?? ''));
        if ($name === '') throw new InvalidArgumentException('Bitte den Namen des Präparats angeben.');

        $start = trim((string)($d['start_date'] ?? ''));
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $start)) {
            throw new InvalidArgumentException('Bitte den Beginn angeben.');
        }

        $status = isset(self::STATUS[$d['status'] ?? '']) ? $d['status'] : 'active';
        $form   = isset(self::FORMS[$d['form'] ?? ''])   ? $d['form']   : 'tablet';

        $end = trim((string)($d['end_date'] ?? ''));
        $end = preg_match('/^\d{4}-\d{2}-\d{2}$/', $end) ? $end : null;
        if ($end !== null && $end < $start) {
            throw new InvalidArgumentException('Das Ende liegt vor dem Beginn.');
        }
        if ($status === 'stopped' && $end === null) $end = date('Y-m-d');
        if ($status === 'active' && $end !== null && $end < date('Y-m-d')) {
            // Ein Enddatum in der Vergangenheit bei "aktiv" wäre widersprüchlich –
            // ohne diese Angleichung stünde die Kur als aktiv, obwohl sie laut
            // eigenem Datum längst vorbei ist.
            $status = 'stopped';
        }

        $fields = [
            'form' => $form, 'status' => $status, 'is_prn' => !empty($d['is_prn']) ? 1 : 0,
            'start_date' => $start, 'end_date' => $end,
            'name' => mb_substr($name, 0, 200),
            'strength' => self::n($d['strength'] ?? null),
            'purpose'  => self::n($d['purpose'] ?? null),
            'doctor'   => self::n($d['doctor'] ?? null),
            'note'     => self::n($d['note'] ?? null),
            'stock_unit'     => self::n($d['stock_unit'] ?? null),
            'stock_quantity' => VitalsRepository::num($d['stock_quantity'] ?? null),
            'stock_warn_at'  => VitalsRepository::num($d['stock_warn_at'] ?? null),
        ];

        if ($id === null) {
            $id = $this->create($fields);
        } else {
            $this->update($id, $fields);
        }

        $label = $fields['name'] . ($fields['strength'] ? ' ' . $fields['strength'] : '');

        $this->touchTimeline(
            refId:      $id,
            occurredAt: $start . ' 00:00:00',
            title:      'Medikation begonnen: ' . $label,
            summary:    $fields['purpose'],
            severity:   0,
            eventType:  'started'
        );

        if ($fields['end_date'] !== null) {
            $this->touchTimeline(
                refId:      $id,
                occurredAt: $fields['end_date'] . ' 00:00:00',
                title:      ($status === 'stopped' ? 'Abgesetzt: ' : 'Ende geplant: ') . $label,
                summary:    null, severity: 0, eventType: 'ended'
            );
        } else {
            $this->db->run(
                'DELETE FROM timeline_events WHERE user_id=:u AND module=:m AND ref_id=:r AND event_type=:t',
                [':u' => $this->ownerId, ':m' => $this->module(), ':r' => $id, ':t' => 'ended']
            );
        }

        return $id;
    }

    public function detail(int $id): ?array
    {
        $row = $this->find($id);
        if (!$row) return null;
        $row['schedule']    = $this->scheduleFor($id);
        $row['attachments'] = $this->app->attachments()->forObject($this->module(), $id, $this->ownerId);
        return $row;
    }

    public function listAll(bool $includeStopped = true): array
    {
        $sql = 'SELECT * FROM medications WHERE user_id = :u';
        if (!$includeStopped) $sql .= " AND status <> 'stopped'";
        $sql .= " ORDER BY FIELD(status,'active','paused','stopped'), start_date DESC";
        return $this->hydrateAll($this->db->all($sql, [':u' => $this->ownerId]));
    }

    public function endingSoon(int $withinDays = 14): array
    {
        return $this->hydrateAll($this->db->all(
            "SELECT * FROM medications
             WHERE user_id = :u AND status = 'active' AND end_date IS NOT NULL
               AND end_date <= DATE_ADD(CURDATE(), INTERVAL :d DAY)
             ORDER BY end_date ASC", [':u' => $this->ownerId, ':d' => $withinDays]
        ));
    }

    // =================================================================
    // Einnahmeplan
    // =================================================================

    /**
     * @param string $cycleType 'daily' | 'weekly' | 'interval'
     * @param string $weekdays  nur bei 'weekly' relevant, z.B. "135" für Mo/Mi/Fr
     */
    public function addScheduleRow(
        int $medicationId, string $period, string $dose, string $cycleType,
        string $weekdays = '', ?int $intervalDays = null, ?string $anchorDate = null,
        ?float $doseQty = null
    ): int {
        $this->assertOwnMedication($medicationId);
        if (!isset(self::PERIODS[$period])) throw new InvalidArgumentException('Ungültige Tageszeit.');
        if (!isset(self::CYCLES[$cycleType])) $cycleType = 'weekly';

        $dose = trim($dose);
        if ($dose === '') throw new InvalidArgumentException('Bitte die Dosis angeben.');

        $weekdaysNorm = $cycleType === 'weekly' ? self::normalizeWeekdays($weekdays) : '1234567';

        if ($cycleType === 'interval') {
            if ($intervalDays === null || $intervalDays < 1 || $intervalDays > 365) {
                throw new InvalidArgumentException('Bitte einen Abstand zwischen 1 und 365 Tagen angeben.');
            }
            $anchorDate = ($anchorDate && preg_match('/^\d{4}-\d{2}-\d{2}$/', $anchorDate))
                ? $anchorDate : date('Y-m-d');
        } else {
            $intervalDays = null;
            $anchorDate = null;
        }

        $st = $this->db->pdo()->prepare(
            'INSERT INTO medication_schedule
                (medication_id, user_id, period, cycle_type, weekdays, interval_days, anchor_date,
                 dose_enc, dose_qty, sort_order)
             VALUES (:m, :u, :p, :ct, :w, :iv, :an, :d, :dq, :so)'
        );
        $st->bindValue(':m', $medicationId, \PDO::PARAM_INT);
        $st->bindValue(':u', $this->ownerId, \PDO::PARAM_INT);
        $st->bindValue(':p', $period);
        $st->bindValue(':ct', $cycleType);
        $st->bindValue(':w', $weekdaysNorm);
        $st->bindValue(':iv', $intervalDays, $intervalDays === null ? \PDO::PARAM_NULL : \PDO::PARAM_INT);
        $st->bindValue(':an', $anchorDate, $anchorDate === null ? \PDO::PARAM_NULL : \PDO::PARAM_STR);
        $st->bindValue(':d', $this->crypto->enc($this->dek(), $dose, 'medication_schedule.dose'), \PDO::PARAM_LOB);
        $st->bindValue(':dq', $doseQty, $doseQty === null ? \PDO::PARAM_NULL : \PDO::PARAM_STR);
        $st->bindValue(':so', array_search($period, array_keys(self::PERIODS), true) * 10);
        $st->execute();

        return (int)$this->db->pdo()->lastInsertId();
    }

    public function deleteScheduleRow(int $rowId): void
    {
        $this->db->run('DELETE FROM medication_schedule WHERE id = :id AND user_id = :u',
                       [':id' => $rowId, ':u' => $this->ownerId]);
    }

    public function scheduleFor(int $medicationId): array
    {
        $rows = $this->db->all(
            'SELECT * FROM medication_schedule WHERE medication_id = :m AND user_id = :u
             ORDER BY sort_order, id', [':m' => $medicationId, ':u' => $this->ownerId]
        );
        foreach ($rows as &$r) {
            $r['dose'] = $this->crypto->dec($this->dek(), $r['dose_enc'], 'medication_schedule.dose');
            unset($r['dose_enc']);
        }
        return $rows;
    }

    // =================================================================
    // Nächste Fälligkeit
    // =================================================================

    /**
     * Nächster fälliger Zeitpunkt über alle Plan-Slots eines Präparats.
     * Tageszeiten sind keine exakten Uhrzeiten – für Sortierung und die
     * Überfällig-Markierung wird mit den Richtwerten aus PERIOD_TIMES
     * gerechnet (08/12/18/22 Uhr). Das ist eine Näherung, keine Prognose.
     *
     * @return array{at: \DateTimeImmutable, schedule_id: int, period: string, dose: string}|null
     */
    public function nextDueForMedication(int $medicationId): ?array
    {
        $best = null;
        foreach ($this->scheduleFor($medicationId) as $row) {
            $at = $this->nextOccurrence($row);
            if ($at !== null && ($best === null || $at < $best['at'])) {
                $best = ['at' => $at, 'schedule_id' => (int)$row['id'], 'period' => $row['period'], 'dose' => $row['dose']];
            }
        }
        return $best;
    }

    /** Nächster Termin eines einzelnen Plan-Slots, ab heute gesucht. */
    private function nextOccurrence(array $row, int $maxDays = 400): ?\DateTimeImmutable
    {
        $tz  = new \DateTimeZone($this->app->config['app']['timezone']);
        $today = new \DateTimeImmutable('now', $tz);

        for ($d = 0; $d <= $maxDays; $d++) {
            $date = $today->modify("+{$d} days");
            if (!self::matchesDate($row, $date)) continue;

            if ($d === 0 && $this->takenOn((int)$row['id'], $date)) {
                continue; // heute schon abgezeichnet – nächstes Vorkommen suchen
            }
            $time = self::PERIOD_TIMES[$row['period']] ?? '08:00';
            return new \DateTimeImmutable($date->format('Y-m-d') . ' ' . $time, $tz);
        }
        return null; // z.B. Intervall mit ungültigen Angaben
    }

    /** UTC-Zeitraum [00:00, 24:00) eines lokalen Kalendertags. */
    private function localDayRangeUtc(\DateTimeImmutable $localDate): array
    {
        $tz = new \DateTimeZone($this->app->config['app']['timezone']);
        $start = new \DateTimeImmutable($localDate->format('Y-m-d') . ' 00:00:00', $tz);
        $end   = $start->modify('+1 day');
        return [
            $start->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s'),
            $end->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s'),
        ];
    }

    public function takenOn(int $scheduleId, \DateTimeImmutable $localDate): bool
    {
        [$from, $to] = $this->localDayRangeUtc($localDate);
        return (bool)$this->db->value(
            'SELECT COUNT(*) FROM medication_intakes
             WHERE user_id = :u AND schedule_id = :s AND taken_at >= :f AND taken_at < :t',
            [':u' => $this->ownerId, ':s' => $scheduleId, ':f' => $from, ':t' => $to]
        );
    }

    // =================================================================
    // Einnahme abzeichnen
    // =================================================================

    /**
     * Protokolliert eine Einnahme und schreibt den Bestand fort.
     * $scheduleId ist null bei "bei Bedarf" oder nachträglicher, freier
     * Erfassung. Einnahmen landen bewusst NICHT auf der Timeline – bei
     * mehreren Einträgen täglich würde das die Zeitachse zuflüstern statt
     * informieren; der Verlauf hier auf der Detailseite deckt "wann was
     * wie viel genommen" ab.
     */
    public function logIntake(
        int $medicationId, ?int $scheduleId, string $takenAtLocal,
        ?float $quantity, ?string $doseText = null, ?string $note = null
    ): int {
        if (!$this->exists($medicationId)) throw new RuntimeException('Präparat nicht gefunden.');

        $schedule = null;
        if ($scheduleId !== null) {
            $schedule = $this->db->one(
                'SELECT * FROM medication_schedule WHERE id = :id AND medication_id = :m AND user_id = :u',
                [':id' => $scheduleId, ':m' => $medicationId, ':u' => $this->ownerId]
            );
            if (!$schedule) throw new InvalidArgumentException('Plan-Eintrag nicht gefunden.');
            if ($quantity === null && $schedule['dose_qty'] !== null) $quantity = (float)$schedule['dose_qty'];
            if ($doseText === null) $doseText = $this->crypto->dec($this->dek(), $schedule['dose_enc'], 'medication_schedule.dose');
        }

        $takenUtc = $this->toUtc(str_replace('T', ' ', trim($takenAtLocal)));
        $dek = $this->dek();

        return $this->db->transaction(function () use ($medicationId, $scheduleId, $takenUtc, $quantity, $doseText, $note, $dek): int {
            $st = $this->db->pdo()->prepare(
                'INSERT INTO medication_intakes (medication_id, schedule_id, user_id, taken_at, quantity, dose_enc, note_enc)
                 VALUES (:m, :s, :u, :t, :q, :d, :n)'
            );
            $st->bindValue(':m', $medicationId, \PDO::PARAM_INT);
            $st->bindValue(':s', $scheduleId, $scheduleId === null ? \PDO::PARAM_NULL : \PDO::PARAM_INT);
            $st->bindValue(':u', $this->ownerId, \PDO::PARAM_INT);
            $st->bindValue(':t', $takenUtc);
            $st->bindValue(':q', $quantity, $quantity === null ? \PDO::PARAM_NULL : \PDO::PARAM_STR);
            $doseEnc = $doseText ? $this->crypto->enc($dek, $doseText, 'medication_intakes.dose') : null;
            $st->bindValue(':d', $doseEnc, $doseEnc === null ? \PDO::PARAM_NULL : \PDO::PARAM_LOB);
            $noteEnc = $note ? $this->crypto->enc($dek, $note, 'medication_intakes.note') : null;
            $st->bindValue(':n', $noteEnc, $noteEnc === null ? \PDO::PARAM_NULL : \PDO::PARAM_LOB);
            $st->execute();

            if ($quantity !== null) {
                $this->db->run(
                    'UPDATE medications SET stock_quantity = stock_quantity - :q
                     WHERE id = :id AND user_id = :u AND stock_quantity IS NOT NULL',
                    [':q' => $quantity, ':id' => $medicationId, ':u' => $this->ownerId]
                );
            }

            return (int)$this->db->pdo()->lastInsertId();
        });
    }

    /** Macht eine Protokollierung rückgängig, gibt den Bestand zurück. */
    public function deleteIntake(int $intakeId): void
    {
        $row = $this->db->one(
            'SELECT * FROM medication_intakes WHERE id = :id AND user_id = :u',
            [':id' => $intakeId, ':u' => $this->ownerId]
        );
        if (!$row) return;

        $this->db->transaction(function () use ($row): void {
            $this->db->run('DELETE FROM medication_intakes WHERE id = :id', [':id' => $row['id']]);
            if ($row['quantity'] !== null) {
                $this->db->run(
                    'UPDATE medications SET stock_quantity = stock_quantity + :q
                     WHERE id = :id AND user_id = :u AND stock_quantity IS NOT NULL',
                    [':q' => $row['quantity'], ':id' => $row['medication_id'], ':u' => $this->ownerId]
                );
            }
        });
    }

    /** Einnahmeverlauf eines Präparats, neueste zuerst – "wann was wie viel". */
    public function intakesFor(int $medicationId, int $limit = 100): array
    {
        $dek = $this->dek();
        $rows = $this->db->all(
            'SELECT * FROM medication_intakes WHERE medication_id = :m AND user_id = :u
             ORDER BY taken_at DESC LIMIT ' . max(1, min($limit, 500)),
            [':m' => $medicationId, ':u' => $this->ownerId]
        );
        foreach ($rows as &$r) {
            $r['dose'] = $r['dose_enc'] !== null ? $this->crypto->dec($dek, $r['dose_enc'], 'medication_intakes.dose') : null;
            $r['note'] = $r['note_enc'] !== null ? $this->crypto->dec($dek, $r['note_enc'], 'medication_intakes.note') : null;
            unset($r['dose_enc'], $r['note_enc']);
        }
        return $rows;
    }

    // =================================================================
    // Bestand und Zukäufe
    // =================================================================

    public function addRestock(int $medicationId, float $quantity, string $date, ?string $note = null): int
    {
        if (!$this->exists($medicationId)) throw new RuntimeException('Präparat nicht gefunden.');
        if ($quantity <= 0) throw new InvalidArgumentException('Die Menge muss größer als 0 sein.');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) $date = date('Y-m-d');

        return $this->db->transaction(function () use ($medicationId, $quantity, $date, $note): int {
            $noteEnc = $note ? $this->crypto->enc($this->dek(), $note, 'medication_restocks.note') : null;
            $st = $this->db->pdo()->prepare(
                'INSERT INTO medication_restocks (medication_id, user_id, restock_date, quantity, note_enc)
                 VALUES (:m, :u, :d, :q, :n)'
            );
            $st->bindValue(':m', $medicationId, \PDO::PARAM_INT);
            $st->bindValue(':u', $this->ownerId, \PDO::PARAM_INT);
            $st->bindValue(':d', $date);
            $st->bindValue(':q', $quantity);
            $st->bindValue(':n', $noteEnc, $noteEnc === null ? \PDO::PARAM_NULL : \PDO::PARAM_LOB);
            $st->execute();

            // COALESCE: die erste Packungserfassung darf auch dann klappen,
            // wenn zuvor gar kein Bestand geführt wurde (stock_quantity NULL).
            $this->db->run(
                'UPDATE medications SET stock_quantity = COALESCE(stock_quantity, 0) + :q
                 WHERE id = :id AND user_id = :u',
                [':q' => $quantity, ':id' => $medicationId, ':u' => $this->ownerId]
            );

            return (int)$this->db->pdo()->lastInsertId();
        });
    }

    public function deleteRestock(int $restockId): void
    {
        $row = $this->db->one(
            'SELECT * FROM medication_restocks WHERE id = :id AND user_id = :u',
            [':id' => $restockId, ':u' => $this->ownerId]
        );
        if (!$row) return;

        $this->db->transaction(function () use ($row): void {
            $this->db->run('DELETE FROM medication_restocks WHERE id = :id', [':id' => $row['id']]);
            $this->db->run(
                'UPDATE medications SET stock_quantity = stock_quantity - :q WHERE id = :id AND user_id = :u',
                [':q' => $row['quantity'], ':id' => $row['medication_id'], ':u' => $this->ownerId]
            );
        });
    }

    public function restocksFor(int $medicationId, int $limit = 50): array
    {
        $dek = $this->dek();
        $rows = $this->db->all(
            'SELECT * FROM medication_restocks WHERE medication_id = :m AND user_id = :u
             ORDER BY restock_date DESC, id DESC LIMIT ' . max(1, min($limit, 200)),
            [':m' => $medicationId, ':u' => $this->ownerId]
        );
        foreach ($rows as &$r) {
            $r['note'] = $r['note_enc'] !== null ? $this->crypto->dec($dek, $r['note_enc'], 'medication_restocks.note') : null;
            unset($r['note_enc']);
        }
        return $rows;
    }

    /** Präparate, deren Bestand die Warnschwelle erreicht oder unterschritten hat. */
    public function lowStock(): array
    {
        return $this->hydrateAll($this->db->all(
            "SELECT * FROM medications
             WHERE user_id = :u AND status = 'active'
               AND stock_quantity IS NOT NULL AND stock_warn_at IS NOT NULL
               AND stock_quantity <= stock_warn_at
             ORDER BY stock_quantity ASC", [':u' => $this->ownerId]
        ));
    }

    /**
     * Prüft, ob ein Plan-Slot an einem bestimmten Tag zutrifft.
     *
     *   daily     – immer
     *   weekly    – Wochentag steckt in $row['weekdays']
     *   interval  – Tagesabstand zum Bezugsdatum ist durch interval_days
     *               teilbar. DateTime::diff() rechnet in Kalendertagen
     *               (Julianisches Datum), Zeitzonenwechsel verfälschen
     *               das Ergebnis dadurch nicht.
     */
    public static function matchesDate(array $row, \DateTimeImmutable $date): bool
    {
        return match ($row['cycle_type'] ?? 'weekly') {
            'daily'    => true,
            'interval' => self::matchesInterval($row, $date),
            default    => str_contains((string)$row['weekdays'], $date->format('N')),
        };
    }

    private static function matchesInterval(array $row, \DateTimeImmutable $date): bool
    {
        $anchor = $row['anchor_date'] ?? null;
        $n      = (int)($row['interval_days'] ?? 0);
        if (!$anchor || $n < 1) return false;

        $anchorDate = new \DateTimeImmutable($anchor);
        if ($date < $anchorDate) return false;

        $days = (int)$anchorDate->diff($date)->days;
        return $days % $n === 0;
    }

    /** Lesbare Kurzbeschreibung eines Plan-Slots für die Anzeige. */
    public static function cycleLabel(array $row): string
    {
        return match ($row['cycle_type'] ?? 'weekly') {
            'daily'    => 'täglich',
            'interval' => 'alle ' . (int)$row['interval_days'] . ' Tage'
                          . (!empty($row['anchor_date']) ? ' (ab ' . date('d.m.Y', strtotime($row['anchor_date'])) . ')' : ''),
            default    => (function () use ($row): string {
                $days = str_split((string)$row['weekdays']);
                if (count($days) === 7) return 'täglich';
                return implode(', ', array_map(fn($d) => self::WEEKDAYS[(int)$d], $days));
            })(),
        };
    }

    // =================================================================

    private function assertOwnMedication(int $medicationId): void
    {
        if (!$this->exists($medicationId)) throw new RuntimeException('Präparat nicht gefunden.');
    }

    private static function normalizeWeekdays(string $raw): string
    {
        $digits = array_values(array_unique(array_filter(str_split(preg_replace('/\D/', '', $raw) ?? ''))));
        sort($digits);
        $digits = array_intersect($digits, ['1','2','3','4','5','6','7']);
        return $digits ? implode('', $digits) : '1234567';
    }

    private static function n($v): ?string
    {
        $v = trim((string)$v);
        return $v === '' ? null : $v;
    }
}
