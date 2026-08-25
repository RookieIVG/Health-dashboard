<?php
declare(strict_types=1);
require __DIR__ . '/_init.php';
require __DIR__ . '/bin/_medication_push_core.php';

use Health\App;
use Health\Csrf;
use Health\View;
use Health\WebPush;

$app = App::boot();
$app->auth->requireLogin();

$error = null;
$ranNow = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::requireValid();
    try {
        $subject = 'mailto:' . ($app->config['app']['vapid_subject'] ?? 'noreply@example.com');
        $push    = new WebPush($app->keyDir(), $subject);
        $ranNow  = run_medication_push_check($app, $push);
    } catch (\Throwable $e) {
        $error = $e->getMessage();
    }
}

$logFile = rtrim($app->config['paths']['storage'], '/') . '/cron_log.txt';
$lines = [];
if (is_file($logFile)) {
    $raw = @file_get_contents($logFile);
    if ($raw !== false && trim($raw) !== '') {
        $lines = array_reverse(array_filter(explode("\n", rtrim($raw, "\n"))));
    }
}
$lastRun = $lines[0] ?? null;

// Herzschlag: wird als ALLERERSTES geschrieben, noch vor jeder
// Datenbank-/Konfigurationsnutzung - beweist allein durch sein
// Vorhandensein/Alter, dass der Cron den PHP-Interpreter überhaupt
// erreicht hat, unabhängig davon, ob danach alles fehlerfrei durchläuft.
$heartbeatFile = __DIR__ . '/bin/cron_heartbeat.txt';
$heartbeatLines = [];
if (is_file($heartbeatFile)) {
    $raw = @file_get_contents($heartbeatFile);
    if ($raw !== false && trim($raw) !== '') {
        $heartbeatLines = array_reverse(array_filter(explode("\n", rtrim($raw, "\n"))));
    }
}
$lastHeartbeat = $heartbeatLines[0] ?? null;
$heartbeatAgeMin = is_file($heartbeatFile) ? (int)round((time() - filemtime($heartbeatFile)) / 60) : null;

View::start($app, ['title' => 'Cron-Status – ' . $app->config['app']['name'], 'active' => 'contacts']);
?>
<div class="panel">
  <h1>Cron-Status: Medikamenten-Erinnerung</h1>
  <p class="sub">
    Jeder Durchlauf von <code>send_medication_push.php</code>,
    <code>send_medication_push_hourly.php</code> oder der selbstauslösenden
    Kette (<code>kickoff_medication_chain.php</code> +
    <code>cron_tick_medication.php</code>) trägt hier eine Zeile ein –
    unabhängig davon, welche der drei Varianten eingerichtet ist.
  </p>

  <h2 style="margin-top:20px">Herzschlag: erreicht der Cron überhaupt PHP?</h2>
  <p class="sub">
    Wird als Allererstes geschrieben, noch vor jeder Datenbank- oder
    Konfigurationsnutzung – zeigt nur, ob der Cron den PHP-Interpreter
    erreicht, unabhängig davon, ob der Rest danach fehlerfrei durchläuft.
  </p>
  <?php if (!$lastHeartbeat): ?>
    <div class="msg error">
      Kein einziger Herzschlag. Der Cron-Job erreicht PHP nicht – falscher
      Pfad, falscher Interpreter, oder er läuft schlicht nicht. Das ist
      unabhängig von allem, was weiter unten steht.
    </div>
  <?php else: ?>
    <div class="msg <?= $heartbeatAgeMin !== null && $heartbeatAgeMin > 90 ? 'warn' : 'ok' ?>">
      Letzter Herzschlag: <?= App::e($lastHeartbeat) ?>
      <?php if ($heartbeatAgeMin !== null): ?> (vor <?= $heartbeatAgeMin ?> Minuten)<?php endif; ?>
    </div>
    <?php if ($heartbeatAgeMin !== null && $heartbeatAgeMin > 90): ?>
      <p class="hint">Älter als 90 Minuten – bei stündlichem Cron sollte spätestens alle 60
      Minuten ein neuer Herzschlag dazukommen. Prüfe, ob der Cron-Job in World4You noch aktiv ist.</p>
    <?php endif; ?>
    <details style="margin-top:8px">
      <summary style="cursor:pointer;color:var(--accent)">Alle Herzschläge anzeigen (<?= count($heartbeatLines) ?>)</summary>
      <pre style="white-space:pre-wrap;font-size:.82rem;margin-top:8px;font-family:ui-monospace,Menlo,monospace"><?php
        foreach (array_slice($heartbeatLines, 0, 100) as $l) echo App::e($l) . "\n";
      ?></pre>
    </details>
  <?php endif; ?>

  <h2 style="margin-top:24px">Vollständiger Durchlauf: kommt die Erinnerungslogik bis zum Ende?</h2>

  <?php if ($error): ?>
    <div class="msg error"><?= App::e($error) ?></div>
  <?php endif; ?>

  <?php if (!$lines): ?>
    <div class="msg warn">
      Noch kein einziger Eintrag. Entweder ist der Cron-Job noch nie
      gelaufen, oder er zeigt auf den falschen Pfad. Weiter unten prüfen.
    </div>
  <?php else: ?>
    <div class="msg ok">
      Letzter Lauf: <?= App::e($lastRun) ?>
    </div>
    <p class="hint">
      Trage in World4You den Cron-Job ein und warte auf den nächsten
      Takt – kommt danach eine neue Zeile dazu, läuft der Cron. Bleibt
      es bei diesem einen Zeitstempel, feuert der Cron nicht (siehe
      Prüfliste unten).
    </p>
  <?php endif; ?>

  <?php if ($ranNow): ?>
    <div class="msg ok" style="margin-top:12px">
      Gerade eben von Hand ausgeführt: erinnert <?= (int)$ranNow['sent'] ?>,
      nicht fällig <?= (int)$ranNow['skippedNotDue'] ?>,
      bereits genommen <?= (int)$ranNow['skippedTaken'] ?>,
      bereits erinnert <?= (int)$ranNow['skippedLogged'] ?>,
      fehlgeschlagen <?= (int)$ranNow['failed'] ?>.
    </div>
  <?php endif; ?>

  <form method="post" style="margin-top:14px">
    <?= Csrf::field() ?>
    <button type="submit" class="secondary">Jetzt von Hand ausführen</button>
  </form>
  <p class="hint">
    Prüft sofort, ohne auf den Cron zu warten – nützlich, um die
    Erinnerungslogik selbst zu testen, unabhängig davon, ob Cron feuert.
  </p>
</div>

<?php if ($lines): ?>
<div class="panel">
  <h2>Verlauf (neueste zuerst)</h2>
  <div class="table-wrap">
    <pre style="white-space:pre-wrap;font-size:.85rem;margin:0;font-family:ui-monospace,Menlo,monospace"><?php
      foreach (array_slice($lines, 0, 200) as $l) echo App::e($l) . "\n";
    ?></pre>
  </div>
  <?php if (count($lines) > 200): ?>
    <p class="hint">Zeigt die neuesten 200 von <?= count($lines) ?> Zeilen.</p>
  <?php endif; ?>
</div>
<?php endif; ?>

<div class="panel">
  <h2>Falls gar keine Zeile erscheint</h2>
  <ol style="padding-left:20px;color:var(--muted);font-size:.92rem;line-height:1.7">
    <li>In World4You den genauen Befehl prüfen – Tippfehler im Pfad sind die häufigste Ursache.
        Muss exakt so aussehen (Pfad an deine Installation anpassen):<br>
        <code>/opt/alt/php85/usr/bin/php -f /pfad/zu/deiner/installation/bin/send_medication_push.php</code></li>
    <li>Den Pfad zur Installation gegenprüfen: die Skripte in <code>bin/</code> finden
        <code>app/</code> relativ zu ihrem eigenen Speicherort (<code>bin/../app/</code>) –
        das setzt nur voraus, dass <code>bin/</code> und <code>app/</code> nebeneinander liegen,
        wie sie ausgeliefert wurden. Entscheidend ist, dass der Pfad im Cron-Befehl exakt auf
        die Datei <em>innerhalb von</em> <code>bin/</code> zeigt, nicht auf <code>bin/</code> selbst.</li>
    <li>In World4You einmal "Jetzt ausführen" bei der Aufgabe selbst antippen (falls die
        Oberfläche das anbietet) – manche Cron-Oberflächen zeigen dabei eine Fehlermeldung an,
        die bei einem stillen, zeitgesteuerten Lauf verborgen bliebe.</li>
    <li>Diese Seite hier danach neu laden. Kommt jetzt eine Zeile, lag es am Zeitplan, nicht am Pfad.</li>
  </ol>
</div>
<?php View::end($app); ?>
