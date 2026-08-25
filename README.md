# Gesundheitsdashboard

Persönliches, selbst gehostetes Gesundheitsdashboard. PHP 8.1+ / MySQL,
ohne Composer-Abhängigkeiten und ohne Node/Build-Schritt – läuft damit
unverändert auf Shared Hosting (z.B. World4You).

## Grundlage

- Benutzerverwaltung inkl. Admin-Oberfläche (anlegen, sperren, 2FA zurücksetzen)
- Login mit Passwort (Argon2id) + TOTP-Zweitfaktor + Wiederherstellungscodes,
  optional "Angemeldet bleiben" über vertraute Geräte
- Envelope Encryption (AES-256-GCM) mit Master-Key und eigenem DEK pro Benutzer
- Blind Indexes für Gleichheitssuche auf verschlüsselten Spalten
- Session-Härtung, CSRF-Schutz, CSP, HSTS, HTTPS-Zwang
- Rate Limiting getrennt nach Benutzer und IP
- Audit-Log mit verschlüsselten Details
- `Repository` als gemeinsame Basis aller Module (enc/dec, Ownership,
  Aufräumen), `TimelineService` (zentrale, idempotente Zeitachse),
  `TagService` (modulübergreifende Tags über Blind Index),
  `AttachmentService` + `FileCrypto` (verschlüsselte Dateiablage, blockweise)

Hintergründe dazu: `docs/ARCHITEKTUR.md`, `docs/STUFE2.md`.

## Oberfläche

- Gemeinsames Seitengerüst über `Health\View`
- Hell / Dunkel / Automatisch, ohne Aufblitzen beim Laden
- Mobil nutzbar: Klappmenü, Karten-Tabellen, 44-px-Tippziele
- Serverseitig erzeugtes SVG für sämtliche Diagramme (kein JS-Framework,
  keine externe Bibliothek – vermeidet sowohl eine Lücke in der
  Content-Security-Policy als auch einen Hinweis an Dritte, dass hier ein
  Gesundheitsdashboard betrieben wird)
- Feste Farbe je Modul, durchgängig in Menü, Übersicht und Modulseiten

Details: `docs/UI.md`.

## Module

Medikation (inkl. Einnahmeplan, Bestand, Push-Erinnerungen), Befunde, Labor
(inkl. kumulativer Verlaufsansicht), Vitalwerte, Tagebücher (Stuhl,
Ernährung, Psyche, Schlaf, Schmerz – frei um eigene Tagebücher erweiterbar,
mit Musteranalyse zwischen zwei Tagebüchern über einen wählbaren Zeitraum),
Diagnosen, Allergien/Unverträglichkeiten, Impfpass, Termine (inkl.
ICS-Export und Erinnerungen per Mail/Push), Kosten/Erstattungen, Kontakte
(Ärzte, Kliniken, Einrichtungen), modulübergreifende Zeitleiste mit
gestapelter Tagesübersicht.

## Verzeichnisse

    app/config/   Konfiguration (config.php NIEMALS ins Git)
    app/keys/     Schlüsseldateien – NIEMALS ins Git, NIEMALS ins Backup neben die Datenbank
    app/src/      Anwendungsklassen
    app/storage/  Sessions und verschlüsselte Dateien
    bin/          Cron-Skripte und Einrichtung (bin/setup.php)
    db/           schema.sql – nur zur Einrichtung gebraucht
    docs/         Installation und Architektur
    public/       Webroot
    public/assets app.css (zwei Farbschemata), push.js, ui.js

## Schnellstart

    cp app/config/config.example.php app/config/config.php   # anpassen
    mysql -u user -p datenbank < db/schema.sql
    php bin/setup.php keys
    php bin/setup.php check
    php bin/setup.php admin thomas

Ohne SSH-Zugang: `public/web-setup.php` bietet denselben Ablauf über den
Browser.

Vollständige Anleitung inkl. Cron-Einrichtung: `docs/INSTALL.md`.
