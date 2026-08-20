<?php
declare(strict_types=1);

namespace Health;

use InvalidArgumentException;

/**
 * Befunde und Dokumentenarchiv.
 *
 * Bewusst ein Modul statt zweier: ein Arztbrief ist beides zugleich –
 * ein Befund mit Inhalt und eine Datei, die man aufhebt. Zwei getrennte
 * Module hätten bedeutet, denselben Vorgang doppelt zu erfassen und
 * die Verbindung zwischen Text und Scan von Hand zu pflegen.
 */
final class FindingsRepository extends Repository
{
    protected function table(): string  { return 'findings'; }
    protected function module(): string { return Modules::FINDING; }
    protected function dateColumn(): string { return 'occurred_at'; }

    protected function encryptedFields(): array
    {
        return ['title' => true, 'institution' => true, 'doctor' => true,
                'summary' => true, 'text' => true];
    }

    public const CATEGORIES = [
        'arztbrief'   => 'Arztbrief',
        'ambulanz'    => 'Ambulanzbefund',
        'entlassung'  => 'Entlassungsbrief',
        'labor'       => 'Laborbefund',
        'bildgebung'  => 'Bildgebung',
        'op'          => 'OP-Bericht',
        'pathologie'  => 'Histologie / Pathologie',
        'funktions'   => 'Funktionsdiagnostik',
        'therapie'    => 'Therapie / Reha',
        'attest'      => 'Attest / Bescheinigung',
        'rezept'      => 'Rezept / Verordnung',
        'other'       => 'Sonstiges',
    ];

    public static function categoryLabel(string $key): string
    {
        return self::CATEGORIES[$key] ?? self::CATEGORIES['other'];
    }

    // =================================================================
    // Schreiben
    // =================================================================

    public function store(array $d, ?int $id = null): int
    {
        $title = trim((string)($d['title'] ?? ''));
        if ($title === '') {
            throw new InvalidArgumentException('Ein Titel ist nötig, sonst findest du den Befund später nicht wieder.');
        }

        $category = (string)($d['category'] ?? 'other');
        if (!isset(self::CATEGORIES[$category])) $category = 'other';

        $when = trim((string)($d['occurred_at'] ?? ''));
        if ($when === '') {
            throw new InvalidArgumentException('Bitte das Datum des Befunds angeben.');
        }
        $utc = $this->toUtc(str_replace('T', ' ', $when));

        $fields = [
            'occurred_at'  => $utc,
            'received_at'  => self::dateOrNull($d['received_at'] ?? null),
            'category'     => $category,
            'follow_up_at' => self::dateOrNull($d['follow_up_at'] ?? null),
            'is_important' => !empty($d['is_important']) ? 1 : 0,
            'is_archived'  => !empty($d['is_archived']) ? 1 : 0,
            'title'        => mb_substr($title, 0, 200),
            'institution'  => self::strOrNull($d['institution'] ?? null),
            'doctor'       => self::strOrNull($d['doctor'] ?? null),
            'summary'      => self::strOrNull($d['summary'] ?? null),
            'text'         => self::strOrNull($d['text'] ?? null),
        ];

        if ($id === null) {
            $id = $this->create($fields);
        } else {
            $this->update($id, $fields);
        }

        $this->touchTimeline(
            refId:      $id,
            occurredAt: $utc,
            title:      self::categoryLabel($category) . ': ' . $fields['title'],
            summary:    $this->buildSummary($fields),
            severity:   $fields['is_important'] ? 1 : 0
        );

        if (isset($d['tags'])) {
            $this->app->tags()->sync($this->module(), $id, self::parseTags((string)$d['tags']), $this->ownerId);
        }

        return $id;
    }

    /** Wiedervorlage abhaken, ohne den Befund sonst anzufassen. */
    public function clearFollowUp(int $id): void
    {
        $this->update($id, ['follow_up_at' => null]);
    }

    // =================================================================
    // Lesen
    // =================================================================

    /**
     * Gefilterte Liste.
     *
     * @param array $f  category, from, to, tag_id, archived, important, q
     */
    public function search(array $f = [], int $limit = 200): array
    {
        $sql = 'SELECT f.* FROM findings f';
        $par = [':u' => $this->ownerId];

        if (!empty($f['tag_id'])) {
            $sql .= ' JOIN taggables tg ON tg.module = :mod AND tg.ref_id = f.id AND tg.tag_id = :tag';
            $par[':mod'] = $this->module();
            $par[':tag'] = (int)$f['tag_id'];
        }

        $sql .= ' WHERE f.user_id = :u';

        if (!empty($f['category']) && isset(self::CATEGORIES[$f['category']])) {
            $sql .= ' AND f.category = :cat'; $par[':cat'] = $f['category'];
        }
        if (!empty($f['from'])) { $sql .= ' AND f.occurred_at >= :from'; $par[':from'] = $f['from']; }
        if (!empty($f['to']))   { $sql .= ' AND f.occurred_at <= :to';   $par[':to']   = $f['to']; }
        if (!empty($f['important'])) { $sql .= ' AND f.is_important = 1'; }

        $sql .= empty($f['archived']) ? ' AND f.is_archived = 0' : ' AND f.is_archived = 1';
        $sql .= ' ORDER BY f.occurred_at DESC, f.id DESC LIMIT ' . max(1, min($limit, 1000));

        $rows = $this->hydrateAll($this->db->all($sql, $par));

        // Volltextsuche muss nach dem Entschlüsseln stattfinden: über eine
        // verschlüsselte Spalte kann MySQL kein LIKE ausführen. Bei privaten
        // Datenmengen ist das unkritisch, skaliert aber nicht beliebig –
        // deshalb greift die Suche erst nach den obigen Filtern.
        $q = trim((string)($f['q'] ?? ''));
        if ($q !== '') {
            $needle = mb_strtolower($q);
            $rows = array_values(array_filter($rows, function (array $r) use ($needle): bool {
                foreach (['title', 'institution', 'doctor', 'summary', 'text'] as $k) {
                    if ($r[$k] !== null && str_contains(mb_strtolower((string)$r[$k]), $needle)) {
                        return true;
                    }
                }
                return false;
            }));
        }

        return $rows;
    }

    /** Befund samt Anhängen und Tags. */
    public function detail(int $id): ?array
    {
        $row = $this->find($id);
        if (!$row) return null;

        $row['attachments'] = $this->app->attachments()->forObject($this->module(), $id, $this->ownerId);
        $row['tags']        = $this->app->tags()->forObject($this->module(), $id, $this->ownerId);
        return $row;
    }

    /** Anzahl Anhänge je Befund – für die Liste, ohne N+1-Abfragen. */
    public function attachmentCounts(array $ids): array
    {
        if (!$ids) return [];
        $in  = implode(',', array_fill(0, count($ids), '?'));
        $par = array_merge([$this->ownerId, $this->module()], array_map('intval', $ids));

        $out = [];
        foreach ($this->db->all(
            "SELECT ref_id, COUNT(*) n FROM attachments
             WHERE user_id = ? AND module = ? AND ref_id IN ({$in}) GROUP BY ref_id", $par
        ) as $r) {
            $out[(int)$r['ref_id']] = (int)$r['n'];
        }
        return $out;
    }

    /** Offene Wiedervorlagen, fällige zuerst. */
    public function dueFollowUps(int $withinDays = 60): array
    {
        return $this->hydrateAll($this->db->all(
            'SELECT * FROM findings
             WHERE user_id = :u AND follow_up_at IS NOT NULL
               AND follow_up_at <= DATE_ADD(CURDATE(), INTERVAL :d DAY)
             ORDER BY follow_up_at ASC LIMIT 50',
            [':u' => $this->ownerId, ':d' => $withinDays]
        ));
    }

    /** Anzahl je Kategorie – für die Filterleiste. */
    public function countsByCategory(bool $archived = false): array
    {
        $out = [];
        foreach ($this->db->all(
            'SELECT category, COUNT(*) n FROM findings
             WHERE user_id = :u AND is_archived = :a GROUP BY category',
            [':u' => $this->ownerId, ':a' => $archived ? 1 : 0]
        ) as $r) {
            $out[$r['category']] = (int)$r['n'];
        }
        return $out;
    }

    // =================================================================

    private function buildSummary(array $f): ?string
    {
        $parts = array_filter([$f['institution'], $f['doctor'], $f['summary']]);
        $s = implode(' · ', $parts);
        return $s === '' ? null : mb_substr($s, 0, 400);
    }

    public static function parseTags(string $raw): array
    {
        $parts = preg_split('/[,;]+/u', $raw) ?: [];
        return array_values(array_filter(array_map('trim', $parts), fn($t) => $t !== ''));
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
