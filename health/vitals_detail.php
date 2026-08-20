<?php
declare(strict_types=1);
require __DIR__ . '/_init.php';

use Health\App;
use Health\Chart;
use Health\Csrf;
use Health\View;
use Health\VitalsRepository;

$app = App::boot();
$app->auth->requireLogin();

$repo     = new VitalsRepository($app);
$metricId = (int)($_GET['metric'] ?? 0);
$metric   = $repo->metric($metricId);

if (!$metric) {
    header('Location: ' . App::url('/vitals.php'));
    exit;
}

$error = $ok = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::requireValid();
    try {
        if (($_POST['action'] ?? '') === 'delete') {
            $repo->delete((int)($_POST['id'] ?? 0));
            $ok = 'Messwert gelöscht.';
        }
    } catch (\Throwable $e) {
        $error = $e->getMessage();
    }
}

$days   = (int)($_GET['days'] ?? 90);
$days   = in_array($days, [30, 90, 365, 0], true) ? $days : 90;
$from   = $days > 0 ? gmdate('Y-m-d H:i:s', time() - $days * 86400) : null;

$series = $repo->series($metricId, $from);
$stats  = $repo->stats($metricId, $days > 0 ? $days : 3650);

$points = array_map(fn($r) => [
    't'  => strtotime($r['measured_at'] . ' UTC'),
    'v'  => (float)$r['value'],
    'v2' => $r['value2'] === null ? null : (float)$r['value2'],
], $series);

$dec = (int)$metric['decimals'];
$fmt = fn(?float $v) => $v === null ? '–' : number_format($v, $dec, ',', '.');

View::start($app, [
    'title'  => $metric['label'] . ' – ' . $app->config['app']['name'],
    'active' => 'vitals',
]);
?>
<?php View::flash($ok, 'ok'); View::flash($error, 'error'); ?>

<div class="panel">
  <h1><?= App::e($metric['label']) ?></h1>
  <p class="sub">
    <a href="<?= App::url('/vitals.php?metric=' . $metricId) ?>">Neuen Wert erfassen</a>
    · <a href="<?= App::url('/vitals.php') ?>">Übersicht</a>
  </p>

  <div class="filters">
    <?php foreach ([30 => '30 Tage', 90 => '90 Tage', 365 => '1 Jahr', 0 => 'Alles'] as $d => $l): ?>
      <a class="chip <?= $days === $d ? 'active' : '' ?>"
         href="?metric=<?= $metricId ?>&amp;days=<?= $d ?>"><?= App::e($l) ?></a>
    <?php endforeach; ?>
  </div>
</div>

<div class="panel">
  <?php if (count($points) >= 2): ?>
    <?= Chart::line($points, [
          'decimals'  => $dec,
          'ref_low'   => VitalsRepository::num($metric['ref_low']),
          'ref_high'  => VitalsRepository::num($metric['ref_high']),
          'ref_low2'  => VitalsRepository::num($metric['ref_low2']),
          'ref_high2' => VitalsRepository::num($metric['ref_high2']),
          'timezone'  => $app->config['app']['timezone'],
        ]) ?>
    <?php if ($metric['ref_low'] !== null || $metric['ref_high'] !== null): ?>
      <p class="hint">
        Das getönte Band zeigt den hinterlegten Orientierungsbereich
        (<?= App::e($fmt(VitalsRepository::num($metric['ref_low']))) ?>–<?= App::e($fmt(VitalsRepository::num($metric['ref_high']))) ?>
        <?= App::e($metric['unit']) ?>). Ob ein Wert für dich passend ist, hängt von
        Vorgeschichte, Medikation und Messsituation ab – das kann nur deine Ärztin
        oder dein Arzt einordnen.
      </p>
    <?php endif; ?>
  <?php else: ?>
    <p class="empty">Noch zu wenige Werte für eine Kurve.</p>
  <?php endif; ?>
</div>

<?php if (($stats['n'] ?? 0) > 0): ?>
<div class="panel">
  <h2>Kennzahlen<?= $days > 0 ? ' der letzten ' . $days . ' Tage' : '' ?></h2>
  <div class="table-wrap">
    <table class="stack">
      <thead><tr><th>Messungen</th><th>Kleinster</th><th>Größter</th><th>Mittel</th><th>Median</th></tr></thead>
      <tbody>
        <tr>
          <td data-label="Messungen"><?= (int)$stats['n'] ?></td>
          <td data-label="Kleinster"><?= App::e($fmt($stats['min'])) ?></td>
          <td data-label="Größter"><?= App::e($fmt($stats['max'])) ?></td>
          <td data-label="Mittel"><?= App::e($fmt($stats['avg'])) ?></td>
          <td data-label="Median"><?= App::e($fmt($stats['median'])) ?></td>
        </tr>
      </tbody>
    </table>
  </div>
  <p class="hint">
    Median und Mittelwert stehen nebeneinander, weil eine einzelne
    Fehlmessung den Mittelwert deutlich verschiebt, den Median kaum.
    Liegen beide weit auseinander, lohnt ein Blick auf Ausreißer.
  </p>
</div>
<?php endif; ?>

<div class="panel">
  <h2>Einzelwerte</h2>
  <?php if (!$series): ?>
    <p class="empty">Keine Werte im gewählten Zeitraum.</p>
  <?php else: ?>
  <div class="table-wrap">
    <table class="stack">
      <thead>
        <tr><th>Zeitpunkt</th><th>Wert</th><th>Situation</th><th>Notiz</th><th></th></tr>
      </thead>
      <tbody>
      <?php foreach (array_reverse($series) as $r):
            $sev = $repo->severity($metric, (float)$r['value'],
                       $r['value2'] === null ? null : (float)$r['value2']); ?>
        <tr>
          <td data-label="Zeitpunkt"><?= App::e($app->local($r['measured_at'], 'd.m.Y H:i')) ?></td>
          <td data-label="Wert" style="<?= $sev === 2 ? 'color:var(--danger);font-weight:600' : ($sev === 1 ? 'color:var(--warn)' : '') ?>">
            <?= App::e(VitalsRepository::formatValue($metric, (float)$r['value'],
                  $r['value2'] === null ? null : (float)$r['value2'])) ?>
          </td>
          <td data-label="Situation"><?= App::e(VitalsRepository::CONTEXTS[$r['context']] ?? '') ?></td>
          <td data-label="Notiz"><?= App::e($r['note'] ?? '') ?></td>
          <td>
            <form method="post" data-confirm="Diesen Messwert löschen?" style="margin:0">
              <?= Csrf::field() ?>
              <input type="hidden" name="action" value="delete">
              <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
              <button type="submit" class="secondary small">Löschen</button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</div>
<?php View::end($app); ?>
