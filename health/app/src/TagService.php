<?php
declare(strict_types=1);

namespace Health;

/**
 * Modulübergreifende Tags.
 *
 * Tag-Namen sind verschlüsselt – "Laktoseintoleranz" oder "Reha Nord" sind
 * für sich schon Gesundheitsinformation. Gesucht wird deshalb über den
 * Blind Index: HMAC über den normalisierten Namen. Das erlaubt exakte
 * Treffer, aber weder Teilstringsuche noch alphabetische Sortierung in
 * der Datenbank – Sortieren passiert nach dem Entschlüsseln in PHP.
 * Bei der zu erwartenden Zahl von Tags ist das unproblematisch.
 */
final class TagService
{
    private const MAX_NAME_LENGTH = 60;

    public function __construct(private App $app) {}

    // =================================================================
    // Tags verwalten
    // =================================================================

    /** Liefert die Tag-ID, legt den Tag bei Bedarf an. */
    public function ensure(string $name, ?string $color = null, ?int $ownerId = null): int
    {
        $ownerId ??= $this->app->auth->userId();
        $name = $this->normalize($name);
        if ($name === '') {
            throw new \InvalidArgumentException('Tag-Name ist leer.');
        }

        $bidx = $this->app->crypto->blindIndex('tag.name', $name);

        $existing = $this->app->db->one(
            'SELECT id FROM tags WHERE user_id = :u AND name_bidx = :b',
            [':u' => $ownerId, ':b' => $bidx]
        );
        if ($existing) {
            return (int)$existing['id'];
        }

        $dek = $this->app->dekFor($ownerId);
        $st  = $this->app->db->pdo()->prepare(
            'INSERT INTO tags (user_id, name_enc, name_bidx, color) VALUES (:u, :n, :b, :c)'
        );
        $st->bindValue(':u', $ownerId, \PDO::PARAM_INT);
        $st->bindValue(':n', $this->app->crypto->enc($dek, $name, 'tag.name'), \PDO::PARAM_LOB);
        $st->bindValue(':b', $bidx, \PDO::PARAM_LOB);
        $st->bindValue(':c', $color, $color === null ? \PDO::PARAM_NULL : \PDO::PARAM_STR);
        $st->execute();

        return (int)$this->app->db->pdo()->lastInsertId();
    }

    /** Alle Tags des Benutzers, alphabetisch sortiert. */
    public function all(?int $ownerId = null, bool $withCounts = false): array
    {
        $ownerId ??= $this->app->auth->userId();
        $dek = $this->app->dekFor($ownerId);

        $sql = $withCounts
            ? 'SELECT t.*, COUNT(tg.tag_id) AS usage_count
               FROM tags t LEFT JOIN taggables tg ON tg.tag_id = t.id
               WHERE t.user_id = :u GROUP BY t.id'
            : 'SELECT * FROM tags WHERE user_id = :u';

        $rows = $this->app->db->all($sql, [':u' => $ownerId]);
        foreach ($rows as &$r) {
            $r['name'] = $this->app->crypto->dec($dek, $r['name_enc'], 'tag.name');
            unset($r['name_enc'], $r['name_bidx']);
            $r['usage_count'] = (int)($r['usage_count'] ?? 0);
        }
        unset($r);

        usort($rows, fn($a, $b) => strcoll((string)$a['name'], (string)$b['name']));
        return $rows;
    }

    /** Exakte Suche über den Blind Index. */
    public function findByName(string $name, ?int $ownerId = null): ?array
    {
        $ownerId ??= $this->app->auth->userId();
        $bidx = $this->app->crypto->blindIndex('tag.name', $this->normalize($name));

        $row = $this->app->db->one(
            'SELECT * FROM tags WHERE user_id = :u AND name_bidx = :b',
            [':u' => $ownerId, ':b' => $bidx]
        );
        if (!$row) {
            return null;
        }
        $row['name'] = $this->app->crypto->dec($this->app->dekFor($ownerId), $row['name_enc'], 'tag.name');
        unset($row['name_enc'], $row['name_bidx']);
        return $row;
    }

    public function rename(int $tagId, string $newName, ?int $ownerId = null): void
    {
        $ownerId ??= $this->app->auth->userId();
        $newName = $this->normalize($newName);
        $dek = $this->app->dekFor($ownerId);

        $st = $this->app->db->pdo()->prepare(
            'UPDATE tags SET name_enc = :n, name_bidx = :b WHERE id = :id AND user_id = :u'
        );
        $st->bindValue(':n',  $this->app->crypto->enc($dek, $newName, 'tag.name'), \PDO::PARAM_LOB);
        $st->bindValue(':b',  $this->app->crypto->blindIndex('tag.name', $newName), \PDO::PARAM_LOB);
        $st->bindValue(':id', $tagId, \PDO::PARAM_INT);
        $st->bindValue(':u',  $ownerId, \PDO::PARAM_INT);
        $st->execute();
    }

    public function deleteTag(int $tagId, ?int $ownerId = null): void
    {
        $ownerId ??= $this->app->auth->userId();
        // taggables hängt per Fremdschlüssel dran und wird kaskadierend gelöscht
        $this->app->db->run('DELETE FROM tags WHERE id = :id AND user_id = :u',
                            [':id' => $tagId, ':u' => $ownerId]);
    }

    // =================================================================
    // Zuordnung zu Datensätzen
    // =================================================================

    public function attach(string $module, int $refId, int $tagId, ?int $ownerId = null): void
    {
        Modules::assert($module);
        $ownerId ??= $this->app->auth->userId();

        if (!$this->ownsTag($tagId, $ownerId)) {
            throw new \RuntimeException('Tag gehört nicht zu diesem Benutzer.');
        }
        $this->app->db->run(
            'INSERT IGNORE INTO taggables (tag_id, module, ref_id) VALUES (:t, :m, :r)',
            [':t' => $tagId, ':m' => $module, ':r' => $refId]
        );
    }

    /** Komfort: Tag über den Namen zuordnen, bei Bedarf anlegen. */
    public function attachByName(string $module, int $refId, string $name, ?int $ownerId = null): int
    {
        $tagId = $this->ensure($name, null, $ownerId);
        $this->attach($module, $refId, $tagId, $ownerId);
        return $tagId;
    }

    public function detach(string $module, int $refId, int $tagId, ?int $ownerId = null): void
    {
        $ownerId ??= $this->app->auth->userId();
        if (!$this->ownsTag($tagId, $ownerId)) {
            return;
        }
        $this->app->db->run(
            'DELETE FROM taggables WHERE tag_id = :t AND module = :m AND ref_id = :r',
            [':t' => $tagId, ':m' => $module, ':r' => $refId]
        );
    }

    /**
     * Alle Zuordnungen eines Datensatzes lösen. Wird von
     * Repository::delete() aufgerufen – ohne Fremdschlüssel auf die
     * Modultabellen bleiben sonst verwaiste Einträge zurück.
     */
    public function detachAll(string $module, int $refId, ?int $ownerId = null): void
    {
        $ownerId ??= $this->app->auth->userId();
        $this->app->db->run(
            'DELETE tg FROM taggables tg
             JOIN tags t ON t.id = tg.tag_id
             WHERE tg.module = :m AND tg.ref_id = :r AND t.user_id = :u',
            [':m' => $module, ':r' => $refId, ':u' => $ownerId]
        );
    }

    /** Setzt die Tags eines Datensatzes auf genau diese Liste. */
    public function sync(string $module, int $refId, array $names, ?int $ownerId = null): void
    {
        $ownerId ??= $this->app->auth->userId();
        $this->app->db->transaction(function () use ($module, $refId, $names, $ownerId): void {
            $this->detachAll($module, $refId, $ownerId);
            foreach ($names as $n) {
                $n = $this->normalize((string)$n);
                if ($n !== '') {
                    $this->attachByName($module, $refId, $n, $ownerId);
                }
            }
        });
    }

    /** Tags eines Datensatzes. */
    public function forObject(string $module, int $refId, ?int $ownerId = null): array
    {
        $ownerId ??= $this->app->auth->userId();
        $dek = $this->app->dekFor($ownerId);

        $rows = $this->app->db->all(
            'SELECT t.id, t.name_enc, t.color
             FROM taggables tg JOIN tags t ON t.id = tg.tag_id
             WHERE tg.module = :m AND tg.ref_id = :r AND t.user_id = :u',
            [':m' => $module, ':r' => $refId, ':u' => $ownerId]
        );
        foreach ($rows as &$r) {
            $r['name'] = $this->app->crypto->dec($dek, $r['name_enc'], 'tag.name');
            unset($r['name_enc']);
        }
        unset($r);
        usort($rows, fn($a, $b) => strcoll((string)$a['name'], (string)$b['name']));
        return $rows;
    }

    /**
     * Alle Datensätze zu einem Tag – modulübergreifend.
     * Das ist die Abfrage hinter "zeig mir alles zu 'Reha Nord'".
     */
    public function objectsFor(int $tagId, ?int $ownerId = null): array
    {
        $ownerId ??= $this->app->auth->userId();
        if (!$this->ownsTag($tagId, $ownerId)) {
            return [];
        }
        return $this->app->db->all(
            'SELECT tg.module, tg.ref_id
             FROM taggables tg JOIN tags t ON t.id = tg.tag_id
             WHERE tg.tag_id = :t AND t.user_id = :u',
            [':t' => $tagId, ':u' => $ownerId]
        );
    }

    /** Timeline-Einträge zu einem Tag – nutzt die zentrale Zeitachse. */
    public function timelineFor(int $tagId, ?int $ownerId = null): array
    {
        $objects = $this->objectsFor($tagId, $ownerId);
        if (!$objects) {
            return [];
        }
        $ownerId ??= $this->app->auth->userId();

        $conds = [];
        $par   = [':u' => $ownerId];
        foreach ($objects as $i => $o) {
            $conds[] = "(module = :m{$i} AND ref_id = :r{$i})";
            $par[":m{$i}"] = $o['module'];
            $par[":r{$i}"] = (int)$o['ref_id'];
        }

        $rows = $this->app->db->all(
            'SELECT * FROM timeline_events WHERE user_id = :u AND (' . implode(' OR ', $conds) . ')
             ORDER BY occurred_at DESC LIMIT 500',
            $par
        );

        $dek = $this->app->dekFor($ownerId);
        foreach ($rows as &$r) {
            $r['title']   = $this->app->crypto->dec($dek, $r['title_enc'], 'timeline.title');
            $r['summary'] = $this->app->crypto->dec($dek, $r['summary_enc'], 'timeline.summary');
            unset($r['title_enc'], $r['summary_enc']);
        }
        return $rows;
    }

    // =================================================================

    private function ownsTag(int $tagId, int $ownerId): bool
    {
        return (int)$this->app->db->value(
            'SELECT COUNT(*) FROM tags WHERE id = :id AND user_id = :u',
            [':id' => $tagId, ':u' => $ownerId]
        ) === 1;
    }

    /**
     * Normalisierung vor dem Blind Index. Muss deterministisch sein,
     * sonst finden sich identische Tags gegenseitig nicht: doppelte
     * Leerzeichen zusammenziehen, trimmen, Länge begrenzen.
     * Die Kleinschreibung übernimmt Crypto::blindIndex().
     */
    private function normalize(string $name): string
    {
        $name = preg_replace('/\s+/u', ' ', trim($name)) ?? '';
        return mb_substr($name, 0, self::MAX_NAME_LENGTH);
    }
}
