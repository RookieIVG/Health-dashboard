<?php
declare(strict_types=1);
require __DIR__ . '/_init.php';

use Health\App;
use Health\AttachmentService;
use Health\Csrf;
use Health\FindingsRepository;
use Health\Modules;
use Health\View;

$app = App::boot();
$app->auth->requireLogin();

$repo = new FindingsRepository($app);
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
                $app->attachments()->storeUpload($_FILES['file'], Modules::FINDING, $id);
                $ok = 'Datei angehängt.';
                break;

            case 'delete_file':
                $app->attachments()->deleteOne((int)($_POST['att'] ?? 0));
                $ok = 'Datei gelöscht.';
                break;

            case 'clear_followup':
                $repo->clearFollowUp($id);
                $ok = 'Wiedervorlage erledigt.';
                break;

            case 'archive':
                $f = $repo->find($id);
                $repo->store(array_merge($f, [
                    'occurred_at' => $app->local($f['occurred_at'], 'Y-m-d H:i'),
                    'is_archived' => empty($f['is_archived']) ? 1 : 0,
                ]), $id);
                $ok = 'Archivstatus geändert.';
                break;

            case 'delete':
                $repo->delete($id);
                header('Location: ' . App::url('/findings.php'));
                exit;
        }
    } catch (\Throwable $e) {
        $error = $e->getMessage();
    }
}

$f = $repo->detail($id);
if (!$f) {
    header('Location: ' . App::url('/findings.php'));
    exit;
}

$tagStr   = implode(', ', array_column($f['tags'], 'name'));
$occLocal = $app->local($f['occurred_at'], 'Y-m-d\TH:i');

View::start($app, ['title' => $f['title'] . ' – Befund', 'active' => 'findings']);
?>
<?php View::flash($ok, 'ok'); View::flash($error, 'error'); ?>

<div class="panel">
  <h1><?= App::e($f['title']) ?></h1>
  <p class="sub">
    <?= App::e(FindingsRepository::categoryLabel($f['category'])) ?>
    · <?= App::e($app->local($f['occurred_at'], 'd.m.Y H:i')) ?>
    <?php if ($f['is_archived']): ?> · archiviert<?php endif; ?>
    · <a href="<?= App::url('/findings.php') ?>">zur Liste</a>
  </p>

  <?php if ($f['follow_up_at']): ?>
    <div class="msg warn">
      Wiedervorlage am <?= App::e(date('d.m.Y', strtotime($f['follow_up_at']))) ?>
      <form method="post" style="display:inline;margin-left:8px">
        <?= Csrf::field() ?>
        <input type="hidden" name="action" value="clear_followup">
        <input type="hidden" name="id" value="<?= $id ?>">
        <button type="submit" class="secondary small">Erledigt</button>
      </form>
    </div>
  <?php endif; ?>

  <?php if ($f['summary']): ?><p><?= App::e($f['summary']) ?></p><?php endif; ?>
  <?php if ($f['text']): ?>
    <div style="white-space:pre-wrap;margin-top:10px"><?= App::e($f['text']) ?></div>
  <?php endif; ?>

  <?php if ($f['tags']): ?>
    <div class="filters" style="margin-top:14px">
      <?php foreach ($f['tags'] as $t): ?>
        <a class="chip" href="<?= App::url('/findings.php?tag=' . (int)$t['id']) ?>"><?= App::e($t['name']) ?></a>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</div>

<div class="panel">
  <h2>Dateien</h2>
  <?php if ($f['attachments']): ?>
    <div class="table-wrap">
      <table class="stack">
        <thead><tr><th>Datei</th><th>Typ</th><th>Größe</th><th>Hinzugefügt</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($f['attachments'] as $a): ?>
          <tr>
            <td data-label="Datei">
              <a href="<?= App::url('/file.php?id=' . (int)$a['id']) ?>" target="_blank" rel="noopener">
                <?= App::e($a['filename']) ?>
              </a>
            </td>
            <td data-label="Typ"><?= App::e($a['mime_type']) ?></td>
            <td data-label="Größe"><?= App::e(AttachmentService::formatSize((int)$a['size_bytes'])) ?></td>
            <td data-label="Hinzugefügt"><?= App::e($app->local($a['created_at'], 'd.m.Y')) ?></td>
            <td>
              <div class="actions" style="margin:0;justify-content:flex-end">
                <a class="btn secondary small" style="margin:0"
                   href="<?= App::url('/file.php?download=1&id=' . (int)$a['id']) ?>">Download</a>
                <form method="post" data-confirm="Diese Datei löschen?" style="margin:0">
                  <?= Csrf::field() ?>
                  <input type="hidden" name="action" value="delete_file">
                  <input type="hidden" name="id" value="<?= $id ?>">
                  <input type="hidden" name="att" value="<?= (int)$a['id'] ?>">
                  <button type="submit" class="secondary small">Löschen</button>
                </form>
              </div>
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
    <label for="file">Datei hinzufügen (max. 25 MB)</label>
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
    <input type="hidden" name="is_archived" value="<?= (int)$f['is_archived'] ?>">

    <label for="title">Titel</label>
    <input type="text" id="title" name="title" required maxlength="200" value="<?= App::e($f['title']) ?>">

    <div class="field-row">
      <div>
        <label for="category">Art</label>
        <select id="category" name="category">
          <?php foreach (FindingsRepository::CATEGORIES as $k => $l): ?>
            <option value="<?= App::e($k) ?>" <?= $f['category'] === $k ? 'selected' : '' ?>>
              <?= App::e($l) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div>
        <label for="occurred_at">Befunddatum</label>
        <input type="datetime-local" id="occurred_at" name="occurred_at"
               value="<?= App::e($occLocal) ?>" required>
      </div>
    </div>

    <div class="field-row">
      <div>
        <label for="institution">Einrichtung</label>
        <input type="text" id="institution" name="institution" maxlength="200"
               value="<?= App::e($f['institution'] ?? '') ?>">
      </div>
      <div>
        <label for="doctor">Ärztin / Arzt</label>
        <input type="text" id="doctor" name="doctor" maxlength="200"
               value="<?= App::e($f['doctor'] ?? '') ?>">
      </div>
    </div>

    <label for="summary">Kurzfassung</label>
    <input type="text" id="summary" name="summary" maxlength="400" value="<?= App::e($f['summary'] ?? '') ?>">

    <label for="text">Befundtext / Notizen</label>
    <textarea id="text" name="text" rows="10"><?= App::e($f['text'] ?? '') ?></textarea>

    <div class="field-row">
      <div>
        <label for="received_at">Erhalten am</label>
        <input type="date" id="received_at" name="received_at" value="<?= App::e($f['received_at'] ?? '') ?>">
      </div>
      <div>
        <label for="follow_up_at">Wiedervorlage</label>
        <input type="date" id="follow_up_at" name="follow_up_at" value="<?= App::e($f['follow_up_at'] ?? '') ?>">
      </div>
    </div>

    <label for="tags">Tags (Komma-getrennt)</label>
    <input type="text" id="tags" name="tags" value="<?= App::e($tagStr) ?>" autocomplete="off">

    <label style="display:flex;align-items:center;gap:8px;font-weight:400">
      <input type="checkbox" name="is_important" value="1" style="width:auto"
             <?= $f['is_important'] ? 'checked' : '' ?>>
      Als wichtig kennzeichnen
    </label>

    <button type="submit" class="auto">Speichern</button>
  </form>

  <div class="actions">
    <form method="post">
      <?= Csrf::field() ?>
      <input type="hidden" name="action" value="archive">
      <input type="hidden" name="id" value="<?= $id ?>">
      <button type="submit" class="secondary small">
        <?= $f['is_archived'] ? 'Aus dem Archiv holen' : 'Archivieren' ?>
      </button>
    </form>
    <form method="post" data-confirm="Befund samt Dateien, Tags und Timeline-Eintrag löschen?">
      <?= Csrf::field() ?>
      <input type="hidden" name="action" value="delete">
      <input type="hidden" name="id" value="<?= $id ?>">
      <button type="submit" class="secondary small">Löschen</button>
    </form>
  </div>
</div>
<?php View::end($app); ?>
