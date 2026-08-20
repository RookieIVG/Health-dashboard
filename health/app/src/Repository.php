<?php
declare(strict_types=1);

namespace Health;

use RuntimeException;
use InvalidArgumentException;

/**
 * Basis für alle Modul-Repositories.
 *
 * Nimmt drei Dinge ab, die sonst in jedem der zwölf Module neu geschrieben
 * (und irgendwann einmal falsch geschrieben) würden:
 *
 *   1. Ver- und Entschlüsselung der Felder inklusive korrektem AAD
 *   2. Ownership – jede Abfrage ist auf einen Benutzer eingegrenzt
 *   3. Zugriffsprüfung über account_grants
 *
 * Ein konkretes Repository deklariert nur noch Tabelle, Modulname und
 * welche Felder verschlüsselt sind.
 */
abstract class Repository
{
    protected Db $db;
    protected Crypto $crypto;
    protected Auth $auth;
    protected Audit $audit;

    /** Datenbesitzer – nicht zwingend der angemeldete Benutzer. */
    protected int $ownerId;

    public function __construct(protected App $app, ?int $ownerId = null)
    {
        $this->db     = $app->db;
        $this->crypto = $app->crypto;
        $this->auth   = $app->auth;
        $this->audit  = $app->audit;

        $uid = $ownerId ?? $app->auth->userId();
        if ($uid === null) {
            throw new RuntimeException('Kein angemeldeter Benutzer.');
        }
        $this->ownerId = $uid;
    }

    // =================================================================
    // Von den Modulen zu füllen
    // =================================================================

    abstract protected function table(): string;

    abstract protected function module(): string;

    /**
     * Felder, die verschlüsselt gespeichert werden.
     * Schlüssel = Spaltenname ohne "_enc", Wert = true.
     * Beispiel: ['title' => true, 'notes' => true]
     */
    abstract protected function encryptedFields(): array;

    /** Spalte mit dem fachlichen Zeitpunkt – Basis für die Timeline. */
    protected function dateColumn(): string
    {
        return 'occurred_at';
    }

    // =================================================================
    // Ownership
    // =================================================================

    public function ownerId(): int
    {
        return $this->ownerId;
    }

    /** Repository auf fremde Daten umstellen (setzt eine Freigabe voraus). */
    public function forUser(int $ownerId, string $need = 'read'): static
    {
        if (!$this->auth->mayAccess($ownerId, $this->module(), $need)) {
            throw new RuntimeException('Kein Zugriff auf diese Daten.');
        }
        $clone = clone $this;
        $clone->ownerId = $ownerId;
        return $clone;
    }

    protected function assertWritable(): void
    {
        if (!$this->auth->mayAccess($this->ownerId, $this->module(), 'write')) {
            throw new RuntimeException('Keine Schreibberechtigung.');
        }
    }

    // =================================================================
    // Verschlüsselung
    // =================================================================

    /** DEK des Datenbesitzers, pro Request zwischengespeichert. */
    protected function dek(): string
    {
        return $this->app->dekFor($this->ownerId);
    }

    /**
     * AAD bindet den Ciphertext an Tabelle und Feld. Damit lässt sich ein
     * Wert nicht von einem Feld in ein anderes kopieren – etwa eine fremde
     * Notiz in ein Diagnosefeld. GCM prüft das beim Entschlüsseln mit.
     */
    protected function aad(string $field): string
    {
        return $this->table() . '.' . $field;
    }

    protected function encField(string $field, ?string $value): ?string
    {
        return $this->crypto->enc($this->dek(), $value, $this->aad($field));
    }

    protected function decField(string $field, $blob): ?string
    {
        return $this->crypto->dec($this->dek(), $blob, $this->aad($field));
    }

    /**
     * Wandelt eine DB-Zeile in ein Array mit entschlüsselten Klartextfeldern.
     * Aus `title_enc` wird `title`.
     */
    protected function hydrate(?array $row): ?array
    {
        if ($row === null) {
            return null;
        }
        foreach ($this->encryptedFields() as $field => $_) {
            $col = $field . '_enc';
            if (array_key_exists($col, $row)) {
                $row[$field] = $this->decField($field, $row[$col]);
                unset($row[$col]);
            }
        }
        return $row;
    }

    protected function hydrateAll(array $rows): array
    {
        return array_map(fn(array $r) => $this->hydrate($r), $rows);
    }

    /**
     * Gegenstück: trennt ein Eingabearray in verschlüsselte und
     * Klartextspalten auf und liefert die fertigen Spaltenwerte.
     */
    protected function dehydrate(array $data): array
    {
        $out = [];
        $encFields = $this->encryptedFields();
        foreach ($data as $key => $value) {
            if (isset($encFields[$key])) {
                $out[$key . '_enc'] = $this->encField($key, $value === null ? null : (string)$value);
            } else {
                $out[$key] = $value;
            }
        }
        return $out;
    }

    // =================================================================
    // CRUD
    // =================================================================

    public function find(int $id): ?array
    {
        $row = $this->db->one(
            'SELECT * FROM `' . $this->table() . '` WHERE id = :id AND user_id = :u',
            [':id' => $id, ':u' => $this->ownerId]
        );
        return $this->hydrate($row);
    }

    /** Datensätze in einem Zeitraum, neueste zuerst. */
    public function between(?string $fromUtc, ?string $toUtc, int $limit = 200): array
    {
        $col   = $this->dateColumn();
        $sql   = 'SELECT * FROM `' . $this->table() . '` WHERE user_id = :u';
        $par   = [':u' => $this->ownerId];
        if ($fromUtc) { $sql .= " AND `{$col}` >= :from"; $par[':from'] = $fromUtc; }
        if ($toUtc)   { $sql .= " AND `{$col}` <= :to";   $par[':to']   = $toUtc; }
        $sql .= " ORDER BY `{$col}` DESC, id DESC LIMIT " . max(1, min($limit, 1000));

        return $this->hydrateAll($this->db->all($sql, $par));
    }

    /**
     * Anlegen. $data enthält Klartext; verschlüsselte Felder werden anhand
     * von encryptedFields() erkannt.
     */
    public function create(array $data): int
    {
        $this->assertWritable();
        $cols = $this->dehydrate($data);
        $cols['user_id'] = $this->ownerId;

        $names        = array_keys($cols);
        $placeholders = array_map(fn($n) => ':' . $n, $names);

        $sql = 'INSERT INTO `' . $this->table() . '` (`' . implode('`,`', $names) . '`) '
             . 'VALUES (' . implode(',', $placeholders) . ')';

        $st = $this->db->pdo()->prepare($sql);
        foreach ($cols as $name => $value) {
            $st->bindValue(':' . $name, $value, $this->paramType($name, $value));
        }
        $st->execute();

        $id = (int)$this->db->pdo()->lastInsertId();
        $this->audit->log($this->module() . '.created', $this->ownerId,
                          $this->auth->userId(), $this->module(), $id);
        return $id;
    }

    public function update(int $id, array $data): void
    {
        $this->assertWritable();
        if (!$this->exists($id)) {
            throw new RuntimeException('Datensatz nicht gefunden.');
        }
        $cols = $this->dehydrate($data);
        unset($cols['user_id'], $cols['id']);
        if (!$cols) {
            return;
        }

        $set = implode(', ', array_map(fn($n) => "`{$n}` = :{$n}", array_keys($cols)));
        $st  = $this->db->pdo()->prepare(
            'UPDATE `' . $this->table() . "` SET {$set} WHERE id = :__id AND user_id = :__u"
        );
        foreach ($cols as $name => $value) {
            $st->bindValue(':' . $name, $value, $this->paramType($name, $value));
        }
        $st->bindValue(':__id', $id, \PDO::PARAM_INT);
        $st->bindValue(':__u', $this->ownerId, \PDO::PARAM_INT);
        $st->execute();

        $this->audit->log($this->module() . '.updated', $this->ownerId,
                          $this->auth->userId(), $this->module(), $id);
    }

    /**
     * Löschen inklusive Aufräumen der polymorphen Verweise. Ohne
     * Fremdschlüssel muss das die Anwendung erledigen – sonst bleiben
     * verwaiste Timeline-Einträge, Tags und Anhänge zurück.
     */
    public function delete(int $id): void
    {
        $this->assertWritable();
        if (!$this->exists($id)) {
            return;
        }

        $this->db->transaction(function () use ($id): void {
            $this->app->attachments()->deleteFor($this->module(), $id, $this->ownerId);
            $this->app->tags()->detachAll($this->module(), $id, $this->ownerId);
            $this->app->timeline()->remove($this->module(), $id, $this->ownerId);

            $this->db->run(
                'DELETE FROM `' . $this->table() . '` WHERE id = :id AND user_id = :u',
                [':id' => $id, ':u' => $this->ownerId]
            );
        });

        $this->audit->log($this->module() . '.deleted', $this->ownerId,
                          $this->auth->userId(), $this->module(), $id);
    }

    public function exists(int $id): bool
    {
        return (int)$this->db->value(
            'SELECT COUNT(*) FROM `' . $this->table() . '` WHERE id = :id AND user_id = :u',
            [':id' => $id, ':u' => $this->ownerId]
        ) === 1;
    }

    public function count(): int
    {
        return (int)$this->db->value(
            'SELECT COUNT(*) FROM `' . $this->table() . '` WHERE user_id = :u',
            [':u' => $this->ownerId]
        );
    }

    // =================================================================
    // Timeline-Anbindung
    // =================================================================

    /**
     * Schreibt oder aktualisiert den Timeline-Eintrag zu einem Datensatz.
     * Idempotent über den Unique-Key (module, ref_id, event_type).
     */
    protected function touchTimeline(
        int $refId,
        string $occurredAt,
        string $title,
        ?string $summary = null,
        int $severity = 0,
        ?string $occurredEnd = null,
        string $eventType = 'entry'
    ): void {
        $this->app->timeline()->record(
            module:      $this->module(),
            refId:       $refId,
            occurredAt:  $occurredAt,
            title:       $title,
            summary:     $summary,
            severity:    $severity,
            occurredEnd: $occurredEnd,
            eventType:   $eventType,
            ownerId:     $this->ownerId
        );
    }

    // =================================================================
    // Hilfsfunktionen
    // =================================================================

    /** BLOB-Spalten müssen als LOB gebunden werden, sonst verstümmelt PDO sie. */
    protected function paramType(string $column, $value): int
    {
        if ($value === null) {
            return \PDO::PARAM_NULL;
        }
        if (str_ends_with($column, '_enc') || str_ends_with($column, '_bidx')) {
            return \PDO::PARAM_LOB;
        }
        if (is_int($value))  return \PDO::PARAM_INT;
        if (is_bool($value)) return \PDO::PARAM_INT;
        return \PDO::PARAM_STR;
    }

    /** Lokale Eingabe ("2026-08-19 14:30") nach UTC für die Speicherung. */
    public function toUtc(string $local, ?string $tz = null): string
    {
        $tz = $tz ?? $this->app->config['app']['timezone'];
        return (new \DateTimeImmutable($local, new \DateTimeZone($tz)))
            ->setTimezone(new \DateTimeZone('UTC'))
            ->format('Y-m-d H:i:s');
    }

    protected function requireNonEmpty(array $data, array $fields): void
    {
        foreach ($fields as $f) {
            if (!isset($data[$f]) || trim((string)$data[$f]) === '') {
                throw new InvalidArgumentException("Pflichtfeld fehlt: {$f}");
            }
        }
    }
}
