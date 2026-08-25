<?php
declare(strict_types=1);

namespace Health;

/**
 * Zentrales Modulverzeichnis.
 *
 * Bezeichnung, Adresse und Reihenfolge stehen ab hier an einer Stelle –
 * Navigation und Startseite lasen das vorher getrennt, mit dem Ergebnis
 * zweier Namen für dasselbe Modul ("Vitalwert" gegen "Vitalwerte").
 *
 * Die persönliche Reihenfolge liegt in user_settings statt in einer
 * eigenen Tabelle: es ist eine kurze Liste von Schlüsseln, und ein
 * Schema plus Migration wäre dafür unverhältnismäßig.
 */
final class ModuleRegistry
{
    private const SETTING_KEY = 'module_order';

    /**
     * nav  – erscheint in der Kopfzeile
     * card – erscheint als Kachel auf der Startseite
     * ready– bereits gebaut
     */
    public const DEFS = [
        'diary'      => ['label' => 'Tagebücher', 'url' => '/diary.php',     'nav' => true,  'card' => true,  'ready' => true],
        'diagnoses'  => ['label' => 'Diagnosen',  'url' => '/diagnoses.php', 'nav' => true,  'card' => true,  'ready' => true],
        'findings'   => ['label' => 'Befunde',    'url' => '/findings.php',  'nav' => true,  'card' => true,  'ready' => true],
        'vitals'     => ['label' => 'Vitalwerte', 'url' => '/vitals.php',    'nav' => true,  'card' => true,  'ready' => true],
        'timeline'   => ['label' => 'Timeline',   'url' => '/timeline.php',  'nav' => true,  'card' => true,  'ready' => true],

        'medication' => ['label' => 'Medikation', 'url' => '/medications.php', 'nav' => true, 'card' => true, 'ready' => true],
        'lab'        => ['label' => 'Labor', 'url' => '/lab.php', 'nav' => true, 'card' => true, 'ready' => true],
        'appointments'=> ['label' => 'Termine', 'url' => '/appointments.php', 'nav' => true, 'card' => true, 'ready' => true],
        'vaccinations'=> ['label' => 'Impfpass', 'url' => '/vaccinations.php', 'nav' => true, 'card' => true, 'ready' => true],
        'allergies'  => ['label' => 'Allergien', 'url' => '/allergies.php', 'nav' => true, 'card' => true, 'ready' => true],
        'activity'   => ['label' => 'Aktivität', 'url' => null, 'nav' => false, 'card' => true, 'ready' => false],
        'costs'      => ['label' => 'Kosten', 'url' => '/costs.php', 'nav' => true, 'card' => true, 'ready' => true],
        'contacts'   => ['label' => 'Kontakte', 'url' => '/contacts.php', 'nav' => true, 'card' => true, 'ready' => true],
    ];

    /** Feste Punkte außerhalb der Sortierung. */
    public const FIXED_NAV = [
        ['key' => 'dashboard', 'url' => '/index.php', 'label' => 'Übersicht'],
    ];

    public const EXTERNAL_NAV = [
        ['key' => 'elga', 'url' => 'https://secure.gesundheit.gv.at/', 'label' => 'ELGA'],
    ];

    public function __construct(private App $app) {}

    // =================================================================
    // Reihenfolge und Sichtbarkeit
    // =================================================================

    /** @return array{order: string[], hidden: string[]} */
    public function prefs(?int $userId = null): array
    {
        $userId ??= $this->app->auth->userId();

        $raw = $this->app->db->value(
            'SELECT value_enc FROM user_settings WHERE user_id = :u AND skey = :k',
            [':u' => $userId, ':k' => self::SETTING_KEY]
        );

        $stored = ['order' => [], 'hidden' => []];
        if ($raw !== null) {
            $json = $this->app->crypto->dec($this->app->dekFor($userId), $raw, 'settings.' . self::SETTING_KEY);
            $d = json_decode((string)$json, true);
            if (is_array($d)) {
                $stored['order']  = array_values(array_filter((array)($d['order'] ?? []), 'is_string'));
                $stored['hidden'] = array_values(array_filter((array)($d['hidden'] ?? []), 'is_string'));
            }
        }

        // Gespeicherte Reihenfolge zuerst, danach alles Neue –
        // sonst verschwinden später ergänzte Module aus der Ansicht.
        $known = array_keys(self::DEFS);
        $order = array_values(array_filter($stored['order'], fn($k) => in_array($k, $known, true)));
        foreach ($known as $k) {
            if (!in_array($k, $order, true)) $order[] = $k;
        }

        return ['order' => $order, 'hidden' => array_intersect($stored['hidden'], $known)];
    }

    public function saveOrder(array $order, array $hidden = [], ?int $userId = null): void
    {
        $userId ??= $this->app->auth->userId();
        $known = array_keys(self::DEFS);

        $payload = json_encode([
            'order'  => array_values(array_filter($order, fn($k) => in_array($k, $known, true))),
            'hidden' => array_values(array_filter($hidden, fn($k) => in_array($k, $known, true))),
        ], JSON_UNESCAPED_UNICODE);

        $enc = $this->app->crypto->enc($this->app->dekFor($userId), $payload, 'settings.' . self::SETTING_KEY);

        $st = $this->app->db->pdo()->prepare(
            'INSERT INTO user_settings (user_id, skey, value_enc) VALUES (:u, :k, :v)
             ON DUPLICATE KEY UPDATE value_enc = VALUES(value_enc)'
        );
        $st->bindValue(':u', $userId, \PDO::PARAM_INT);
        $st->bindValue(':k', self::SETTING_KEY);
        $st->bindValue(':v', $enc, \PDO::PARAM_LOB);
        $st->execute();
    }

    /** Ein Modul um eine Position verschieben. */
    public function move(string $key, int $direction): void
    {
        $p = $this->prefs();
        $i = array_search($key, $p['order'], true);
        if ($i === false) return;

        $j = $i + ($direction < 0 ? -1 : 1);
        if ($j < 0 || $j >= count($p['order'])) return;

        [$p['order'][$i], $p['order'][$j]] = [$p['order'][$j], $p['order'][$i]];
        $this->saveOrder($p['order'], $p['hidden']);
    }

    public function toggleHidden(string $key): void
    {
        $p = $this->prefs();
        $hidden = $p['hidden'];
        $hidden = in_array($key, $hidden, true)
            ? array_values(array_diff($hidden, [$key]))
            : array_merge($hidden, [$key]);
        $this->saveOrder($p['order'], $hidden);
    }

    // =================================================================
    // Ausgabe
    // =================================================================

    /** Navigationspunkte in persönlicher Reihenfolge – dieselbe wie auf der Startseite. */
    public function navItems(): array
    {
        $p    = $this->prefs();
        $out  = self::FIXED_NAV;

        foreach ($p['order'] as $key) {
            $d = self::DEFS[$key];
            if (empty($d['nav']) || !$d['ready'] || in_array($key, $p['hidden'], true)) continue;
            $out[] = ['key' => $key, 'url' => $d['url'], 'label' => $d['label']];
        }

        foreach (self::EXTERNAL_NAV as $e) {
            $out[] = $e + ['external' => true];
        }
        return $out;
    }

    /** Kacheln für die Startseite, samt Zusammenfassung. */
    /**
     * DEFS-Schlüssel (Seiten-/Navigationsslug, oft Mehrzahl, z.B.
     * "findings") sind nicht immer identisch mit den Modules::-
     * Schlüsseln (Datenbank-/Timeline-Modulname, Einzahl, z.B.
     * "finding"). Für alles, was hier fehlt, sind beide Schreibweisen
     * gleich.
     */
    private const DEF_TO_MODULE = [
        'diagnoses'    => Modules::DIAGNOSIS,
        'findings'     => Modules::FINDING,
        'appointments' => Modules::APPOINTMENT,
        'vaccinations' => Modules::VACCINATION,
        'allergies'    => Modules::ALLERGY,
        'costs'        => Modules::COST,
        'contacts'     => Modules::CONTACT,
    ];

    public static function colorForDefKey(string $defKey): ?string
    {
        $moduleKey = self::DEF_TO_MODULE[$defKey] ?? (Modules::isValid($defKey) ? $defKey : null);
        return $moduleKey ? Modules::color($moduleKey) : null;
    }

    public function cards(int $timelineDays = 14): array
    {
        $p   = $this->prefs();
        $out = [];

        foreach ($p['order'] as $key) {
            $d = self::DEFS[$key];
            if (empty($d['card']) || in_array($key, $p['hidden'], true)) continue;

            $row = [
                'key'     => $key,
                'label'   => $d['label'],
                'url'     => $d['ready'] ? $d['url'] : null,
                'ready'   => (bool)$d['ready'],
                'color'   => self::colorForDefKey($key),
                // Die Timeline-Kachel bekommt eine grafische Auswertung
                // statt der Zählliste - die generische summary() dafür
                // aufzurufen wäre nur unnötig verschwendete Arbeit.
                'summary' => $d['ready'] ? ($key === 'timeline' ? [] : $this->summary($key))
                                         : [['l' => 'geplant', 'v' => '']],
            ];
            if ($key === 'timeline' && $d['ready']) {
                $row['chart'] = $this->timelineChart(max(0, $timelineDays - 1));
                $row['timelineDays'] = $timelineDays;
            }
            $out[] = $row;
        }
        return $out;
    }

    // =================================================================
    // Zusammenfassungen
    // =================================================================

    /**
     * Kurzstand je Modul, als Beschriftung/Wert-Paare.
     *
     * Bewusst aufgeschlüsselt statt zusammengezählt: "4 laufend" sagt
     * nicht, worum es geht, und "12 Befunde" nicht, ob etwas fehlt.
     *
     * @return array<int, array{l: string, v: string}>
     */
    private function summary(string $key): array
    {
        try {
            return match ($key) {
                'diary'     => $this->diarySummary(),
                'diagnoses' => $this->diagnosesSummary(),
                'findings'  => $this->findingsSummary(),
                'vitals'    => $this->vitalsSummary(),
                'timeline'  => $this->timelineSummary(),
                'appointments' => $this->appointmentsSummary(),
                'allergies' => $this->allergiesSummary(),
                'lab'        => $this->labSummary(),
                'vaccinations' => $this->vaccinationsSummary(),
                'costs'      => $this->costsSummary(),
                'contacts'   => $this->contactsSummary(),
                'medication' => $this->medicationSummary(),
                default     => [],
            };
        } catch (\Throwable $e) {
            // Eine defekte Kachel darf die Startseite nicht mitnehmen
            error_log('[health] Zusammenfassung ' . $key . ': ' . $e->getMessage());
            return [];
        }
    }

    /** Je Tagebuch der letzte Eintrag. */
    private function diarySummary(): array
    {
        $repo   = new DiaryRepository($this->app);
        $counts = $repo->countsByType();

        $out = [];
        foreach ($repo->types() as $t) {
            $c = $counts[(int)$t['id']] ?? null;
            $out[] = [
                'l'   => $t['label'],
                'v'   => $c ? $this->app->local($c['last'], 'd.m.Y') : '–',
                'url' => '/diary_type.php?type=' . (int)$t['id'],
            ];
        }
        return $out ?: [['l' => 'Noch kein Tagebuch', 'v' => '']];
    }

    /** Alle laufenden Diagnosen einzeln. */
    private function diagnosesSummary(): array
    {
        $repo = new DiagnosesRepository($this->app);
        $open = $repo->open();
        if (!$open) return [['l' => 'Keine aktuelle Diagnose', 'v' => '']];

        $out = [];
        foreach (array_slice($open, 0, 10) as $d) {
            $out[] = [
                'l' => (string)$d['title'],
                'v' => $d['onset_date'] ? 'seit ' . date('d.m.Y', strtotime($d['onset_date'])) : '',
            ];
        }
        if (count($open) > 10) {
            $out[] = ['l' => 'und ' . (count($open) - 10) . ' weitere', 'v' => ''];
        }
        return $out;
    }

    /** Je Befundart der jeweils letzte Eintrag. */
    private function findingsSummary(): array
    {
        $rows = $this->app->db->all(
            'SELECT category, MAX(occurred_at) AS last, COUNT(*) AS n
             FROM findings WHERE user_id = :u AND is_archived = 0
             GROUP BY category ORDER BY last DESC',
            [':u' => $this->app->auth->userId()]
        );
        if (!$rows) return [['l' => 'Noch keine Befunde', 'v' => '']];

        $out = [];
        foreach ($rows as $r) {
            $out[] = [
                'l' => FindingsRepository::categoryLabel($r['category'])
                       . ((int)$r['n'] > 1 ? ' (' . (int)$r['n'] . ')' : ''),
                'v' => $this->app->local($r['last'], 'd.m.Y'),
            ];
        }

        $repo = new FindingsRepository($this->app);
        $due  = $repo->dueFollowUps(30);
        if ($due) {
            $overdue = array_filter($due, fn($d) => $d['follow_up_at'] < date('Y-m-d'));
            $out[] = [
                'l' => $overdue ? 'Wiedervorlage überfällig' : 'Wiedervorlage offen',
                'v' => date('d.m.Y', strtotime($due[0]['follow_up_at'])),
            ];
        }
        return $out;
    }

    /** Je Messgröße der letzte Wert. */
    private function vitalsSummary(): array
    {
        $repo = new VitalsRepository($this->app);
        $ov   = $repo->overview();
        if (!$ov) return [['l' => 'Noch keine Messwerte', 'v' => '']];

        $out = [];
        foreach ($ov as $o) {
            $m = $o['metric'];
            $out[] = [
                'l' => $m['label'] . ' ' . VitalsRepository::formatValue(
                        $m, (float)$o['last']['value'],
                        $o['last']['value2'] === null ? null : (float)$o['last']['value2']
                       ),
                'v' => $this->app->local($o['last']['measured_at'], 'd.m.Y'),
            ];
        }
        return $out;
    }

    /** Die nächsten Termine mit Datum. */
    private function appointmentsSummary(): array
    {
        $repo = new AppointmentsRepository($this->app);
        $next = $repo->upcoming(5);
        if (!$next) return [['l' => 'Kein Termin geplant', 'v' => '']];

        $soon = new \DateTimeImmutable('+3 days');

        $out = [];
        foreach ($next as $a) {
            $starts = new \DateTimeImmutable($a['starts_at'] . ' UTC');
            $out[] = [
                'l' => (string)$a['title'],
                'v' => $this->app->local($a['starts_at'], 'd.m.Y'),
                'url' => '/appointment.php?id=' . (int)$a['id'],
                'severity' => $starts <= $soon ? 2 : 0,
            ];
        }
        return $out;
    }

    private function allergiesSummary(): array
    {
        $repo = new AllergiesRepository($this->app);
        $list = $repo->listAll(false);
        if (!$list) return [['l' => 'Keine Allergien bekannt', 'v' => '']];

        $out = [];
        foreach (array_slice($list, 0, 8) as $a) {
            $out[] = [
                'l' => (string)$a['substance'],
                'v' => AllergiesRepository::SEVERITY[(int)$a['severity']],
            ];
        }
        if (count($list) > 8) $out[] = ['l' => 'und ' . (count($list) - 8) . ' weitere', 'v' => ''];
        return $out;
    }

    private function labSummary(): array
    {
        $repo = new LabRepository($this->app);
        $visits = $repo->visits(3);
        if (!$visits) return [['l' => 'Noch keine Befunde', 'v' => '']];

        $out = [];
        foreach ($visits as $v) {
            $out[] = ['l' => $v['institution'] ?: 'Laborbefund', 'v' => $this->app->local($v['visit_date'] . ' 00:00:00', 'd.m.Y')];
        }
        return $out;
    }

    /** Nur die letzte Impfung je Impfstoff, mit Auffrischungsdatum. */
    private function vaccinationsSummary(): array
    {
        $repo   = new VaccinationsRepository($this->app);
        $latest = $repo->byVaccine();
        if (!$latest) return [['l' => 'Noch keine Impfungen', 'v' => '']];

        usort($latest, fn($a, $b) => $b['given_date'] <=> $a['given_date']);
        $today    = date('Y-m-d');
        $soonDate = date('Y-m-d', strtotime('+30 days'));

        $out = [];
        foreach (array_slice($latest, 0, 8) as $v) {
            $value = 'zuletzt ' . date('d.m.Y', strtotime($v['given_date']));
            $sev = 0;
            if ($v['next_due_date']) {
                $overdue = $v['next_due_date'] < $today;
                // Auch ohne bereits verstrichenen Termin rot markieren,
                // wenn die Auffrischung innerhalb der nächsten 30 Tage
                // ansteht – nicht erst, wenn sie schon überfällig ist.
                $dueSoon = !$overdue && $v['next_due_date'] <= $soonDate;
                $value .= ' · ' . ($overdue ? 'überfällig seit ' : 'Auffrischung ')
                        . date('d.m.Y', strtotime($v['next_due_date']));
                $sev = ($overdue || $dueSoon) ? 2 : 0;
            }
            $out[] = ['l' => (string)$v['vaccine'], 'v' => $value, 'severity' => $sev];
        }
        if (count($latest) > 8) $out[] = ['l' => 'und ' . (count($latest) - 8) . ' weitere', 'v' => ''];
        return $out;
    }

    private function costsSummary(): array
    {
        $repo = new CostsRepository($this->app);
        $years = $repo->years();
        if (!$years) return [['l' => 'Noch keine Ausgaben', 'v' => '']];

        $s = $repo->yearSummary((string)$years[0]);
        return [
            ['l' => $years[0] . ' Ausgaben', 'v' => number_format($s['total'], 2, ',', '.') . ' €'],
            ['l' => 'Eigenanteil', 'v' => number_format($s['out_of_pocket'], 2, ',', '.') . ' €'],
        ];
    }

    private function contactsSummary(): array
    {
        $repo = new ContactsRepository($this->app);
        $list = $repo->listAll(true);
        if (!$list) return [['l' => 'Noch kein Kontakt', 'v' => '']];

        $out = [];
        foreach (array_slice($list, 0, 8) as $c) {
            $out[] = [
                'l' => (string)$c['name'],
                'v' => ContactsRepository::KINDS[$c['kind']] ?? '',
                'url' => '/contacts.php?edit=' . (int)$c['id'],
            ];
        }
        if (count($list) > 8) $out[] = ['l' => 'und ' . (count($list) - 8) . ' weitere', 'v' => ''];
        return $out;
    }

    /**
     * Statt eines reinen Status zeigt jede Zeile die nächste Fälligkeit
     * (rot, wenn überfällig) und – bei planbasierten, aktiven
     * Präparaten – ein Häkchen-Symbol zum direkten Abzeichnen, ohne
     * erst die Medikationsseite öffnen zu müssen.
     */
    private function medicationSummary(): array
    {
        $repo = new MedicationRepository($this->app);
        $list = $repo->listAll(false);
        if (!$list) return [['l' => 'Keine laufende Medikation', 'v' => '']];

        $now = new \DateTimeImmutable('now', new \DateTimeZone($this->app->config['app']['timezone']));

        $out = [];
        foreach (array_slice($list, 0, 8) as $m) {
            $label = $m['name'] . ($m['strength'] ? ' ' . $m['strength'] : '');

            $url = '/medication.php?id=' . (int)$m['id'];

            if ($m['is_prn']) {
                $out[] = ['l' => $label, 'v' => 'bei Bedarf', 'url' => $url];
                continue;
            }
            if ($m['status'] !== 'active') {
                $out[] = ['l' => $label, 'v' => MedicationRepository::STATUS[$m['status']], 'url' => $url];
                continue;
            }

            $due = $repo->nextDueForMedication((int)$m['id']);
            if (!$due) {
                $out[] = ['l' => $label, 'v' => 'kein Plan hinterlegt', 'url' => $url];
                continue;
            }

            $overdue = $due['at'] < $now;
            $sameDay = $due['at']->format('Y-m-d') === $now->format('Y-m-d');
            $value = ($overdue ? 'überfällig seit ' : 'nächste Gabe ')
                   . $due['intake_time'] . ' Uhr'
                   . ' ' . ($sameDay ? 'heute' : $due['at']->format('d.m.Y'));

            $out[] = [
                'l' => $label, 'v' => $value, 'url' => $url, 'severity' => $overdue ? 2 : 0,
                // 'active' steuert, ob der Haken auf der Startseite anklickbar
                // ist. Ist die nächste Gabe nicht heute, würde ein Klick
                // trotzdem mit dem heutigen Zeitstempel buchen – dann lieber
                // ausgegraut zeigen, statt eine falsch datierte Einnahme zu
                // riskieren.
                'action' => ['type' => 'take', 'medication_id' => (int)$m['id'],
                             'schedule_id' => $due['schedule_id'], 'active' => $sameDay],
            ];
        }
        if (count($list) > 8) $out[] = ['l' => 'und ' . (count($list) - 8) . ' weitere', 'v' => ''];

        $ending = $repo->endingSoon(14);
        if ($ending) $out[] = ['l' => count($ending) . ' bald zu Ende', 'v' => ''];

        // Eigene Zeile je Präparat mit niedrigem Bestand, wie im
        // Warnbanner auf medications.php – nicht nur eine Zahl, damit auf
        // der Übersicht sofort erkennbar ist, was betroffen ist.
        foreach ($repo->lowStock() as $low) {
            $qty = $low['stock_quantity'] !== null
                 ? rtrim(rtrim(number_format((float)$low['stock_quantity'], 1, ',', '.'), '0'), ',') : '0';
            $out[] = [
                'l' => $low['name'] . ($low['strength'] ? ' ' . $low['strength'] : ''),
                'v' => 'noch ' . $qty . ' ' . $low['stock_unit'],
                'url' => '/medication.php?id=' . (int)$low['id'],
                'severity' => 2,
            ];
        }
        return $out;
    }

    private function timelineSummary(): array
    {
        $counts = $this->app->timeline()->countsByModule();
        if (!$counts) return [['l' => 'Noch keine Ereignisse', 'v' => '']];

        $out = [];
        foreach ($counts as $mod => $n) {
            $out[] = ['l' => Modules::label($mod), 'v' => (string)$n];
        }
        return $out;
    }

    /**
     * Gestapelte Balken je Tag über die letzten 14 Tage, ein Segment
     * pro Modul mit Einträgen an dem Tag. Ergänzt (ersetzt nicht) die
     * reine Zählliste, die es weiterhin auf der vollen Timeline-Seite
     * selbst gibt.
     */
    private function timelineChart(int $daysBack = 13): array
    {
        $tz    = new \DateTimeZone($this->app->config['app']['timezone']);
        $today = new \DateTimeImmutable('now', $tz);

        $fromUtc = $today->modify("-{$daysBack} days")->setTime(0, 0, 0)
                         ->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        $toUtc   = $today->setTime(23, 59, 59)
                         ->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');

        $events = $this->app->timeline()->range($fromUtc, $toUtc, null, 500);
        $byDay  = $this->app->timeline()->groupedByDay($events);

        $days = [];
        $usedModules = [];
        for ($i = $daysBack; $i >= 0; $i--) {
            $date      = $today->modify("-{$i} days")->format('Y-m-d');
            $dayEvents = $byDay[$date] ?? [];

            $byModule = [];
            foreach ($dayEvents as $e) {
                $byModule[$e['module']] = ($byModule[$e['module']] ?? 0) + 1;
                $usedModules[$e['module']] = true;
            }
            $days[] = ['date' => $date, 'byModule' => $byModule];
        }

        $total = count($events);
        $legend = [];
        foreach (array_keys($usedModules) as $m) {
            $legend[] = ['label' => Modules::label($m), 'color' => Modules::color($m)];
        }
        usort($legend, fn($a, $b) => $a['label'] <=> $b['label']);

        return [
            'svg'     => Chart::dayBars($days),
            'legend'  => $legend,
            'caption' => $total > 0
                ? $total . ' Ereignis' . ($total === 1 ? '' : 'se') . ' in den letzten ' . ($daysBack + 1) . ' Tagen'
                : 'Keine Ereignisse in den letzten ' . ($daysBack + 1) . ' Tagen',
        ];
    }
}
