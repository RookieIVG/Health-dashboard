<?php
declare(strict_types=1);

namespace Health;

/**
 * Regelintervalle nach Impfplan Österreich 2025/2026 (Version 1.1,
 * Sozialministerium, Stand 10.10.2025).
 *
 * Quelle: https://www.sozialministerium.gv.at/impfplan
 *
 * Reine Orientierung für die Auffrischungs-Erinnerung dieser App –
 * ersetzt keine ärztliche Beratung. Bei Grunderkrankungen,
 * Immunsuppression, Reisemedizin, beruflicher Exposition oder anderen
 * Sonderindikationen weicht das tatsächliche Intervall oft ab; in
 * diesen Fällen zählt die individuelle ärztliche Einschätzung, nicht
 * diese Tabelle.
 *
 * Der Plan wird periodisch aktualisiert (zuletzt u. a. dTpa-Intervall
 * auf 5 Jahre vereinheitlicht). Diese Datei spiegelt den Stand zum
 * Zeitpunkt der Einbindung – bei Zweifeln gilt die jeweils aktuelle
 * Fassung unter obiger Quelle.
 */
final class VaccinationPlan
{
    public const SOURCE_LABEL = 'Impfplan Österreich 2025/26 (Sozialministerium, Stand 10.10.2025)';
    public const SOURCE_URL   = 'https://www.sozialministerium.gv.at/impfplan';

    /**
     * Zuordnung über Stichwörter im Impfstoffnamen (Kleinschreibung,
     * Teilstring-Vergleich), da der Impfstoffname als Freitext erfasst
     * wird. 'years' ist das Standardintervall; bei FSME gilt ein
     * altersabhängiges Intervall (years_under60 / years_60plus) nach
     * vollendetem 60. Lebensjahr zum Zeitpunkt dieser Impfung.
     * 'years' = null bedeutet: nach vollständiger Grundimmunisierung
     * ist keine routinemäßige Auffrischung vorgesehen.
     */
    private const RULES = [
        [
            'label' => 'FSME',
            'keys' => ['fsme', 'zecken', 'tbe', 'encepur'],
            'years_under60' => 5, 'years_60plus' => 3,
            'note' => 'Alle 5 Jahre bis zum vollendeten 60. Lebensjahr, danach alle 3 Jahre.',
        ],
        [
            'label' => 'Tetanus/Diphtherie/Pertussis',
            'keys' => ['tetanus', 'diphtherie', 'pertussis', 'keuchhusten', 'dtpa', 'boostrix'],
            'years' => 5,
            'note' => 'Alle 5 Jahre.',
        ],
        [
            'label' => 'Polio',
            'keys' => ['polio', 'kinderlähmung'],
            'years' => 10,
            'note' => 'Alle 10 Jahre bzw. bei besonderer Indikation.',
        ],
        [
            'label' => 'Grippe/Influenza',
            'keys' => ['grippe', 'influenza'],
            'years' => 1,
            'note' => 'Jährlich, saisonal.',
        ],
        [
            'label' => 'COVID-19',
            'keys' => ['covid'],
            'years' => 1,
            'note' => 'Aktuell saisonal empfohlen, insbesondere für Risikogruppen.',
        ],
        [
            'label' => 'Hepatitis A',
            'keys' => ['hepatitis a'],
            'years' => null,
            'note' => 'Nach vollständiger Grundimmunisierung in der Regel keine Auffrischung nötig.',
        ],
        [
            'label' => 'Hepatitis B',
            'keys' => ['hepatitis b'],
            'years' => null,
            'note' => 'Nach vollständiger Grundimmunisierung in der Regel keine Auffrischung nötig.',
        ],
        [
            'label' => 'MMR (Masern/Mumps/Röteln)',
            'keys' => ['mmr', 'masern', 'mumps', 'röteln'],
            'years' => null,
            'note' => 'Nach 2 Dosen in der Regel keine Auffrischung nötig.',
        ],
        [
            'label' => 'HPV',
            'keys' => ['hpv', 'papillomav'],
            'years' => null,
            'note' => 'Keine Auffrischung vorgesehen.',
        ],
        [
            'label' => 'Varizellen',
            'keys' => ['varizellen', 'windpocken'],
            'years' => null,
            'note' => 'Nach 2 Dosen in der Regel keine Auffrischung nötig.',
        ],
        [
            'label' => 'Pneumokokken',
            'keys' => ['pneumokokken'],
            'years' => null,
            'note' => 'Intervall je nach Alter, Impfstoff und Risikogruppe unterschiedlich – bitte ärztlich klären.',
        ],
        [
            'label' => 'Meningokokken',
            'keys' => ['meningokokken'],
            'years' => null,
            'note' => 'Meist Einzelimpfung; Auffrischung nur bei fortbestehendem Risiko.',
        ],
        [
            'label' => 'Herpes Zoster (Gürtelrose)',
            'keys' => ['zoster', 'gürtelrose'],
            'years' => null,
            'note' => 'Nach Grundimmunisierung aktuell keine routinemäßige Auffrischung vorgesehen.',
        ],
        [
            'label' => 'Gelbfieber',
            'keys' => ['gelbfieber', 'yellow fever'],
            'years' => null,
            'note' => 'Nach WHO-Empfehlung 2016 in der Regel lebenslang gültig, keine Auffrischung nötig.',
        ],
    ];

    /** Passende Regel zu einem (frei eingegebenen) Impfstoffnamen, falls bekannt. */
    public static function find(string $vaccineName): ?array
    {
        $n = mb_strtolower($vaccineName);
        foreach (self::RULES as $rule) {
            foreach ($rule['keys'] as $k) {
                if (str_contains($n, $k)) return $rule;
            }
        }
        return null;
    }

    /**
     * Schlägt ein Auffrischungsdatum vor, sofern der Impfplan ein
     * Regelintervall für diesen Impfstoff kennt.
     *
     * @return array{date: ?string, note: string}|null  null = unbekannter Impfstoff
     */
    public static function suggestNextDue(string $vaccineName, string $givenDate, ?string $birthdate): ?array
    {
        $rule = self::find($vaccineName);
        if ($rule === null) return null;

        $years = $rule['years'] ?? null;
        if (isset($rule['years_under60'])) {
            $age = $birthdate ? self::completedYears($birthdate, $givenDate) : null;
            $years = ($age !== null && $age >= 60) ? $rule['years_60plus'] : $rule['years_under60'];
        }
        if ($years === null) {
            return ['date' => null, 'note' => $rule['note']];
        }

        $date = (new \DateTimeImmutable($givenDate))
            ->modify('+' . (int)round($years * 12) . ' months')
            ->format('Y-m-d');
        return ['date' => $date, 'note' => $rule['note']];
    }

    /** Vollendete Lebensjahre zu einem Stichtag – "vollendetes 60. Lebensjahr". */
    private static function completedYears(string $birthdate, string $atDate): int
    {
        return (new \DateTimeImmutable($birthdate))->diff(new \DateTimeImmutable($atDate))->y;
    }

    /** Für eine Übersichtstabelle in der Oberfläche. */
    public static function overview(): array
    {
        return self::RULES;
    }
}
