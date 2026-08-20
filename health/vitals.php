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

$repo  = new VitalsRepository($app);
$error = $ok = null;

// Vorauswahl der Metrik im Formular
$selected = (int)($_POST['metric_id'] ?? $_GET['metric'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::requireValid();
    try {
        if (($_POST['action'] ?? '') === 'record') {
            $metricId = (int)($_POST['metric_id'] ?? 0);
            $value    = VitalsRepository::num($_POST['value'] ?? null);
            $value2   = VitalsRepository::num($_POST['value2'] ?? null);

            if ($value === null) {
                throw new InvalidArgumentException('Bitte einen Messwert eingeben.');
            }

            $when = trim((string)($_POST['measured_at'] ?? ''));
            if ($when === '') {
                $when = (new DateTimeImmutable('now', new DateTimeZone($app->config['app']['timezone'])))
                        ->format('Y-m-d H:i');
            } else {
                $when = str_replace('T', ' ', $when);
            }

            $repo->record(
                metricId: $metricId,
                measuredAtLocal: $when,
                value: $value,
                value2: $value2,
                context: (string)($_POST['context'] ?? ''),
                note: trim((string)($_POST['note'] ?? '')) ?: null
            );
            $ok = 'Messwert gespeichert.';
            $selected = $metricId;

        } elseif (($_POST['action'] ?? '') === 'new_metric') {
            $id = $repo->createMetric([
                'mkey'         => $_POST['mkey'] ?? '',
                'label'        => $_POST['label'] ?? '',
                'unit'         => $_POST['unit'] ?? '',
                'decimals'     => $_POST['decimals'] ?? 0,
                'has_second'   => !empty($_POST['has_second']),
                'label_first'  => trim((string)($_POST['label_first'] ?? '')) ?: null,
                'label_second' => trim((string)($_POST['label_second'] ?? '')) ?: null,
                'ref_low'      => $_POST['ref_low'] ?? null,
                'ref_high'     => $_POST['ref_high'] ?? null,
                'plaus_min'    => $_POST['plaus_min'] ?? null,
                'plaus_max'    => $_POST['plaus_max'] ?? null,
            ]);
            $ok = 'Metrik angelegt.';
            $selected = $id;
        }
    } catch (\Throwable $e) {
        $error = $e->getMessage();
    }
}

$metrics  = $repo->metrics();
$overview = $repo->overview();

if ($selected === 0 && $metrics) {
    $selected = (int)$metrics[0]['id'];
}
$selMetric = $repo->metric($selected) ?? ($metrics[0] ?? null);

$nowLocal = (new DateTimeImmutable('now', new DateTimeZone($app->config['app']['timezone'])))
            ->format('Y-m-d\TH:i');

View::start($app, ['title' => 'Vitalwerte – ' . $app->config['app']['name'], 'active' => 'vitals']);
?>
<?php View::flash($ok, 'ok'); View::flash($error, 'error'); ?>

<div class="panel">
  <h1>Vitalwerte</h1>
  <p class="sub">
    <?= $overview
        ? 'Letzter Stand je Messgröße. Zum Verlauf tippen.'
        : 'Noch keine Messwerte erfasst.' ?>
    · <a href="<?= App::url('/vitals_metrics.php') ?>">Messgrößen verwalten</a>
  </p>

  <?php if ($overview): ?>
  <div class="vital-grid">
    <?php foreach ($overview as $o):
          $m = $o['metric']; $l = $o['last']; ?>
      <a class="vital-card sev<?= (int)$o['severity'] ?>"
         href="<?= App::url('/vitals_detail.php?metric=' . (int)$m['id']) ?>">
        <div class="lbl"><?= App::e($m['label']) ?></div>
        <div class="val">
          <?= App::e(number_format((float)$l['value'], (int)$m['decimals'], ',', '.')) ?><?php
            if ($l['value2'] !== null): ?>/<?= App::e(number_format((float)$l['value2'], (int)$m['decimals'], ',', '.')) ?><?php endif; ?>
          <span class="u"><?= App::e($m['unit']) ?></span>
        </div>
        <div class="meta">
          <?= App::e($app->local($l['measured_at'], 'd.m.Y H:i')) ?>
          <?php if ($l['context'] !== ''): ?>
            · <?= App::e(VitalsRepository::CONTEXTS[$l['context']] ?? '') ?>
          <?php endif; ?>
        </div>
        <div class="foot-row">
          <?php if ($o['delta'] !== null && abs($o['delta']) > 0.0001):
                $up = $o['delta'] > 0; ?>
            <span class="delta <?= $up ? 'up' : 'down' ?>">
              <?= $up ? '▲' : '▼' ?> <?= App::e(number_format(abs($o['delta']), (int)$m['decimals'], ',', '.')) ?>
            </span>
          <?php else: ?><span class="delta">&nbsp;</span><?php endif; ?>
          <?= Chart::spark($o['spark']) ?>
        </div>
      </a>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
</div>

<div class="panel">
  <h2>Messwert erfassen</h2>
  <?php if (!$metrics): ?>
    <p class="sub">Es ist noch keine Metrik angelegt.</p>
  <?php else: ?>
  <form method="post">
    <?= Csrf::field() ?>
    <input type="hidden" name="action" value="record">

    <label for="metric_id">Messgröße</label>
    <select id="metric_id" name="metric_id" required>
      <?php foreach ($metrics as $m): ?>
        <option value="<?= (int)$m['id'] ?>" <?= (int)$m['id'] === $selected ? 'selected' : '' ?>
                data-second="<?= (int)$m['has_second'] ?>"
                data-unit="<?= App::e($m['unit']) ?>"
                data-label1="<?= App::e($m['label_first'] ?: 'Wert') ?>"
                data-label2="<?= App::e($m['label_second'] ?: 'Zweiter Wert') ?>">
          <?= App::e($m['label']) ?><?= $m['unit'] !== '' ? ' (' . App::e($m['unit']) . ')' : '' ?>
        </option>
      <?php endforeach; ?>
    </select>
    <?php $hasSecond = $selMetric && (int)$selMetric['has_second'] === 1; ?>
    <div class="field-row">
      <div>
        <label for="value" id="value-label">
          <?= App::e($hasSecond && $selMetric['label_first'] ? $selMetric['label_first'] : 'Wert') ?>
        </label>
        <input type="text" id="value" name="value" required inputmode="decimal"
               autocomplete="off" placeholder="<?= App::e($selMetric['unit'] ?? '') ?>">
      </div>
      <?php /* Immer im Markup, damit ui.js es ohne Nachladen einblenden kann.
               Ohne JavaScript entscheidet das serverseitige hidden-Attribut. */ ?>
      <div id="value2-wrap" <?= $hasSecond ? '' : 'hidden' ?>>
        <label for="value2" id="value2-label">
          <?= App::e($hasSecond ? ($selMetric['label_second'] ?: 'Zweiter Wert') : 'Zweiter Wert') ?>
        </label>
        <input type="text" id="value2" name="value2" inputmode="decimal" autocomplete="off"
               <?= $hasSecond ? 'required' : '' ?>>
      </div>
    </div>

    <label for="measured_at">Zeitpunkt</label>
    <input type="datetime-local" id="measured_at" name="measured_at" value="<?= App::e($nowLocal) ?>">

    <label for="context">Situation</label>
    <select id="context" name="context">
      <?php foreach (VitalsRepository::CONTEXTS as $k => $l): ?>
        <option value="<?= App::e($k) ?>"><?= App::e($l) ?></option>
      <?php endforeach; ?>
    </select>

    <label for="note">Notiz (optional)</label>
    <input type="text" id="note" name="note" maxlength="300" autocomplete="off">

    <button type="submit" class="auto">Speichern</button>
  </form>
  <?php endif; ?>
</div>

<div class="panel">
  <h2>Eigene Messgröße anlegen</h2>
  <p class="sub">
    Für alles, was nicht mitgeliefert ist – etwa INR, Gewicht eines Kindes
    oder ein Wert aus einer Spezialambulanz.
  </p>
  <form method="post">
    <?= Csrf::field() ?>
    <input type="hidden" name="action" value="new_metric">

    <div class="field-row">
      <div>
        <label for="label">Bezeichnung</label>
        <input type="text" id="label" name="label" required maxlength="96">
      </div>
      <div>
        <label for="unit">Einheit</label>
        <input type="text" id="unit" name="unit" maxlength="24">
      </div>
    </div>

    <div class="field-row">
      <div>
        <label for="mkey">Kurzname</label>
        <input type="text" id="mkey" name="mkey" required maxlength="48"
               pattern="[a-z0-9_]+" placeholder="z. B. inr">
      </div>
      <div>
        <label for="decimals">Nachkommastellen</label>
        <select id="decimals" name="decimals">
          <option value="0">0</option><option value="1">1</option>
          <option value="2">2</option><option value="3">3</option>
        </select>
      </div>
    </div>

    <div class="field-row">
      <div>
        <label for="ref_low">Orientierung von</label>
        <input type="text" id="ref_low" name="ref_low" inputmode="decimal">
      </div>
      <div>
        <label for="ref_high">bis</label>
        <input type="text" id="ref_high" name="ref_high" inputmode="decimal">
      </div>
    </div>

    <div class="field-row">
      <div>
        <label for="plaus_min">Möglich von</label>
        <input type="text" id="plaus_min" name="plaus_min" inputmode="decimal">
      </div>
      <div>
        <label for="plaus_max">bis</label>
        <input type="text" id="plaus_max" name="plaus_max" inputmode="decimal">
      </div>
    </div>
    <p class="hint">
      Die Plausibilitätsgrenzen fangen Tippfehler ab, der Orientierungsbereich
      dient nur der farblichen Kennzeichnung im Verlauf.
    </p>

    <button type="submit" class="auto secondary">Metrik anlegen</button>
  </form>
</div>
<?php View::end($app); ?>
