<?php
declare(strict_types=1);

namespace Health;

use InvalidArgumentException;

/**
 * Diagnosen.
 *
 * Anders als bei Vitalwerten wird hier auch der ICD-Code verschlüsselt.
 * Er ist keine Metadatenangabe, sondern die Diagnose selbst in
 * normierter Form – "F32.1" im Klartext in der Datenbank wäre exakt
 * die Information, die das ganze Verschlüsselungskonzept schützen soll.
 * Für die trotzdem nötige Suche nach einem Code gibt es den Blind Index.
 */
final class DiagnosesRepository extends Repository
{
    protected function table(): string  { return 'diagnoses'; }
    protected function module(): string { return Modules::DIAGNOSIS; }
    protected function dateColumn(): string { return 'onset_date'; }

    protected function encryptedFields(): array
    {
        return ['title' => true, 'icd' => true, 'doctor' => true, 'note' => true];
    }

    public const STATUS = [
        'suspected' => 'Verdacht',
        'active'    => 'aktiv',
        'chronic'   => 'chronisch',
        'remission' => 'in Remission',
        'resolved'  => 'ausgeheilt',
    ];

    /** Status, die als laufend gelten. */
    public const OPEN_STATUS = ['suspected', 'active', 'chronic', 'remission'];

    public const SEVERITY = [
        0 => 'unbestimmt',
        1 => 'leicht',
        2 => 'mittel',
        3 => 'schwer',
    ];

    // =================================================================
    // Schreiben
    // =================================================================

    public function store(array $d, ?int $id = null): int
    {
        $title = trim((string)($d['title'] ?? ''));
        if ($title === '') {
            throw new InvalidArgumentException('Eine Bezeichnung ist nötig.');
        }

        $onset = self::dateOrNull($d['onset_date'] ?? null);
        if ($onset === null) {
            throw new InvalidArgumentException('Bitte ein Datum für den Beginn angeben.');
        }

        $status = (string)($d['status'] ?? 'active');
        if (!isset(self::STATUS[$status])) $status = 'active';

        $end = self::dateOrNull($d['end_date'] ?? null);
        if ($end !== null && $end < $onset) {
            throw new InvalidArgumentException('Das Enddatum liegt vor dem Beginn.');
        }
        // Abgeschlossene Diagnosen brauchen ein Ende, laufende keines
        if (in_array($status, ['resolved'], true) && $end === null) {
            $end = date('Y-m-d');
        }
        if (in_array($status, self::OPEN_STATUS, true)) {
            $end = null;
        }

        $icd = self::normalizeIcd((string)($d['icd'] ?? ''));

        $fields = [
            'onset_date' => $onset,
            'end_date'   => $end,
            'status'     => $status,
            'severity'   => max(0, min(3, (int)($d['severity'] ?? 0))),
            'is_pinned'  => !empty($d['is_pinned']) ? 1 : 0,
            'title'      => mb_substr($title, 0, 200),
            'icd'        => $icd,
            'doctor'     => self::strOrNull($d['doctor'] ?? null),
            'note'       => self::strOrNull($d['note'] ?? null),
        ];

        if ($id === null) {
            $id = $this->create($fields);
        } else {
            $this->update($id, $fields);
        }

        // Blind Index getrennt setzen: das automatische enc/dec der
        // Basisklasse kennt nur *_enc-Spalten.
        $st = $this->db->pdo()->prepare(
            'UPDATE diagnoses SET icd_bidx = :b WHERE id = :id AND user_id = :u'
        );
        $st->bindValue(':b', $icd === null ? null : $this->crypto->blindIndex('diagnosis.icd', $icd),
                       $icd === null ? \PDO::PARAM_NULL : \PDO::PARAM_LOB);
        $st->bindValue(':id', $id, \PDO::PARAM_INT);
        $st->bindValue(':u', $this->ownerId, \PDO::PARAM_INT);
        $st->execute();

        $this->syncTimeline($id, $fields);

        if (isset($d['tags'])) {
            $this->app->tags()->sync($this->module(), $id, FindingsRepository::parseTags((string)$d['tags']), $this->ownerId);
        }

        return $id;
    }

    /**
     * Zwei Timeline-Einträge: Beginn und, falls vorhanden, Abschluss.
     * Getrennte event_type-Werte, damit beide nebeneinander bestehen
     * und der Unique-Key sie beim erneuten Speichern aktualisiert
     * statt zu duplizieren.
     */
    private function syncTimeline(int $id, array $f): void
    {
        $label = $f['title'] . ($f['icd'] ? ' (' . $f['icd'] . ')' : '');

        $this->touchTimeline(
            refId:      $id,
            occurredAt: $f['onset_date'] . ' 00:00:00',
            title:      'Diagnose: ' . $label,
            summary:    trim(self::STATUS[$f['status']] . ' · ' . self::SEVERITY[$f['severity']], ' ·'),
            severity:   (int)$f['severity'] >= 3 ? 2 : ((int)$f['severity'] === 2 ? 1 : 0),
            eventType:  'onset'
        );

        if ($f['end_date'] !== null) {
            $this->touchTimeline(
                refId:      $id,
                occurredAt: $f['end_date'] . ' 00:00:00',
                title:      'Abgeschlossen: ' . $label,
                summary:    self::STATUS[$f['status']],
                severity:   0,
                eventType:  'resolved'
            );
        } else {
            $this->db->run(
                'DELETE FROM timeline_events
                 WHERE user_id = :u AND module = :m AND ref_id = :r AND event_type = :t',
                [':u' => $this->ownerId, ':m' => $this->module(), ':r' => $id, ':t' => 'resolved']
            );
        }
    }

    // =================================================================
    // Lesen
    // =================================================================

    /**
     * Liste, laufende zuerst und darin die schwersten oben.
     * Beim Öffnen der Seite interessiert, was gerade gilt – nicht,
     * was chronologisch zuletzt eingetragen wurde.
     */
    public function listAll(bool $includeClosed = true): array
    {
        $sql = 'SELECT * FROM diagnoses WHERE user_id = :u';
        if (!$includeClosed) {
            $sql .= " AND status IN ('" . implode("','", self::OPEN_STATUS) . "')";
        }
        $sql .= " ORDER BY
                    FIELD(status,'chronic','active','suspected','remission','resolved'),
                    is_pinned DESC, severity DESC, onset_date DESC";

        return $this->hydrateAll($this->db->all($sql, [':u' => $this->ownerId]));
    }

    public function open(): array
    {
        return $this->listAll(false);
    }

    /** Für das spätere Notfallblatt. */
    public function pinned(): array
    {
        return $this->hydrateAll($this->db->all(
            'SELECT * FROM diagnoses WHERE user_id = :u AND is_pinned = 1
             ORDER BY severity DESC, onset_date ASC',
            [':u' => $this->ownerId]
        ));
    }

    public function detail(int $id): ?array
    {
        $row = $this->find($id);
        if (!$row) return null;
        $row['attachments'] = $this->app->attachments()->forObject($this->module(), $id, $this->ownerId);
        $row['tags']        = $this->app->tags()->forObject($this->module(), $id, $this->ownerId);
        return $row;
    }

    /** Exakte Suche über den Blind Index. */
    public function findByIcd(string $code): array
    {
        $code = self::normalizeIcd($code);
        if ($code === null) return [];
        return $this->hydrateAll($this->db->all(
            'SELECT * FROM diagnoses WHERE user_id = :u AND icd_bidx = :b ORDER BY onset_date DESC',
            [':u' => $this->ownerId, ':b' => $this->crypto->blindIndex('diagnosis.icd', $code)]
        ));
    }

    public function countsByStatus(): array
    {
        $out = [];
        foreach ($this->db->all(
            'SELECT status, COUNT(*) n FROM diagnoses WHERE user_id = :u GROUP BY status',
            [':u' => $this->ownerId]
        ) as $r) {
            $out[$r['status']] = (int)$r['n'];
        }
        return $out;
    }

    /** Dauer in Tagen bzw. bis heute. */
    public static function duration(array $d): string
    {
        $start = new \DateTimeImmutable($d['onset_date']);
        $end   = new \DateTimeImmutable($d['end_date'] ?? 'today');
        $days  = (int)$start->diff($end)->days;

        if ($days < 31)  return $days . ' Tage';
        if ($days < 365) return round($days / 30.4) . ' Monate';
        $years = $days / 365.25;
        return ($years < 10 ? number_format($years, 1, ',', '') : (string)round($years)) . ' Jahre';
    }

    // =================================================================

    /**
     * ICD-10 grob prüfen: Buchstabe, zwei Ziffern, optional Punkt und
     * bis zu zwei weitere Stellen (z. B. "E11.9", "M54.5", "Z00.0").
     * Bewusst nur Formatprüfung – ein vollständiger ICD-Katalog wäre
     * ein eigenes Projekt und müsste jährlich gepflegt werden.
     */
    public static function normalizeIcd(string $raw): ?string
    {
        $c = strtoupper(preg_replace('/\s+/', '', $raw) ?? '');
        if ($c === '') return null;

        if (!preg_match('/^[A-Z]\d{2}(\.\d{1,2})?[+*!]?$/', $c)) {
            throw new InvalidArgumentException(
                "\"{$raw}\" sieht nicht nach einem ICD-10-Code aus (erwartet z. B. E11.9). "
                . 'Feld leer lassen, wenn kein Code vorliegt.'
            );
        }
        return $c;
    }

    private static function strOrNull($v): ?string
    {
        $v = trim((string)$v);
        return $v === '' ? null : $v;
    }

    private static function dateOrNull($v): ?string
    {
        $v = trim((string)$v);
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) ? $v : null;
    }
}
