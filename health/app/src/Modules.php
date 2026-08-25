<?php
declare(strict_types=1);

namespace Health;

/**
 * Modulnamen an einer Stelle. Timeline, Tags und Anhänge referenzieren
 * Datensätze polymorph über (module, ref_id) – ohne Fremdschlüssel, weil
 * sonst jedes neue Modul eine Schemaänderung an drei Stellen erzwingen
 * würde. Der Preis dafür ist, dass ein Tippfehler im Modulnamen still
 * ins Leere zeigt. Diese Klasse ist die Gegenmaßnahme.
 */
final class Modules
{
    public const MEDICATION   = 'medication';
    public const FINDING      = 'finding';
    public const LAB          = 'lab';
    public const VITALS       = 'vitals';
    public const DIARY        = 'diary';
    public const DIAGNOSIS    = 'diagnosis';
    public const ALLERGY      = 'allergy';
    public const VACCINATION  = 'vaccination';
    public const APPOINTMENT  = 'appointment';
    public const CONTACT      = 'contact';
    public const ACTIVITY     = 'activity';
    public const COST         = 'cost';

    /**
     * Anzeigename, Icon und Farbe je Modul. Die Farbe zeigt sich vor
     * allem in der gestapelten Tagesübersicht der Timeline-Kachel -
     * eigene CSS-Variable je Modul (siehe app.css), damit sich helles
     * und dunkles Thema unabhängig voneinander abstimmen lassen, ohne
     * hier feste Hex-Werte zu duplizieren.
     */
    public const META = [
        self::MEDICATION  => ['label' => 'Medikation',   'icon' => 'pill',        'color' => 'var(--mod-medication)'],
        self::FINDING     => ['label' => 'Befund',       'icon' => 'file',        'color' => 'var(--mod-finding)'],
        self::LAB         => ['label' => 'Labor',        'icon' => 'flask',       'color' => 'var(--mod-lab)'],
        self::VITALS      => ['label' => 'Vitalwert',    'icon' => 'heart',       'color' => 'var(--mod-vitals)'],
        self::DIARY       => ['label' => 'Tagebuch',     'icon' => 'book',        'color' => 'var(--mod-diary)'],
        self::DIAGNOSIS   => ['label' => 'Diagnose',     'icon' => 'stethoscope', 'color' => 'var(--mod-diagnosis)'],
        self::ALLERGY     => ['label' => 'Allergie',     'icon' => 'alert',       'color' => 'var(--mod-allergy)'],
        self::VACCINATION => ['label' => 'Impfung',      'icon' => 'syringe',     'color' => 'var(--mod-vaccination)'],
        self::APPOINTMENT => ['label' => 'Termin',       'icon' => 'calendar',    'color' => 'var(--mod-appointment)'],
        self::CONTACT     => ['label' => 'Kontakt',      'icon' => 'user',        'color' => 'var(--mod-contact)'],
        self::ACTIVITY    => ['label' => 'Aktivität',    'icon' => 'run',         'color' => 'var(--mod-activity)'],
        self::COST        => ['label' => 'Kosten',       'icon' => 'euro',        'color' => 'var(--mod-cost)'],
    ];

    public static function all(): array
    {
        return array_keys(self::META);
    }

    public static function isValid(string $module): bool
    {
        return isset(self::META[$module]);
    }

    public static function label(string $module): string
    {
        return self::META[$module]['label'] ?? $module;
    }

    public static function icon(string $module): string
    {
        return self::META[$module]['icon'] ?? 'dot';
    }

    public static function color(string $module): string
    {
        return self::META[$module]['color'] ?? 'var(--muted)';
    }

    /** Wirft, wenn ein unbekannter Modulname durchgereicht wird. */
    public static function assert(string $module): string
    {
        if (!self::isValid($module)) {
            throw new \InvalidArgumentException("Unbekanntes Modul: {$module}");
        }
        return $module;
    }
}
