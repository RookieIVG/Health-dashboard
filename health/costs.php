<?php
declare(strict_types=1);
require __DIR__ . '/_init.php';

use Health\App;
use Health\CostsRepository as Costs;
use Health\Csrf;
use Health\Modules;
use Health\View;

$app = App::boot();
$app->auth->requireLogin();

$repo  = new Costs($app);
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

$years = $repo->years();
$year  = (string)($_GET['year'] ?? ($years[0] ?? date('Y')));
if (!in_array($year, $years, true) && $years) $year = $years[0];

$list    = $repo->listAll($year);
$summary = $repo->yearSummary($year);
$open    = $repo->openSubmissions();
$today   = date('Y-m-d');

$fmt = fn($n) => number_format((float)$n, 2, ',', '.') . ' €';

View::start($app, ['title' => 'Kosten – ' . $app->config['app']['name'], 'active' => 'costs']);
?>
<?php View::flash($ok, 'ok'); View::flash($error, 'error'); ?>

<div class="panel">
  <h1><?= View::moduleDot(Modules::COST) ?>Kosten und Erstattungen</h1>
  <?php if ($years): ?>
  <div class="filters">
    <?php foreach ($years as $y): ?>
      <a class="chip <?= $year === (string)$y ? 'active' : '' ?>" href="?year=<?= (int)$y ?>"><?= (int)$y ?></a>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
</div>

<div class="panel">
  <h2><?= App::e($year) ?> im Überblick</h2>
  <?php if ($summary['n'] === 0): ?>
    <p class="empty">Keine Einträge in diesem Jahr.</p>
  <?php else: ?>
    <div class="table-wrap">
      <table>
        <tr><td>Ausgaben gesamt</td><td style="text-align:right"><?= App::e($fmt($summary['total'])) ?></td></tr>
        <tr><td>Erstattet</td><td style="text-align:right"><?= App::e($fmt($summary['reimbursed'])) ?></td></tr>
        <tr><td><strong>Eigenanteil</strong></td>
            <td style="text-align:right"><strong><?= App::e($fmt($summary['out_of_pocket'])) ?></strong></td></tr>
      </table>
    </div>
    <p class="hint">Der Eigenanteil ist die Grundlage für außergewöhnliche Belastungen in der Steuererklärung – Belege prüfen, das hier ersetzt keine Buchhaltung.</p>
    <?php if ($summary['by_category']): ?>
      <div class="filters" style="margin-top:10px">
        <?php foreach ($summary['by_category'] as $cat => $sum): ?>
          <span class="chip"><?= App::e(Costs::CATEGORIES[$cat]) ?><span class="n"><?= App::e($fmt($sum)) ?></span></span>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  <?php endif; ?>
</div>

<?php if ($open): ?>
<div class="panel">
  <h2>Offene Einreichungen</h2>
  <?php foreach ($open as $c): ?>
    <div class="ev">
      <div class="t" style="width:74px"><?= App::e(date('d.m.Y', strtotime($c['cost_date']))) ?></div>
      <div class="body">
        <div class="title"><?= App::e($c['description']) ?>
          <span class="mod"><?= App::e(Costs::REIMB_STATUS[$c['reimbursement_status']]) ?></span></div>
        <div class="sum"><?= App::e($fmt($c['amount'])) ?></div>
      </div>
    </div>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<div class="panel">
  <h2><?= App::e($year) ?>: Einträge</h2>
  <?php if (!$list): ?>
    <p class="empty">Keine Einträge.</p>
  <?php else: ?>
    <?php foreach ($list as $c): ?>
      <div class="ev">
        <div class="t" style="width:74px"><?= App::e(date('d.m.Y', strtotime($c['cost_date']))) ?></div>
        <div class="body">
          <div class="title">
            <?= App::e($c['description']) ?>
            <span class="mod"><?= App::e(Costs::CATEGORIES[$c['category']]) ?></span>
          </div>
          <div class="sum">
            <?= App::e($fmt($c['amount'])) ?>
            · <?= App::e(Costs::REIMB_STATUS[$c['reimbursement_status']]) ?>
            <?= $c['provider'] ? ' · ' . App::e($c['provider']) : '' ?>
          </div>
        </div>
        <div class="t" style="width:auto">
          <a class="btn secondary small" style="margin:0" href="?edit=<?= (int)$c['id'] ?>&amp;year=<?= App::e($year) ?>">Ändern</a>
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

    <label for="description">Beschreibung</label>
    <input type="text" id="description" name="description" required maxlength="500"
           value="<?= App::e($edit['description'] ?? '') ?>">

    <div class="field-row">
      <div>
        <label for="category">Kategorie</label>
        <select id="category" name="category">
          <?php foreach (Costs::CATEGORIES as $k => $l): ?>
            <option value="<?= App::e($k) ?>" <?= ($edit['category'] ?? '') === $k ? 'selected' : '' ?>>
              <?= App::e($l) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div>
        <label for="cost_date">Datum</label>
        <input type="date" id="cost_date" name="cost_date" value="<?= App::e($edit['cost_date'] ?? $today) ?>" required>
      </div>
      <div>
        <label for="amount">Betrag (€)</label>
        <input type="text" id="amount" name="amount" required inputmode="decimal"
               value="<?= App::e($edit ? number_format((float)$edit['amount'], 2, ',', '') : '') ?>">
      </div>
    </div>

    <div class="field-row">
      <div>
        <label for="reimbursement_status">Erstattung</label>
        <select id="reimbursement_status" name="reimbursement_status">
          <?php foreach (Costs::REIMB_STATUS as $k => $l): ?>
            <option value="<?= App::e($k) ?>" <?= ($edit['reimbursement_status'] ?? 'none') === $k ? 'selected' : '' ?>>
              <?= App::e($l) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div>
        <label for="reimbursed_amount">Erstatteter Betrag (€)</label>
        <input type="text" id="reimbursed_amount" name="reimbursed_amount" inputmode="decimal"
               value="<?= App::e(isset($edit['reimbursed_amount']) ? number_format((float)$edit['reimbursed_amount'], 2, ',', '') : '') ?>">
      </div>
      <div>
        <label for="submitted_date">Eingereicht am</label>
        <input type="date" id="submitted_date" name="submitted_date" value="<?= App::e($edit['submitted_date'] ?? '') ?>">
      </div>
    </div>

    <label for="provider">Anbieter</label>
    <input type="text" id="provider" name="provider" maxlength="200" value="<?= App::e($edit['provider'] ?? '') ?>">

    <label for="note">Notiz</label>
    <textarea id="note" name="note" rows="3"><?= App::e($edit['note'] ?? '') ?></textarea>

    <button type="submit" class="auto"><?= $edit ? 'Aktualisieren' : 'Anlegen' ?></button>
    <?php if ($edit): ?><p class="foot"><a href="<?= App::url('/costs.php') ?>">Abbrechen</a></p><?php endif; ?>
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
