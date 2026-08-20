# Oberfläche: Mobil und Darstellungsmodi

## Gemeinsames Seitengerüst

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

## Darstellungsmodi

Drei Zustände: **automatisch** (folgt dem Betriebssystem), **hell**, **dunkel**.
Der Umschalter in der Kopfzeile geht der Reihe nach durch.

### Warum Cookie statt localStorage

Das Theme steht in einem Cookie, weil PHP es beim Rendern liest und direkt in
`<html data-theme="…">` schreibt. Mit `localStorage` könnte erst JavaScript
nach dem ersten Rendern umschalten – bei dunkler Einstellung blitzt dann kurz
die helle Variante auf. Besonders störend nachts, und genau dann ist der
Dunkelmodus in Gebrauch.

Cookie-Pfad ist der `base_path` der Installation; `ui.js` leitet ihn aus der
eigenen Skript-URL ab, weil eine Inline-Variable von der
Content-Security-Policy blockiert würde.

### Farbwahl

Der Dunkelmodus ist nicht invertiert. Reines Schwarz erzeugt auf OLED bei
Textmengen Nachzieheffekte, volle Weißwerte blenden. Deshalb abgesetzte
Grautöne (`#14181c` Grund, `#1c2229` Flächen) und ein aufgehellter Akzent
(`#4bbfae` statt `#0f6e63`), damit der Kontrast auf dunklem Grund erhalten
bleibt.

`color-scheme` ist gesetzt, damit Formularelemente, Scrollbalken und
Datumsauswahl des Browsers mitziehen. `theme-color` färbt die Browserleiste
auf iOS und Android.

## Mobil

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

## Content-Security-Policy

`script-src 'self'` – kein Inline-JavaScript. Das betrifft auch
Event-Attribute: `onsubmit="return confirm(…)"` ist Inline-JavaScript und wird
blockiert. Das Formular hätte dann **ohne jede Rückfrage** abgeschickt, was bei
"2FA deaktivieren" unangenehm ist. Sicherheitsabfragen laufen deshalb über
`data-confirm="Frage"` und einen zentralen Handler in `ui.js`.

Beim Bauen neuer Seiten also: keine `on*`-Attribute, kein `<script>` ohne
`src`.
