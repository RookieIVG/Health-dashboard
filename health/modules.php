<?php
declare(strict_types=1);
require __DIR__ . '/_init.php';

use Health\App;
use Health\Csrf;
use Health\ModuleRegistry;
use Health\View;

$app = App::boot();
$app->auth->requireLogin();

$reg   = $app->modules();
$ok    = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::requireValid();
    $key = (string)($_POST['key'] ?? '');
    switch ($_POST['action'] ?? '') {
        case 'up':     $reg->move($key, -1); $ok = 'Reihenfolge geändert.'; break;
        case 'down':   $reg->move($key,  1); $ok = 'Reihenfolge geändert.'; break;
        case 'toggle': $reg->toggleHidden($key); $ok = 'Sichtbarkeit geändert.'; break;
        case 'reset':  $reg->saveOrder(array_keys(ModuleRegistry::DEFS), []); $ok = 'Zurückgesetzt.'; break;
    }
}

$prefs = $reg->prefs();
$order = $prefs['order'];
$last  = count($order) - 1;

View::start($app, ['title' => 'Module ordnen', 'active' => 'modules']);
?>
<?php View::flash($ok, 'ok'); ?>

<div class="panel">
  <h1>Module ordnen</h1>
  <p class="sub">
    Die Reihenfolge gilt für die Kachelübersicht und die Kopfzeile.
    Ausgeblendete Module verschwinden aus beidem – die Daten bleiben erhalten
    und sind über die Adresse weiterhin erreichbar.
  </p>

  <div class="table-wrap">
    <table class="stack">
      <thead><tr><th>Modul</th><th>Status</th><th>Sichtbar</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($order as $i => $key):
            $d = ModuleRegistry::DEFS[$key];
            $hidden = in_array($key, $prefs['hidden'], true); ?>
        <tr>
          <td data-label="Modul"><strong><?= App::e($d['label']) ?></strong></td>
          <td data-label="Status"><?= $d['ready'] ? 'verfügbar' : 'geplant' ?></td>
          <td data-label="Sichtbar">
            <form method="post" style="margin:0">
              <?= Csrf::field() ?>
              <input type="hidden" name="action" value="toggle">
              <input type="hidden" name="key" value="<?= App::e($key) ?>">
              <button type="submit" class="secondary small">
                <?= $hidden ? 'ausgeblendet' : 'sichtbar' ?>
              </button>
            </form>
          </td>
          <td>
            <div class="actions" style="margin:0;justify-content:flex-end">
              <?php if ($i > 0): ?>
              <form method="post" style="margin:0">
                <?= Csrf::field() ?>
                <input type="hidden" name="action" value="up">
                <input type="hidden" name="key" value="<?= App::e($key) ?>">
                <button type="submit" class="secondary small" aria-label="nach oben">↑</button>
              </form>
              <?php endif; ?>
              <?php if ($i < $last): ?>
              <form method="post" style="margin:0">
                <?= Csrf::field() ?>
                <input type="hidden" name="action" value="down">
                <input type="hidden" name="key" value="<?= App::e($key) ?>">
                <button type="submit" class="secondary small" aria-label="nach unten">↓</button>
              </form>
              <?php endif; ?>
            </div>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <div class="actions">
    <form method="post" data-confirm="Reihenfolge auf den Auslieferungszustand zurücksetzen?">
      <?= Csrf::field() ?>
      <input type="hidden" name="action" value="reset">
      <button type="submit" class="secondary small">Zurücksetzen</button>
    </form>
    <a class="btn secondary small" style="margin:0" href="<?= App::url('/index.php') ?>">Fertig</a>
  </div>
</div>
<?php View::end($app); ?>
