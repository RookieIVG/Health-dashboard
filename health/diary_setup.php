<?php
declare(strict_types=1);
require __DIR__ . '/_init.php';

use Health\App;
use Health\Csrf;
use Health\DiaryRepository;
use Health\View;

$app = App::boot();
$app->auth->requireLogin();

$repo   = new DiaryRepository($app);
$typeId = (int)($_GET['type'] ?? 0);
$type   = $repo->type($typeId);

if (!$type) { header('Location: ' . App::url('/diary.php')); exit; }

$error = $ok = null;
$isOwn = $type['user_id'] !== null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::requireValid();
    try {
        switch ($_POST['action'] ?? '') {
            case 'update_type':
                $repo->updateType($typeId, (string)($_POST['label'] ?? ''),
                                  trim((string)($_POST['description'] ?? '')) ?: null);
                $ok = 'Gespeichert.';
                break;
            case 'toggle_type':
                $repo->toggleTypeActive($typeId);
                $ok = 'Sichtbarkeit geändert.';
                break;
            case 'add_field':
                $repo->addField($typeId, $_POST);
                $ok = 'Feld hinzugefügt.';
                break;
            case 'update_field':
                $repo->updateField((int)($_POST['field_id'] ?? 0), $_POST);
                $ok = 'Feld gespeichert.';
                break;
            case 'toggle_field':
                $repo->toggleFieldActive((int)($_POST['field_id'] ?? 0));
                $ok = 'Sichtbarkeit geändert.';
                break;
            case 'delete_field':
                $repo->deleteField((int)($_POST['field_id'] ?? 0));
                $ok = 'Feld gelöscht.';
                break;
        }
        $type = $repo->type($typeId); // Anzeige aktualisieren
    } catch (\Throwable $e) {
        $error = $e->getMessage();
    }
}

$fields    = $repo->fields($typeId, false);
$editField = null;
if (($_GET['field'] ?? '') !== '') {
    foreach ($fields as $f) if ((int)$f['id'] === (int)$_GET['field']) { $editField = $f; break; }
}

$typeLabels = [
    'scale' => 'Skala (Auswahl von … bis)', 'number' => 'Zahl', 'duration' => 'Dauer',
    'choice' => 'Auswahlliste', 'bool' => 'Ja / Nein', 'text' => 'Text (einzeilig)',
    'longtext' => 'Text (mehrzeilig)', 'time' => 'Uhrzeit',
];

/** Optionen für das Textfeld "schluessel|Beschriftung" je Zeile aufbereiten. */
function optionsToText(array $field): string
{
    $lines = [];
    foreach ($field['options_list'] ?? [] as $o) $lines[] = $o['k'] . '|' . $o['l'];
    return implode("\n", $lines);
}

View::start($app, ['title' => $type['label'] . ' – Einstellungen', 'active' => 'diary']);
?>
<?php View::flash($ok, 'ok'); View::flash($error, 'error'); ?>

<div class="panel">
  <h1><?= App::e($type['label']) ?></h1>
  <p class="sub">
    Einstellungen
    · <a href="<?= App::url('/diary_type.php?type=' . $typeId) ?>">zum Tagebuch</a>
    · <a href="<?= App::url('/diary.php') ?>">Übersicht</a>
  </p>

  <?php if ($isOwn): ?>
    <form method="post">
      <?= Csrf::field() ?>
      <input type="hidden" name="action" value="update_type">
      <label for="tlabel">Bezeichnung</label>
      <input type="text" id="tlabel" name="label" required maxlength="96" value="<?= App::e($type['label']) ?>">
      <label for="tdesc">Kurzbeschreibung</label>
      <input type="text" id="tdesc" name="description" maxlength="255" value="<?= App::e($type['description'] ?? '') ?>">
      <button type="submit" class="auto secondary">Speichern</button>
    </form>
  <?php else: ?>
    <p class="sub">Mitgeliefertes Tagebuch – Bezeichnung und Felder sind fest.
       Du kannst es aber ausblenden, wenn du es nicht brauchst.</p>
  <?php endif; ?>

  <div class="actions">
    <form method="post" <?= $type['is_active'] ? 'data-confirm="Tagebuch aus der Übersicht ausblenden? Einträge bleiben erhalten."' : '' ?>>
      <?= Csrf::field() ?>
      <input type="hidden" name="action" value="toggle_type">
      <button type="submit" class="secondary small">
        <?= $type['is_active'] ? 'Ausblenden' : 'Wieder einblenden' ?>
      </button>
    </form>
  </div>
</div>

<div class="panel">
  <h2>Felder</h2>
  <div class="table-wrap">
    <table class="stack">
      <thead><tr><th>Feld</th><th>Typ</th><th>Sichtbar</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($fields as $f): ?>
        <tr>
          <td data-label="Feld">
            <strong><?= App::e($f['label']) ?></strong><?= $f['unit'] !== '' ? ' (' . App::e($f['unit']) . ')' : '' ?>
            <?php if ((int)$f['is_primary'] === 1): ?> ★<?php endif; ?>
          </td>
          <td data-label="Typ"><?= App::e($typeLabels[$f['ftype']] ?? $f['ftype']) ?></td>
          <td data-label="Sichtbar">
            <form method="post" style="margin:0">
              <?= Csrf::field() ?>
              <input type="hidden" name="action" value="toggle_field">
              <input type="hidden" name="field_id" value="<?= (int)$f['id'] ?>">
              <button type="submit" class="secondary small"><?= $f['is_active'] ? 'sichtbar' : 'ausgeblendet' ?></button>
            </form>
          </td>
          <td>
            <?php if ($isOwn): ?>
              <a class="btn secondary small" style="margin:0"
                 href="?type=<?= $typeId ?>&amp;field=<?= (int)$f['id'] ?>#feldbearbeiten">Ändern</a>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$fields): ?>
        <tr><td colspan="4">Noch keine Felder.</td></tr>
      <?php endif; ?>
      </tbody>
    </table>
  </div>
  <p class="hint">
    Ausgeblendete Felder verschwinden aus der Erfassungsmaske; bereits
    eingetragene Werte bleiben in bestehenden Einträgen sichtbar.
  </p>
</div>

<?php if ($isOwn): ?>
<div class="panel" id="feldbearbeiten">
  <h2><?= $editField ? 'Feld bearbeiten: ' . App::e($editField['label']) : 'Feld hinzufügen' ?></h2>

  <?php if ($editField && $repo->fieldUsageCount((int)$editField['id']) > 0): ?>
    <p class="hint">
      Für dieses Feld liegen bereits Werte vor. Der Feldtyp lässt sich
      deshalb nicht mehr ändern – Bezeichnung, Bereich und Optionen schon.
    </p>
  <?php endif; ?>

  <form method="post">
    <?= Csrf::field() ?>
    <?php if ($editField): ?>
      <input type="hidden" name="action" value="update_field">
      <input type="hidden" name="field_id" value="<?= (int)$editField['id'] ?>">
    <?php else: ?>
      <input type="hidden" name="action" value="add_field">
    <?php endif; ?>

    <div class="field-row">
      <div>
        <label for="label">Bezeichnung</label>
        <input type="text" id="label" name="label" required maxlength="96"
               value="<?= App::e($editField['label'] ?? '') ?>">
      </div>
      <div>
        <label for="ftype">Feldtyp<?= $editField ? ' (fest)' : '' ?></label>
        <?php if ($editField): ?>
          <input type="text" value="<?= App::e($typeLabels[$editField['ftype']] ?? '') ?>" disabled>
        <?php else: ?>
          <select id="ftype" name="ftype">
            <?php foreach ($typeLabels as $k => $l): ?>
              <option value="<?= App::e($k) ?>"><?= App::e($l) ?></option>
            <?php endforeach; ?>
          </select>
        <?php endif; ?>
      </div>
    </div>

    <div class="field-row">
      <div>
        <label for="min_val">Von (Skala/Zahl)</label>
        <input type="text" id="min_val" name="min_val" inputmode="decimal"
               value="<?= App::e($editField['min_val'] !== null ? (string)(float)$editField['min_val'] : '') ?>">
      </div>
      <div>
        <label for="max_val">Bis</label>
        <input type="text" id="max_val" name="max_val" inputmode="decimal"
               value="<?= App::e($editField['max_val'] !== null ? (string)(float)$editField['max_val'] : '') ?>">
      </div>
      <div>
        <label for="unit">Einheit</label>
        <input type="text" id="unit" name="unit" maxlength="24" value="<?= App::e($editField['unit'] ?? '') ?>">
      </div>
    </div>

    <label for="options">Optionen (nur bei Auswahlliste)</label>
    <textarea id="options" name="options" rows="4"
              placeholder="schluessel|Beschriftung&#10;oder eine Beschriftung je Zeile"><?= $editField ? App::e(optionsToText($editField)) : '' ?></textarea>
    <p class="hint">
      Je Zeile eine Option. Der Schlüssel wandert in die Datenbank – bei
      bereits erfassten Werten den Schlüssel nicht mehr ändern, sonst
      passen alte Einträge nicht mehr zur Beschriftung.
    </p>

    <label for="hint">Hinweis unter dem Feld</label>
    <input type="text" id="hint" name="hint" maxlength="255" value="<?= App::e($editField['hint'] ?? '') ?>">

    <label for="sort_order">Reihenfolge (kleiner = weiter oben)</label>
    <input type="number" id="sort_order" name="sort_order" value="<?= (int)($editField['sort_order'] ?? 100) ?>">

    <label style="display:flex;align-items:center;gap:8px;font-weight:400">
      <input type="checkbox" name="is_required" value="1" style="width:auto"
             <?= !empty($editField['is_required']) ? 'checked' : '' ?>> Pflichtfeld
    </label>
    <label style="display:flex;align-items:center;gap:8px;font-weight:400">
      <input type="checkbox" name="is_primary" value="1" style="width:auto"
             <?= !empty($editField['is_primary']) ? 'checked' : '' ?>>
      Leitwert (Kurve und Timeline)
    </label>

    <button type="submit" class="auto secondary"><?= $editField ? 'Speichern' : 'Feld hinzufügen' ?></button>
    <?php if ($editField): ?>
      <p class="foot"><a href="?type=<?= $typeId ?>">Bearbeitung abbrechen</a></p>
    <?php endif; ?>
  </form>

  <?php if ($editField): ?>
  <div class="actions">
    <?php if ($repo->fieldUsageCount((int)$editField['id']) === 0): ?>
      <form method="post" data-confirm="Dieses Feld endgültig löschen?">
        <?= Csrf::field() ?>
        <input type="hidden" name="action" value="delete_field">
        <input type="hidden" name="field_id" value="<?= (int)$editField['id'] ?>">
        <button type="submit" class="secondary small">Feld löschen</button>
      </form>
    <?php else: ?>
      <p class="hint">Löschen erst möglich, wenn kein Eintrag mehr einen Wert für dieses Feld hat.</p>
    <?php endif; ?>
  </div>
  <?php endif; ?>
</div>
<?php endif; ?>
<?php View::end($app); ?>
