<?php
declare(strict_types=1);
require __DIR__ . '/_init.php';

use Health\App;
use Health\AppointmentsRepository as Appt;
use Health\AttachmentService;
use Health\ContactsRepository;
use Health\Csrf;
use Health\Modules;
use Health\View;

$app = App::boot();
$app->auth->requireLogin();

$repo = new Appt($app);
$id   = (int)($_GET['id'] ?? 0);
$error = $ok = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::requireValid();
    $id = (int)($_POST['id'] ?? $id);
    try {
        switch ($_POST['action'] ?? '') {
            case 'save':   $repo->store($_POST, $id); $ok = 'Gespeichert.'; break;
            case 'status': $repo->setStatus($id, (string)($_POST['status'] ?? '')); $ok = 'Status geändert.'; break;
            case 'upload':
                if (empty($_FILES['file']['name'])) throw new RuntimeException('Keine Datei ausgewählt.');
                $app->attachments()->storeUpload($_FILES['file'], Modules::APPOINTMENT, $id);
                $ok = 'Datei angehängt.';
                break;
            case 'delete_file':
                $app->attachments()->deleteOne((int)($_POST['att'] ?? 0));
                $ok = 'Datei gelöscht.';
                break;
            case 'delete':
                $repo->delete($id);
                header('Location: ' . App::url('/appointments.php'));
                exit;
        }
    } catch (\Throwable $e) {
        $error = $e->getMessage();
    }
}

$a = $repo->detail($id);
if (!$a) { header('Location: ' . App::url('/appointments.php')); exit; }

$contacts = (new ContactsRepository($app))->listAll();
$startVal = $app->local($a['starts_at'], 'Y-m-d\TH:i');
$endVal   = $a['ends_at'] ? $app->local($a['ends_at'], 'Y-m-d\TH:i') : '';
$isPast   = strtotime($a['starts_at'] . ' UTC') < time();

View::start($app, ['title' => $a['title'] . ' – Termin', 'active' => 'appointments']);
?>
<?php View::flash($ok, 'ok'); View::flash($error, 'error'); ?>

<div class="panel">
  <h1><?= App::e($a['title']) ?></h1>
  <p class="sub">
    <?= App::e($app->local($a['starts_at'], 'd.m.Y H:i')) ?>
    <?php if ($a['ends_at']): ?>–<?= App::e($app->local($a['ends_at'], 'H:i')) ?><?php endif; ?>
    · <?= App::e(Appt::STATUS[$a['status']]) ?>
    <?php if (!empty($a['contact']['name'])): ?> · <?= App::e($a['contact']['name']) ?><?php endif; ?>
    · <a href="<?= App::url('/appointments.php') ?>">zur Liste</a>
  </p>

  <?php if (!empty($a['location'])): ?><p><?= App::e($a['location']) ?></p><?php endif; ?>
  <?php if (!empty($a['purpose'])): ?><p class="sub"><?= App::e($a['purpose']) ?></p><?php endif; ?>

  <?php if (!empty($a['prep'])): ?>
    <h2 style="margin-top:16px">Vorbereitung</h2>
    <div style="white-space:pre-wrap"><?= App::e($a['prep']) ?></div>
  <?php endif; ?>

  <?php if (!empty($a['result'])): ?>
    <h2 style="margin-top:16px">Ergebnis</h2>
    <div style="white-space:pre-wrap"><?= App::e($a['result']) ?></div>
  <?php endif; ?>

  <?php if ($a['status'] === 'planned'): ?>
  <div class="actions">
    <form method="post">
      <?= Csrf::field() ?>
      <input type="hidden" name="action" value="status">
      <input type="hidden" name="id" value="<?= $id ?>">
      <input type="hidden" name="status" value="done">
      <button type="submit" class="secondary small">Als erledigt markieren</button>
    </form>
    <form method="post">
      <?= Csrf::field() ?>
      <input type="hidden" name="action" value="status">
      <input type="hidden" name="id" value="<?= $id ?>">
      <input type="hidden" name="status" value="cancelled">
      <button type="submit" class="secondary small">Abgesagt</button>
    </form>
  </div>
  <?php endif; ?>
</div>

<div class="panel">
  <h2>Dateien</h2>
  <?php if ($a['attachments']): ?>
    <div class="table-wrap">
      <table class="stack">
        <thead><tr><th>Datei</th><th>Größe</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($a['attachments'] as $f): ?>
          <tr>
            <td data-label="Datei">
              <a href="<?= App::url('/file.php?id=' . (int)$f['id']) ?>" target="_blank" rel="noopener">
                <?= App::e($f['filename']) ?></a>
            </td>
            <td data-label="Größe"><?= App::e(AttachmentService::formatSize((int)$f['size_bytes'])) ?></td>
            <td>
              <form method="post" data-confirm="Diese Datei löschen?" style="margin:0">
                <?= Csrf::field() ?>
                <input type="hidden" name="action" value="delete_file">
                <input type="hidden" name="id" value="<?= $id ?>">
                <input type="hidden" name="att" value="<?= (int)$f['id'] ?>">
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

    <label for="title">Titel</label>
    <input type="text" id="title" name="title" required maxlength="200" value="<?= App::e($a['title']) ?>">

    <div class="field-row">
      <div>
        <label for="starts_at">Beginn</label>
        <input type="datetime-local" id="starts_at" name="starts_at" value="<?= App::e($startVal) ?>" required>
      </div>
      <div>
        <label for="ends_at">Ende</label>
        <input type="datetime-local" id="ends_at" name="ends_at" value="<?= App::e($endVal) ?>">
      </div>
    </div>

    <div class="field-row">
      <div>
        <label for="contact_id">Kontakt</label>
        <select id="contact_id" name="contact_id">
          <option value="">—</option>
          <?php foreach ($contacts as $c): ?>
            <option value="<?= (int)$c['id'] ?>"
                    <?= (int)$a['contact_id'] === (int)$c['id'] ? 'selected' : '' ?>>
              <?= App::e($c['name']) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div>
        <label for="status">Status</label>
        <select id="status" name="status">
          <?php foreach (Appt::STATUS as $k => $l): ?>
            <option value="<?= App::e($k) ?>" <?= $a['status'] === $k ? 'selected' : '' ?>>
              <?= App::e($l) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
    </div>

    <label for="reminder_min">Erinnerung im Kalender</label>
    <select id="reminder_min" name="reminder_min">
      <?php foreach (Appt::REMINDERS as $k => $l): ?>
        <option value="<?= App::e((string)$k) ?>"
                <?= (string)($a['reminder_min'] ?? '') === (string)$k ? 'selected' : '' ?>>
          <?= App::e($l) ?></option>
      <?php endforeach; ?>
    </select>

    <label for="location">Ort</label>
    <input type="text" id="location" name="location" maxlength="200" value="<?= App::e($a['location'] ?? '') ?>">

    <label for="purpose">Anlass</label>
    <input type="text" id="purpose" name="purpose" maxlength="300" value="<?= App::e($a['purpose'] ?? '') ?>">

    <label for="prep">Vorbereitung, Fragen</label>
    <textarea id="prep" name="prep" rows="5"><?= App::e($a['prep'] ?? '') ?></textarea>

    <label for="result">Ergebnis<?= $isPast ? '' : ' (nach dem Termin)' ?></label>
    <textarea id="result" name="result" rows="5"><?= App::e($a['result'] ?? '') ?></textarea>

    <button type="submit" class="auto">Speichern</button>
  </form>

  <div class="actions">
    <form method="post" data-confirm="Termin samt Dateien und Timeline-Eintrag löschen?">
      <?= Csrf::field() ?>
      <input type="hidden" name="action" value="delete">
      <input type="hidden" name="id" value="<?= $id ?>">
      <button type="submit" class="secondary small">Löschen</button>
    </form>
  </div>
</div>
<?php View::end($app); ?>
