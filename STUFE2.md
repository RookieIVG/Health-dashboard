# Stufe 2 – Querschnittsdienste

Die Grundlage, auf der alle Module aufsetzen. Wer sie überspringt, schreibt
Verschlüsselung, Ownership-Prüfung und Aufräumlogik zwölfmal – und irgendwann
einmal falsch.

## Repository

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

### AAD-Konvention

Jedes Feld wird mit `tabelle.feld` als Additional Authenticated Data
verschlüsselt. GCM prüft das beim Entschlüsseln mit. Ein Ciphertext lässt sich
dadurch nicht von einem Feld in ein anderes kopieren – etwa eine fremde Notiz
in ein Diagnosefeld.

**Folge für Migrationen:** Wird eine Tabelle umbenannt, ändert sich das AAD und
die bestehenden Werte sind nicht mehr entschlüsselbar. Tabellennamen sind damit
Teil des Datenformats, nicht bloß Kosmetik.

### Löschen

`delete()` räumt Anhänge, Tags und Timeline-Einträge mit ab. Das muss die
Anwendung erledigen, weil die polymorphen Verweise `(module, ref_id)` keine
Fremdschlüssel haben. Wer in einem Modul an `Repository::delete()` vorbei
löscht, hinterlässt Karteileichen – `AttachmentService::findOrphans()` findet
sie im Nachhinein.

## TimelineService

Jedes Modul meldet seine Datensätze über `record()` an. Der Eintrag hält Titel
und Kurzfassung redundant – bewusst: die Timeline soll ohne Zugriff auf zwölf
Modultabellen darstellbar sein. Preis ist die Pflicht, sie bei Änderungen
mitzuziehen. Deshalb ist `record()` idempotent (Unique-Key über
`module, ref_id, event_type`) und `Repository::touchTimeline()` kapselt den
Aufruf.

Bereitgestellt: `range()` mit Modul- und Zeitraumfilter, `around()` für den
Kontext eines Datums, `groupedByDay()`, `countsByModule()`, `bounds()`.

## TagService

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

## AttachmentService und FileCrypto

Dateien liegen verschlüsselt unter Zufallsnamen in `app/storage/files/`,
verteilt auf Unterverzeichnisse nach den ersten beiden Zeichen. Der echte
Dateiname steht verschlüsselt in der Datenbank – `Befund_Onkologie_2026.pdf`
verrät im Klartext-Dateisystem bereits mehr als nötig.

### Warum blockweise

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

### Was das Format nicht erkennt

Abschneiden am Ende. Ein verkürzter Ciphertext entschlüsselt sauber – es fehlt
nur Inhalt. Dagegen steht der `sha256` des Klartexts in der Datenbank;
`stream()` entschlüsselt deshalb erst vollständig nach `php://temp`, prüft den
Hash und gibt erst dann das erste Byte aus. `php://temp` läuft ab 2 MB auf die
Platte über, das `memory_limit` bleibt unangetastet.

### Upload-Prüfung

MIME wird aus dem Dateiinhalt bestimmt (`finfo`), nicht aus dem Browser-Header
– der ist frei wählbar. Zusätzlich muss die Dateiendung zum erkannten Inhalt
passen. Erlaubt sind PDF, JPEG, PNG, HEIC, TIFF, TXT, CSV und DICOM.

## DEK-Cache

`App::dekFor()` hält entpackte Schlüssel für die Dauer des Requests. Ohne das
löste jedes verschlüsselte Feld eine Datenbankabfrage plus Entschlüsselung aus
– bei 200 Timeline-Einträgen 400 überflüssige Operationen. In der Session
landet weiterhin nichts.

## Selbsttest

`public/stufe2-selftest.php` (Administrator erforderlich) prüft alle Dienste
gegen die laufende Installation und räumt seine Testdaten wieder ab. Nach
erfolgreichem Durchlauf löschen.
