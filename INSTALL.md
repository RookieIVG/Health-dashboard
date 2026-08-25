# Installation

Vollständige Anleitung für eine Neuinstallation auf Shared Hosting (Beispiele
beziehen sich auf World4You/Plesk, das Vorgehen ist bei anderen Hostern
sinngemäß gleich). Für ein **bestehendes** System siehe stattdessen den
Hinweis am Ende jedes Abschnitts – dort weiterhin einzeln vorgehen, nicht
diese Anleitung als Update-Weg verwenden.

## Voraussetzungen

- PHP 8.1 oder neuer, mit den Erweiterungen `openssl`, `pdo_mysql`, `mbstring`
- MySQL 8.0 oder MariaDB 10.5+
- Argon2id wird bevorzugt (automatischer Fallback auf bcrypt, falls die
  PHP-Installation Argon2id nicht mitbringt)
- Ein gültiges TLS-Zertifikat (Let's-Encrypt reicht, siehe Abschnitt HTTPS)

Keine Composer-Abhängigkeiten, kein Node/Build-Schritt – die Dateien lassen
sich unverändert per FTP hochladen.

## 1. Verzeichnisstruktur

Es gibt zwei gängige Varianten, je nachdem was der Hoster erlaubt.

**Variante A – app/ außerhalb des Webroots (sicherer, wo möglich):**

```
/kunde/
├── app/                  <-- NICHT im Webroot
│   ├── config/config.php
│   ├── keys/              (chmod 700, Dateien darin chmod 400)
│   ├── src/
│   └── storage/{sessions,files}/   (beschreibbar)
├── bin/                  <-- NICHT im Webroot
├── db/                   <-- nur zur Einrichtung gebraucht, danach entbehrlich
└── html/                 <-- Webroot (= Inhalt von public/)
    ├── index.php
    ├── login.php
    ├── .htaccess
    └── assets/
```

**Variante B – alles im selben Verzeichnisbaum (wenn der Hoster keinen
Ordner oberhalb des Webroots erlaubt):** `app/`, `bin/`, `db/` liegen als
Unterordner direkt neben den `public/`-Dateien im Webroot, z.B.
`web/health/app/`, `web/health/bin/`, mit `public/`s Inhalt direkt in
`web/health/` selbst (nicht `web/health/public/`). Das ist die schwächere
Variante, weil der Schutz dann ausschließlich von den mitgelieferten
`.htaccess`-Dateien (`Require all denied`) abhängt statt zusätzlich vom
Betriebssystem selbst – bei einer Fehlkonfiguration des Servers (z.B. wenn
`.htaccess` aus irgendeinem Grund ignoriert wird) greift dieser Schutz nicht
mehr. **Vorher beim Hoster nachfragen**, ob ein Verzeichnis außerhalb des
Document Roots möglich ist; bei den meisten Anbietern mit Plesk-Oberfläche
geht das über "Websites & Domains" → Verzeichnisstruktur.

Unabhängig von der gewählten Variante: **die Pfade in `config.php` unter
`paths` müssen zur tatsächlichen Ablage passen**, und alle `require`-Aufrufe
in den mitgelieferten Dateien gehen von der jeweils gewählten Struktur aus.
Bei Variante B liegen `app/` und `bin/` als direkte Unterordner neben den
`public/`-Dateien – das ist bereits die Struktur, für die alle Dateien in
diesem Paket vorbereitet sind, inklusive der `.htaccess`-Sperren in `app/`
und `bin/`.

## 2. Dateien hochladen

Per FTP/SFTP: `app/`, `bin/` (und vorübergehend `db/`) an die gewählte
Stelle, den **Inhalt** von `public/` (nicht den Ordner `public/` selbst) in
den Webroot.

## 3. Datenbank einrichten

Im Kundencenter des Hosters eine MySQL-Datenbank samt Benutzer anlegen, dann
`db/schema.sql` einspielen:

```bash
mysql -u dein_db_user -p deine_datenbank < db/schema.sql
```

Oder über phpMyAdmin: Datenbank auswählen → Import → `db/schema.sql`
hochladen.

Das legt alle 37 Tabellen an **und** befüllt die mitgelieferten Vorlagen:
neun Vitalwert-Messgrößen (Blutdruck, Puls, Gewicht, …), fünf Tagebücher
samt ihrer 25 Felder (Stuhl-, Ernährungs-, Stimmungs-, Schlaf- und
Schmerztagebuch, inklusive der ausführlichen Infotexte für Stuhl- und
Stimmungstagebuch) und 23 Laborparameter (Blutbild, Nieren-, Leber- und
Schilddrüsenwerte, Elektrolyte, Fette, Zucker, Vitamine). Diese Vorlagen
lassen sich später über die jeweilige Modul-Oberfläche erweitern oder
ausblenden, ohne die Datenbank direkt anzufassen.

> `db/schema.sql` ist der tatsächliche Endzustand von 29 einzeln
> gewachsenen Migrationen, gegen eine echte Datenbank getestet und mit dem
> Ergebnis der Einzelmigrationen abgeglichen (Struktur, Zeilenzahlen und
> Inhalt der Vorlagedaten identisch) – nicht von Hand zusammengetragen.
> Für ein **bestehendes** System diese Datei nicht verwenden.

## 4. Konfiguration

```bash
cp app/config/config.example.php app/config/config.php
```

Danach in `app/config/config.php` mindestens anpassen:

| Schlüssel | Bedeutung |
|---|---|
| `app.base_url` | volle Adresse inkl. `https://` |
| `app.base_path` | Unterverzeichnis im Webroot (z.B. `/health`), leer lassen falls direkt unter der Domain |
| `app.mail_from` | Absenderadresse für Termin-Erinnerungen – ohne diesen Wert verschickt `bin/send_reminders.php` keine E-Mails |
| `app.vapid_subject` | Kontaktadresse für Web-Push-Erinnerungen (ohne `mailto:` davor) |
| `app.cron_token` | nur nötig für die selbstauslösende Medikamenten-Erinnerungskette, siehe Abschnitt Cron-Jobs |
| `db.*` | Zugangsdaten der angelegten Datenbank |
| `paths.*` | müssen zur gewählten Verzeichnisstruktur (Abschnitt 1) passen |
| `security.require_2fa` | Zwei-Faktor-Pflicht für alle Konten, standardmäßig an |

Alle übrigen Werte in `config.example.php` sind sinnvoll vorbelegt und
müssen in der Regel nicht verändert werden.

**config.php niemals in ein Git-Repository committen oder ins Backup neben
die Schlüsseldateien legen** (siehe Abschnitt 5).

## 5. Schlüssel erzeugen und Administrator anlegen

Zwei Wege, je nachdem ob SSH-Zugang besteht.

### Mit SSH-Zugang

```bash
php bin/setup.php keys
php bin/setup.php check
php bin/setup.php admin thomas
```

`keys` erzeugt `app/keys/master.key.php` und `index.key.php`. `check` prüft
PHP-Version, Erweiterungen, Schreibrechte, Datenbankverbindung und macht
einen Verschlüsselungs-Testlauf. `admin` fragt Passwort, Anzeigename und
E-Mail interaktiv ab und legt den ersten Administrator an.

### Ohne SSH-Zugang

`public/web-setup.php` ist eine vollständige, browserbasierte Alternative:

1. In der Datei `SETUP_TOKEN` auf einen eigenen Zufallswert ändern (z.B. mit
   `php -r "echo bin2hex(random_bytes(24));"` irgendwo erzeugt, oder ein
   beliebiger langer Zufallsstring).
2. Datei neben `index.php` in den Webroot legen.
3. Aufrufen: `https://deinedomain.at/health/web-setup.php?token=DEIN_TOKEN`
4. Der Reihe nach: Schlüssel erzeugen → Umgebung prüfen → Administrator
   anlegen. Der Prüfschritt warnt explizit, falls der Schlüsselordner aus
   Versehen direkt per URL erreichbar wäre – das ist bei korrekter
   `paths`-Konfiguration nicht der Fall, aber es lohnt sich, das nicht nur
   dieser Anleitung zu glauben, sondern selbst gegenzuprüfen.
5. **Zuletzt "Setup-Datei löschen" antippen.** Diese Datei kann Schlüssel
   erzeugen und Administratoren mit vollem Zugriff anlegen – sie darf nach
   getaner Arbeit nicht online bleiben.

### Schlüssel-Backup

**Das Backup von `app/keys/master.key.php` und `index.key.php` gehört an
einen anderen Ort als das Datenbank-Backup.** Liegen beide zusammen, ist die
Verschlüsselung wertlos – wer den einen Ordner hat, hat alles. Umgekehrt:
ohne `master.key.php` sind alle verschlüsselten Daten unwiederbringlich
verloren, auch für dich selbst. Kein Support, kein Trick holt sie zurück.

## 6. Erste Anmeldung

Mit dem angelegten Administrator-Konto anmelden, dann unter **Konto →
Sicherheit** die Zwei-Faktor-Authentifizierung einrichten. Die
Wiederherstellungscodes werden nur **einmal** angezeigt – an einem sicheren
Ort (nicht im selben Backup wie die Schlüsseldateien) aufbewahren.

## 7. HTTPS

Let's-Encrypt-Zertifikat im Kundencenter des Hosters aktivieren. Danach
greifen `force_https` in der Konfiguration und der Redirect in der
`.htaccess` automatisch. HSTS wird mit einem Jahr Laufzeit gesetzt – erst
einschalten (bzw. die Domain erst mit `force_https: true` betreiben), wenn
das Zertifikat sicher und dauerhaft eingerichtet ist, sonst sperrst du dich
für die Laufzeit des HSTS-Headers selbst aus, falls das Zertifikat einmal
ausfällt.

## 8. Cron-Jobs

Drei getrennte Aufgaben, unabhängig voneinander einzurichten.

### Aufräumen (täglich)

```
15 3 * * *   php bin/setup.php purge
```

Räumt abgelaufene Sessions, alte Login-Versuche, verbrauchte
TOTP-Zeitschritte und abgelaufene Tokens auf.

### Termin-Erinnerungen (alle 15 Minuten)

```
*/15 * * * * php bin/send_reminders.php
```

Verschickt fällige Termin-Erinnerungen per E-Mail und/oder Push, je nach
Kontoeinstellung. Voraussetzung für tatsächliche Zustellung: `mail_from` in
`config.php` gesetzt, und der Hoster hat für die Domain SPF/DKIM
eingerichtet – sonst landen die Mails leicht im Spam-Ordner des Empfängers.

### Medikamenten-Erinnerungen (abhängig vom Hosting-Typ)

**Entscheidend ist, ob der Hoster echte Kommandozeilen-Cron-Jobs anbietet,
oder nur eine URL zeitgesteuert aufruft.** Plesk-basierte Hoster bieten
meist beides als Aufgabentyp an ("Befehl ausführen" / "PHP-Skript
ausführen" vs. "URL abrufen"); manche vereinfachte Oberflächen (z.B.
World4You) bieten **ausschließlich** eine Skript-URL an, ganz ohne
Kommandozeilen-Feld. Im Zweifel im Cron-Formular des Hosters nachsehen: gibt
es ein Feld für einen Befehl/Interpreterpfad, oder ausschließlich ein
Feld für eine Web-Adresse?

**A) Echter Kommandozeilen-Zugriff, Cron-Takt unter einer Stunde erlaubt:**

```
*/2 * * * *  php bin/send_medication_push.php
```

**B) Echter Kommandozeilen-Zugriff, aber nur stündlicher Takt erlaubt:**

```
0 * * * *  php bin/send_medication_push_hourly.php
```

Läuft nur einmal pro Stunde per Cron, wiederholt die Prüfung aber intern
alle 90 Sekunden bis kurz vor die nächste volle Stunde.

**C) Nur eine Skript-URL, keine Kommandozeile** (z.B. World4You):

Skript-URL im Cron-Formular des Hosters:

```
https://deinedomain.at/health/cron_tick_medication.php?token=DEIN_CRON_TOKEN
```

`app.cron_token` in `config.php` vorher auf einen Zufallswert setzen (`php
-r "echo bin2hex(random_bytes(24));"`, oder ohne Kommandozeile: irgendeinen
langen Zufallsstring eintragen). Jeder Aufruf prüft einmal, schläft kurz (55
Sekunden) und stößt den nächsten Aufruf auf sich selbst an, bis kurz vor die
nächste volle Stunde – dafür braucht es **einen Cron-Eintrag mit einer
Uhrzeit für jede Stunde**, zu der Medikamente fällig sein könnten (die
Uhrzeitauswahl lässt in der Regel mehrere Häkchen gleichzeitig zu). Kein
einzelner Aufruf lebt dabei länger als etwa eine Minute – das funktioniert
auch dort, wo der Hoster lang schlafende Prozesse vorzeitig beendet.

**Nur wenn A oder B tatsächlich per Kommandozeile möglich sind**, funktioniert
auch die alternative Kettenvariante über die Kommandozeile
(`bin/kickoff_medication_chain.php` statt der URL-Variante C) – für reine
URL-Hoster ist ausschließlich Variante C nutzbar, da `bin/`-Skripte
grundsätzlich die Ausführung über HTTP verweigern (siehe unten).

### Diagnose: läuft der Cron überhaupt?

`https://deinedomain.at/health/cron_status.php` (Login erforderlich) zeigt
zwei getrennte Dinge: einen **Herzschlag**, der beweist, dass der
Cron-Aufruf PHP überhaupt erreicht (unabhängig davon, ob danach alles
fehlerfrei durchläuft), und ein **vollständiges Protokoll**, das zeigt ob
die Erinnerungslogik bis zum Ende kommt. Über den Knopf "Jetzt von Hand
ausführen" lässt sich die Logik sofort testen, ohne auf den nächsten
Cron-Takt zu warten.

## 9. Push-Benachrichtigungen (nutzerseitig)

Funktionieren nur für eine als App zum Startbildschirm hinzugefügte Seite
(iOS: Safari → Teilen → Zum Home-Bildschirm; Android: Chrome bietet das von
selbst an). Unter **Konto → Push-Benachrichtigungen** aktivieren, danach mit
"Test senden" prüfen, ob eine Benachrichtigung ankommt. Der VAPID-Schlüssel
wird beim allerersten Versand automatisch erzeugt und unter
`app/keys/vapid_private.key.php` abgelegt – diese Datei gehört, genau wie
die Verschlüsselungsschlüssel, nicht ins Backup neben die Datenbank und
nicht in ein Repository.

Zustellung auf iOS ist laut Apples eigener Aussage "best effort", keine
Garantie – auch bei vollständig korrekter Einrichtung können
Benachrichtigungen verzögert ankommen, wenn iOS sie aus Gründen des
Akkusparens zurückhält.

## 10. Aufräumen nach der Installation

- `web-setup.php` gelöscht (siehe Abschnitt 5, falls diese Variante genutzt
  wurde).
- `db/` kann nach erfolgreichem Einspielen von `schema.sql` vom Server
  entfernt werden – wird von der laufenden Anwendung nicht mehr gebraucht.
- `app/config/config.example.php` kann bleiben, wird nicht ausgeliefert
  (liegt außerhalb des Webroots bzw. hinter `.htaccess`).

## Bei Problemen

- `php bin/setup.php check` (mit SSH) oder `web-setup.php?token=…&step=check`
  (ohne SSH) zeigen fehlende Erweiterungen, falsche Schreibrechte oder eine
  nicht erreichbare Datenbank direkt an.
- `cron_status.php` für alles rund um die Medikamenten-Erinnerungen.
- Ein leerer, weißer Bildschirm ohne jede Fehlermeldung deutet meist auf
  einen falschen Pfad in `_init.php` (Konstante `APP_ROOT`) oder in
  `config.php` unter `paths` hin – beide müssen zur tatsächlichen Ablage auf
  dem Server passen, nicht zur lokalen Ordnerstruktur vor dem Hochladen.
