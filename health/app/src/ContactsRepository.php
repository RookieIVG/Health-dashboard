<?php
declare(strict_types=1);

namespace Health;

use InvalidArgumentException;

/**
 * Ärztinnen, Ärzte, Einrichtungen.
 *
 * Auch die Fachrichtung ist verschlüsselt: "Onkologie" neben einem Namen
 * benennt die Erkrankung so deutlich wie eine Diagnose.
 */
final class ContactsRepository extends Repository
{
    protected function table(): string  { return 'contacts'; }
    protected function module(): string { return Modules::CONTACT; }
    protected function dateColumn(): string { return 'created_at'; }

    protected function encryptedFields(): array
    {
        return ['name' => true, 'specialty' => true, 'institution' => true,
                'phone' => true, 'email' => true, 'address' => true, 'note' => true];
    }

    public const KINDS = [
        'doctor'    => 'Arztpraxis',
        'clinic'    => 'Krankenhaus / Ambulanz',
        'therapist' => 'Therapie',
        'pharmacy'  => 'Apotheke',
        'insurance' => 'Versicherung / Kasse',
        'other'     => 'Sonstiges',
    ];

    public function store(array $d, ?int $id = null): int
    {
        $name = trim((string)($d['name'] ?? ''));
        if ($name === '') throw new InvalidArgumentException('Ein Name ist nötig.');

        $kind = (string)($d['kind'] ?? 'doctor');
        if (!isset(self::KINDS[$kind])) $kind = 'doctor';

        $fields = [
            'kind'        => $kind,
            'is_active'   => isset($d['is_active']) ? (!empty($d['is_active']) ? 1 : 0) : 1,
            'name'        => mb_substr($name, 0, 200),
            'specialty'   => self::n($d['specialty'] ?? null),
            'institution' => self::n($d['institution'] ?? null),
            'phone'       => self::n($d['phone'] ?? null),
            'email'       => self::n($d['email'] ?? null),
            'address'     => self::n($d['address'] ?? null),
            'note'        => self::n($d['note'] ?? null),
        ];

        if ($id === null) return $this->create($fields);
        $this->update($id, $fields);
        return $id;
    }

    /** Alle Kontakte, aktive zuerst, danach alphabetisch. */
    public function listAll(bool $onlyActive = false): array
    {
        $sql = 'SELECT * FROM contacts WHERE user_id = :u';
        if ($onlyActive) $sql .= ' AND is_active = 1';

        $rows = $this->hydrateAll($this->db->all($sql, [':u' => $this->ownerId]));

        // Sortierung erst nach dem Entschlüsseln – der Name liegt
        // verschlüsselt in der Datenbank und ist dort nicht sortierbar.
        usort($rows, function ($a, $b) {
            if ($a['is_active'] !== $b['is_active']) return $b['is_active'] <=> $a['is_active'];
            return strcoll((string)$a['name'], (string)$b['name']);
        });
        return $rows;
    }

    /** Kennzahlen für die Detailansicht eines Kontakts. */
    public function appointmentStats(int $contactId): array
    {
        $row = $this->db->one(
            'SELECT COUNT(*) n,
                    MAX(CASE WHEN starts_at <= UTC_TIMESTAMP() THEN starts_at END) last,
                    MIN(CASE WHEN starts_at >  UTC_TIMESTAMP() AND status = "planned"
                             THEN starts_at END) next
             FROM appointments WHERE user_id = :u AND contact_id = :c',
            [':u' => $this->ownerId, ':c' => $contactId]
        );
        return ['n' => (int)($row['n'] ?? 0), 'last' => $row['last'] ?? null, 'next' => $row['next'] ?? null];
    }

    private static function n($v): ?string
    {
        $v = trim((string)$v);
        return $v === '' ? null : $v;
    }
}
