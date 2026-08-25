<?php
declare(strict_types=1);

namespace Health;

use InvalidArgumentException;

/**
 * Ärztinnen, Ärzte, Einrichtungen – alles Kontakte.
 *
 * Eine eigene "Einrichtungen"-Tabelle gab es kurz, war aber dieselbe
 * Sache in zwei Verkleidungen: eine Klinik ist einfach ein Kontakt mit
 * kind='clinic'. Ein Arzt kann per parent_contact_id auf die Klinik
 * verweisen, in der er arbeitet – ein Selbstverweis auf dieselbe
 * Tabelle statt zweier getrennter Listen, die man sonst beide aktuell
 * halten müsste.
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
        return ['name' => true, 'specialty' => true,
                'phone' => true, 'email' => true, 'address' => true, 'note' => true];
    }

    public const KINDS = [
        'doctor'    => 'Arztpraxis',
        'clinic'    => 'Krankenhaus / Ambulanz',
        'radiology' => 'Radiologieinstitut',
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

        $parentId = !empty($d['parent_contact_new'])
            ? $this->findOrCreateClinic((string)$d['parent_contact_new'])
            : ((!empty($d['parent_contact_id'])) ? (int)$d['parent_contact_id'] : null);

        // Ein Kontakt darf nicht sein eigener übergeordneter Kontakt sein
        // – könnte sonst passieren, wenn beim Bearbeiten zufällig derselbe
        // Name im "neu anlegen"-Feld eingetragen wird.
        if ($id !== null && $parentId === $id) $parentId = null;

        $fields = [
            'kind'        => $kind,
            'is_active'   => isset($d['is_active']) ? (!empty($d['is_active']) ? 1 : 0) : 1,
            'name'        => mb_substr($name, 0, 200),
            'specialty'   => self::n($d['specialty'] ?? null),
            'phone'       => self::n($d['phone'] ?? null),
            'email'       => self::n($d['email'] ?? null),
            'address'     => self::n($d['address'] ?? null),
            'note'        => self::n($d['note'] ?? null),
        ];
        $fields['parent_contact_id'] = $parentId;

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
        self::resolveInto($rows, $this->app, $this->ownerId, 'parent_contact_id');

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

    // =================================================================
    // Kontakt aus Freitext finden oder anlegen
    // =================================================================

    /**
     * Für die "oder neue Einrichtung/Kontakt"-Felder in Befund, Labor
     * und Kontakte selbst: findet einen bestehenden Kontakt über den
     * Namen (ohne Rücksicht auf Groß-/Kleinschreibung, über alle
     * Kontaktarten hinweg), legt sonst einen neuen als Klinik/Institut
     * an. Wer schon als Arzt erfasst ist, wird also wiederverwendet
     * statt doppelt angelegt, nur weil der Name auch als "Einrichtung"
     * eingetragen wurde.
     */
    public function findOrCreateClinic(string $name): ?int
    {
        $name = trim($name);
        if ($name === '') return null;

        $needle = mb_strtolower($name);
        foreach ($this->listAll() as $c) {
            if (mb_strtolower((string)$c['name']) === $needle) return (int)$c['id'];
        }

        return $this->create([
            'kind' => 'clinic', 'is_active' => 1,
            'name' => mb_substr($name, 0, 200),
        ]);
    }

    /**
     * Überschreibt in bereits entschlüsselten Datensätzen ein Feld mit
     * dem AKTUELLEN Namen des verknüpften Kontakts, live aus der
     * Kontakteliste gelesen statt aus einer beim Speichern eingefrorenen
     * Kopie. Genau darin liegt der Sinn der zentralen Pflege: benennt
     * man einen Kontakt um, zeigt sich das sofort überall, wo er
     * zugeordnet ist – bei Befunden, Laborbefunden und bei anderen
     * Kontakten, die auf ihn verweisen.
     *
     * @param string $idField     Spalte mit der Kontakt-ID im Datensatz
     *                            (z.B. 'contact_id' oder 'parent_contact_id')
     * @param string $targetField Feld, das mit dem aktuellen Namen
     *                            überschrieben wird (Standard: 'institution')
     */
    public static function resolveInto(
        array &$rows, App $app, int $ownerId,
        string $idField = 'contact_id', string $targetField = 'institution'
    ): void {
        // Ohne eigene institution_enc-Spalte legt hydrate() dieses Feld
        // nicht mehr automatisch an (auch nicht als null) – das hier
        // übernimmt genau diese Rolle jetzt, für JEDE Zeile, bevor
        // überhaupt geprüft wird, ob es etwas aufzulösen gibt. Sonst
        // würde bei Zeilen ganz ohne Verknüpfung ein "undefined array
        // key" entstehen, wo früher zuverlässig null stand.
        foreach ($rows as &$row) {
            $row[$targetField] ??= null;
        }
        unset($row);

        $needed = array_values(array_unique(array_filter(array_map(
            fn($r) => $r[$idField] ?? null, $rows
        ))));
        if (!$needed) return;

        // Bewusst eine eigene, schlanke Abfrage statt listAll(): würde
        // listAll() hier verwendet, riefe das für 'parent_contact_id'
        // wiederum resolveInto() auf – eine Endlosschleife. Für die
        // Namens-Zuordnung selbst genügt das rohe 'name'-Feld ohnehin,
        // das ist nie von einer Selbstreferenz betroffen.
        $dek = $app->dekFor($ownerId);
        $in  = implode(',', array_fill(0, count($needed), '?'));
        $nameRows = $app->db->all(
            "SELECT id, name_enc FROM contacts WHERE user_id = ? AND id IN ({$in})",
            array_merge([$ownerId], $needed)
        );

        $map = [];
        foreach ($nameRows as $nr) {
            $map[(int)$nr['id']] = $app->crypto->dec($dek, $nr['name_enc'], 'contacts.name');
        }

        foreach ($rows as &$row) {
            $cid = $row[$idField] ?? null;
            if ($cid !== null && isset($map[(int)$cid])) {
                $row[$targetField] = $map[(int)$cid];
            }
        }
    }

    private static function n($v): ?string
    {
        $v = trim((string)$v);
        return $v === '' ? null : $v;
    }
}
