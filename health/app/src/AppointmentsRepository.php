<?php
declare(strict_types=1);

namespace Health;

use InvalidArgumentException;

final class AppointmentsRepository extends Repository
{
    protected function table(): string  { return 'appointments'; }
    protected function module(): string { return Modules::APPOINTMENT; }
    protected function dateColumn(): string { return 'starts_at'; }

    protected function encryptedFields(): array
    {
        return ['title' => true, 'location' => true, 'purpose' => true,
                'prep' => true, 'result' => true];
    }

    public const STATUS = [
        'planned'   => 'geplant',
        'done'      => 'erledigt',
        'cancelled' => 'abgesagt',
    ];

    public const REMINDERS = [
        ''     => 'keine',
        '60'   => '1 Stunde vorher',
        '180'  => '3 Stunden vorher',
        '1440' => '1 Tag vorher',
        '2880' => '2 Tage vorher',
        '10080'=> '1 Woche vorher',
    ];

    // =================================================================

    public function store(array $d, ?int $id = null): int
    {
        $title = trim((string)($d['title'] ?? ''));
        if ($title === '') throw new InvalidArgumentException('Ein Titel ist nötig.');

        $start = trim((string)($d['starts_at'] ?? ''));
        if ($start === '') throw new InvalidArgumentException('Bitte Datum und Uhrzeit angeben.');
        $startUtc = $this->toUtc(str_replace('T', ' ', $start));

        $endUtc = null;
        $end = trim((string)($d['ends_at'] ?? ''));
        if ($end !== '') {
            $endUtc = $this->toUtc(str_replace('T', ' ', $end));
            if ($endUtc <= $startUtc) {
                throw new InvalidArgumentException('Das Ende liegt vor dem Beginn.');
            }
        }

        $status = (string)($d['status'] ?? 'planned');
        if (!isset(self::STATUS[$status])) $status = 'planned';

        $contactId = (int)($d['contact_id'] ?? 0) ?: null;
        if ($contactId !== null) {
            $ok = $this->db->value('SELECT COUNT(*) FROM contacts WHERE id = :c AND user_id = :u',
                                   [':c' => $contactId, ':u' => $this->ownerId]);
            if (!$ok) $contactId = null;
        }

        $reminder = (string)($d['reminder_min'] ?? '');
        $reminder = isset(self::REMINDERS[$reminder]) && $reminder !== '' ? (int)$reminder : null;

        $fields = [
            'contact_id'   => $contactId,
            'starts_at'    => $startUtc,
            'ends_at'      => $endUtc,
            'all_day'      => !empty($d['all_day']) ? 1 : 0,
            'status'       => $status,
            'reminder_min' => $reminder,
            'title'        => mb_substr($title, 0, 200),
            'location'     => self::n($d['location'] ?? null),
            'purpose'      => self::n($d['purpose'] ?? null),
            'prep'         => self::n($d['prep'] ?? null),
            'result'       => self::n($d['result'] ?? null),
        ];

        if ($id === null) {
            // Die UID bleibt über Änderungen hinweg gleich, sonst legt der
            // Kalender bei jeder Bearbeitung einen neuen Termin an.
            $fields['uid'] = Auth::uuid4();
            $id = $this->create($fields);
        } else {
            // Nach jeder Bearbeitung darf die E-Mail-Erinnerung erneut
            // auslösen – sonst bliebe sie bei einer Verschiebung stumm,
            // weil reminder_sent_at noch vom alten Termin gesetzt ist.
            $fields['reminder_sent_at'] = null;
            $this->update($id, $fields);
        }

        $this->touchTimeline(
            refId:       $id,
            occurredAt:  $startUtc,
            title:       'Termin: ' . $fields['title'],
            summary:     $this->summaryLine($fields, $contactId),
            severity:    0,
            occurredEnd: $endUtc
        );

        return $id;
    }

    private function summaryLine(array $f, ?int $contactId): ?string
    {
        $parts = [];
        if ($contactId) {
            $c = (new ContactsRepository($this->app, $this->ownerId))->find($contactId);
            if ($c) $parts[] = (string)$c['name'];
        }
        if ($f['location']) $parts[] = $f['location'];
        if ($f['purpose'])  $parts[] = mb_substr((string)$f['purpose'], 0, 120);
        return $parts ? implode(' · ', $parts) : null;
    }

    public function setStatus(int $id, string $status): void
    {
        if (!isset(self::STATUS[$status])) return;
        $this->update($id, ['status' => $status]);
    }

    // =================================================================
    // Abfragen
    // =================================================================

    public function upcoming(int $limit = 50): array
    {
        return $this->withContacts($this->hydrateAll($this->db->all(
            'SELECT * FROM appointments
             WHERE user_id = :u AND status = "planned" AND starts_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL 12 HOUR)
             ORDER BY starts_at ASC LIMIT ' . max(1, min($limit, 200)),
            [':u' => $this->ownerId]
        )));
    }

    public function past(int $limit = 50): array
    {
        return $this->withContacts($this->hydrateAll($this->db->all(
            'SELECT * FROM appointments
             WHERE user_id = :u AND (starts_at < UTC_TIMESTAMP() OR status <> "planned")
             ORDER BY starts_at DESC LIMIT ' . max(1, min($limit, 200)),
            [':u' => $this->ownerId]
        )));
    }

    public function detail(int $id): ?array
    {
        $row = $this->find($id);
        if (!$row) return null;
        $rows = $this->withContacts([$row]);
        $rows[0]['attachments'] = $this->app->attachments()->forObject($this->module(), $id, $this->ownerId);
        return $rows[0];
    }

    /** Termine für den Kalender-Export. */
    public function forExport(int $pastDays = 365, int $futureDays = 730): array
    {
        return $this->withContacts($this->hydrateAll($this->db->all(
            'SELECT * FROM appointments
             WHERE user_id = :u AND status <> "cancelled"
               AND starts_at BETWEEN DATE_SUB(UTC_TIMESTAMP(), INTERVAL :p DAY)
                                 AND DATE_ADD(UTC_TIMESTAMP(), INTERVAL :f DAY)
             ORDER BY starts_at ASC LIMIT 1000',
            [':u' => $this->ownerId, ':p' => $pastDays, ':f' => $futureDays]
        )));
    }

    /** Namen der Kontakte in einem Rutsch nachladen. */
    private function withContacts(array $rows): array
    {
        $ids = array_values(array_filter(array_map(fn($r) => (int)($r['contact_id'] ?? 0), $rows)));
        $names = [];

        if ($ids) {
            $repo = new ContactsRepository($this->app, $this->ownerId);
            foreach (array_unique($ids) as $cid) {
                $c = $repo->find($cid);
                if ($c) $names[$cid] = ['name' => $c['name'], 'specialty' => $c['specialty']];
            }
        }

        foreach ($rows as &$r) {
            $cid = (int)($r['contact_id'] ?? 0);
            $r['contact'] = $names[$cid] ?? null;
        }
        return $rows;
    }

    private static function n($v): ?string
    {
        $v = trim((string)$v);
        return $v === '' ? null : $v;
    }
}
