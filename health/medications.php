<?php
declare(strict_types=1);
require __DIR__ . '/_init.php';

use Health\App;
use Health\Csrf;
use Health\MedicationRepository as Med;
use Health\Modules;
use Health\View;

$app = App::boot();
$app->auth->requireLogin();

$repo  = new Med($app);
$error = $ok = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::requireValid();
    try {
        switch ($_POST['action'] ?? '') {
            case 'create':
                $id = $repo->store($_POST);
                header('Location: ' . App::url('/medication.php?id=' . $id));
                exit;
            case 'take':
                $medId = (int)($_POST['medication_id'] ?? 0);
                $schedId = (int)($_POST['schedule_id'] ?? 0) ?: null;
                $now = (new DateTimeImmutable('now', new DateTimeZone($app->config['app']['timezone'])));
                if ($schedId !== null && $repo->takenOn($schedId, $now)) {
                    // Zweiter Klick auf denselben Knopf (Doppelklick, zwei
                    // Tabs) darf keine zweite Einnahme protokollieren.
                    $ok = 'Für heute bereits abgezeichnet.';
                } else {
                    $repo->logIntake($medId, $schedId, $now->format('Y-m-d\TH:i'), null);
                    $ok = 'Abgezeichnet.';
                }
                break;
        }
    } catch (\Throwable $e) {
        $error = $e->getMessage();
    }
}

$showStopped = !empty($_GET['all']);
$list    = $repo->listAll($showStopped);
$ending  = $repo->endingSoon();
$lowStock = $repo->lowStock();
$today   = date('Y-m-d');
$now     = new DateTimeImmutable('now', new DateTimeZone($app->config['app']['timezone']));

// Nächste Fälligkeit je aktivem, planbasiertem Präparat ermitteln und
// die Liste danach sortieren – Fälligstes zuerst. Pausierte, abgesetzte
// und "bei Bedarf"-Einträge haben keine Fälligkeit und bleiben unten.
$nextDue = [];
foreach ($list as $m) {
    if ($m['status'] === 'active' && !$m['is_prn']) {
        $nextDue[(int)$m['id']] = $repo->nextDueForMedication((int)$m['id']);
    }
}
usort($list, function ($a, $b) use ($nextDue) {
    $da = $nextDue[(int)$a['id']]['at'] ?? null;
    $db = $nextDue[(int)$b['id']]['at'] ?? null;
    if ($da && $db) return $da <=> $db;
    if ($da) return -1;
    if ($db) return 1;
    return 0; // ursprüngliche Reihenfolge (Status, dann Startdatum) beibehalten
});

View::start($app, ['title' => 'Medikation – ' . $app->config['app']['name'], 'active' => 'medication']);
?>
<?php View::flash($ok, 'ok'); View::flash($error, 'error'); ?>

<?php if ($ending): ?>
<div class="msg warn">
  <strong>Bald zu Ende:</strong>
  <?= App::e(implode(', ', array_map(
        fn($m) => $m['name'] . ' (' . date('d.m.', strtotime($m['end_date'])) . ')', $ending
      ))) ?>
</div>
<?php endif; ?>

<?php if ($lowStock): ?>
<div class="msg error">
  <strong>Bestand niedrig – nachbestellen:</strong>
  <?= App::e(implode(', ', array_map(
        fn($m) => $m['name'] . ' (noch ' . rtrim(rtrim(number_format((float)$m['stock_quantity'],1,',','.'),'0'),',')
                  . ' ' . $m['stock_unit'] . ')', $lowStock
      ))) ?>
</div>
<?php endif; ?>

<div class="panel">
  <h1><?= View::moduleDot(Modules::MEDICATION) ?>Medikation</h1>
  <p class="sub">
    <?= count($list) ?> Einträge
    · <a href="<?= App::url('/medications.php' . ($showStopped ? '' : '?all=1')) ?>">
        <?= $showStopped ? 'nur aktuelle' : 'inkl. abgesetzte' ?></a>
  </p>

  <?php if (!$list): ?>
    <p class="empty">Noch kein Präparat erfasst.</p>
  <?php else: ?>
    <?php foreach ($list as $m):
          $due = $nextDue[(int)$m['id']] ?? null;
          $overdue = $due && $due['at'] < $now;
          $isToday = $due && $due['at']->format('Y-m-d') === $now->format('Y-m-d'); ?>
      <div class="ev<?= $overdue ? ' sev3' : '' ?>">
        <div class="body">
          <div class="title">
            <a href="<?= App::url('/medication.php?id=' . (int)$m['id']) ?>">
              <?= App::e($m['name']) ?><?= $m['strength'] ? ' ' . App::e($m['strength']) : '' ?>
            </a>
            <span class="mod"><?= App::e(Med::FORMS[$m['form']]) ?></span>
            <?php if ($m['is_prn']): ?><span class="mod">bei Bedarf</span>
            <?php elseif ($m['status'] !== 'active'): ?><span class="mod"><?= App::e(Med::STATUS[$m['status']]) ?></span>
            <?php endif; ?>
          </div>
          <div class="sum">
            <?php if ($due): ?>
              <span style="<?= $overdue ? 'color:var(--danger);font-weight:600' : '' ?>">
                <?= $overdue ? 'überfällig seit ' : 'nächste Einnahme ' ?>
                <?= App::e($due['intake_time']) ?> Uhr,
                <?= $isToday ? 'heute' : $due['at']->format('d.m.Y') ?>
              </span>
              · <?= App::e($due['dose']) ?>
            <?php elseif ($m['status'] === 'active' && !$m['is_prn']): ?>
              kein Plan hinterlegt
            <?php else: ?>
              <?= App::e(implode(' · ', array_filter([
                    $m['purpose'],
                    'seit ' . date('m.Y', strtotime($m['start_date'])),
                    $m['end_date'] ? 'bis ' . date('d.m.Y', strtotime($m['end_date'])) : null,
                 ]))) ?>
            <?php endif; ?>
          </div>
        </div>
        <?php if ($due): ?>
          <div class="t" style="width:auto">
            <?php if ($isToday): ?>
              <form method="post" style="margin:0">
                <?= Csrf::field() ?>
                <input type="hidden" name="action" value="take">
                <input type="hidden" name="medication_id" value="<?= (int)$m['id'] ?>">
                <input type="hidden" name="schedule_id" value="<?= (int)$due['schedule_id'] ?>">
                <button type="submit" class="secondary small">Abzeichnen</button>
              </form>
            <?php else: ?>
              <button type="button" class="secondary small" disabled
                      title="Erst am Fälligkeitstag abzeichenbar – für heute ist nichts offen.">
                Abzeichnen
              </button>
            <?php endif; ?>
          </div>
        <?php endif; ?>
      </div>
    <?php endforeach; ?>
  <?php endif; ?>
</div>

<div class="panel">
  <h2>Neues Präparat</h2>
  <form method="post">
    <?= Csrf::field() ?>
    <input type="hidden" name="action" value="create">

    <div class="field-row">
      <div>
        <label for="name">Name</label>
        <input type="text" id="name" name="name" required maxlength="200">
      </div>
      <div>
        <label for="strength">Stärke</label>
        <input type="text" id="strength" name="strength" maxlength="60" placeholder="z. B. 50 mg">
      </div>
      <div>
        <label for="form">Form</label>
        <select id="form" name="form">
          <?php foreach (Med::FORMS as $k => $l): ?>
            <option value="<?= App::e($k) ?>"><?= App::e($l) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
    </div>

    <label for="purpose">Wofür</label>
    <input type="text" id="purpose" name="purpose" maxlength="300">

    <div class="field-row">
      <div>
        <label for="start_date">Beginn</label>
        <input type="date" id="start_date" name="start_date" value="<?= App::e($today) ?>" required>
      </div>
      <div>
        <label for="end_date">Ende (falls bekannt)</label>
        <input type="date" id="end_date" name="end_date">
      </div>
      <div>
        <label for="doctor">Verordnet von</label>
        <input type="text" id="doctor" name="doctor" maxlength="200">
      </div>
    </div>

    <label style="display:flex;align-items:center;gap:8px;font-weight:400">
      <input type="checkbox" name="is_prn" value="1" style="width:auto">
      Bei Bedarf, kein fester Einnahmeplan
    </label>

    <label for="note">Notiz</label>
    <textarea id="note" name="note" rows="3"></textarea>

    <button type="submit" class="auto">Anlegen</button>
    <p class="hint">Den Einnahmeplan trägst du danach auf der Detailseite ein.</p>
  </form>
</div>
<?php View::end($app); ?>
