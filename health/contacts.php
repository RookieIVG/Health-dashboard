<?php
declare(strict_types=1);
require __DIR__ . '/_init.php';

use Health\App;
use Health\ContactsRepository as Contacts;
use Health\Csrf;
use Health\Modules;
use Health\View;

$app = App::boot();
$app->auth->requireLogin();

$repo  = new Contacts($app);
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
                $ok = 'Kontakt gelöscht.';
                break;
        }
    } catch (\Throwable $e) {
        $error = $e->getMessage();
    }
}

if (($_GET['edit'] ?? '') !== '') $edit = $repo->find((int)$_GET['edit']);
$list = $repo->listAll();

View::start($app, ['title' => 'Kontakte – ' . $app->config['app']['name'], 'active' => 'contacts']);
?>
<?php View::flash($ok, 'ok'); View::flash($error, 'error'); ?>

<div class="panel">
  <h1><?= View::moduleDot(Modules::CONTACT) ?>Ärzte und Kontakte</h1>
  <p class="sub"><?= count($list) ?> Einträge · <a href="<?= App::url('/appointments.php') ?>">Termine</a></p>

  <?php if (!$list): ?>
    <p class="empty">Noch kein Kontakt angelegt.</p>
  <?php else: ?>
    <?php foreach ($list as $c): $st = $repo->appointmentStats((int)$c['id']); ?>
      <div class="ev">
        <div class="body">
          <div class="title">
            <?= App::e($c['name']) ?>
            <span class="mod"><?= App::e(Contacts::KINDS[$c['kind']]) ?></span>
            <?php if (!$c['is_active']): ?><span class="mod">inaktiv</span><?php endif; ?>
          </div>
          <div class="sum">
            <?= App::e(implode(' · ', array_filter([
                  $c['specialty'], $c['institution'], $c['phone'],
                  $st['next'] ? 'nächster Termin ' . $app->local($st['next'], 'd.m.Y') : null,
                  (!$st['next'] && $st['last']) ? 'zuletzt ' . $app->local($st['last'], 'd.m.Y') : null,
               ]))) ?>
          </div>
        </div>
        <div class="t" style="width:auto">
          <a class="btn secondary small" style="margin:0"
             href="?edit=<?= (int)$c['id'] ?>">Ändern</a>
        </div>
      </div>
    <?php endforeach; ?>
  <?php endif; ?>
</div>

<div class="panel">
  <h2><?= $edit ? 'Kontakt bearbeiten' : 'Neuer Kontakt' ?></h2>
  <form method="post">
    <?= Csrf::field() ?>
    <input type="hidden" name="action" value="save">
    <?php if ($edit): ?><input type="hidden" name="id" value="<?= (int)$edit['id'] ?>"><?php endif; ?>

    <div class="field-row">
      <div>
        <label for="name">Name</label>
        <input type="text" id="name" name="name" required maxlength="200"
               value="<?= App::e($edit['name'] ?? '') ?>">
      </div>
      <div>
        <label for="kind">Art</label>
        <select id="kind" name="kind">
          <?php foreach (Contacts::KINDS as $k => $l): ?>
            <option value="<?= App::e($k) ?>" <?= ($edit['kind'] ?? '') === $k ? 'selected' : '' ?>>
              <?= App::e($l) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
    </div>

    <div class="field-row">
      <div>
        <label for="specialty">Fachrichtung</label>
        <input type="text" id="specialty" name="specialty" maxlength="120"
               value="<?= App::e($edit['specialty'] ?? '') ?>">
      </div>
    </div>

    <label for="parent_contact_id">Gehört zu (z.&nbsp;B. Klinik, in der die Person arbeitet)</label>
    <select id="parent_contact_id" name="parent_contact_id">
      <option value="">– keine Auswahl –</option>
      <?php foreach ($list as $c): if ($edit && (int)$c['id'] === (int)$edit['id']) continue; ?>
        <option value="<?= (int)$c['id'] ?>"
          <?= isset($edit['parent_contact_id']) && (int)$edit['parent_contact_id'] === (int)$c['id'] ? 'selected' : '' ?>>
          <?= App::e($c['name']) ?>
        </option>
      <?php endforeach; ?>
    </select>
    <label for="parent_contact_new">oder neu anlegen</label>
    <input type="text" id="parent_contact_new" name="parent_contact_new" maxlength="200"
           placeholder="nur ausfüllen, wenn nicht in der Liste oben">
    <p class="hint">
      Umbenennungen wirken sich überall aus, wo dieser Kontakt als
      "Gehört zu" hinterlegt ist.
    </p>

    <div class="field-row">
      <div>
        <label for="phone">Telefon</label>
        <input type="text" id="phone" name="phone" maxlength="60"
               value="<?= App::e($edit['phone'] ?? '') ?>">
      </div>
      <div>
        <label for="email">E-Mail</label>
        <input type="email" id="email" name="email" maxlength="200"
               value="<?= App::e($edit['email'] ?? '') ?>">
      </div>
    </div>

    <label for="address">Adresse</label>
    <textarea id="address" name="address" rows="3"><?= App::e($edit['address'] ?? '') ?></textarea>

    <label for="note">Notizen</label>
    <textarea id="note" name="note" rows="3"><?= App::e($edit['note'] ?? '') ?></textarea>

    <label style="display:flex;align-items:center;gap:8px;font-weight:400">
      <input type="checkbox" name="is_active" value="1" style="width:auto"
             <?= (!$edit || $edit['is_active']) ? 'checked' : '' ?>> aktiv
    </label>

    <button type="submit" class="auto"><?= $edit ? 'Aktualisieren' : 'Anlegen' ?></button>
    <?php if ($edit): ?>
      <p class="foot"><a href="<?= App::url('/contacts.php') ?>">Bearbeitung abbrechen</a></p>
    <?php endif; ?>
  </form>

  <?php if ($edit): ?>
  <div class="actions">
    <form method="post" data-confirm="Kontakt löschen? Termine bleiben erhalten, verlieren aber die Zuordnung.">
      <?= Csrf::field() ?>
      <input type="hidden" name="action" value="delete">
      <input type="hidden" name="id" value="<?= (int)$edit['id'] ?>">
      <button type="submit" class="secondary small">Löschen</button>
    </form>
  </div>
  <?php endif; ?>
</div>
<?php View::end($app); ?>
