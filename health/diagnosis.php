<?php
declare(strict_types=1);
require __DIR__ . '/_init.php';

use Health\App;
use Health\AttachmentService;
use Health\Csrf;
use Health\DiagnosesRepository as Diag;
use Health\Modules;
use Health\View;

$app = App::boot();
$app->auth->requireLogin();

$repo = new Diag($app);
$id   = (int)($_GET['id'] ?? 0);

$error = $ok = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::requireValid();
    $id = (int)($_POST['id'] ?? $id);
    try {
        switch ($_POST['action'] ?? '') {
            case 'save':
                $repo->store($_POST, $id);
                $ok = 'Gespeichert.';
                break;

            case 'upload':
                if (empty($_FILES['file']['name'])) {
                    throw new RuntimeException('Es wurde keine Datei ausgewählt.');
                }
                $app->attachments()->storeUpload($_FILES['file'], Modules::DIAGNOSIS, $id);
                $ok = 'Datei angehängt.';
                break;

            case 'delete_file':
                $app->attachments()->deleteOne((int)($_POST['att'] ?? 0));
                $ok = 'Datei gelöscht.';
                break;

            case 'delete':
                $repo->delete($id);
                header('Location: ' . App::url('/diagnoses.php'));
                exit;
        }
    } catch (\Throwable $e) {
        $error = $e->getMessage();
    }
}

$d = $repo->detail($id);
if (!$d) {
    header('Location: ' . App::url('/diagnoses.php'));
    exit;
}

$tagStr  = implode(', ', array_column($d['tags'], 'name'));
$related = $d['icd'] ? array_values(array_filter(
    $repo->findByIcd($d['icd']), fn($r) => (int)$r['id'] !== $id
)) : [];

View::start($app, ['title' => $d['title'] . ' – Diagnose', 'active' => 'diagnoses']);
?>
<?php View::flash($ok, 'ok'); View::flash($error, 'error'); ?>

<div class="panel">
  <h1><?= App::e($d['title']) ?></h1>
  <p class="sub">
    <?= App::e(Diag::STATUS[$d['status']]) ?>
    <?php if ($d['icd']): ?> · ICD-10 <?= App::e($d['icd']) ?><?php endif; ?>
    · seit <?= App::e(date('d.m.Y', strtotime($d['onset_date']))) ?>
    <?php if ($d['end_date']): ?> bis <?= App::e(date('d.m.Y', strtotime($d['end_date']))) ?><?php endif; ?>
    · <?= App::e(Diag::duration($d)) ?>
    · <a href="<?= App::url('/diagnoses.php?all=1') ?>">zur Liste</a>
  </p>

  <?php if ($d['is_pinned']): ?>
    <div class="msg warn">Für das Notfallblatt vorgemerkt.</div>
  <?php endif; ?>

  <?php if ($d['note']): ?>
    <div style="white-space:pre-wrap"><?= App::e($d['note']) ?></div>
  <?php endif; ?>

  <?php if ($d['tags']): ?>
    <div class="filters" style="margin-top:14px">
      <?php foreach ($d['tags'] as $t): ?>
        <a class="chip" href="<?= App::url('/findings.php?tag=' . (int)$t['id']) ?>"><?= App::e($t['name']) ?></a>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

  <?php if ($related): ?>
    <p class="hint" style="margin-top:14px">
      Weitere Einträge mit demselben ICD-Code:
      <?php foreach ($related as $r): ?>
        <a href="<?= App::url('/diagnosis.php?id=' . (int)$r['id']) ?>"><?= App::e($r['title']) ?></a>
        (<?= App::e(date('Y', strtotime($r['onset_date']))) ?>)
      <?php endforeach; ?>
    </p>
  <?php endif; ?>
</div>

<div class="panel">
  <h2>Dateien</h2>
  <?php if ($d['attachments']): ?>
    <div class="table-wrap">
      <table class="stack">
        <thead><tr><th>Datei</th><th>Größe</th><th>Hinzugefügt</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($d['attachments'] as $a): ?>
          <tr>
            <td data-label="Datei">
              <a href="<?= App::url('/file.php?id=' . (int)$a['id']) ?>" target="_blank" rel="noopener">
                <?= App::e($a['filename']) ?>
              </a>
            </td>
            <td data-label="Größe"><?= App::e(AttachmentService::formatSize((int)$a['size_bytes'])) ?></td>
            <td data-label="Hinzugefügt"><?= App::e($app->local($a['created_at'], 'd.m.Y')) ?></td>
            <td>
              <form method="post" data-confirm="Diese Datei löschen?" style="margin:0">
                <?= Csrf::field() ?>
                <input type="hidden" name="action" value="delete_file">
                <input type="hidden" name="id" value="<?= $id ?>">
                <input type="hidden" name="att" value="<?= (int)$a['id'] ?>">
                <button type="submit" class="secondary small">Löschen</button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php else: ?>
    <p class="sub">Noch keine Datei angehängt.</p>
  <?php endif; ?>

  <form method="post" enctype="multipart/form-data">
    <?= Csrf::field() ?>
    <input type="hidden" name="action" value="upload">
    <input type="hidden" name="id" value="<?= $id ?>">
    <label for="file">Datei hinzufügen</label>
    <input type="file" id="file" name="file" required
           accept=".pdf,.jpg,.jpeg,.png,.heic,.tif,.tiff,.txt,.csv,.dcm">
    <button type="submit" class="auto secondary">Hochladen</button>
  </form>
</div>

<div class="panel">
  <h2>Bearbeiten</h2>
  <form method="post">
    <?= Csrf::field() ?>
    <input type="hidden" name="action" value="save">
    <input type="hidden" name="id" value="<?= $id ?>">

    <label for="title">Bezeichnung</label>
    <input type="text" id="title" name="title" required maxlength="200" value="<?= App::e($d['title']) ?>">

    <div class="field-row">
      <div>
        <label for="icd">ICD-10</label>
        <input type="text" id="icd" name="icd" maxlength="10" value="<?= App::e($d['icd'] ?? '') ?>"
               autocapitalize="characters" spellcheck="false">
      </div>
      <div>
        <label for="severity">Ausprägung</label>
        <select id="severity" name="severity">
          <?php foreach (Diag::SEVERITY as $k => $l): ?>
            <option value="<?= (int)$k ?>" <?= (int)$d['severity'] === $k ? 'selected' : '' ?>>
              <?= App::e($l) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
    </div>

    <div class="field-row">
      <div>
        <label for="onset_date">Beginn</label>
        <input type="date" id="onset_date" name="onset_date" value="<?= App::e($d['onset_date']) ?>" required>
      </div>
      <div>
        <label for="end_date">Ende</label>
        <input type="date" id="end_date" name="end_date" value="<?= App::e($d['end_date'] ?? '') ?>">
      </div>
    </div>

    <label for="status">Status</label>
    <select id="status" name="status">
      <?php foreach (Diag::STATUS as $k => $l): ?>
        <option value="<?= App::e($k) ?>" <?= $d['status'] === $k ? 'selected' : '' ?>>
          <?= App::e($l) ?>
        </option>
      <?php endforeach; ?>
    </select>
    <p class="hint">
      Bei laufendem Status wird ein Enddatum verworfen, bei „ausgeheilt“ ohne
      Angabe das heutige Datum gesetzt – sonst stehen Status und Zeitraum
      irgendwann im Widerspruch zueinander.
    </p>

    <label for="doctor">Diagnostiziert von</label>
    <input type="text" id="doctor" name="doctor" maxlength="200" value="<?= App::e($d['doctor'] ?? '') ?>">

    <label for="note">Notizen</label>
    <textarea id="note" name="note" rows="8"><?= App::e($d['note'] ?? '') ?></textarea>

    <label for="tags">Tags (Komma-getrennt)</label>
    <input type="text" id="tags" name="tags" value="<?= App::e($tagStr) ?>" autocomplete="off">

    <label style="display:flex;align-items:center;gap:8px;font-weight:400">
      <input type="checkbox" name="is_pinned" value="1" style="width:auto"
             <?= $d['is_pinned'] ? 'checked' : '' ?>>
      Für das Notfallblatt vormerken
    </label>

    <button type="submit" class="auto">Speichern</button>
  </form>

  <div class="actions">
    <form method="post" data-confirm="Diagnose samt Dateien, Tags und Timeline-Einträgen löschen?">
      <?= Csrf::field() ?>
      <input type="hidden" name="action" value="delete">
      <input type="hidden" name="id" value="<?= $id ?>">
      <button type="submit" class="secondary small">Löschen</button>
    </form>
  </div>
</div>
<?php View::end($app); ?>
