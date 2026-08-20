# Health-dashboard

## Installation

### 1. Verzeichnisstruktur

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

### 2. Datenbank

Im World4You-Kundencenter eine MySQL-Datenbank anlegen, dann:

```sql
SOURCE db/01_core_schema.sql;
```

oder den Inhalt über phpMyAdmin einspielen.

### 3. Konfiguration

```bash
cp app/config/config.example.php app/config/config.php
```

DB-Zugangsdaten, `base_url` und Pfade eintragen.

### 4. Schlüssel erzeugen

```bash
php bin/setup.php keys
```

Erzeugt `master.key.php` und `index.key.php`.

> **Das Backup dieser beiden Dateien gehört an einen anderen Ort als das
> Datenbank-Backup.** Liegen beide im selben Ordner, ist die Verschlüsselung
> wertlos – wer den Ordner hat, hat alles. Umgekehrt: ohne `master.key` sind
> die Daten endgültig verloren, auch von dir.

### 5. Prüfen und Admin anlegen

```bash
php bin/setup.php check
php bin/setup.php admin thomas
```

Danach anmelden und unter **Sicherheit** 2FA einrichten. Die
Wiederherstellungscodes werden nur einmal angezeigt.

### 6. Cronjob

```
15 3 * * *  php /kunde/bin/setup.php purge
```

Räumt Login-Versuche, abgelaufene Sessions, verbrauchte TOTP-Zeitschritte und
Tokens auf.

### Ohne Kommandozeile

Falls kein SSH verfügbar ist: `bin/setup.php` temporär in den Webroot kopieren,
die CLI-Prüfung am Dateianfang auskommentieren, die drei Befehle über den
Browser aufrufen (`?cmd=keys` müsste man dann noch ergänzen) und die Datei
**sofort wieder löschen**. Sauberer ist, den SSH-Zugang freischalten zu lassen.

### HTTPS

World4You liefert Let's-Encrypt-Zertifikate im Kundencenter. Nach der
Aktivierung greifen `force_https` in der Konfiguration und der Redirect in der
`.htaccess`. HSTS wird mit einem Jahr Laufzeit gesetzt – erst einschalten, wenn
das Zertifikat sicher steht, sonst sperrst du dich für die Dauer aus.

### Anforderungen

- PHP 8.1+ mit `openssl`, `pdo_mysql`, `mbstring`
- Argon2id (sonst automatischer Fallback auf bcrypt cost 12)
- MySQL 8.0 / MariaDB 10.5+

## Oberfläche: Mobil und Darstellungsmodi

### Gemeinsames Seitengerüst

`Health\View` liefert Kopfzeile, Navigation und Umschalter. Vorher stand
dieselbe Kopfzeile in sechs Dateien – mit zwölf Modulen wären daraus zwanzig
geworden, die auseinanderlaufen. Eine neue Seite braucht jetzt:

```php
View::start($app, ['title' => 'Medikation', 'active' => 'medication']);
// … Inhalt …
View::end($app);
```

Login-Seiten nutzen `View::startBare()` – schmales Layout ohne Navigation.

Neue Menüpunkte kommen in das Array `View::$nav`; `'admin' => true` blendet
einen Eintrag für Nicht-Administratoren aus.

### Darstellungsmodi

Drei Zustände: **automatisch** (folgt dem Betriebssystem), **hell**, **dunkel**.
Der Umschalter in der Kopfzeile geht der Reihe nach durch.

#### Warum Cookie statt localStorage

Das Theme steht in einem Cookie, weil PHP es beim Rendern liest und direkt in
`<html data-theme="…">` schreibt. Mit `localStorage` könnte erst JavaScript
nach dem ersten Rendern umschalten – bei dunkler Einstellung blitzt dann kurz
die helle Variante auf. Besonders störend nachts, und genau dann ist der
Dunkelmodus in Gebrauch.

Cookie-Pfad ist der `base_path` der Installation; `ui.js` leitet ihn aus der
eigenen Skript-URL ab, weil eine Inline-Variable von der
Content-Security-Policy blockiert würde.

#### Farbwahl

Der Dunkelmodus ist nicht invertiert. Reines Schwarz erzeugt auf OLED bei
Textmengen Nachzieheffekte, volle Weißwerte blenden. Deshalb abgesetzte
Grautöne (`#14181c` Grund, `#1c2229` Flächen) und ein aufgehellter Akzent
(`#4bbfae` statt `#0f6e63`), damit der Kontrast auf dunklem Grund erhalten
bleibt.

`color-scheme` ist gesetzt, damit Formularelemente, Scrollbalken und
Datumsauswahl des Browsers mitziehen. `theme-color` färbt die Browserleiste
auf iOS und Android.

### Mobil

- **Navigation** klappt unter 760 px über ein Symbol auf. Umgesetzt mit einer
  versteckten Checkbox, nicht mit `<details>`: bei `<details>` blendet der
  Browser alles außer `<summary>` aus, solange es geschlossen ist, und das
  lässt sich per CSS auf Desktopbreite nicht zuverlässig zurücknehmen.
  Funktioniert dadurch ohne JavaScript; `ui.js` ergänzt nur das Schließen bei
  Tippen daneben und Escape.
- **Tabellen** mit `class="stack"` werden unter 600 px zu Karten – jede Zelle
  eine Zeile mit ihrer Überschrift aus `data-label`. Ohne das müsste man die
  Sitzungsübersicht auf dem Telefon seitlich schieben.
- **Eingabefelder** haben 16 px Schriftgröße. Darunter zoomt iOS beim Fokus
  automatisch hinein und der Nutzer muss danach manuell zurückzoomen.
- **Tippziele** mindestens 44 px hoch.
- **Filterleisten** scrollen horizontal statt umzubrechen.
- `viewport-fit=cover` plus `env(safe-area-inset-*)` für Geräte mit Notch.

### Content-Security-Policy

`script-src 'self'` – kein Inline-JavaScript. Das betrifft auch
Event-Attribute: `onsubmit="return confirm(…)"` ist Inline-JavaScript und wird
blockiert. Das Formular hätte dann **ohne jede Rückfrage** abgeschickt, was bei
"2FA deaktivieren" unangenehm ist. Sicherheitsabfragen laufen deshalb über
`data-confirm="Frage"` und einen zentralen Handler in `ui.js`.

Beim Bauen neuer Seiten also: keine `on*`-Attribute, kein `<script>` ohne
`src`.


## Architektur der Basis

### Verschlüsselungsmodell

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

#### AAD (Additional Authenticated Data)

Jedes Feld wird mit einem Kontextstring verschlüsselt, z. B. `"findings.title"`.
GCM prüft diesen mit. Damit lässt sich ein Ciphertext nicht von einem Feld in
ein anderes kopieren – etwa eine fremde Notiz in das eigene Diagnosefeld.

#### Was verschlüsselt wird – und was nicht

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

#### Blind Index

Für Gleichheitssuche auf verschlüsselten Spalten (E-Mail, Tag-Namen):
`HMAC-SHA256(kontext || wert, index.key)`, auf 16 Byte gekürzt. Erlaubt
`WHERE email_bidx = ?`, aber keine Teilstringsuche und keine Sortierung.

### Authentifizierung

1. **Passwort** – Argon2id (64 MB, t=4), Fallback bcrypt cost 12.
   Timing-Angleichung bei unbekanntem Benutzer über einen Dummy-Hash.
2. **TOTP** – RFC 6238, SHA1/6 Stellen/30 s, ±1 Zeitschritt Toleranz.
   Eigene Implementierung ohne Composer-Abhängigkeit, gegen die
   RFC-Testvektoren verifiziert.
3. **Replay-Schutz** – jeder verbrauchte Zeitschritt landet in
   `totp_used_codes`; derselbe Code gilt nicht zweimal.
4. **Wiederherstellungscodes** – 10 Stück, bcrypt-gehasht, Einmalverwendung.

#### Sitzungen

PHP-Sessions (Speicherort außerhalb des Webroots, sonst liest sie auf Shared
Hosting im Zweifel der Nachbar) plus eine Spiegeltabelle `user_sessions`. Die
Tabelle macht dreierlei möglich: Übersicht über angemeldete Geräte, gezielter
Widerruf einzelner Sitzungen, und das automatische Abmelden aller anderen
Geräte nach einem Passwortwechsel.

Zwei Zeitgrenzen: Inaktivität (1 h) und absolute Laufzeit (12 h).

**Der DEK wird bewusst nicht in der Session gespeichert.** Er wird pro Request
aus der Datenbank entpackt. Das kostet eine Entschlüsselung, verhindert aber,
dass Klartextschlüssel in Session-Dateien auf der Platte liegen.

#### Rate Limiting

`login_attempts` zählt Fehlversuche je Benutzername **und** je IP getrennt –
sonst sperrt ein Angreifer durch gezielte Fehlversuche fremde Konten aus
(Account Lockout DoS). Standard: 5 Versuche pro 15 Minuten.

### Querschnittstabellen

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

#### Ablageform der Schlüssel

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

### Was noch fehlt

- Passwort-Reset per E-Mail (`user_tokens` ist vorbereitet, Versand fehlt)
- Verschlüsselte Dateiablage (`attachments` steht, Upload-Handler fehlt)
- Schlüsselrotation (`dek_version` ist vorgesehen, Routine fehlt)
- CSP-Nonce wird gesetzt, aber noch nirgends gebraucht (kein Inline-JS)

## Querschnittsdienste

Die Grundlage, auf der alle Module aufsetzen. Wer sie überspringt, schreibt
Verschlüsselung, Ownership-Prüfung und Aufräumlogik zwölfmal – und irgendwann
einmal falsch.

### Repository

Basisklasse für alle Modul-Repositories. Ein konkretes Modul deklariert nur
noch drei Dinge:

```php
final class FindingRepository extends Repository
{
    protected function table(): string  { return 'findings'; }
    protected function module(): string { return Modules::FINDING; }
    protected function encryptedFields(): array
    {
        return ['title' => true, 'text' => true, 'doctor' => true];
    }
}
```

Damit funktionieren `find()`, `between()`, `create()`, `update()`, `delete()`,
`count()` – jeweils auf den Datenbesitzer eingegrenzt und mit korrekt
gesetztem AAD.

#### AAD-Konvention

Jedes Feld wird mit `tabelle.feld` als Additional Authenticated Data
verschlüsselt. GCM prüft das beim Entschlüsseln mit. Ein Ciphertext lässt sich
dadurch nicht von einem Feld in ein anderes kopieren – etwa eine fremde Notiz
in ein Diagnosefeld.

**Folge für Migrationen:** Wird eine Tabelle umbenannt, ändert sich das AAD und
die bestehenden Werte sind nicht mehr entschlüsselbar. Tabellennamen sind damit
Teil des Datenformats, nicht bloß Kosmetik.

#### Löschen

`delete()` räumt Anhänge, Tags und Timeline-Einträge mit ab. Das muss die
Anwendung erledigen, weil die polymorphen Verweise `(module, ref_id)` keine
Fremdschlüssel haben. Wer in einem Modul an `Repository::delete()` vorbei
löscht, hinterlässt Karteileichen – `AttachmentService::findOrphans()` findet
sie im Nachhinein.

### TimelineService

Jedes Modul meldet seine Datensätze über `record()` an. Der Eintrag hält Titel
und Kurzfassung redundant – bewusst: die Timeline soll ohne Zugriff auf zwölf
Modultabellen darstellbar sein. Preis ist die Pflicht, sie bei Änderungen
mitzuziehen. Deshalb ist `record()` idempotent (Unique-Key über
`module, ref_id, event_type`) und `Repository::touchTimeline()` kapselt den
Aufruf.

Bereitgestellt: `range()` mit Modul- und Zeitraumfilter, `around()` für den
Kontext eines Datums, `groupedByDay()`, `countsByModule()`, `bounds()`.

### TagService

Tag-Namen sind verschlüsselt – "Laktoseintoleranz" ist für sich schon
Gesundheitsinformation. Gesucht wird über den Blind Index (HMAC über den
normalisierten Namen).

**Konsequenz:** exakte Treffer ja, Teilstringsuche nein, Sortierung nur nach
dem Entschlüsseln in PHP. Bei der zu erwartenden Zahl von Tags ist das
unproblematisch.

Die Normalisierung (trimmen, Mehrfach-Leerzeichen zusammenziehen,
Kleinschreibung) muss deterministisch bleiben. Ändert sie sich später, finden
alte und neue Tags einander nicht mehr und brauchen eine Neuberechnung aller
Blind Indexes.

### AttachmentService und FileCrypto

Dateien liegen verschlüsselt unter Zufallsnamen in `app/storage/files/`,
verteilt auf Unterverzeichnisse nach den ersten beiden Zeichen. Der echte
Dateiname steht verschlüsselt in der Datenbank – `Befund_Onkologie_2026.pdf`
verrät im Klartext-Dateisystem bereits mehr als nötig.

#### Warum blockweise

Ein 25-MB-PDF durch `openssl_encrypt()` läge dreimal gleichzeitig im Speicher
und reißt auf Shared Hosting das `memory_limit`. Daher Blöcke zu 1 MiB, je mit
eigenem IV und eigenem Tag:

```
Header:  "HDF1" | chunkSize (uint32 BE)
Block:   IV (12) | Tag (16) | Länge (uint32 BE) | Ciphertext
```

Als AAD dient `storageKey:blockIndex`. Das schließt zwei Angriffe aus, die bei
blockweiser Verschlüsselung sonst offenstehen: Blöcke innerhalb einer Datei
vertauschen und ganze Dateien gegen andere, ebenfalls gültige austauschen.

#### Was das Format nicht erkennt

Abschneiden am Ende. Ein verkürzter Ciphertext entschlüsselt sauber – es fehlt
nur Inhalt. Dagegen steht der `sha256` des Klartexts in der Datenbank;
`stream()` entschlüsselt deshalb erst vollständig nach `php://temp`, prüft den
Hash und gibt erst dann das erste Byte aus. `php://temp` läuft ab 2 MB auf die
Platte über, das `memory_limit` bleibt unangetastet.

#### Upload-Prüfung

MIME wird aus dem Dateiinhalt bestimmt (`finfo`), nicht aus dem Browser-Header
– der ist frei wählbar. Zusätzlich muss die Dateiendung zum erkannten Inhalt
passen. Erlaubt sind PDF, JPEG, PNG, HEIC, TIFF, TXT, CSV und DICOM.

### DEK-Cache

`App::dekFor()` hält entpackte Schlüssel für die Dauer des Requests. Ohne das
löste jedes verschlüsselte Feld eine Datenbankabfrage plus Entschlüsselung aus
– bei 200 Timeline-Einträgen 400 überflüssige Operationen. In der Session
landet weiterhin nichts.

### Selbsttest

`public/stufe2-selftest.php` (Administrator erforderlich) prüft alle Dienste
gegen die laufende Installation und räumt seine Testdaten wieder ab. Nach
erfolgreichem Durchlauf löschen.
