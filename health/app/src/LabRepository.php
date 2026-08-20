<?php
declare(strict_types=1);

namespace Health;

use InvalidArgumentException;
use RuntimeException;

/**
 * Labor-Kumulativbefund.
 *
 * Ein Befundtermin (lab_visits) enthält mehrere Testwerte (lab_results) –
 * das entspricht der Blutabnahme, nicht dem Einzelwert. Erst dadurch
 * ergibt sich die klassische Kumulativtabelle: Tests als Zeilen,
 * Termine als Spalten.
 */
final class LabRepository extends Repository
{
    protected function table(): string  { return 'lab_visits'; }
    protected function module(): string { return Modules::LAB; }
    protected function dateColumn(): string { return 'visit_date'; }
    protected function encryptedFields(): array { return ['institution' => true, 'note' => true]; }

    // =================================================================
    // Tests
    // =================================================================

    public function tests(bool $onlyActive = true): array
    {
        $sql = 'SELECT * FROM lab_tests WHERE (user_id IS NULL OR user_id = :u)';
        if ($onlyActive) $sql .= ' AND is_active = 1';
        return $this->db->all($sql . ' ORDER BY category, sort_order, label', [':u' => $this->ownerId]);
    }

    public function test(int $testId): ?array
    {
        return $this->db->one(
            'SELECT * FROM lab_tests WHERE id = :id AND (user_id IS NULL OR user_id = :u)',
            [':id' => $testId, ':u' => $this->ownerId]
        );
    }

    public function createTest(array $d): int
    {
        $label = trim((string)($d['label'] ?? ''));
        if ($label === '') throw new InvalidArgumentException('Bezeichnung fehlt.');

        $key = preg_replace('/[^a-z0-9_]/', '', strtolower(str_replace(' ', '_', $label))) ?: '';
        if ($key === '') $key = 'test_' . bin2hex(random_bytes(3));

        return $this->db->insert(
            'INSERT INTO lab_tests (user_id, tkey, label, unit, decimals, ref_low, ref_high, category, sort_order)
             VALUES (:u,:k,:l,:un,:dec,:rl,:rh,:cat,500)',
            [
                ':u' => $this->ownerId, ':k' => $key, ':l' => mb_substr($label, 0, 96),
                ':un' => mb_substr(trim((string)($d['unit'] ?? '')), 0, 24),
                ':dec' => max(0, min(4, (int)($d['decimals'] ?? 1))),
                ':rl' => VitalsRepository::num($d['ref_low'] ?? null),
                ':rh' => VitalsRepository::num($d['ref_high'] ?? null),
                ':cat' => mb_substr(trim((string)($d['category'] ?? 'Eigene')), 0, 64) ?: 'Eigene',
            ]
        );
    }

    /**
     * Test bearbeiten. Nur eigene – mitgelieferte bleiben inhaltlich
     * fest, ihre Sichtbarkeit lässt sich über toggleTestActive() ändern.
     */
    public function updateTest(int $testId, array $d): void
    {
        $t = $this->test($testId);
        if (!$t) throw new InvalidArgumentException('Test nicht gefunden.');
        if ($t['user_id'] === null) {
            throw new RuntimeException('Mitgelieferte Tests lassen sich nicht ändern.');
        }

        $label = trim((string)($d['label'] ?? ''));
        if ($label === '') throw new InvalidArgumentException('Bezeichnung fehlt.');

        $this->db->run(
            'UPDATE lab_tests SET label=:l, unit=:un, decimals=:dec, ref_low=:rl, ref_high=:rh, category=:cat
             WHERE id = :id AND user_id = :u',
            [
                ':l' => mb_substr($label, 0, 96),
                ':un' => mb_substr(trim((string)($d['unit'] ?? '')), 0, 24),
                ':dec' => max(0, min(4, (int)($d['decimals'] ?? 1))),
                ':rl' => VitalsRepository::num($d['ref_low'] ?? null),
                ':rh' => VitalsRepository::num($d['ref_high'] ?? null),
                ':cat' => mb_substr(trim((string)($d['category'] ?? 'Eigene')), 0, 64) ?: 'Eigene',
                ':id' => $testId, ':u' => $this->ownerId,
            ]
        );
    }

    /** Blendet einen Test aus – auch mitgelieferte, das ändert keine Inhalte. */
    public function toggleTestActive(int $testId): void
    {
        $t = $this->test($testId);
        if (!$t) throw new RuntimeException('Test nicht gefunden.');
        $this->db->run(
            'UPDATE lab_tests SET is_active = 1 - is_active WHERE id = :id AND (user_id IS NULL OR user_id = :u)',
            [':id' => $testId, ':u' => $this->ownerId]
        );
    }

    public function testUsageCount(int $testId): int
    {
        return (int)$this->db->value(
            'SELECT COUNT(*) FROM lab_results WHERE test_id = :t AND user_id = :u',
            [':t' => $testId, ':u' => $this->ownerId]
        );
    }

    public function deleteTest(int $testId): void
    {
        $t = $this->test($testId);
        if (!$t) return;
        if ($t['user_id'] === null) {
            throw new RuntimeException('Mitgelieferte Tests lassen sich nicht löschen, nur ausblenden.');
        }
        if ($this->testUsageCount($testId) > 0) {
            throw new RuntimeException('Für diesen Test liegen bereits Werte vor. Bitte stattdessen ausblenden.');
        }
        $this->db->run('DELETE FROM lab_tests WHERE id = :id AND user_id = :u',
                       [':id' => $testId, ':u' => $this->ownerId]);
    }

    // =================================================================
    // Befundtermine
    // =================================================================

    /**
     * @param array $values  test_id => Rohwert (numerisch oder Text)
     */
    public function saveVisit(string $visitDate, array $values, ?string $institution = null,
                              ?string $note = null, ?int $visitId = null): int
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $visitDate)) {
            throw new InvalidArgumentException('Bitte ein Datum angeben.');
        }

        $fields = ['visit_date' => $visitDate, 'institution' => self::n($institution), 'note' => self::n($note)];

        return $this->db->transaction(function () use ($fields, $values, $visitId): int {
            if ($visitId === null) {
                $id = $this->create($fields);
            } else {
                $id = $visitId;
                $this->update($id, $fields);
                $this->db->run('DELETE FROM lab_results WHERE visit_id = :v', [':v' => $id]);
            }

            $abnormal = [];
            foreach ($values as $testId => $raw) {
                $raw = is_string($raw) ? trim($raw) : $raw;
                if ($raw === '' || $raw === null) continue;

                $test = $this->test((int)$testId);
                if (!$test) continue;

                $num = VitalsRepository::num($raw);
                $flag = null;
                if ($num !== null) {
                    $lo = $test['ref_low'] === null ? null : (float)$test['ref_low'];
                    $hi = $test['ref_high'] === null ? null : (float)$test['ref_high'];
                    if ($lo !== null && $num < $lo) $flag = 'L';
                    if ($hi !== null && $num > $hi) $flag = 'H';
                    if ($flag) $abnormal[] = $test['label'];
                }

                $st = $this->db->pdo()->prepare(
                    'INSERT INTO lab_results (visit_id, test_id, user_id, value_num, value_text_enc, flag)
                     VALUES (:v, :t, :u, :n, :txt, :f)'
                );
                $st->bindValue(':v', $id, \PDO::PARAM_INT);
                $st->bindValue(':t', (int)$testId, \PDO::PARAM_INT);
                $st->bindValue(':u', $this->ownerId, \PDO::PARAM_INT);
                $st->bindValue(':n', $num, $num === null ? \PDO::PARAM_NULL : \PDO::PARAM_STR);
                $textEnc = $num === null
                    ? $this->crypto->enc($this->dek(), (string)$raw, 'lab_results.value_text') : null;
                $st->bindValue(':txt', $textEnc, $textEnc === null ? \PDO::PARAM_NULL : \PDO::PARAM_LOB);
                $st->bindValue(':f', $flag, $flag === null ? \PDO::PARAM_NULL : \PDO::PARAM_STR);
                $st->execute();
            }

            $this->touchTimeline(
                refId:      $id,
                occurredAt: $fields['visit_date'] . ' 00:00:00',
                title:      'Laborbefund' . ($fields['institution'] ? ' – ' . $fields['institution'] : ''),
                summary:    $abnormal ? 'auffällig: ' . implode(', ', array_slice($abnormal, 0, 5)) : null,
                severity:   $abnormal ? 1 : 0
            );

            return $id;
        });
    }

    public function visits(int $limit = 100): array
    {
        return $this->hydrateAll($this->db->all(
            'SELECT * FROM lab_visits WHERE user_id = :u ORDER BY visit_date DESC LIMIT '
            . max(1, min($limit, 500)),
            [':u' => $this->ownerId]
        ));
    }

    public function visit(int $visitId): ?array
    {
        $v = $this->find($visitId);
        if (!$v) return null;
        $v['results'] = $this->resultsForVisit($visitId);
        return $v;
    }

    private function resultsForVisit(int $visitId): array
    {
        $rows = $this->db->all(
            'SELECT r.*, t.label, t.unit, t.decimals, t.ref_low, t.ref_high, t.category
             FROM lab_results r JOIN lab_tests t ON t.id = r.test_id
             WHERE r.visit_id = :v ORDER BY t.category, t.sort_order',
            [':v' => $visitId]
        );
        foreach ($rows as &$r) {
            $r['value_text'] = $r['value_text_enc'] !== null
                ? $this->crypto->dec($this->dek(), $r['value_text_enc'], 'lab_results.value_text') : null;
            unset($r['value_text_enc']);
        }
        return $rows;
    }

    // =================================================================
    // Kumulativtabelle und Kurve
    // =================================================================

    /**
     * Tests als Zeilen, Termine als Spalten – der klassische
     * Kumulativbefund. $limitVisits begrenzt die Spaltenzahl, sonst
     * würde die Tabelle nach Jahren unlesbar breit.
     */
    public function cumulative(int $limitVisits = 8): array
    {
        $visits = $this->db->all(
            'SELECT id, visit_date FROM lab_visits WHERE user_id = :u
             ORDER BY visit_date DESC LIMIT ' . max(1, min($limitVisits, 30)),
            [':u' => $this->ownerId]
        );
        if (!$visits) return ['visits' => [], 'rows' => []];

        $visitIds = array_column($visits, 'id');
        $in = implode(',', array_fill(0, count($visitIds), '?'));

        $results = $this->db->all(
            "SELECT r.visit_id, r.test_id, r.value_num, r.flag, t.label, t.unit, t.decimals, t.category, t.sort_order
             FROM lab_results r JOIN lab_tests t ON t.id = r.test_id
             WHERE r.visit_id IN ({$in})", $visitIds
        );

        $byTest = [];
        foreach ($results as $r) {
            $tid = (int)$r['test_id'];
            $byTest[$tid]['meta'] ??= $r;
            $byTest[$tid]['values'][(int)$r['visit_id']] = ['num' => $r['value_num'], 'flag' => $r['flag']];
        }
        uasort($byTest, fn($a, $b) => [$a['meta']['category'], $a['meta']['sort_order']]
                                    <=> [$b['meta']['category'], $b['meta']['sort_order']]);

        return ['visits' => array_reverse($visits), 'rows' => $byTest];
    }

    /** Messreihe eines Tests, älteste zuerst – für die Kurve. */
    public function seriesForTest(int $testId): array
    {
        $rows = $this->db->all(
            'SELECT v.visit_date, r.value_num FROM lab_results r
             JOIN lab_visits v ON v.id = r.visit_id
             WHERE r.user_id = :u AND r.test_id = :t AND r.value_num IS NOT NULL
             ORDER BY v.visit_date ASC', [':u' => $this->ownerId, ':t' => $testId]
        );
        return array_map(fn($r) => ['t' => strtotime($r['visit_date']), 'v' => (float)$r['value_num']], $rows);
    }

    private static function n($v): ?string
    {
        $v = trim((string)$v);
        return $v === '' ? null : $v;
    }
}
