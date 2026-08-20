# Health-dashboard

# Installation

## 1. Verzeichnisstruktur

Entscheidend ist, dass **nur `public/` im Webroot liegt**. Bei World4You ist der
Webroot je nach Paket `/html` oder `/httpdocs`.

```
/kunde/
├── app/                  <-- NICHT im Webroot
│   ├── config/config.php
│   ├── keys/master.key   (chmod 400)
│   ├── keys/index.key
│   ├── src/
│   └── storage/{sessions,files}/   (beschreibbar)
├── bin/
├── db/
└── html/                 <-- Webroot (Inhalt von public/)
    ├── index.php
    ├── login.php
    ├── .htaccess
    └── assets/
```

Wenn das Paket keinen Ordner oberhalb des Webroots erlaubt, lege `app/` daneben
und verlasse dich auf die mitgelieferten `.htaccess` mit `Require all denied` –
das ist die schwächere Variante, weil sie bei einer Server-Fehlkonfiguration
wirkungslos ist. **Vorher beim Support nachfragen**, ob ein Verzeichnis
außerhalb des Document Roots möglich ist; bei World4You geht das in der Regel.

Die Pfade in `config.php` unter `paths` entsprechend anpassen.

## 2. Datenbank

Im World4You-Kundencenter eine MySQL-Datenbank anlegen, dann:

```sql
SOURCE db/01_core_schema.sql;
```

oder den Inhalt über phpMyAdmin einspielen.

## 3. Konfiguration

```bash
cp app/config/config.example.php app/config/config.php
```

DB-Zugangsdaten, `base_url` und Pfade eintragen.

## 4. Schlüssel erzeugen

```bash
php bin/setup.php keys
```

Erzeugt `master.key.php` und `index.key.php`.

> **Das Backup dieser beiden Dateien gehört an einen anderen Ort als das
> Datenbank-Backup.** Liegen beide im selben Ordner, ist die Verschlüsselung
> wertlos – wer den Ordner hat, hat alles. Umgekehrt: ohne `master.key` sind
> die Daten endgültig verloren, auch von dir.

## 5. Prüfen und Admin anlegen

```bash
php bin/setup.php check
php bin/setup.php admin thomas
```

Danach anmelden und unter **Sicherheit** 2FA einrichten. Die
Wiederherstellungscodes werden nur einmal angezeigt.

## 6. Cronjob

```
15 3 * * *  php /kunde/bin/setup.php purge
```

Räumt Login-Versuche, abgelaufene Sessions, verbrauchte TOTP-Zeitschritte und
Tokens auf.

## Ohne Kommandozeile

Falls kein SSH verfügbar ist: `bin/setup.php` temporär in den Webroot kopieren,
die CLI-Prüfung am Dateianfang auskommentieren, die drei Befehle über den
Browser aufrufen (`?cmd=keys` müsste man dann noch ergänzen) und die Datei
**sofort wieder löschen**. Sauberer ist, den SSH-Zugang freischalten zu lassen.

## HTTPS

World4You liefert Let's-Encrypt-Zertifikate im Kundencenter. Nach der
Aktivierung greifen `force_https` in der Konfiguration und der Redirect in der
`.htaccess`. HSTS wird mit einem Jahr Laufzeit gesetzt – erst einschalten, wenn
das Zertifikat sicher steht, sonst sperrst du dich für die Dauer aus.

## Anforderungen

- PHP 8.1+ mit `openssl`, `pdo_mysql`, `mbstring`
- Argon2id (sonst automatischer Fallback auf bcrypt cost 12)
- MySQL 8.0 / MariaDB 10.5+


# Architektur der Basis

## Verschlüsselungsmodell

```
app/keys/master.key.php  (32 Byte base64, chmod 400)
        │
        │  AES-256-GCM, AAD "dek"
        ▼
users.dek_wrapped    (ein DEK pro Benutzer)
        │
        │  AES-256-GCM, AAD "<tabelle>.<feld>"
        ▼
Nutzdaten in *_enc-Spalten
```

**Warum diese Variante und nicht passwortabgeleitete Schlüssel:**
Ein aus dem Passwort abgeleiteter Schlüssel wäre stärker gegen einen
kompromittierten Server, macht aber Passwort-Reset unmöglich (Daten weg) und
schließt jeden Hintergrundprozess aus – CalDAV-Sync, Erinnerungen, Auswertungen
liefen nicht mehr. Der Master-Key-Ansatz schützt zuverlässig gegen das
realistische Szenario auf Shared Hosting: SQL-Injection, gestohlenes
DB-Backup, neugieriger Datenbankadministrator. Gegen vollständigen
Dateisystemzugriff schützt er nicht – das kann bei PHP grundsätzlich keine
Lösung, weil der Code den Schlüssel lesen können muss.

**Konsequenz für Backups:** DB-Dump und `app/keys/` müssen getrennt gelagert
werden, sonst ist der Schutz aufgehoben.

**Konsequenz für Passwortwechsel:** Der DEK hängt nicht am Passwort. Ein
Passwortwechsel erfordert deshalb keine Neuverschlüsselung der Daten.

### AAD (Additional Authenticated Data)

Jedes Feld wird mit einem Kontextstring verschlüsselt, z. B. `"findings.title"`.
GCM prüft diesen mit. Damit lässt sich ein Ciphertext nicht von einem Feld in
ein anderes kopieren – etwa eine fremde Notiz in das eigene Diagnosefeld.

### Was verschlüsselt wird – und was nicht

| verschlüsselt | Klartext |
|---|---|
| Freitexte, Notizen, Befundtexte | Zeitstempel |
| Namen, E-Mail, Diagnosetexte | numerische Messwerte |
| Dateinamen und Dateiinhalte | Fremdschlüssel, Kategorien, Skalenwerte |

Der Grund ist banal, aber bestimmend: über eine verschlüsselte Spalte kann
MySQL nicht sortieren, gruppieren oder mit `LIKE` suchen. Würde man Messwerte
verschlüsseln, wäre jede Verlaufskurve und jede Korrelationsansicht nur noch
in PHP über alle Datensätze hinweg berechenbar. Der Informationsgewinn eines
Angreifers aus „Blutdruck 128/82 am 14.03." ohne zugehörige Person und Kontext
ist gering – der Preis wäre hoch.

### Blind Index

Für Gleichheitssuche auf verschlüsselten Spalten (E-Mail, Tag-Namen):
`HMAC-SHA256(kontext || wert, index.key)`, auf 16 Byte gekürzt. Erlaubt
`WHERE email_bidx = ?`, aber keine Teilstringsuche und keine Sortierung.

## Authentifizierung

1. **Passwort** – Argon2id (64 MB, t=4), Fallback bcrypt cost 12.
   Timing-Angleichung bei unbekanntem Benutzer über einen Dummy-Hash.
2. **TOTP** – RFC 6238, SHA1/6 Stellen/30 s, ±1 Zeitschritt Toleranz.
   Eigene Implementierung ohne Composer-Abhängigkeit, gegen die
   RFC-Testvektoren verifiziert.
3. **Replay-Schutz** – jeder verbrauchte Zeitschritt landet in
   `totp_used_codes`; derselbe Code gilt nicht zweimal.
4. **Wiederherstellungscodes** – 10 Stück, bcrypt-gehasht, Einmalverwendung.

### Sitzungen

PHP-Sessions (Speicherort außerhalb des Webroots, sonst liest sie auf Shared
Hosting im Zweifel der Nachbar) plus eine Spiegeltabelle `user_sessions`. Die
Tabelle macht dreierlei möglich: Übersicht über angemeldete Geräte, gezielter
Widerruf einzelner Sitzungen, und das automatische Abmelden aller anderen
Geräte nach einem Passwortwechsel.

Zwei Zeitgrenzen: Inaktivität (1 h) und absolute Laufzeit (12 h).

**Der DEK wird bewusst nicht in der Session gespeichert.** Er wird pro Request
aus der Datenbank entpackt. Das kostet eine Entschlüsselung, verhindert aber,
dass Klartextschlüssel in Session-Dateien auf der Platte liegen.

### Rate Limiting

`login_attempts` zählt Fehlversuche je Benutzername **und** je IP getrennt –
sonst sperrt ein Angreifer durch gezielte Fehlversuche fremde Konten aus
(Account Lockout DoS). Standard: 5 Versuche pro 15 Minuten.

## Querschnittstabellen

**`timeline_events`** ist die Achse des Systems. Jedes Modul schreibt beim
Anlegen und Ändern eines Datensatzes einen Eintrag mit `module`, `ref_id`,
`occurred_at` und verschlüsseltem Titel. Damit ist „was war rund um den 14.
März?" eine einzige indizierte Abfrage statt zwölf Joins. Der Unique-Key auf
`(module, ref_id, event_type)` macht das Schreiben idempotent.

**`tags` / `taggables`** und **`attachments`** sind polymorph über
`(module, ref_id)`. Bewusst ohne Fremdschlüssel auf die Modultabellen – dafür
braucht jedes neue Modul beim Löschen eine Aufräumroutine. Der Tausch lohnt
sich, weil sonst jede Moduleinführung eine Schemaänderung an drei Stellen
erzwingen würde.

**`account_grants`** liegt schon bereit, auch wenn zunächst jeder einen eigenen
Account bekommt. Sobald ein Kind mitverwaltet werden soll oder jemand
Leserechte braucht, ist das eine Zeile statt einer Migration. Funktioniert
technisch, weil der Server den fremden DEK über den Master-Key entpacken kann.

### Ablageform der Schlüssel

Die Schlüssel liegen als PHP-Dateien:

```php
<?php return 'BASE64WERT';
```

Der Grund ist die Praxis auf Shared Hosting: Wenn `open_basedir` den Zugriff
auf Verzeichnisse oberhalb des Webroots verbietet – bei World4You ist genau das
der Fall – muss `app/` innerhalb von `web/` liegen. Dann ist die `.htaccess`
mit `Require all denied` die einzige Barriere vor dem Schlüsselmaterial. Fällt
sie aus – Serverumzug, nginx statt Apache, Tippfehler beim Deployment – liefert
der Webserver eine `.key`-Datei im Klartext aus. Eine `.php`-Datei wird
stattdessen ausgeführt und gibt nach außen nichts zurück.

Das ersetzt die `.htaccess` nicht, sondern ergänzt sie um eine Schicht, die
nicht an einer einzelnen Konfigurationszeile hängt. Das alte Format wird
weiterhin gelesen, damit bestehende Installationen nicht brechen.

## Was noch fehlt

- Passwort-Reset per E-Mail (`user_tokens` ist vorbereitet, Versand fehlt)
- Verschlüsselte Dateiablage (`attachments` steht, Upload-Handler fehlt)
- Schlüsselrotation (`dek_version` ist vorgesehen, Routine fehlt)
- CSP-Nonce wird gesetzt, aber noch nirgends gebraucht (kein Inline-JS)
