<?php
declare(strict_types=1);
require __DIR__ . '/_init.php';

use Health\App;
use Health\Csrf;
use Health\DiaryRepository;
use Health\View;

$app = App::boot();
$app->auth->requireLogin();

$repo  = new DiaryRepository($app);
$error = $ok = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::requireValid();
    try {
        if (($_POST['action'] ?? '') === 'new_type') {
            $id = $repo->createType(
                (string)($_POST['label'] ?? ''),
                trim((string)($_POST['description'] ?? '')) ?: null
            );
            header('Location: ' . App::url('/diary_setup.php?type=' . $id));
            exit;
        }
    } catch (\Throwable $e) {
        $error = $e->getMessage();
    }
}

$types  = $repo->types();
$counts = $repo->countsByType();

View::start($app, ['title' => 'Tagebücher – ' . $app->config['app']['name'], 'active' => 'diary']);
?>
<?php View::flash($ok, 'ok'); View::flash($error, 'error'); ?>

<div class="panel">
  <h1>Tagebücher</h1>
  <p class="sub">Fünf sind vorbereitet. Eigene lassen sich frei zusammenstellen.</p>

  <div class="mod-list">
    <?php foreach ($types as $t):
          $c = $counts[(int)$t['id']] ?? null; ?>
      <a class="mod-item" href="<?= App::url('/diary_type.php?type=' . (int)$t['id']) ?>"
         style="text-decoration:none;color:inherit">
        <div class="n"><?= App::e($t['label']) ?></div>
        <?php if ($t['description']): ?>
          <div class="d"><?= App::e($t['description']) ?></div>
        <?php endif; ?>
        <div class="s">
          <?php if ($c): ?>
            <?= $c['n'] ?> Einträge · zuletzt <?= App::e($app->local($c['last'], 'd.m.Y')) ?>
          <?php else: ?>
            noch kein Eintrag
          <?php endif; ?>
          <?php if ($t['user_id'] !== null): ?> · eigen<?php endif; ?>
        </div>
      </a>
    <?php endforeach; ?>
  </div>
</div>

<div class="panel">
  <h2>Eigenes Tagebuch anlegen</h2>
  <p class="sub">
    Danach legst du die Felder fest – Skala, Zahl, Auswahl, Ja/Nein, Text,
    Uhrzeit oder Dauer.
  </p>
  <form method="post">
    <?= Csrf::field() ?>
    <input type="hidden" name="action" value="new_type">
    <label for="label">Bezeichnung</label>
    <input type="text" id="label" name="label" required maxlength="96"
           placeholder="z. B. Migränetagebuch">
    <label for="description">Kurzbeschreibung</label>
    <input type="text" id="description" name="description" maxlength="255">
    <button type="submit" class="auto secondary">Anlegen und Felder festlegen</button>
  </form>
</div>
<?php View::end($app); ?>
