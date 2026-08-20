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

    /** Anzeigename und Icon je Modul. */
    public const META = [
        self::MEDICATION  => ['label' => 'Medikation',   'icon' => 'pill'],
        self::FINDING     => ['label' => 'Befund',       'icon' => 'file'],
        self::LAB         => ['label' => 'Labor',        'icon' => 'flask'],
        self::VITALS      => ['label' => 'Vitalwert',    'icon' => 'heart'],
        self::DIARY       => ['label' => 'Tagebuch',     'icon' => 'book'],
        self::DIAGNOSIS   => ['label' => 'Diagnose',     'icon' => 'stethoscope'],
        self::ALLERGY     => ['label' => 'Allergie',     'icon' => 'alert'],
        self::VACCINATION => ['label' => 'Impfung',      'icon' => 'syringe'],
        self::APPOINTMENT => ['label' => 'Termin',       'icon' => 'calendar'],
        self::CONTACT     => ['label' => 'Kontakt',      'icon' => 'user'],
        self::ACTIVITY    => ['label' => 'Aktivität',    'icon' => 'run'],
        self::COST        => ['label' => 'Kosten',       'icon' => 'euro'],
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

    /** Wirft, wenn ein unbekannter Modulname durchgereicht wird. */
    public static function assert(string $module): string
    {
        if (!self::isValid($module)) {
            throw new \InvalidArgumentException("Unbekanntes Modul: {$module}");
        }
        return $module;
    }
}
