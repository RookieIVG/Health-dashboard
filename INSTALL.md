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
