<?php
declare(strict_types=1);
require __DIR__ . '/_init.php';

use Health\App;
use Health\Chart;
use Health\Csrf;
use Health\DiaryRepository;
use Health\View;

$app = App::boot();
$app->auth->requireLogin();

$repo   = new DiaryRepository($app);
$typeId = (int)($_GET['type'] ?? 0);
$type   = $repo->type($typeId);

if (!$type) {
    header('Location: ' . App::url('/diary.php'));
    exit;
}

$fields  = $repo->fields($typeId, true);
$error   = $ok = null;
$editing = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::requireValid();
    try {
        switch ($_POST['action'] ?? '') {
            case 'save':
                $entryId = (int)($_POST['entry_id'] ?? 0) ?: null;
                $repo->saveEntry(
                    typeId: $typeId,
                    occurredLocal: (string)($_POST['occurred_at'] ?? ''),
                    values: (array)($_POST['f'] ?? []),
                    note: trim((string)($_POST['note'] ?? '')) ?: null,
                    entryId: $entryId
                );
                $ok = $entryId ? 'Eintrag aktualisiert.' : 'Eintrag gespeichert.';
                break;

            case 'delete':
                $repo->delete((int)($_POST['entry_id'] ?? 0));
                $ok = 'Eintrag gelöscht.';
                break;
        }
    } catch (\Throwable $e) {
        $error = $e->getMessage();
    }
}

if (($_GET['edit'] ?? '') !== '') {
    $editing = $repo->entry((int)$_GET['edit']);
    if ($editing && (int)$editing['type_id'] !== $typeId) $editing = null;
}

$days    = (int)($_GET['days'] ?? 30);
$days    = in_array($days, [7, 30, 90, 365, 0], true) ? $days : 30;
$fromUtc = $days > 0 ? gmdate('Y-m-d H:i:s', time() - $days * 86400) : null;

$entries = $repo->entries($typeId, $fromUtc, 200);
$series  = $repo->primarySeries($typeId, $fromUtc);
$primary = $repo->primaryField($typeId);

$tz       = new DateTimeZone($app->config['app']['timezone']);
$nowLocal = (new DateTimeImmutable('now', $tz))->format('Y-m-d\TH:i');
$formTime = $editing ? $app->local($editing['occurred_at'], 'Y-m-d\TH:i') : $nowLocal;

/** Bisheriger Wert eines Feldes beim Bearbeiten. */
function old(?array $editing, array $f): string
{
    if (!$editing) return '';
    $v = $editing['values'][$f['fkey']] ?? null;
    if (!$v) return '';
    return match ($f['ftype']) {
        'bool'   => $v['num'] ? '1' : '',
        'choice', 'time' => (string)$v['key'],
        'text', 'longtext' => (string)$v['text'],
        default  => $v['num'] === null ? '' : \Health\VitalsRepository::trimNum((float)$v['num']),
    };
}

View::start($app, ['title' => $type['label'], 'active' => 'diary']);
?>
<?php View::flash($ok, 'ok'); View::flash($error, 'error'); ?>

<div class="panel">
  <h1><?= App::e($type['label']) ?></h1>
  <p class="sub">
    <?= count($entries) ?> Einträge im Zeitraum
    · <a href="<?= App::url('/diary.php') ?>">alle Tagebücher</a>
    · <a href="<?= App::url('/diary_setup.php?type=' . $typeId) ?>">Einstellungen</a>
  </p>
  <div class="filters">
    <?php foreach ([7 => '7 Tage', 30 => '30 Tage', 90 => '90 Tage', 365 => '1 Jahr', 0 => 'Alles'] as $d => $l): ?>
      <a class="chip <?= $days === $d ? 'active' : '' ?>"
         href="?type=<?= $typeId ?>&amp;days=<?= $d ?>"><?= App::e($l) ?></a>
    <?php endforeach; ?>
  </div>
</div>

<?php if ($primary && count($series) >= 2): ?>
<div class="panel">
  <h2><?= App::e($primary['label']) ?> im Verlauf</h2>
  <?= Chart::line($series, [
        'decimals' => 0,
        'ref_low'  => $primary['min_val'] === null ? null : (float)$primary['min_val'],
        'ref_high' => $primary['max_val'] === null ? null : (float)$primary['max_val'],
        'timezone' => $app->config['app']['timezone'],
      ]) ?>
  <p class="hint">
    Das getönte Band ist der mögliche Wertebereich des Feldes, kein
    Zielbereich – bei einer Schmerzskala wäre „im Band“ nichts Gutes.
  </p>
</div>
<?php endif; ?>

<div class="panel">
  <h2><?= $editing ? 'Eintrag bearbeiten' : 'Neuer Eintrag' ?></h2>
  <form method="post">
    <?= Csrf::field() ?>
    <input type="hidden" name="action" value="save">
    <?php if ($editing): ?>
      <input type="hidden" name="entry_id" value="<?= (int)$editing['id'] ?>">
    <?php endif; ?>

    <label for="occurred_at">Zeitpunkt</label>
    <input type="datetime-local" id="occurred_at" name="occurred_at"
           value="<?= App::e($formTime) ?>" required>

    <?php foreach ($fields as $f):
          $id = 'f_' . $f['fkey'];
          $val = old($editing, $f); ?>

      <?php if ($f['ftype'] === 'bool'): ?>
        <label style="display:flex;align-items:center;gap:8px;font-weight:400">
          <input type="checkbox" id="<?= App::e($id) ?>" name="f[<?= App::e($f['fkey']) ?>]"
                 value="1" style="width:auto" <?= $val ? 'checked' : '' ?>>
          <?= App::e($f['label']) ?>
        </label>

      <?php else: ?>
        <label for="<?= App::e($id) ?>">
          <?= App::e($f['label']) ?><?= $f['unit'] !== '' ? ' (' . App::e($f['unit']) . ')' : '' ?>
          <?= (int)$f['is_required'] === 1 ? ' *' : '' ?>
        </label>

        <?php if ($f['ftype'] === 'choice'): ?>
          <select id="<?= App::e($id) ?>" name="f[<?= App::e($f['fkey']) ?>]"
                  <?= (int)$f['is_required'] === 1 ? 'required' : '' ?>>
            <?php if ((int)$f['is_required'] !== 1): ?><option value="">—</option><?php endif; ?>
            <?php foreach ($f['options_list'] as $o): ?>
              <option value="<?= App::e((string)$o['k']) ?>"
                      <?= $val === (string)$o['k'] ? 'selected' : '' ?>><?= App::e((string)$o['l']) ?></option>
            <?php endforeach; ?>
          </select>

        <?php elseif ($f['ftype'] === 'scale'):
              $min = (int)($f['min_val'] ?? 0); $max = (int)($f['max_val'] ?? 10); ?>
          <select id="<?= App::e($id) ?>" name="f[<?= App::e($f['fkey']) ?>]"
                  <?= (int)$f['is_required'] === 1 ? 'required' : '' ?>>
            <?php if ((int)$f['is_required'] !== 1): ?><option value="">—</option><?php endif; ?>
            <?php for ($i = $min; $i <= $max; $i++): ?>
              <option value="<?= $i ?>" <?= $val !== '' && (int)$val === $i ? 'selected' : '' ?>>
                <?= $i ?><?= $i === $min ? ' (niedrigster)' : ($i === $max ? ' (höchster)' : '') ?>
              </option>
            <?php endfor; ?>
          </select>

        <?php elseif ($f['ftype'] === 'time'): ?>
          <input type="time" id="<?= App::e($id) ?>" name="f[<?= App::e($f['fkey']) ?>]"
                 value="<?= App::e($val) ?>" <?= (int)$f['is_required'] === 1 ? 'required' : '' ?>>

        <?php elseif ($f['ftype'] === 'longtext'): ?>
          <textarea id="<?= App::e($id) ?>" name="f[<?= App::e($f['fkey']) ?>]" rows="4"
                    <?= (int)$f['is_required'] === 1 ? 'required' : '' ?>><?= App::e($val) ?></textarea>

        <?php elseif (in_array($f['ftype'], ['number', 'duration'], true)): ?>
          <input type="text" inputmode="decimal" id="<?= App::e($id) ?>"
                 name="f[<?= App::e($f['fkey']) ?>]" value="<?= App::e($val) ?>"
                 autocomplete="off" <?= (int)$f['is_required'] === 1 ? 'required' : '' ?>>

        <?php else: ?>
          <input type="text" id="<?= App::e($id) ?>" name="f[<?= App::e($f['fkey']) ?>]"
                 value="<?= App::e($val) ?>" maxlength="300" autocomplete="off"
                 <?= (int)$f['is_required'] === 1 ? 'required' : '' ?>>
        <?php endif; ?>

        <?php if ($f['hint']): ?><p class="hint"><?= App::e($f['hint']) ?></p><?php endif; ?>
      <?php endif; ?>
    <?php endforeach; ?>

    <label for="note">Notiz</label>
    <textarea id="note" name="note" rows="3"><?= App::e($editing['note'] ?? '') ?></textarea>

    <button type="submit" class="auto"><?= $editing ? 'Aktualisieren' : 'Speichern' ?></button>
    <?php if ($editing): ?>
      <p class="foot"><a href="?type=<?= $typeId ?>&amp;days=<?= $days ?>">Bearbeitung abbrechen</a></p>
    <?php endif; ?>
  </form>
</div>

<div class="panel">
  <h2>Einträge</h2>
  <?php if (!$entries): ?>
    <p class="empty">Keine Einträge im gewählten Zeitraum.</p>
  <?php else: ?>
    <?php
    $lastDay = null;
    foreach ($entries as $e):
        $day = $app->local($e['occurred_at'], 'd.m.Y');
        if ($day !== $lastDay): $lastDay = $day; ?>
          <div class="day-head" style="margin-top:14px"><?= App::e($day) ?></div>
        <?php endif; ?>
      <div class="ev">
        <div class="t"><?= App::e($app->local($e['occurred_at'], 'H:i')) ?></div>
        <div class="body">
          <div class="title">
            <?php
            $parts = [];
            foreach ($e['values'] as $v) {
                $txt = DiaryRepository::displayValue($v);
                if ($txt === '' || $txt === 'nein') continue;
                $parts[] = $v['field']['label'] . ': ' . $txt;
            }
            echo App::e($parts ? implode(' · ', array_slice($parts, 0, 3)) : 'Eintrag');
            ?>
          </div>
          <?php if (count($parts) > 3): ?>
            <div class="sum"><?= App::e(implode(' · ', array_slice($parts, 3))) ?></div>
          <?php endif; ?>
          <?php if (!empty($e['note'])): ?>
            <div class="sum"><?= App::e($e['note']) ?></div>
          <?php endif; ?>
        </div>
        <div class="t" style="width:auto">
          <div class="actions" style="margin:0">
            <a class="btn secondary small" style="margin:0"
               href="?type=<?= $typeId ?>&amp;days=<?= $days ?>&amp;edit=<?= (int)$e['id'] ?>">Ändern</a>
            <form method="post" data-confirm="Diesen Eintrag löschen?" style="margin:0">
              <?= Csrf::field() ?>
              <input type="hidden" name="action" value="delete">
              <input type="hidden" name="entry_id" value="<?= (int)$e['id'] ?>">
              <button type="submit" class="secondary small">Löschen</button>
            </form>
          </div>
        </div>
      </div>
    <?php endforeach; ?>
  <?php endif; ?>
</div>
<?php View::end($app); ?>
