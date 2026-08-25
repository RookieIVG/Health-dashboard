<?php
declare(strict_types=1);
require __DIR__ . '/_init.php';

use Health\App;
use Health\Csrf;
use Health\LabRepository;
use Health\Modules;
use Health\View;

$app = App::boot();
$app->auth->requireLogin();

$repo  = new LabRepository($app);
$error = $ok = null;

$visits = $repo->visits();
$tests  = $repo->tests();

View::start($app, ['title' => 'Labor – ' . $app->config['app']['name'], 'active' => 'lab']);
?>
<?php View::flash($ok, 'ok'); View::flash($error, 'error'); ?>

<div class="panel">
  <h1><?= View::moduleDot(Modules::LAB) ?>Labor</h1>
  <p class="sub">
    <?= count($visits) ?> Laborbefunde
    · <a href="<?= App::url('/lab_cumulative.php') ?>">Kumulativbefund</a>
    · <a href="<?= App::url('/lab_visit.php') ?>">Neuer Laborbefund</a>
  </p>

  <?php if (!$visits): ?>
    <p class="empty">Noch kein Laborbefund erfasst.</p>
  <?php else: ?>
    <?php foreach ($visits as $v): ?>
      <div class="ev">
        <div class="t" style="width:74px"><?= App::e(date('d.m.Y', strtotime($v['visit_date']))) ?></div>
        <div class="body">
          <div class="title">
            <a href="<?= App::url('/lab_visit.php?id=' . (int)$v['id']) ?>">
              Laborbefund<?= $v['institution'] ? ' – ' . App::e($v['institution']) : '' ?>
            </a>
          </div>
        </div>
      </div>
    <?php endforeach; ?>
  <?php endif; ?>
</div>

<div class="panel">
  <h2>Laborparameter verwalten</h2>
  <p class="sub">Bezeichnungen, Referenzbereiche bearbeiten, eigene Laborparameter anlegen, ausblenden.</p>
  <a class="btn secondary auto" href="<?= App::url('/lab_tests.php') ?>">Laborparameter verwalten</a>
</div>
<?php View::end($app); ?>
