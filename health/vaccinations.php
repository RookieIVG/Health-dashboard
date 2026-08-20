<?php
declare(strict_types=1);
require __DIR__ . '/_init.php';

use Health\App;
use Health\Csrf;
use Health\VaccinationsRepository as Vacc;
use Health\View;

$app = App::boot();
$app->auth->requireLogin();

$repo  = new Vacc($app);
$error = $ok = null;
$edit  = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::requireValid();
    try {
        switch ($_POST['action'] ?? '') {
            case 'save':
                $repo->store($_POST, (int)($_POST['id'] ?? 0) ?: null);
                $ok = 'Gespeichert.';
                break;
            case 'delete':
                $repo->delete((int)($_POST['id'] ?? 0));
                $ok = 'Eintrag gelöscht.';
                break;
        }
    } catch (\Throwable $e) {
        $error = $e->getMessage();
    }
}

if (($_GET['edit'] ?? '') !== '') $edit = $repo->find((int)$_GET['edit']);
$list = $repo->listAll();
$due  = $repo->dueSoon();
$today = date('Y-m-d');

View::start($app, ['title' => 'Impfpass – ' . $app->config['app']['name'], 'active' => 'vaccinations']);
?>
<?php View::flash($ok, 'ok'); View::flash($error, 'error'); ?>

<?php if ($due): ?>
<div class="panel">
  <h2>Auffrischung fällig</h2>
  <?php foreach ($due as $v):
        $overdue = $v['next_due_date'] < $today; ?>
    <div class="ev">
      <div class="t" style="width:80px;<?= $overdue ? 'color:var(--danger);font-weight:600' : '' ?>">
        <?= App::e(date('m.Y', strtotime($v['next_due_date']))) ?>
      </div>
      <div class="body">
        <div class="title"><?= App::e($v['vaccine']) ?></div>
        <div class="sum"><?= App::e($overdue ? 'überfällig' : 'anstehend') ?></div>
      </div>
    </div>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<div class="panel">
  <h1>Impfpass</h1>
  <p class="sub"><?= count($list) ?> Einträge</p>

  <?php if (!$list): ?>
    <p class="empty">Noch keine Impfung erfasst.</p>
  <?php else: ?>
    <?php foreach ($list as $v): ?>
      <div class="ev">
        <div class="t" style="width:74px"><?= App::e(date('d.m.Y', strtotime($v['given_date']))) ?></div>
        <div class="body">
          <div class="title">
            <?= App::e($v['vaccine']) ?>
            <?php if ($v['dose_number']): ?><span class="mod"><?= (int)$v['dose_number'] ?>. Dosis</span><?php endif; ?>
          </div>
          <?php if ($v['doctor'] || $v['location']): ?>
            <div class="sum"><?= App::e(implode(' · ', array_filter([$v['location'], $v['doctor']]))) ?></div>
          <?php endif; ?>
        </div>
        <div class="t" style="width:auto">
          <a class="btn secondary small" style="margin:0" href="?edit=<?= (int)$v['id'] ?>">Ändern</a>
        </div>
      </div>
    <?php endforeach; ?>
  <?php endif; ?>
</div>

<div class="panel">
  <h2><?= $edit ? 'Eintrag bearbeiten' : 'Neue Impfung' ?></h2>
  <form method="post">
    <?= Csrf::field() ?>
    <input type="hidden" name="action" value="save">
    <?php if ($edit): ?><input type="hidden" name="id" value="<?= (int)$edit['id'] ?>"><?php endif; ?>

    <div class="field-row">
      <div>
        <label for="vaccine">Impfstoff</label>
        <input type="text" id="vaccine" name="vaccine" required maxlength="200"
               value="<?= App::e($edit['vaccine'] ?? '') ?>"
               list="vaccine-suggest" placeholder="z. B. Tetanus/Diphtherie">
        <datalist id="vaccine-suggest">
          <?php foreach (['FSME','Tetanus/Diphtherie/Pertussis','Grippe','COVID-19','Hepatitis A','Hepatitis B',
                          'MMR (Masern/Mumps/Röteln)','Pneumokokken','HPV','Meningokokken','Gelbfieber'] as $s): ?>
            <option value="<?= App::e($s) ?>">
          <?php endforeach; ?>
        </datalist>
      </div>
      <div>
        <label for="dose_number">Dosis-Nr.</label>
        <input type="number" id="dose_number" name="dose_number" min="1" max="20"
               value="<?= App::e((string)($edit['dose_number'] ?? '')) ?>">
      </div>
    </div>

    <div class="field-row">
      <div>
        <label for="given_date">Datum</label>
        <input type="date" id="given_date" name="given_date"
               value="<?= App::e($edit['given_date'] ?? $today) ?>" required>
      </div>
      <div>
        <label for="next_due_date">Nächste Auffrischung</label>
        <input type="date" id="next_due_date" name="next_due_date"
               value="<?= App::e($edit['next_due_date'] ?? '') ?>">
      </div>
    </div>

    <div class="field-row">
      <div>
        <label for="location">Ort</label>
        <input type="text" id="location" name="location" maxlength="200"
               value="<?= App::e($edit['location'] ?? '') ?>">
      </div>
      <div>
        <label for="doctor">Ärztin / Arzt</label>
        <input type="text" id="doctor" name="doctor" maxlength="200"
               value="<?= App::e($edit['doctor'] ?? '') ?>">
      </div>
    </div>

    <label for="lot">Chargennummer</label>
    <input type="text" id="lot" name="lot" maxlength="120" value="<?= App::e($edit['lot'] ?? '') ?>">

    <label for="note">Notiz</label>
    <textarea id="note" name="note" rows="3"><?= App::e($edit['note'] ?? '') ?></textarea>

    <button type="submit" class="auto"><?= $edit ? 'Aktualisieren' : 'Anlegen' ?></button>
    <?php if ($edit): ?><p class="foot"><a href="<?= App::url('/vaccinations.php') ?>">Abbrechen</a></p><?php endif; ?>
  </form>

  <?php if ($edit): ?>
  <div class="actions">
    <form method="post" data-confirm="Eintrag löschen?">
      <?= Csrf::field() ?>
      <input type="hidden" name="action" value="delete">
      <input type="hidden" name="id" value="<?= (int)$edit['id'] ?>">
      <button type="submit" class="secondary small">Löschen</button>
    </form>
  </div>
  <?php endif; ?>
</div>
<?php View::end($app); ?>
