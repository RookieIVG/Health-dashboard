<?php
declare(strict_types=1);

namespace Health;

use InvalidArgumentException;

final class CostsRepository extends Repository
{
    protected function table(): string  { return 'costs'; }
    protected function module(): string { return Modules::COST; }
    protected function dateColumn(): string { return 'cost_date'; }

    protected function encryptedFields(): array
    {
        return ['provider' => true, 'description' => true, 'note' => true];
    }

    public const CATEGORIES = [
        'medication' => 'Medikamente',
        'doctor'     => 'Arzthonorar',
        'hospital'   => 'Krankenhaus',
        'therapy'    => 'Therapie',
        'aids'       => 'Hilfsmittel',
        'dental'     => 'Zahnbehandlung',
        'other'      => 'Sonstiges',
    ];

    public const REIMB_STATUS = [
        'none'      => 'nicht eingereicht',
        'submitted' => 'eingereicht',
        'partial'   => 'teilweise erstattet',
        'full'      => 'voll erstattet',
    ];

    public function store(array $d, ?int $id = null): int
    {
        $desc = trim((string)($d['description'] ?? ''));
        if ($desc === '') throw new InvalidArgumentException('Bitte eine Beschreibung angeben.');

        $date = trim((string)($d['cost_date'] ?? ''));
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            throw new InvalidArgumentException('Bitte ein Datum angeben.');
        }

        $amount = VitalsRepository::num($d['amount'] ?? null);
        if ($amount === null || $amount <= 0) {
            throw new InvalidArgumentException('Bitte einen Betrag größer als 0 angeben.');
        }

        $cat = isset(self::CATEGORIES[$d['category'] ?? '']) ? $d['category'] : 'other';
        $status = isset(self::REIMB_STATUS[$d['reimbursement_status'] ?? '']) ? $d['reimbursement_status'] : 'none';

        $reimbursed = VitalsRepository::num($d['reimbursed_amount'] ?? null);
        if (in_array($status, ['partial', 'full'], true) && $reimbursed === null) {
            $reimbursed = $status === 'full' ? $amount : 0.0;
        }
        if ($status === 'none') $reimbursed = null;

        $submitted = trim((string)($d['submitted_date'] ?? ''));
        $submitted = preg_match('/^\d{4}-\d{2}-\d{2}$/', $submitted) ? $submitted : null;

        $fields = [
            'category'    => $cat,
            'cost_date'   => $date,
            'amount'      => $amount,
            'reimbursement_status' => $status,
            'reimbursed_amount'    => $reimbursed,
            'submitted_date'       => $submitted,
            'provider'    => self::n($d['provider'] ?? null),
            'description' => mb_substr($desc, 0, 500),
            'note'        => self::n($d['note'] ?? null),
        ];

        if ($id === null) {
            $id = $this->create($fields);
        } else {
            $this->update($id, $fields);
        }

        $this->touchTimeline(
            refId:      $id,
            occurredAt: $date . ' 00:00:00',
            title:      self::CATEGORIES[$cat] . ': ' . number_format($amount, 2, ',', '.') . ' €',
            summary:    $fields['description'],
            severity:   0
        );

        return $id;
    }

    public function listAll(?string $year = null): array
    {
        $sql = 'SELECT * FROM costs WHERE user_id = :u';
        $par = [':u' => $this->ownerId];
        if ($year !== null) { $sql .= ' AND YEAR(cost_date) = :y'; $par[':y'] = $year; }
        $sql .= ' ORDER BY cost_date DESC';
        return $this->hydrateAll($this->db->all($sql, $par));
    }

    /** Jahre, für die Einträge existieren – für die Jahresauswahl. */
    public function years(): array
    {
        return array_column($this->db->all(
            'SELECT DISTINCT YEAR(cost_date) y FROM costs WHERE user_id = :u ORDER BY y DESC',
            [':u' => $this->ownerId]
        ), 'y');
    }

    /**
     * Summen für ein Jahr: Gesamtausgaben, erstattet, Eigenanteil.
     * Nützlich für die Steuererklärung (außergewöhnliche Belastungen).
     */
    public function yearSummary(string $year): array
    {
        $row = $this->db->one(
            'SELECT COUNT(*) n, COALESCE(SUM(amount),0) total,
                    COALESCE(SUM(reimbursed_amount),0) reimbursed
             FROM costs WHERE user_id = :u AND YEAR(cost_date) = :y',
            [':u' => $this->ownerId, ':y' => $year]
        );
        $total = (float)($row['total'] ?? 0);
        $reimb = (float)($row['reimbursed'] ?? 0);

        $byCat = [];
        foreach ($this->db->all(
            'SELECT category, SUM(amount) s FROM costs WHERE user_id = :u AND YEAR(cost_date) = :y GROUP BY category',
            [':u' => $this->ownerId, ':y' => $year]
        ) as $r) {
            $byCat[$r['category']] = (float)$r['s'];
        }

        return [
            'n' => (int)($row['n'] ?? 0), 'total' => $total, 'reimbursed' => $reimb,
            'out_of_pocket' => $total - $reimb, 'by_category' => $byCat,
        ];
    }

    /** Eingereicht, aber noch nicht (voll) erstattet – zum Nachverfolgen. */
    public function openSubmissions(): array
    {
        return $this->hydrateAll($this->db->all(
            "SELECT * FROM costs WHERE user_id = :u
             AND reimbursement_status IN ('submitted','partial')
             ORDER BY submitted_date ASC", [':u' => $this->ownerId]
        ));
    }

    private static function n($v): ?string
    {
        $v = trim((string)$v);
        return $v === '' ? null : $v;
    }
}
