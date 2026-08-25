<?php
declare(strict_types=1);
require __DIR__ . '/_init.php';

use Health\App;
use Health\Csrf;
use Health\DiagnosesRepository as Diag;
use Health\Modules;
use Health\View;

$app = App::boot();
$app->auth->requireLogin();

$repo  = new Diag($app);
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::requireValid();
    try {
        if (($_POST['action'] ?? '') === 'create') {
            $id = $repo->store($_POST);
            header('Location: ' . App::url('/diagnosis.php?id=' . $id));
            exit;
        }
    } catch (\Throwable $e) {
        $error = $e->getMessage();
    }
}

$showClosed = !empty($_GET['all']);
$list   = $repo->listAll($showClosed);
$counts = $repo->countsByStatus();
$today  = date('Y-m-d');

View::start($app, ['title' => 'Diagnosen – ' . $app->config['app']['name'], 'active' => 'diagnoses']);
?>
<?php View::flash($error, 'error'); ?>

<div class="panel">
  <h1><?= View::moduleDot(Modules::DIAGNOSIS) ?>Diagnosen</h1>
  <p class="sub"><?= count($list) ?> Einträge<?= $showClosed ? '' : ' (nur laufende)' ?></p>

  <div class="filters">
    <a class="chip <?= $showClosed ? '' : 'active' ?>" href="?">Laufend</a>
    <a class="chip <?= $showClosed ? 'active' : '' ?>" href="?all=1">Alle</a>
    <?php foreach (Diag::STATUS as $k => $l): if (empty($counts[$k])) continue; ?>
      <span class="chip"><?= App::e($l) ?><span class="n"><?= (int)$counts[$k] ?></span></span>
    <?php endforeach; ?>
  </div>
</div>

<div class="panel">
  <?php if (!$list): ?>
    <p class="empty">Noch keine Diagnose erfasst.</p>
  <?php else: ?>
    <?php foreach ($list as $d):
          $closed = $d['end_date'] !== null;
          $sev = (int)$d['severity']; ?>
      <div class="ev<?= $sev >= 3 ? ' sev3' : ($sev === 2 ? ' sev2' : '') ?>">
        <div class="t" style="width:62px"><?= App::e(date('m.Y', strtotime($d['onset_date']))) ?></div>
        <div class="body">
          <div class="title">
            <a href="<?= App::url('/diagnosis.php?id=' . (int)$d['id']) ?>"><?= App::e($d['title']) ?></a>
            <?php if ($d['is_pinned']): ?><span class="mod">Notfallblatt</span><?php endif; ?>
            <?php if ($d['icd']): ?><span class="mod"><?= App::e($d['icd']) ?></span><?php endif; ?>
            <span class="mod"><?= App::e(Diag::STATUS[$d['status']]) ?></span>
          </div>
          <div class="sum">
            seit <?= App::e(date('d.m.Y', strtotime($d['onset_date']))) ?>
            <?php if ($closed): ?>
              bis <?= App::e(date('d.m.Y', strtotime($d['end_date']))) ?>
            <?php endif; ?>
            · <?= App::e(Diag::duration($d)) ?>
            <?php if ($sev > 0): ?> · <?= App::e(Diag::SEVERITY[$sev]) ?><?php endif; ?>
            <?php if ($d['doctor']): ?> · <?= App::e($d['doctor']) ?><?php endif; ?>
          </div>
        </div>
      </div>
    <?php endforeach; ?>
  <?php endif; ?>
</div>

<div class="panel">
  <h2>Neue Diagnose</h2>
  <form method="post">
    <?= Csrf::field() ?>
    <input type="hidden" name="action" value="create">

    <label for="title">Bezeichnung</label>
    <input type="text" id="title" name="title" required maxlength="200"
           placeholder="z. B. Diabetes mellitus Typ 2">

    <div class="field-row">
      <div>
        <label for="icd">ICD-10 (optional)</label>
        <input type="text" id="icd" name="icd" maxlength="10" placeholder="E11.9"
               autocapitalize="characters" spellcheck="false">
      </div>
      <div>
        <label for="onset_date">Beginn</label>
        <input type="date" id="onset_date" name="onset_date" value="<?= App::e($today) ?>" required>
      </div>
    </div>

    <div class="field-row">
      <div>
        <label for="status">Status</label>
        <select id="status" name="status">
          <?php foreach (Diag::STATUS as $k => $l): ?>
            <option value="<?= App::e($k) ?>" <?= $k === 'active' ? 'selected' : '' ?>>
              <?= App::e($l) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div>
        <label for="severity">Ausprägung</label>
        <select id="severity" name="severity">
          <?php foreach (Diag::SEVERITY as $k => $l): ?>
            <option value="<?= (int)$k ?>"><?= App::e($l) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
    </div>

    <label for="doctor">Diagnostiziert von</label>
    <input type="text" id="doctor" name="doctor" maxlength="200">

    <label for="note">Notizen</label>
    <textarea id="note" name="note" rows="4"></textarea>

    <label for="tags">Tags (Komma-getrennt)</label>
    <input type="text" id="tags" name="tags" autocomplete="off">

    <label style="display:flex;align-items:center;gap:8px;font-weight:400">
      <input type="checkbox" name="is_pinned" value="1" style="width:auto">
      Für das Notfallblatt vormerken
    </label>

    <button type="submit" class="auto">Anlegen</button>
  </form>
</div>
<?php View::end($app); ?>
