<?php
declare(strict_types=1);
require __DIR__ . '/_init.php';

use Health\App;
use Health\Csrf;
use Health\ContactsRepository;
use Health\LabRepository;
use Health\View;

$app = App::boot();
$app->auth->requireLogin();

$repo    = new LabRepository($app);
$visitId = (int)($_GET['id'] ?? 0) ?: null;
$visit   = $visitId ? $repo->visit($visitId) : null;
$error   = $ok = null;
$institutions = (new ContactsRepository($app))->listAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::requireValid();
    try {
        switch ($_POST['action'] ?? '') {
            case 'save':
                $vals = [];
                foreach ((array)($_POST['v'] ?? []) as $tid => $val) $vals[(int)$tid] = $val;
                $id = $repo->saveVisit(
                    (string)($_POST['visit_date'] ?? ''), $vals,
                    (int)($_POST['contact_id'] ?? 0) ?: null,
                    trim((string)($_POST['contact_new'] ?? '')) ?: null,
                    trim((string)($_POST['note'] ?? '')) ?: null,
                    $visitId
                );
                header('Location: ' . App::url('/lab_visit.php?id=' . $id));
                exit;
            case 'delete':
                $repo->delete($visitId);
                header('Location: ' . App::url('/lab.php'));
                exit;
        }
    } catch (\Throwable $e) {
        $error = $e->getMessage();
    }
}

$tests    = $repo->tests();
$existing = [];
if ($visit) {
    foreach ($visit['results'] as $r) {
        $existing[(int)$r['test_id']] = $r['value_num'] ?? $r['value_text'];
    }
}
$today = date('Y-m-d');

$byCategory = [];
foreach ($tests as $t) $byCategory[$t['category']][] = $t;

View::start($app, ['title' => 'Laborbefund – ' . $app->config['app']['name'], 'active' => 'lab']);
?>
<?php View::flash($ok, 'ok'); View::flash($error, 'error'); ?>

<div class="panel">
  <h1><?= $visit ? 'Laborbefund bearbeiten' : 'Neuer Laborbefund' ?></h1>
  <p class="sub"><a href="<?= App::url('/lab.php') ?>">zur Übersicht</a></p>

  <form method="post">
    <?= Csrf::field() ?>
    <input type="hidden" name="action" value="save">

    <div class="field-row">
      <div>
        <label for="visit_date">Datum der Abnahme</label>
        <input type="date" id="visit_date" name="visit_date"
               value="<?= App::e($visit['visit_date'] ?? $today) ?>" required>
      </div>
    </div>

    <label for="contact_id">Einrichtung / Kontakt</label>
    <select id="contact_id" name="contact_id">
      <option value="">– keine Auswahl –</option>
      <?php foreach ($institutions as $inst): ?>
        <option value="<?= (int)$inst['id'] ?>"
          <?= isset($visit['contact_id']) && (int)$visit['contact_id'] === (int)$inst['id'] ? 'selected' : '' ?>>
          <?= App::e($inst['name']) ?>
        </option>
      <?php endforeach; ?>
    </select>
    <label for="contact_new">oder neu anlegen</label>
    <input type="text" id="contact_new" name="contact_new" maxlength="200"
           placeholder="nur ausfüllen, wenn nicht in der Liste oben">
    <p class="hint">
      Kontakte werden zentral verwaltet – eine Umbenennung wirkt sich
      überall aus, wo sie zugeordnet sind. Verwaltung unter
      <a href="<?= App::url('/contacts.php') ?>">Kontakte</a>.
    </p>

    <?php foreach ($byCategory as $cat => $list): ?>
      <h2 style="margin-top:20px"><?= App::e($cat ?: 'Sonstiges') ?></h2>
      <div class="field-row" style="flex-wrap:wrap">
        <?php foreach ($list as $t): ?>
          <div style="flex:1 1 140px;min-width:140px">
            <label for="t<?= (int)$t['id'] ?>">
              <?= App::e($t['label']) ?><?= $t['unit'] !== '' ? ' (' . App::e($t['unit']) . ')' : '' ?>
            </label>
            <input type="text" id="t<?= (int)$t['id'] ?>" name="v[<?= (int)$t['id'] ?>]"
                   inputmode="decimal" autocomplete="off"
                   value="<?= App::e((string)($existing[(int)$t['id']] ?? '')) ?>"
                   <?php if ($t['ref_low'] !== null || $t['ref_high'] !== null): ?>
                     placeholder="<?= App::e(rtrim(rtrim((string)$t['ref_low'],'0'),'.')) ?>–<?= App::e(rtrim(rtrim((string)$t['ref_high'],'0'),'.')) ?>"
                   <?php endif; ?>>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endforeach; ?>

    <label for="note" style="margin-top:20px">Notiz</label>
    <textarea id="note" name="note" rows="3"><?= App::e($visit['note'] ?? '') ?></textarea>

    <button type="submit" class="auto"><?= $visit ? 'Aktualisieren' : 'Speichern' ?></button>
  </form>

  <?php if ($visit): ?>
  <div class="actions">
    <form method="post" data-confirm="Diesen Laborbefund samt Werten löschen?">
      <?= Csrf::field() ?>
      <input type="hidden" name="action" value="delete">
      <button type="submit" class="secondary small">Löschen</button>
    </form>
  </div>
  <?php endif; ?>
</div>
<?php View::end($app); ?>
