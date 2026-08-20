<?php
declare(strict_types=1);
require __DIR__ . '/_init.php';

use Health\AllergiesRepository as Allergies;
use Health\App;
use Health\Csrf;
use Health\View;

$app = App::boot();
$app->auth->requireLogin();

$repo  = new Allergies($app);
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
$list     = $repo->listAll();
$critical = $repo->critical();

View::start($app, ['title' => 'Allergien – ' . $app->config['app']['name'], 'active' => 'allergies']);
?>
<?php View::flash($ok, 'ok'); View::flash($error, 'error'); ?>

<?php if ($critical): ?>
<div class="msg error">
  <strong>Schwere Reaktionen:</strong>
  <?= App::e(implode(', ', array_map(fn($a) => (string)$a['substance'], $critical))) ?>
</div>
<?php endif; ?>

<div class="panel">
  <h1>Allergien und Unverträglichkeiten</h1>
  <p class="sub"><?= count($list) ?> Einträge</p>

  <?php if (!$list): ?>
    <p class="empty">Keine Allergien bekannt.</p>
  <?php else: ?>
    <?php foreach ($list as $a):
          $sev = (int)$a['severity']; ?>
      <div class="ev<?= $sev >= 3 ? ' sev3' : ($sev === 2 ? ' sev2' : '') ?>">
        <div class="body">
          <div class="title">
            <?= App::e($a['substance']) ?>
            <span class="mod"><?= App::e(Allergies::KINDS[$a['kind']]) ?></span>
            <span class="mod"><?= App::e(Allergies::SEVERITY[$sev]) ?></span>
            <?php if ($a['is_pinned']): ?><span class="mod">Notfallblatt</span><?php endif; ?>
            <?php if ($a['status'] === 'resolved'): ?><span class="mod">nicht mehr aktuell</span><?php endif; ?>
          </div>
          <div class="sum">
            <?= App::e(implode(' · ', array_filter([
                  Allergies::CATEGORIES[$a['category']],
                  $a['reaction'],
                  $a['onset_date'] ? 'seit ' . date('m.Y', strtotime($a['onset_date'])) : null,
               ]))) ?>
          </div>
        </div>
        <div class="t" style="width:auto">
          <a class="btn secondary small" style="margin:0" href="?edit=<?= (int)$a['id'] ?>">Ändern</a>
        </div>
      </div>
    <?php endforeach; ?>
  <?php endif; ?>
</div>

<div class="panel">
  <h2><?= $edit ? 'Eintrag bearbeiten' : 'Neuer Eintrag' ?></h2>
  <form method="post">
    <?= Csrf::field() ?>
    <input type="hidden" name="action" value="save">
    <?php if ($edit): ?><input type="hidden" name="id" value="<?= (int)$edit['id'] ?>"><?php endif; ?>

    <label for="substance">Auslöser</label>
    <input type="text" id="substance" name="substance" required maxlength="200"
           value="<?= App::e($edit['substance'] ?? '') ?>"
           placeholder="z. B. Penicillin, Gräserpollen, Laktose">

    <div class="field-row">
      <div>
        <label for="category">Bereich</label>
        <select id="category" name="category">
          <?php foreach (Allergies::CATEGORIES as $k => $l): ?>
            <option value="<?= App::e($k) ?>" <?= ($edit['category'] ?? '') === $k ? 'selected' : '' ?>>
              <?= App::e($l) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div>
        <label for="kind">Art</label>
        <select id="kind" name="kind">
          <?php foreach (Allergies::KINDS as $k => $l): ?>
            <option value="<?= App::e($k) ?>" <?= ($edit['kind'] ?? '') === $k ? 'selected' : '' ?>>
              <?= App::e($l) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div>
        <label for="severity">Schwere</label>
        <select id="severity" name="severity">
          <?php foreach (Allergies::SEVERITY as $k => $l): ?>
            <option value="<?= (int)$k ?>" <?= (int)($edit['severity'] ?? 1) === $k ? 'selected' : '' ?>>
              <?= App::e($l) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
    </div>

    <label for="reaction">Reaktion</label>
    <input type="text" id="reaction" name="reaction" maxlength="300"
           value="<?= App::e($edit['reaction'] ?? '') ?>"
           placeholder="z. B. Hautausschlag, Atemnot, Bauchschmerzen">

    <div class="field-row">
      <div>
        <label for="onset_date">Bekannt seit</label>
        <input type="date" id="onset_date" name="onset_date" value="<?= App::e($edit['onset_date'] ?? '') ?>">
      </div>
      <div>
        <label for="status">Status</label>
        <select id="status" name="status">
          <option value="active" <?= ($edit['status'] ?? 'active') === 'active' ? 'selected' : '' ?>>aktuell</option>
          <option value="resolved" <?= ($edit['status'] ?? '') === 'resolved' ? 'selected' : '' ?>>nicht mehr aktuell</option>
        </select>
      </div>
    </div>

    <label for="note">Notizen</label>
    <textarea id="note" name="note" rows="3"><?= App::e($edit['note'] ?? '') ?></textarea>

    <label style="display:flex;align-items:center;gap:8px;font-weight:400">
      <input type="checkbox" name="is_pinned" value="1" style="width:auto"
             <?= !empty($edit['is_pinned']) ? 'checked' : '' ?>>
      Für das Notfallblatt vormerken
    </label>

    <button type="submit" class="auto"><?= $edit ? 'Aktualisieren' : 'Anlegen' ?></button>
    <?php if ($edit): ?>
      <p class="foot"><a href="<?= App::url('/allergies.php') ?>">Bearbeitung abbrechen</a></p>
    <?php endif; ?>
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
