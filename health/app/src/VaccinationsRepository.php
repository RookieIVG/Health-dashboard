<?php
declare(strict_types=1);

namespace Health;

use InvalidArgumentException;

final class VaccinationsRepository extends Repository
{
    protected function table(): string  { return 'vaccinations'; }
    protected function module(): string { return Modules::VACCINATION; }
    protected function dateColumn(): string { return 'given_date'; }

    protected function encryptedFields(): array
    {
        return ['vaccine' => true, 'disease' => true, 'lot' => true,
                'location' => true, 'doctor' => true, 'note' => true];
    }

    public function store(array $d, ?int $id = null): int
    {
        $vaccine = trim((string)($d['vaccine'] ?? ''));
        if ($vaccine === '') throw new InvalidArgumentException('Bitte den Impfstoff angeben.');

        $given = trim((string)($d['given_date'] ?? ''));
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $given)) {
            throw new InvalidArgumentException('Bitte das Impfdatum angeben.');
        }

        $due = trim((string)($d['next_due_date'] ?? ''));
        $due = preg_match('/^\d{4}-\d{2}-\d{2}$/', $due) ? $due : null;

        // Bleibt das Feld leer, wird der Impfplan Österreich befragt –
        // aber nur, wenn dafür ein Regelintervall bekannt ist. Ist der
        // Impfstoff unbekannt oder sieht der Plan keine routinemäßige
        // Auffrischung vor (z.B. MMR, HPV), bleibt next_due_date null.
        if ($due === null) {
            $suggestion = VaccinationPlan::suggestNextDue($vaccine, $given, $this->ownerBirthdate());
            if ($suggestion !== null) {
                $due = $suggestion['date'];
            }
        }

        if ($due !== null && $due < $given) {
            throw new InvalidArgumentException('Die Auffrischung liegt vor der Impfung.');
        }

        $fields = [
            'given_date'    => $given,
            'next_due_date' => $due,
            'dose_number'   => (int)($d['dose_number'] ?? 0) ?: null,
            'vaccine'       => mb_substr($vaccine, 0, 200),
            'disease'       => self::n($d['disease'] ?? null),
            'lot'           => self::n($d['lot'] ?? null),
            'location'      => self::n($d['location'] ?? null),
            'doctor'        => self::n($d['doctor'] ?? null),
            'note'          => self::n($d['note'] ?? null),
        ];

        if ($id === null) {
            $id = $this->create($fields);
        } else {
            $this->update($id, $fields);
        }

        $this->touchTimeline(
            refId:      $id,
            occurredAt: $given . ' 00:00:00',
            title:      'Impfung: ' . $fields['vaccine'],
            summary:    $fields['dose_number'] ? (int)$fields['dose_number'] . '. Dosis' : null,
            severity:   0
        );

        return $id;
    }

    public function listAll(): array
    {
        return $this->hydrateAll($this->db->all(
            'SELECT * FROM vaccinations WHERE user_id = :u ORDER BY given_date DESC',
            [':u' => $this->ownerId]
        ));
    }

    /**
     * Fällige und bald fällige Auffrischungen.
     *
     * Betrachtet wird nur der jeweils letzte Eintrag je Impfstoff
     * (siehe byVaccine()) – sonst bliebe eine ältere Impfung als
     * "überfällig" stehen, obwohl längst eine neuere desselben
     * Impfstoffs eingetragen wurde. Der Auffrischungstermin einer
     * überholten alten Zeile ist ohne Aussagekraft, sobald es eine
     * jüngere Impfung desselben Impfstoffs gibt.
     */
    public function dueSoon(int $withinDays = 90): array
    {
        $threshold = date('Y-m-d', strtotime("+{$withinDays} days"));

        $due = array_filter(
            $this->byVaccine(),
            fn(array $v): bool => $v['next_due_date'] !== null && $v['next_due_date'] <= $threshold
        );
        usort($due, fn($a, $b) => $a['next_due_date'] <=> $b['next_due_date']);

        return array_values($due);
    }

    /** Geburtsdatum des Datenbesitzers – Klartextspalte, keine Entschlüsselung nötig. */
    private function ownerBirthdate(): ?string
    {
        return $this->db->value('SELECT birthdate FROM users WHERE id = :id', [':id' => $this->ownerId]);
    }

    /** Letzte Impfung je Impfstoffname (Klartext-Vergleich nach dem Entschlüsseln). */
    public function byVaccine(): array
    {
        $out = [];
        foreach ($this->listAll() as $v) {
            $key = mb_strtolower((string)$v['vaccine']);
            if (!isset($out[$key]) || $v['given_date'] > $out[$key]['given_date']) {
                $out[$key] = $v;
            }
        }
        return array_values($out);
    }

    private static function n($v): ?string
    {
        $v = trim((string)$v);
        return $v === '' ? null : $v;
    }
}
