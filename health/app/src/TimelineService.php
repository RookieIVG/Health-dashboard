<?php
declare(strict_types=1);

namespace Health;

/**
 * Die zentrale Zeitachse.
 *
 * Jedes Modul meldet seine Datensätze hier an. Damit ist "was war rund um
 * den 14. März?" eine einzige indizierte Abfrage statt zwölf Joins über
 * Tabellen mit unterschiedlichen Datumsspalten.
 *
 * Der Eintrag ist eine Kopie, kein Ersatz für den Originaldatensatz –
 * Titel und Kurzfassung liegen redundant vor. Das ist bewusst: die
 * Timeline soll ohne Zugriff auf zwölf Modultabellen darstellbar sein.
 * Preis ist die Pflicht, sie bei Änderungen mitzuziehen; deshalb ist
 * record() idempotent und Repository::update() ruft es mit auf.
 */
final class TimelineService
{
    public function __construct(private App $app) {}

    // =================================================================
    // Schreiben
    // =================================================================

    /**
     * Legt den Timeline-Eintrag an oder aktualisiert ihn.
     *
     * @param string      $occurredAt  UTC, 'Y-m-d H:i:s'
     * @param int         $severity    0 = Info … 3 = kritisch
     */
    public function record(
        string $module,
        int $refId,
        string $occurredAt,
        string $title,
        ?string $summary = null,
        int $severity = 0,
        ?string $occurredEnd = null,
        string $eventType = 'entry',
        ?int $ownerId = null,
        ?string $icon = null
    ): int {
        Modules::assert($module);
        $ownerId ??= $this->app->auth->userId();
        $dek = $this->app->dekFor($ownerId);

        $titleEnc   = $this->app->crypto->enc($dek, $title, 'timeline.title');
        $summaryEnc = $this->app->crypto->enc($dek, $summary, 'timeline.summary');

        $st = $this->app->db->pdo()->prepare(
            'INSERT INTO timeline_events
                (user_id, occurred_at, occurred_end, module, ref_id, event_type,
                 title_enc, summary_enc, severity, icon)
             VALUES (:u, :occ, :end, :mod, :ref, :type, :title, :summary, :sev, :icon)
             ON DUPLICATE KEY UPDATE
                occurred_at  = VALUES(occurred_at),
                occurred_end = VALUES(occurred_end),
                title_enc    = VALUES(title_enc),
                summary_enc  = VALUES(summary_enc),
                severity     = VALUES(severity),
                icon         = VALUES(icon)'
        );
        $st->bindValue(':u',       $ownerId, \PDO::PARAM_INT);
        $st->bindValue(':occ',     $occurredAt);
        $st->bindValue(':end',     $occurredEnd, $occurredEnd === null ? \PDO::PARAM_NULL : \PDO::PARAM_STR);
        $st->bindValue(':mod',     $module);
        $st->bindValue(':ref',     $refId, \PDO::PARAM_INT);
        $st->bindValue(':type',    $eventType);
        $st->bindValue(':title',   $titleEnc, \PDO::PARAM_LOB);
        $st->bindValue(':summary', $summaryEnc, $summaryEnc === null ? \PDO::PARAM_NULL : \PDO::PARAM_LOB);
        $st->bindValue(':sev',     $severity, \PDO::PARAM_INT);
        $st->bindValue(':icon',    $icon ?? Modules::icon($module));
        $st->execute();

        $id = (int)$this->app->db->pdo()->lastInsertId();
        if ($id > 0) {
            return $id;
        }
        // Bei UPDATE liefert lastInsertId 0 – vorhandene ID nachschlagen
        return (int)$this->app->db->value(
            'SELECT id FROM timeline_events WHERE module = :m AND ref_id = :r AND event_type = :t',
            [':m' => $module, ':r' => $refId, ':t' => $eventType]
        );
    }

    /** Entfernt alle Timeline-Einträge zu einem Datensatz. */
    public function remove(string $module, int $refId, ?int $ownerId = null): void
    {
        $ownerId ??= $this->app->auth->userId();
        $this->app->db->run(
            'DELETE FROM timeline_events WHERE module = :m AND ref_id = :r AND user_id = :u',
            [':m' => $module, ':r' => $refId, ':u' => $ownerId]
        );
    }

    // =================================================================
    // Lesen
    // =================================================================

    /**
     * Einträge in einem Zeitraum.
     *
     * @param string[]|null $modules  Filter, null = alle
     */
    public function range(
        ?string $fromUtc = null,
        ?string $toUtc = null,
        ?array $modules = null,
        int $limit = 200,
        int $offset = 0,
        ?int $ownerId = null
    ): array {
        $ownerId ??= $this->app->auth->userId();
        $this->assertAccess($ownerId);

        $sql = 'SELECT * FROM timeline_events WHERE user_id = :u';
        $par = [':u' => $ownerId];

        if ($fromUtc) { $sql .= ' AND occurred_at >= :from'; $par[':from'] = $fromUtc; }
        if ($toUtc)   { $sql .= ' AND occurred_at <= :to';   $par[':to']   = $toUtc; }

        if ($modules) {
            $in = [];
            foreach (array_values($modules) as $i => $m) {
                $in[] = ':m' . $i;
                $par[':m' . $i] = Modules::assert($m);
            }
            $sql .= ' AND module IN (' . implode(',', $in) . ')';
        }

        $sql .= ' ORDER BY occurred_at DESC, id DESC LIMIT '
              . max(1, min($limit, 500)) . ' OFFSET ' . max(0, $offset);

        return $this->decorate($this->app->db->all($sql, $par), $ownerId);
    }

    /**
     * Ein Tag im Kontext: alles vom gewünschten Datum plus die Einträge
     * $days davor und danach. Das ist die Ansicht, für die die Timeline
     * überhaupt existiert.
     */
    public function around(string $dateLocal, int $days = 3, ?int $ownerId = null): array
    {
        $tz  = new \DateTimeZone($this->app->config['app']['timezone']);
        $day = new \DateTimeImmutable($dateLocal, $tz);

        $from = $day->modify("-{$days} days")->setTime(0, 0, 0)
                    ->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        $to   = $day->modify("+{$days} days")->setTime(23, 59, 59)
                    ->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');

        return $this->range($from, $to, null, 500, 0, $ownerId);
    }

    /** Gruppiert nach lokalem Datum – für die Darstellung. */
    public function groupedByDay(array $events): array
    {
        $tz = new \DateTimeZone($this->app->config['app']['timezone']);
        $out = [];
        foreach ($events as $e) {
            $key = (new \DateTimeImmutable($e['occurred_at'], new \DateTimeZone('UTC')))
                ->setTimezone($tz)->format('Y-m-d');
            $out[$key][] = $e;
        }
        return $out;
    }

    /** Anzahl je Modul – für Filterleisten und die Korrelationsansicht. */
    public function countsByModule(?string $fromUtc = null, ?string $toUtc = null, ?int $ownerId = null): array
    {
        $ownerId ??= $this->app->auth->userId();
        $sql = 'SELECT module, COUNT(*) AS n FROM timeline_events WHERE user_id = :u';
        $par = [':u' => $ownerId];
        if ($fromUtc) { $sql .= ' AND occurred_at >= :from'; $par[':from'] = $fromUtc; }
        if ($toUtc)   { $sql .= ' AND occurred_at <= :to';   $par[':to']   = $toUtc; }
        $sql .= ' GROUP BY module ORDER BY n DESC';

        $out = [];
        foreach ($this->app->db->all($sql, $par) as $r) {
            $out[$r['module']] = (int)$r['n'];
        }
        return $out;
    }

    /** Zeitpunkt des ersten und letzten Eintrags – Spannweite der Achse. */
    public function bounds(?int $ownerId = null): array
    {
        $ownerId ??= $this->app->auth->userId();
        $row = $this->app->db->one(
            'SELECT MIN(occurred_at) AS first, MAX(occurred_at) AS last
             FROM timeline_events WHERE user_id = :u',
            [':u' => $ownerId]
        );
        return ['first' => $row['first'] ?? null, 'last' => $row['last'] ?? null];
    }

    // =================================================================
    // Intern
    // =================================================================

    /** Entschlüsselt und reichert um Anzeigedaten an. */
    private function decorate(array $rows, int $ownerId): array
    {
        $dek = $this->app->dekFor($ownerId);
        $tz  = new \DateTimeZone($this->app->config['app']['timezone']);

        foreach ($rows as &$r) {
            $r['title']   = $this->app->crypto->dec($dek, $r['title_enc'], 'timeline.title');
            $r['summary'] = $this->app->crypto->dec($dek, $r['summary_enc'], 'timeline.summary');
            unset($r['title_enc'], $r['summary_enc']);

            $local = (new \DateTimeImmutable($r['occurred_at'], new \DateTimeZone('UTC')))
                ->setTimezone($tz);
            $r['local_date'] = $local->format('Y-m-d');
            $r['local_time'] = $local->format('H:i');
            $r['module_label'] = Modules::label($r['module']);
        }
        return $rows;
    }

    private function assertAccess(int $ownerId): void
    {
        if (!$this->app->auth->mayAccess($ownerId, '*', 'read')) {
            throw new \RuntimeException('Kein Zugriff auf diese Zeitachse.');
        }
    }
}
