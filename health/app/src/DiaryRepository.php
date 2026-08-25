<?php
declare(strict_types=1);

namespace Health;

use InvalidArgumentException;
use RuntimeException;

/**
 * Tagebuch-Framework.
 *
 * Stuhl, Ernährung, Psyche, Schlaf und Schmerz sind strukturell dasselbe:
 * Zeitpunkt, ein paar Skalenwerte, etwas Freitext. Fünf getrennte Module
 * hätten fünfmal dieselbe Logik bedeutet – und beim sechsten Tagebuch
 * wieder von vorn. Hier ist ein neues Tagebuch ein Datensatz in
 * diary_types plus ein paar Felder.
 *
 * Zahlen und Auswahlschlüssel liegen im Klartext, Freitext verschlüsselt.
 * Ohne auswertbare Zahlen gäbe es keinen Verlauf und keine
 * Korrelationsansicht – und genau dafür führt man ein Tagebuch.
 */
final class DiaryRepository extends Repository
{
    protected function table(): string  { return 'diary_entries'; }
    protected function module(): string { return Modules::DIARY; }
    protected function dateColumn(): string { return 'occurred_at'; }
    protected function encryptedFields(): array { return ['note' => true]; }

    // =================================================================
    // Typen und Felder
    // =================================================================

    public function types(bool $onlyActive = true): array
    {
        $sql = 'SELECT * FROM diary_types WHERE (user_id IS NULL OR user_id = :u)';
        if ($onlyActive) $sql .= ' AND is_active = 1';
        return $this->db->all($sql . ' ORDER BY sort_order, label', [':u' => $this->ownerId]);
    }

    public function type(int $typeId): ?array
    {
        return $this->db->one(
            'SELECT * FROM diary_types WHERE id = :id AND (user_id IS NULL OR user_id = :u)',
            [':id' => $typeId, ':u' => $this->ownerId]
        );
    }

    /**
     * Infotext gilt bewusst auch für mitgelieferte Tagebücher als
     * änderbar, anders als deren Felder/Struktur: eine Nutzungshinweis-
     * Anpassung berührt nicht die Auswertbarkeit über mehrere Konten
     * hinweg, anders als eine geänderte Skala es täte.
     */
    public function setInfoText(int $typeId, ?string $text): void
    {
        if (!$this->type($typeId)) throw new InvalidArgumentException('Tagebuch nicht gefunden.');
        $text = $text !== null ? trim($text) : null;
        $this->db->run(
            'UPDATE diary_types SET info_text = :t WHERE id = :id',
            [':t' => ($text === '' ? null : $text), ':id' => $typeId]
        );
    }

    public function typeByKey(string $key): ?array
    {
        return $this->db->one(
            'SELECT * FROM diary_types WHERE tkey = :k AND (user_id IS NULL OR user_id = :u)
             ORDER BY user_id IS NULL LIMIT 1',
            [':k' => $key, ':u' => $this->ownerId]
        );
    }

    /** Felder eines Typs, in Anzeigereihenfolge, mit aufgelösten Optionen. */
    public function fields(int $typeId, bool $onlyActive = false): array
    {
        $sql = 'SELECT * FROM diary_fields WHERE type_id = :t';
        if ($onlyActive) $sql .= ' AND is_active = 1';
        $rows = $this->db->all($sql . ' ORDER BY sort_order, id', [':t' => $typeId]);
        foreach ($rows as &$r) {
            $r['options_list'] = $r['options']
                ? (json_decode((string)$r['options'], true) ?: [])
                : [];
        }
        return $rows;
    }

    /** Das Leitfeld bestimmt Kurve und Timeline-Titel. */
    public function primaryField(int $typeId): ?array
    {
        foreach ($this->fields($typeId) as $f) {
            if ((int)$f['is_primary'] === 1) return $f;
        }
        return null;
    }

    public function createType(string $label, ?string $description = null): int
    {
        $label = trim($label);
        if ($label === '') throw new InvalidArgumentException('Eine Bezeichnung ist nötig.');

        $key = preg_replace('/[^a-z0-9_]/', '', strtolower(str_replace(' ', '_', $label))) ?: '';
        if ($key === '') $key = 'diary_' . bin2hex(random_bytes(3));
        if ($this->typeByKey($key)) $key .= '_' . bin2hex(random_bytes(2));

        return $this->db->insert(
            'INSERT INTO diary_types (user_id, tkey, label, description, sort_order)
             VALUES (:u, :k, :l, :d, 500)',
            [':u' => $this->ownerId, ':k' => $key, ':l' => mb_substr($label, 0, 96),
             ':d' => $description ? mb_substr($description, 0, 255) : null]
        );
    }

    public function addField(int $typeId, array $d): int
    {
        $t = $this->type($typeId);
        if (!$t) throw new RuntimeException('Tagebuch nicht gefunden.');
        if ($t['user_id'] === null) {
            throw new RuntimeException(
                'Mitgelieferte Tagebücher lassen sich nicht verändern. '
                . 'Lege ein eigenes an, wenn du andere Felder brauchst.'
            );
        }

        $label = trim((string)($d['label'] ?? ''));
        if ($label === '') throw new InvalidArgumentException('Feldbezeichnung fehlt.');

        $ftype = (string)($d['ftype'] ?? 'text');
        $allowed = ['scale','number','choice','bool','text','longtext','time','duration'];
        if (!in_array($ftype, $allowed, true)) $ftype = 'text';

        $key = preg_replace('/[^a-z0-9_]/', '', strtolower(str_replace(' ', '_', $label))) ?: 'f';
        if ($this->db->one('SELECT id FROM diary_fields WHERE type_id = :t AND fkey = :k',
                           [':t' => $typeId, ':k' => $key])) {
            $key .= '_' . bin2hex(random_bytes(2));
        }

        $options = $ftype === 'choice' ? self::parseOptions((string)($d['options'] ?? '')) : null;

        return $this->db->insert(
            'INSERT INTO diary_fields
                (type_id, fkey, label, ftype, unit, options, min_val, max_val, step_val,
                 is_required, is_primary, hint, sort_order)
             VALUES (:t,:k,:l,:ft,:un,:o,:mi,:ma,:st,:req,:pri,:h,:so)',
            [
                ':t' => $typeId, ':k' => $key, ':l' => mb_substr($label, 0, 96), ':ft' => $ftype,
                ':un' => mb_substr(trim((string)($d['unit'] ?? '')), 0, 24),
                ':o' => $options,
                ':mi' => VitalsRepository::num($d['min_val'] ?? null),
                ':ma' => VitalsRepository::num($d['max_val'] ?? null),
                ':st' => VitalsRepository::num($d['step_val'] ?? null),
                ':req' => !empty($d['is_required']) ? 1 : 0,
                ':pri' => !empty($d['is_primary']) ? 1 : 0,
                ':h' => trim((string)($d['hint'] ?? '')) ?: null,
                ':so' => (int)($d['sort_order'] ?? 100),
            ]
        );
    }

    // =================================================================
    // Einträge
    // =================================================================

    /**
     * Speichert einen Eintrag samt Feldwerten.
     *
     * @param array $values  fkey => Rohwert aus dem Formular
     */
    public function saveEntry(int $typeId, string $occurredLocal, array $values,
                              ?string $note = null, ?int $entryId = null, ?string $tagsRaw = null): int
    {
        $type = $this->type($typeId);
        if (!$type) throw new InvalidArgumentException('Unbekanntes Tagebuch.');

        // Nur aktive Felder – ausgeblendete werden weder validiert noch
        // angefasst. Ein ausgeblendetes Feld ist in der Erfassungsmaske
        // nicht mehr sichtbar; würde saveEntry() trotzdem alle Werte des
        // Eintrags löschen und nur die übermittelten neu schreiben, gingen
        // die Altwerte eines ausgeblendeten Feldes beim nächsten Speichern
        // eines Eintrags still verloren.
        $fields = $this->fields($typeId, true);
        if (!$fields) throw new RuntimeException('Für dieses Tagebuch sind keine sichtbaren Felder definiert.');

        $utc = $this->toUtc(str_replace('T', ' ', trim($occurredLocal)));

        // Erst prüfen, dann schreiben – sonst bleibt bei einem Fehler im
        // dritten Feld ein halber Eintrag zurück.
        $prepared = [];
        foreach ($fields as $f) {
            $raw = $values[$f['fkey']] ?? null;
            $prepared[] = [$f, $this->normalizeValue($f, $raw)];
        }

        $id = $this->db->transaction(function () use ($typeId, $utc, $note, $entryId, $prepared, $type): int {
            if ($entryId === null) {
                $id = $this->create(['type_id' => $typeId, 'occurred_at' => $utc, 'note' => $note]);
            } else {
                $id = $entryId;
                $this->update($id, ['occurred_at' => $utc, 'note' => $note]);
                // Nur die Werte der aktiven Felder löschen und neu schreiben –
                // Werte ausgeblendeter Felder bleiben unangetastet erhalten.
                $activeFieldIds = array_map(fn($p) => (int)$p[0]['id'], $prepared);
                if ($activeFieldIds) {
                    $in = implode(',', array_fill(0, count($activeFieldIds), '?'));
                    $this->db->run("DELETE FROM diary_values WHERE entry_id = ? AND field_id IN ({$in})",
                                   array_merge([$id], $activeFieldIds));
                }
            }

            foreach ($prepared as [$f, $v]) {
                if ($v['num'] === null && $v['key'] === null && $v['text'] === null) continue;

                $st = $this->db->pdo()->prepare(
                    'INSERT INTO diary_values (entry_id, field_id, value_num, value_key, value_enc)
                     VALUES (:e, :f, :n, :k, :t)'
                );
                $st->bindValue(':e', $id, \PDO::PARAM_INT);
                $st->bindValue(':f', (int)$f['id'], \PDO::PARAM_INT);
                $st->bindValue(':n', $v['num'], $v['num'] === null ? \PDO::PARAM_NULL : \PDO::PARAM_STR);
                $st->bindValue(':k', $v['key'], $v['key'] === null ? \PDO::PARAM_NULL : \PDO::PARAM_STR);

                $enc = $v['text'] === null ? null
                     : $this->crypto->enc($this->dek(), $v['text'], 'diary_values.value');
                $st->bindValue(':t', $enc, $enc === null ? \PDO::PARAM_NULL : \PDO::PARAM_LOB);
                $st->execute();
            }

            $this->touchTimeline(
                refId:      $id,
                occurredAt: $utc,
                title:      $type['label'] . ': ' . $this->headline($typeId, $prepared),
                summary:    $note,
                severity:   0
            );

            return $id;
        });

        // Bewusst AUSSERHALB der Transaktion oben: sync() startet selbst
        // eine eigene db->transaction() - von innen aufgerufen, würde das
        // eine verschachtelte Transaktion auslösen (PDO unterstützt das
        // nicht, würde mit "there is already an active transaction" abbrechen).
        if ($tagsRaw !== null) {
            $this->app->tags()->sync($this->module(), $id, self::parseTags($tagsRaw), $this->ownerId);
        }

        return $id;
    }

    public static function parseTags(string $raw): array
    {
        $parts = preg_split('/[,;]+/u', $raw) ?: [];
        return array_values(array_filter(array_map('trim', $parts), fn($t) => $t !== ''));
    }

    /** Wandelt einen Formularwert in die passende Spalte. */
    private function normalizeValue(array $f, $raw): array
    {
        $empty = ['num' => null, 'key' => null, 'text' => null];

        if ($f['ftype'] === 'bool') {
            // Nicht angehakt heißt "nein", nicht "keine Angabe"
            return ['num' => !empty($raw) ? 1 : 0, 'key' => null, 'text' => null];
        }

        if ($raw === null || trim((string)$raw) === '') {
            if ((int)$f['is_required'] === 1) {
                throw new InvalidArgumentException('Pflichtfeld fehlt: ' . $f['label']);
            }
            return $empty;
        }

        switch ($f['ftype']) {
            case 'scale':
            case 'number':
            case 'duration':
                $n = VitalsRepository::num($raw);
                if ($n === null) {
                    throw new InvalidArgumentException($f['label'] . ': keine gültige Zahl.');
                }
                $min = $f['min_val'] === null ? null : (float)$f['min_val'];
                $max = $f['max_val'] === null ? null : (float)$f['max_val'];
                if (($min !== null && $n < $min) || ($max !== null && $n > $max)) {
                    throw new InvalidArgumentException(sprintf(
                        '%s muss zwischen %s und %s liegen.',
                        $f['label'], VitalsRepository::trimNum((float)$min), VitalsRepository::trimNum((float)$max)
                    ));
                }
                return ['num' => $n, 'key' => null, 'text' => null];

            case 'choice':
                $keys = array_column($f['options_list'], 'k');
                if (!in_array((string)$raw, $keys, true)) {
                    throw new InvalidArgumentException($f['label'] . ': ungültige Auswahl.');
                }
                return ['num' => null, 'key' => (string)$raw, 'text' => null];

            case 'time':
                if (!preg_match('/^([01]\d|2[0-3]):([0-5]\d)$/', (string)$raw)) {
                    throw new InvalidArgumentException($f['label'] . ': Uhrzeit im Format HH:MM angeben.');
                }
                return ['num' => null, 'key' => (string)$raw, 'text' => null];

            default:
                return ['num' => null, 'key' => null, 'text' => mb_substr((string)$raw, 0, 5000)];
        }
    }

    /** Kurzfassung für die Timeline aus dem Leitfeld. */
    private function headline(int $typeId, array $prepared): string
    {
        foreach ($prepared as [$f, $v]) {
            if ((int)$f['is_primary'] !== 1) continue;
            if ($v['num'] !== null) {
                return $f['label'] . ' ' . VitalsRepository::trimNum((float)$v['num'])
                     . ($f['unit'] !== '' ? ' ' . $f['unit'] : '');
            }
            if ($v['key'] !== null) {
                return $f['label'] . ' ' . self::optionLabel($f, $v['key']);
            }
            if ($v['text'] !== null) {
                return mb_substr($v['text'], 0, 60);
            }
        }
        return 'Eintrag';
    }

    // =================================================================
    // Lesen
    // =================================================================

    /** Einträge eines Typs samt aufgelösten Werten. */
    public function entries(int $typeId, ?string $fromUtc = null, int $limit = 100): array
    {
        $sql = 'SELECT * FROM diary_entries WHERE user_id = :u AND type_id = :t';
        $par = [':u' => $this->ownerId, ':t' => $typeId];
        if ($fromUtc) { $sql .= ' AND occurred_at >= :f'; $par[':f'] = $fromUtc; }
        $sql .= ' ORDER BY occurred_at DESC LIMIT ' . max(1, min($limit, 500));

        $rows = $this->hydrateAll($this->db->all($sql, $par));
        if (!$rows) return [];

        $rows = $this->attachValues($rows, $this->fields($typeId));
        $this->attachTags($rows);
        return $rows;
    }

    /** Tags für mehrere Einträge in einer Abfrage statt N+1 pro Zeile. */
    private function attachTags(array &$rows): void
    {
        $ids = array_map(fn($r) => (int)$r['id'], $rows);
        $byEntry = $this->tagsByEntryIds($ids);
        foreach ($rows as &$r) {
            $r['tags'] = $byEntry[(int)$r['id']] ?? [];
        }
    }

    /** @return array<int, array{id:int, name:string}[]> Eintrags-ID => Tags */
    private function tagsByEntryIds(array $ids): array
    {
        if (!$ids) return [];
        $in = implode(',', array_fill(0, count($ids), '?'));
        $tagRows = $this->db->all(
            "SELECT tg.ref_id, t.id AS tag_id, t.name_enc
             FROM taggables tg JOIN tags t ON t.id = tg.tag_id
             WHERE tg.module = ? AND t.user_id = ? AND tg.ref_id IN ({$in})",
            array_merge([$this->module(), $this->ownerId], $ids)
        );

        $dek = $this->dek();
        $decrypted = []; // tag_id => Name, je Tag nur einmal entschlüsseln
        $byEntry = [];
        foreach ($tagRows as $tr) {
            $tagId = (int)$tr['tag_id'];
            if (!isset($decrypted[$tagId])) {
                $decrypted[$tagId] = $this->crypto->dec($dek, $tr['name_enc'], 'tag.name');
            }
            $byEntry[(int)$tr['ref_id']][] = ['id' => $tagId, 'name' => $decrypted[$tagId]];
        }
        return $byEntry;
    }

    // =================================================================
    // Mustererkennung: Auslöser-Tagebuch (z.B. Ernährung) gegen
    // Wirkung-Tagebuch (z.B. Stuhl, Schmerz)
    // =================================================================

    /** Nur Felder, deren Wert sich sinnvoll mitteln lässt. */
    private function isAnalyzableField(array $f): bool
    {
        if (in_array($f['ftype'], ['scale', 'number', 'bool'], true)) return true;
        if ($f['ftype'] === 'choice' && $f['options_list']) {
            foreach ($f['options_list'] as $opt) {
                if (!is_numeric($opt['k'] ?? null)) return false;
            }
            return true;
        }
        return false;
    }

    /**
     * Vergleicht Einträge eines Auslöser-Tagebuchs mit dem, was in einem
     * Wirkung-Tagebuch im gewählten Zeitfenster DANACH passiert ist.
     *
     * Zwei Sichten, beide ohne Signifikanztests oder p-Werte – bei den
     * üblichen Mengen an Tagebucheinträgen wäre das eine falsche
     * Genauigkeit. Nur Mittelwerte und Fallzahlen, mit deutlichem
     * Hinweis bei wenigen Einträgen. Mustererkennung für den Alltag,
     * keine Diagnose.
     *
     *  - "buckets": nach dem Leitwert des Auslöser-Tagebuchs gruppiert
     *    (z.B. niedrige/mittlere/hohe Verträglichkeit) – funktioniert
     *    ohne jede Zusatzeingabe, mit dem, was ohnehin erfasst wird.
     *  - "tags": nach den Stichworten/Zutaten gruppiert, die an den
     *    Auslöser-Einträgen hängen – feiner, aber nur für getaggte
     *    Einträge aussagekräftig.
     */
    public function analyzeCorrelation(int $sourceTypeId, int $outcomeTypeId, int $windowHours, int $minOccurrences): array
    {
        $primaryField  = $this->primaryField($sourceTypeId);
        $outcomeFields = array_values(array_filter(
            $this->fields($outcomeTypeId, true),
            fn($f) => $this->isAnalyzableField($f)
        ));

        $sourceRows = $this->db->all(
            'SELECT e.id, e.occurred_at, v.value_num, v.value_key
             FROM diary_entries e
             LEFT JOIN diary_values v ON v.entry_id = e.id AND v.field_id = :pf
             WHERE e.user_id = :u AND e.type_id = :t
             ORDER BY e.occurred_at',
            [':u' => $this->ownerId, ':t' => $sourceTypeId, ':pf' => (int)($primaryField['id'] ?? 0)]
        );

        $sourceTags = $this->tagsByEntryIds(array_map(fn($r) => (int)$r['id'], $sourceRows));

        $outcomeFieldIds = array_column($outcomeFields, 'id');
        $outcomeRows = [];
        if ($outcomeFieldIds) {
            $in  = implode(',', array_fill(0, count($outcomeFieldIds), '?'));
            $raw = $this->db->all(
                "SELECT e.id AS entry_id, e.occurred_at, v.field_id, v.value_num, v.value_key
                 FROM diary_entries e
                 JOIN diary_values v ON v.entry_id = e.id
                 WHERE e.user_id = ? AND e.type_id = ? AND v.field_id IN ({$in})
                 ORDER BY e.occurred_at",
                array_merge([$this->ownerId, $outcomeTypeId], array_map('intval', $outcomeFieldIds))
            );
            foreach ($raw as $r) {
                $val = $r['value_num'] !== null ? (float)$r['value_num']
                     : ($r['value_key'] !== null ? (float)$r['value_key'] : null);
                if ($val === null) continue;
                $outcomeRows[] = [
                    'entry_id' => (int)$r['entry_id'], 'ts' => strtotime($r['occurred_at'] . ' UTC'),
                    'field_id' => (int)$r['field_id'], 'value' => $val,
                ];
            }
        }

        $windowSeconds = $windowHours * 3600;

        // Schlimmster Wert je Feld innerhalb des Fensters NACH dem
        // Auslöser-Eintrag - nicht der Durchschnitt über das ganze
        // Fenster, sonst verwässert eine ruhige Phase im selben Fenster
        // einen tatsächlichen Ausschlag.
        $responseFor = function (int $sourceEntryId, string $occurredAtUtc) use ($outcomeRows, $windowSeconds): array {
            $startTs = strtotime($occurredAtUtc . ' UTC');
            $endTs   = $startTs + $windowSeconds;
            $best = [];
            foreach ($outcomeRows as $or) {
                if ($or['entry_id'] === $sourceEntryId) continue; // nur bei gleichem Tagebuch als Quelle+Ziel relevant
                if ($or['ts'] < $startTs || $or['ts'] > $endTs) continue;
                if (!isset($best[$or['field_id']]) || $or['value'] > $best[$or['field_id']]) {
                    $best[$or['field_id']] = $or['value'];
                }
            }
            return $best;
        };

        $usable = [];
        foreach ($sourceRows as $sr) {
            $resp = $responseFor((int)$sr['id'], $sr['occurred_at']);
            if (!$resp) continue; // keine Wirkung im Fenster gefunden - kein auswertbarer Datenpunkt
            $usable[] = [
                'primaryValue' => $sr['value_num'] !== null ? (float)$sr['value_num']
                                 : ($sr['value_key'] !== null ? (float)$sr['value_key'] : null),
                'tags'     => $sourceTags[(int)$sr['id']] ?? [],
                'response' => $resp,
            ];
        }

        return [
            'outcomeFields' => $outcomeFields,
            'primaryField'  => $primaryField,
            'buckets'       => $primaryField ? $this->bucketAnalysis($usable, $primaryField, $outcomeFields) : [],
            'tags'          => $this->tagAnalysis($usable, $outcomeFields, $minOccurrences),
            'sourceCount'   => count($sourceRows),
            'usableCount'   => count($usable),
        ];
    }

    private function bucketAnalysis(array $usable, array $primaryField, array $outcomeFields): array
    {
        $bucketed = [];
        foreach ($usable as $u) {
            if ($u['primaryValue'] === null) continue;
            $bucketed[$this->bucketLabel($primaryField, $u['primaryValue'])][] = $u['response'];
        }

        $out = [];
        foreach ($bucketed as $label => $responses) {
            $out[] = ['label' => $label, 'n' => count($responses),
                      'avg' => $this->averagePerField($responses, $outcomeFields)];
        }
        return $out;
    }

    private function bucketLabel(array $field, float $value): string
    {
        if ($field['ftype'] === 'bool') return $value >= 1 ? 'Ja' : 'Nein';
        if ($field['ftype'] === 'choice') {
            foreach ($field['options_list'] as $opt) {
                if (isset($opt['k']) && (float)$opt['k'] === $value) return $opt['l'] ?? (string)$value;
            }
            return (string)$value;
        }
        $min = $field['min_val'] !== null ? (float)$field['min_val'] : 0.0;
        $max = $field['max_val'] !== null ? (float)$field['max_val'] : 10.0;
        $range = max($max - $min, 0.001);
        if ($value <= $min + $range / 3) return 'niedrig';
        if ($value >= $max - $range / 3) return 'hoch';
        return 'mittel';
    }

    private function tagAnalysis(array $usable, array $outcomeFields, int $minOccurrences): array
    {
        $allTagNames = [];
        foreach ($usable as $u) {
            foreach ($u['tags'] as $t) $allTagNames[$t['name']] = true;
        }

        $out = [];
        foreach (array_keys($allTagNames) as $tagName) {
            $with = $without = [];
            foreach ($usable as $u) {
                $has = false;
                foreach ($u['tags'] as $t) { if ($t['name'] === $tagName) { $has = true; break; } }
                if ($has) $with[] = $u['response']; else $without[] = $u['response'];
            }
            if (count($with) < $minOccurrences) continue;

            $out[] = [
                'name' => $tagName, 'n' => count($with), 'nWithout' => count($without),
                'avgWith' => $this->averagePerField($with, $outcomeFields),
                'avgWithout' => $this->averagePerField($without, $outcomeFields),
            ];
        }

        usort($out, function ($a, $b) use ($outcomeFields) {
            return $this->maxDiff($b, $outcomeFields) <=> $this->maxDiff($a, $outcomeFields);
        });
        return $out;
    }

    private function maxDiff(array $tagRow, array $outcomeFields): float
    {
        $max = 0.0;
        foreach ($outcomeFields as $f) {
            $fid = (int)$f['id'];
            if (isset($tagRow['avgWith'][$fid], $tagRow['avgWithout'][$fid])) {
                $max = max($max, abs($tagRow['avgWith'][$fid] - $tagRow['avgWithout'][$fid]));
            }
        }
        return $max;
    }

    private function averagePerField(array $responses, array $outcomeFields): array
    {
        $out = [];
        foreach ($outcomeFields as $f) {
            $fid = (int)$f['id'];
            $vals = [];
            foreach ($responses as $r) { if (isset($r[$fid])) $vals[] = $r[$fid]; }
            $out[$fid] = $vals ? array_sum($vals) / count($vals) : null;
        }
        return $out;
    }

    public function entry(int $entryId): ?array
    {
        $row = $this->hydrate($this->db->one(
            'SELECT * FROM diary_entries WHERE id = :id AND user_id = :u',
            [':id' => $entryId, ':u' => $this->ownerId]
        ));
        if (!$row) return null;

        $rows = $this->attachValues([$row], $this->fields((int)$row['type_id']));
        $rows[0]['tags'] = $this->app->tags()->forObject($this->module(), $entryId, $this->ownerId);
        return $rows[0];
    }

    /** Lädt alle Werte in einer Abfrage statt einer je Eintrag. */
    private function attachValues(array $entries, array $fields): array
    {
        $byId  = [];
        foreach ($fields as $f) $byId[(int)$f['id']] = $f;

        $ids = array_map(fn($e) => (int)$e['id'], $entries);
        $in  = implode(',', array_fill(0, count($ids), '?'));
        $vals = $this->db->all("SELECT * FROM diary_values WHERE entry_id IN ({$in})", $ids);

        $grouped = [];
        foreach ($vals as $v) {
            $f = $byId[(int)$v['field_id']] ?? null;
            if (!$f) continue;

            $grouped[(int)$v['entry_id']][$f['fkey']] = [
                'field'   => $f,
                'num'     => $v['value_num'] === null ? null : (float)$v['value_num'],
                'key'     => $v['value_key'],
                'text'    => $v['value_enc'] === null ? null
                             : $this->crypto->dec($this->dek(), $v['value_enc'], 'diary_values.value'),
            ];
        }

        foreach ($entries as &$e) {
            $e['values'] = $grouped[(int)$e['id']] ?? [];
        }
        return $entries;
    }

    /** Messreihe des Leitfelds – für die Kurve. */
    public function primarySeries(int $typeId, ?string $fromUtc = null): array
    {
        $f = $this->primaryField($typeId);
        if (!$f) return [];

        $sql = 'SELECT e.occurred_at, v.value_num, v.value_key
                FROM diary_entries e
                JOIN diary_values v ON v.entry_id = e.id AND v.field_id = :f
                WHERE e.user_id = :u AND e.type_id = :t';
        $par = [':f' => (int)$f['id'], ':u' => $this->ownerId, ':t' => $typeId];
        if ($fromUtc) { $sql .= ' AND e.occurred_at >= :from'; $par[':from'] = $fromUtc; }
        $sql .= ' ORDER BY e.occurred_at ASC LIMIT 500';

        $out = [];
        foreach ($this->db->all($sql, $par) as $r) {
            // Auswahlfelder mit numerischen Schlüsseln (Bristol 1–7)
            // lassen sich ebenfalls als Kurve darstellen.
            $v = $r['value_num'] !== null ? (float)$r['value_num']
               : (is_numeric((string)$r['value_key']) ? (float)$r['value_key'] : null);
            if ($v === null) continue;
            $out[] = ['t' => strtotime($r['occurred_at'] . ' UTC'), 'v' => $v];
        }
        return $out;
    }

    /** Anzahl Einträge je Typ. */
    public function countsByType(): array
    {
        $out = [];
        foreach ($this->db->all(
            'SELECT type_id, COUNT(*) n, MAX(occurred_at) last FROM diary_entries
             WHERE user_id = :u GROUP BY type_id', [':u' => $this->ownerId]
        ) as $r) {
            $out[(int)$r['type_id']] = ['n' => (int)$r['n'], 'last' => $r['last']];
        }
        return $out;
    }

    // =================================================================

    // =================================================================
    // Verwaltung: Typ und Felder bearbeiten
    // =================================================================

    public function updateType(int $typeId, string $label, ?string $description): void
    {
        $t = $this->type($typeId);
        if (!$t || $t['user_id'] === null) {
            throw new RuntimeException('Mitgelieferte Tagebücher lassen sich nicht umbenennen.');
        }
        $label = trim($label);
        if ($label === '') throw new InvalidArgumentException('Eine Bezeichnung ist nötig.');

        $this->db->run(
            'UPDATE diary_types SET label = :l, description = :d WHERE id = :id AND user_id = :u',
            [':l' => mb_substr($label, 0, 96), ':d' => $description ? mb_substr($description, 0, 255) : null,
             ':id' => $typeId, ':u' => $this->ownerId]
        );
    }

    /** Blendet ein Tagebuch aus der Übersicht aus, ohne Daten zu löschen. */
    public function toggleTypeActive(int $typeId): void
    {
        $t = $this->type($typeId);
        if (!$t) throw new RuntimeException('Tagebuch nicht gefunden.');
        $this->db->run(
            'UPDATE diary_types SET is_active = 1 - is_active WHERE id = :id AND (user_id IS NULL OR user_id = :u)',
            [':id' => $typeId, ':u' => $this->ownerId]
        );
    }

    public function field(int $fieldId): ?array
    {
        $row = $this->db->one('SELECT * FROM diary_fields WHERE id = :id', [':id' => $fieldId]);
        if (!$row) return null;
        // Zugriff nur, wenn der zugehörige Typ für diesen Benutzer sichtbar ist
        if (!$this->type((int)$row['type_id'])) return null;
        $row['options_list'] = $row['options'] ? (json_decode((string)$row['options'], true) ?: []) : [];
        return $row;
    }

    /**
     * Feldeigenschaften ändern. Der Feldtyp selbst bleibt fest – ein
     * Wechsel etwa von "Zahl" auf "Auswahl" würde vorhandene Werte in
     * der falschen Spalte stehen lassen (value_num vs. value_key).
     */
    public function updateField(int $fieldId, array $d): void
    {
        $f = $this->field($fieldId);
        if (!$f) throw new RuntimeException('Feld nicht gefunden.');

        $type = $this->type((int)$f['type_id']);
        if (!$type || $type['user_id'] === null) {
            throw new RuntimeException('Felder mitgelieferter Tagebücher lassen sich nicht bearbeiten.');
        }

        $label = trim((string)($d['label'] ?? ''));
        if ($label === '') throw new InvalidArgumentException('Feldbezeichnung fehlt.');

        $options = $f['ftype'] === 'choice' ? self::parseOptions((string)($d['options'] ?? '')) : null;
        $isPrimary = !empty($d['is_primary']);

        $this->db->transaction(function () use ($fieldId, $f, $label, $options, $isPrimary, $d): void {
            if ($isPrimary) {
                // Nur ein Leitwert je Tagebuch, sonst wäre unklar, welches
                // Feld die Kurve und den Timeline-Titel bestimmt.
                $this->db->run('UPDATE diary_fields SET is_primary = 0 WHERE type_id = :t',
                               [':t' => $f['type_id']]);
            }

            $this->db->run(
                'UPDATE diary_fields SET label=:l, unit=:un, options=:o, min_val=:mi, max_val=:ma,
                    is_required=:req, is_primary=:pri, hint=:h, sort_order=:so
                 WHERE id = :id',
                [
                    ':l' => mb_substr($label, 0, 96),
                    ':un' => mb_substr(trim((string)($d['unit'] ?? '')), 0, 24),
                    ':o' => $options,
                    ':mi' => VitalsRepository::num($d['min_val'] ?? null),
                    ':ma' => VitalsRepository::num($d['max_val'] ?? null),
                    ':req' => !empty($d['is_required']) ? 1 : 0,
                    ':pri' => $isPrimary ? 1 : 0,
                    ':h' => trim((string)($d['hint'] ?? '')) ?: null,
                    ':so' => (int)($d['sort_order'] ?? $f['sort_order']),
                    ':id' => $fieldId,
                ]
            );
        });
    }

    /** Blendet ein Feld aus der Erfassungsmaske aus, Altdaten bleiben sichtbar. */
    public function toggleFieldActive(int $fieldId): void
    {
        $f = $this->field($fieldId);
        if (!$f) throw new RuntimeException('Feld nicht gefunden.');
        $this->db->run('UPDATE diary_fields SET is_active = 1 - is_active WHERE id = :id', [':id' => $fieldId]);
    }

    /** Anzahl Einträge, die für dieses Feld bereits einen Wert haben. */
    public function fieldUsageCount(int $fieldId): int
    {
        return (int)$this->db->value(
            'SELECT COUNT(*) FROM diary_values
             WHERE field_id = :f AND (value_num IS NOT NULL OR value_key IS NOT NULL OR value_enc IS NOT NULL)',
            [':f' => $fieldId]
        );
    }

    public function deleteField(int $fieldId): void
    {
        $f = $this->field($fieldId);
        if (!$f) return;
        $type = $this->type((int)$f['type_id']);
        if (!$type || $type['user_id'] === null) {
            throw new RuntimeException('Felder mitgelieferter Tagebücher lassen sich nicht löschen.');
        }
        if ($this->fieldUsageCount($fieldId) > 0) {
            throw new RuntimeException(
                'Für dieses Feld liegen bereits Werte vor. Bitte stattdessen ausblenden.'
            );
        }
        $this->db->run('DELETE FROM diary_fields WHERE id = :id', [':id' => $fieldId]);
    }

    /** Auswahloptionen aus "schluessel|Beschriftung" je Zeile parsen. */
    private static function parseOptions(string $raw): string
    {
        $list = [];
        foreach (preg_split('/\R/', $raw) ?: [] as $i => $line) {
            $line = trim($line);
            if ($line === '') continue;
            $parts = explode('|', $line, 2);
            $list[] = count($parts) === 2
                ? ['k' => trim($parts[0]), 'l' => trim($parts[1])]
                : ['k' => (string)($i + 1), 'l' => $line];
        }
        if (!$list) throw new InvalidArgumentException('Für eine Auswahl braucht es Optionen.');
        return json_encode($list, JSON_UNESCAPED_UNICODE);
    }

    public static function optionLabel(array $field, ?string $key): string
    {
        if ($key === null) return '';
        foreach ($field['options_list'] ?? [] as $o) {
            if ((string)$o['k'] === $key) return (string)$o['l'];
        }
        return $key;
    }

    /** Wert eines Feldes lesbar darstellen. */
    public static function displayValue(array $v): string
    {
        $f = $v['field'];
        switch ($f['ftype']) {
            case 'bool':
                return $v['num'] ? 'ja' : 'nein';
            case 'choice':
                return self::optionLabel($f, $v['key']);
            case 'time':
                return (string)$v['key'];
            case 'scale':
                $max = $f['max_val'] === null ? null : VitalsRepository::trimNum((float)$f['max_val']);
                return VitalsRepository::trimNum((float)$v['num']) . ($max !== null ? ' / ' . $max : '');
            case 'number':
            case 'duration':
                return VitalsRepository::trimNum((float)$v['num'])
                     . ($f['unit'] !== '' ? ' ' . $f['unit'] : '');
            default:
                return (string)$v['text'];
        }
    }
}
