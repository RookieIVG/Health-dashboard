<?php
declare(strict_types=1);

namespace Health;

use InvalidArgumentException;

/**
 * Allergien und Unverträglichkeiten.
 *
 * Schwere Fälle stehen immer oben: Wer diese Liste aufruft, sucht in
 * der Regel nicht "alles", sondern das, was gefährlich werden kann.
 */
final class AllergiesRepository extends Repository
{
    protected function table(): string  { return 'allergies'; }
    protected function module(): string { return Modules::ALLERGY; }
    protected function dateColumn(): string { return 'created_at'; }

    protected function encryptedFields(): array
    {
        return ['substance' => true, 'reaction' => true, 'note' => true];
    }

    public const CATEGORIES = [
        'drug'        => 'Medikament',
        'food'        => 'Nahrungsmittel',
        'environment' => 'Umwelt / Pollen',
        'insect'      => 'Insektengift',
        'contact'     => 'Kontakt',
        'other'       => 'Sonstiges',
    ];

    public const KINDS = [
        'allergy'     => 'Allergie',
        'intolerance' => 'Unverträglichkeit',
        'suspected'   => 'Verdacht',
    ];

    public const SEVERITY = [
        1 => 'leicht',
        2 => 'mittel',
        3 => 'schwer',
    ];

    public function store(array $d, ?int $id = null): int
    {
        $sub = trim((string)($d['substance'] ?? ''));
        if ($sub === '') throw new InvalidArgumentException('Bitte den Auslöser angeben.');

        $cat  = isset(self::CATEGORIES[$d['category'] ?? '']) ? $d['category'] : 'other';
        $kind = isset(self::KINDS[$d['kind'] ?? ''])          ? $d['kind']     : 'allergy';

        $fields = [
            'category'   => $cat,
            'kind'       => $kind,
            'severity'   => max(1, min(3, (int)($d['severity'] ?? 1))),
            'status'     => ($d['status'] ?? 'active') === 'resolved' ? 'resolved' : 'active',
            'onset_date' => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($d['onset_date'] ?? ''))
                            ? $d['onset_date'] : null,
            'is_pinned'  => !empty($d['is_pinned']) ? 1 : 0,
            'substance'  => mb_substr($sub, 0, 200),
            'reaction'   => self::n($d['reaction'] ?? null),
            'note'       => self::n($d['note'] ?? null),
        ];

        if ($id === null) {
            $id = $this->create($fields);
        } else {
            $this->update($id, $fields);
        }

        // Timeline nur, wenn ein Datum bekannt ist – sonst landet der
        // Eintrag beim Anlegedatum und verfälscht die Zeitachse.
        if ($fields['onset_date'] !== null) {
            $this->touchTimeline(
                refId:      $id,
                occurredAt: $fields['onset_date'] . ' 00:00:00',
                title:      self::KINDS[$kind] . ': ' . $fields['substance'],
                summary:    trim(self::CATEGORIES[$cat] . ' · ' . self::SEVERITY[$fields['severity']]),
                severity:   $fields['severity'] >= 3 ? 2 : 0
            );
        }

        return $id;
    }

    /** Schwerste zuerst, aufgelöste ans Ende. */
    public function listAll(bool $includeResolved = true): array
    {
        $sql = 'SELECT * FROM allergies WHERE user_id = :u';
        if (!$includeResolved) $sql .= " AND status = 'active'";
        $sql .= " ORDER BY FIELD(status,'active','resolved'), severity DESC, is_pinned DESC, id DESC";

        return $this->hydrateAll($this->db->all($sql, [':u' => $this->ownerId]));
    }

    /** Für das spätere Notfallblatt. */
    public function critical(): array
    {
        return $this->hydrateAll($this->db->all(
            "SELECT * FROM allergies
             WHERE user_id = :u AND status = 'active' AND (severity >= 3 OR is_pinned = 1)
             ORDER BY severity DESC",
            [':u' => $this->ownerId]
        ));
    }

    private static function n($v): ?string
    {
        $v = trim((string)$v);
        return $v === '' ? null : $v;
    }
}
