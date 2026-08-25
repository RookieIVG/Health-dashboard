<?php
declare(strict_types=1);
require __DIR__ . '/_init.php';

use Health\App;
use Health\Csrf;
use Health\VitalsRepository as Vitals;
use Health\View;

$app = App::boot();
$app->auth->requireLogin();

$repo  = new Vitals($app);
$error = $ok = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::requireValid();
    try {
        switch ($_POST['action'] ?? '') {
            case 'update':
                $repo->updateMetric((int)($_POST['metric_id'] ?? 0), $_POST);
                $ok = 'Gespeichert.';
                break;
            case 'toggle':
                $repo->toggleMetricActive((int)($_POST['metric_id'] ?? 0));
                $ok = 'Sichtbarkeit geändert.';
                break;
            case 'delete':
                $repo->deleteMetric((int)($_POST['metric_id'] ?? 0));
                $ok = 'Messgröße gelöscht.';
                break;
            case 'new_metric':
                $repo->createMetric($_POST);
                $ok = 'Messgröße angelegt.';
                break;
        }
    } catch (\Throwable $e) {
        $error = $e->getMessage();
    }
}

$metrics = $repo->metrics(false);
$editId  = (int)($_GET['edit'] ?? 0);
$edit    = null;
foreach ($metrics as $m) if ((int)$m['id'] === $editId) { $edit = $m; break; }

View::start($app, ['title' => 'Messgrößen – ' . $app->config['app']['name'], 'active' => 'vitals']);
?>
<?php View::flash($ok, 'ok'); View::flash($error, 'error'); ?>

<div class="panel">
  <h1>Messgrößen</h1>
  <p class="sub"><a href="<?= App::url('/vitals.php') ?>">zur Übersicht</a></p>

  <div class="table-wrap">
    <table class="stack">
      <thead><tr><th>Messgröße</th><th>Referenz</th><th>Sichtbar</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($metrics as $m):
            $n = $repo->metricUsageCount((int)$m['id']); ?>
        <tr>
          <td data-label="Messgröße">
            <?= App::e($m['label']) ?><?= $m['unit'] !== '' ? ' (' . App::e($m['unit']) . ')' : '' ?>
            <?php if ($m['user_id'] === null): ?><span class="mod">mitgeliefert</span><?php endif; ?>
          </td>
          <td data-label="Referenz">
            <?= $m['ref_low'] !== null || $m['ref_high'] !== null
                  ? App::e(rtrim(rtrim((string)$m['ref_low'],'0'),'.')) . '–' . App::e(rtrim(rtrim((string)$m['ref_high'],'0'),'.'))
                  : '–' ?>
          </td>
          <td data-label="Sichtbar">
            <form method="post" style="margin:0">
              <?= Csrf::field() ?>
              <input type="hidden" name="action" value="toggle">
              <input type="hidden" name="metric_id" value="<?= (int)$m['id'] ?>">
              <button type="submit" class="secondary small"><?= $m['is_active'] ? 'sichtbar' : 'ausgeblendet' ?></button>
            </form>
          </td>
          <td>
            <?php if ($m['user_id'] !== null): ?>
              <a class="btn secondary small" style="margin:0"
                 href="?edit=<?= (int)$m['id'] ?>#bearbeiten">Ändern</a>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <p class="hint">
    Mitgelieferte Messgrößen lassen sich ausblenden, aber inhaltlich nicht
    ändern. Ausgeblendete verschwinden aus der Erfassung und Übersicht;
    bereits gespeicherte Werte bleiben erhalten und über den Verlauf abrufbar.
  </p>
</div>

<?php if ($edit): ?>
<div class="panel" id="bearbeiten">
  <h2>Messgröße bearbeiten: <?= App::e($edit['label']) ?></h2>
  <?php $used = $repo->metricUsageCount((int)$edit['id']); ?>

  <form method="post">
    <?= Csrf::field() ?>
    <input type="hidden" name="action" value="update">
    <input type="hidden" name="metric_id" value="<?= (int)$edit['id'] ?>">

    <div class="field-row">
      <div>
        <label for="label">Bezeichnung</label>
        <input type="text" id="label" name="label" required maxlength="96" value="<?= App::e($edit['label']) ?>">
      </div>
      <div>
        <label for="unit">Einheit</label>
        <input type="text" id="unit" name="unit" maxlength="24" value="<?= App::e($edit['unit']) ?>">
      </div>
      <div>
        <label for="decimals">Nachkommastellen</label>
        <select id="decimals" name="decimals">
          <?php for ($i = 0; $i <= 3; $i++): ?>
            <option value="<?= $i ?>" <?= (int)$edit['decimals'] === $i ? 'selected' : '' ?>><?= $i ?></option>
          <?php endfor; ?>
        </select>
      </div>
    </div>

    <label style="display:flex;align-items:center;gap:8px;font-weight:400">
      <input type="checkbox" name="has_second" value="1" style="width:auto"
             <?= $edit['has_second'] ? 'checked' : '' ?>> Zweiter Wert (z. B. Blutdruck)
    </label>

    <div class="field-row">
      <div>
        <label for="label_first">Bezeichnung 1. Wert</label>
        <input type="text" id="label_first" name="label_first" maxlength="48" value="<?= App::e($edit['label_first'] ?? '') ?>">
      </div>
      <div>
        <label for="label_second">Bezeichnung 2. Wert</label>
        <input type="text" id="label_second" name="label_second" maxlength="48" value="<?= App::e($edit['label_second'] ?? '') ?>">
      </div>
    </div>

    <div class="field-row">
      <div>
        <label for="ref_low">Orientierung von</label>
        <input type="text" id="ref_low" name="ref_low" inputmode="decimal"
               value="<?= App::e($edit['ref_low'] !== null ? (string)(float)$edit['ref_low'] : '') ?>">
      </div>
      <div>
        <label for="ref_high">bis</label>
        <input type="text" id="ref_high" name="ref_high" inputmode="decimal"
               value="<?= App::e($edit['ref_high'] !== null ? (string)(float)$edit['ref_high'] : '') ?>">
      </div>
      <div>
        <label for="ref_low2">2. Wert von</label>
        <input type="text" id="ref_low2" name="ref_low2" inputmode="decimal"
               value="<?= App::e($edit['ref_low2'] !== null ? (string)(float)$edit['ref_low2'] : '') ?>">
      </div>
      <div>
        <label for="ref_high2">bis</label>
        <input type="text" id="ref_high2" name="ref_high2" inputmode="decimal"
               value="<?= App::e($edit['ref_high2'] !== null ? (string)(float)$edit['ref_high2'] : '') ?>">
      </div>
    </div>

    <div class="field-row">
      <div>
        <label for="plaus_min">Möglich von</label>
        <input type="text" id="plaus_min" name="plaus_min" inputmode="decimal"
               value="<?= App::e($edit['plaus_min'] !== null ? (string)(float)$edit['plaus_min'] : '') ?>">
      </div>
      <div>
        <label for="plaus_max">bis</label>
        <input type="text" id="plaus_max" name="plaus_max" inputmode="decimal"
               value="<?= App::e($edit['plaus_max'] !== null ? (string)(float)$edit['plaus_max'] : '') ?>">
      </div>
    </div>

    <button type="submit" class="auto secondary">Speichern</button>
    <p class="foot"><a href="<?= App::url('/vitals_metrics.php') ?>">Bearbeitung abbrechen</a></p>
  </form>

  <div class="actions">
    <?php if ($used === 0): ?>
      <form method="post" data-confirm="Diese Messgröße endgültig löschen?">
        <?= Csrf::field() ?>
        <input type="hidden" name="action" value="delete">
        <input type="hidden" name="metric_id" value="<?= (int)$edit['id'] ?>">
        <button type="submit" class="secondary small">Löschen</button>
      </form>
    <?php else: ?>
      <p class="hint">Löschen erst möglich, wenn keine Messung mehr vorliegt (<?= $used ?> vorhanden). Stattdessen ausblenden.</p>
    <?php endif; ?>
  </div>
</div>
<?php endif; ?>
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
