<?php
declare(strict_types=1);
require __DIR__ . '/_init.php';

use Health\App;
use Health\Csrf;
use Health\LabRepository as Lab;
use Health\View;

$app = App::boot();
$app->auth->requireLogin();

$repo  = new Lab($app);
$error = $ok = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::requireValid();
    try {
        switch ($_POST['action'] ?? '') {
            case 'new':
                $repo->createTest($_POST);
                $ok = 'Test angelegt.';
                break;
            case 'update':
                $repo->updateTest((int)($_POST['test_id'] ?? 0), $_POST);
                $ok = 'Gespeichert.';
                break;
            case 'toggle':
                $repo->toggleTestActive((int)($_POST['test_id'] ?? 0));
                $ok = 'Sichtbarkeit geändert.';
                break;
            case 'delete':
                $repo->deleteTest((int)($_POST['test_id'] ?? 0));
                $ok = 'Test gelöscht.';
                break;
        }
    } catch (\Throwable $e) {
        $error = $e->getMessage();
    }
}

$tests  = $repo->tests(false);
$editId = (int)($_GET['edit'] ?? 0);
$edit   = null;
foreach ($tests as $t) if ((int)$t['id'] === $editId) { $edit = $t; break; }

$byCategory = [];
foreach ($tests as $t) $byCategory[$t['category']][] = $t;

View::start($app, ['title' => 'Labortests – ' . $app->config['app']['name'], 'active' => 'lab']);
?>
<?php View::flash($ok, 'ok'); View::flash($error, 'error'); ?>

<div class="panel">
  <h1>Laborparameter</h1>
  <p class="sub"><a href="<?= App::url('/lab.php') ?>">zur Übersicht</a>
     · <a href="<?= App::url('/lab_cumulative.php') ?>">Kumulativbefund</a></p>

  <?php foreach ($byCategory as $cat => $list): ?>
    <h2 style="margin-top:16px"><?= App::e($cat ?: 'Sonstiges') ?></h2>
    <div class="table-wrap">
      <table class="stack">
        <thead><tr><th>Laborparameter</th><th>Referenz</th><th>Sichtbar</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($list as $t): ?>
          <tr>
            <td data-label="Test">
              <?= App::e($t['label']) ?><?= $t['unit'] !== '' ? ' (' . App::e($t['unit']) . ')' : '' ?>
              <?php if ($t['user_id'] === null): ?><span class="mod">mitgeliefert</span><?php endif; ?>
            </td>
            <td data-label="Referenz">
              <?= $t['ref_low'] !== null || $t['ref_high'] !== null
                    ? App::e(rtrim(rtrim((string)$t['ref_low'],'0'),'.')) . '–' . App::e(rtrim(rtrim((string)$t['ref_high'],'0'),'.'))
                    : '–' ?>
            </td>
            <td data-label="Sichtbar">
              <form method="post" style="margin:0">
                <?= Csrf::field() ?>
                <input type="hidden" name="action" value="toggle">
                <input type="hidden" name="test_id" value="<?= (int)$t['id'] ?>">
                <button type="submit" class="secondary small"><?= $t['is_active'] ? 'sichtbar' : 'ausgeblendet' ?></button>
              </form>
            </td>
            <td>
              <?php if ($t['user_id'] !== null): ?>
                <a class="btn secondary small" style="margin:0" href="?edit=<?= (int)$t['id'] ?>#bearbeiten">Ändern</a>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endforeach; ?>
  <p class="hint">
    Mitgelieferte Laborparameter lassen sich ausblenden, aber inhaltlich nicht ändern.
    Werte bereits erfasster Befundtermine bleiben davon unberührt.
  </p>
</div>

<?php if ($edit): ?>
<div class="panel" id="bearbeiten">
  <h2>Test bearbeiten: <?= App::e($edit['label']) ?></h2>
  <?php $used = $repo->testUsageCount((int)$edit['id']); ?>

  <form method="post">
    <?= Csrf::field() ?>
    <input type="hidden" name="action" value="update">
    <input type="hidden" name="test_id" value="<?= (int)$edit['id'] ?>">
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
          <?php for ($i = 0; $i <= 4; $i++): ?>
            <option value="<?= $i ?>" <?= (int)$edit['decimals'] === $i ? 'selected' : '' ?>><?= $i ?></option>
          <?php endfor; ?>
        </select>
      </div>
    </div>
    <div class="field-row">
      <div>
        <label for="ref_low">Referenz von</label>
        <input type="text" id="ref_low" name="ref_low" inputmode="decimal"
               value="<?= App::e($edit['ref_low'] !== null ? (string)(float)$edit['ref_low'] : '') ?>">
      </div>
      <div>
        <label for="ref_high">bis</label>
        <input type="text" id="ref_high" name="ref_high" inputmode="decimal"
               value="<?= App::e($edit['ref_high'] !== null ? (string)(float)$edit['ref_high'] : '') ?>">
      </div>
      <div>
        <label for="category">Kategorie</label>
        <input type="text" id="category" name="category" maxlength="64" value="<?= App::e($edit['category']) ?>">
      </div>
    </div>
    <button type="submit" class="auto secondary">Speichern</button>
    <p class="foot"><a href="<?= App::url('/lab_tests.php') ?>">Bearbeitung abbrechen</a></p>
  </form>

  <div class="actions">
    <?php if ($used === 0): ?>
      <form method="post" data-confirm="Diesen Test endgültig löschen?">
        <?= Csrf::field() ?>
        <input type="hidden" name="action" value="delete">
        <input type="hidden" name="test_id" value="<?= (int)$edit['id'] ?>">
        <button type="submit" class="secondary small">Löschen</button>
      </form>
    <?php else: ?>
      <p class="hint">Löschen erst möglich, wenn kein Befundwert mehr vorliegt (<?= $used ?> vorhanden). Stattdessen ausblenden.</p>
    <?php endif; ?>
  </div>
</div>
<?php endif; ?>

<div class="panel">
  <h2>Neuen Laborparameter anlegen</h2>
  <form method="post">
    <?= Csrf::field() ?>
    <input type="hidden" name="action" value="new">
    <div class="field-row">
      <div>
        <label for="nlabel">Bezeichnung</label>
        <input type="text" id="nlabel" name="label" required maxlength="96">
      </div>
      <div>
        <label for="nunit">Einheit</label>
        <input type="text" id="nunit" name="unit" maxlength="24">
      </div>
    </div>
    <div class="field-row">
      <div>
        <label for="nrl">Referenz von</label>
        <input type="text" id="nrl" name="ref_low" inputmode="decimal">
      </div>
      <div>
        <label for="nrh">bis</label>
        <input type="text" id="nrh" name="ref_high" inputmode="decimal">
      </div>
      <div>
        <label for="ncat">Kategorie</label>
        <input type="text" id="ncat" name="category" maxlength="64" placeholder="z. B. Sonstiges">
      </div>
    </div>
    <button type="submit" class="auto secondary">Anlegen</button>
  </form>
</div>
<?php View::end($app); ?>
