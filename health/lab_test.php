<?php
declare(strict_types=1);
require __DIR__ . '/_init.php';

use Health\App;
use Health\Chart;
use Health\LabRepository;
use Health\View;

$app = App::boot();
$app->auth->requireLogin();

$repo = new LabRepository($app);
$test = $repo->test((int)($_GET['id'] ?? 0));
if (!$test) { header('Location: ' . App::url('/lab_cumulative.php')); exit; }

$series = $repo->seriesForTest((int)$test['id']);

View::start($app, ['title' => $test['label'] . ' – Labor', 'active' => 'lab']);
?>
<div class="panel">
  <h1><?= App::e($test['label']) ?></h1>
  <p class="sub">
    <?= $test['unit'] !== '' ? App::e($test['unit']) . ' · ' : '' ?>
    <?php if ($test['ref_low'] !== null || $test['ref_high'] !== null): ?>
      Referenz <?= App::e(rtrim(rtrim((string)$test['ref_low'],'0'),'.')) ?>–<?= App::e(rtrim(rtrim((string)$test['ref_high'],'0'),'.')) ?>
    <?php endif; ?>
    · <a href="<?= App::url('/lab_cumulative.php') ?>">Kumulativbefund</a>
  </p>
</div>
<div class="panel">
  <?php if (count($series) >= 2): ?>
    <?= Chart::line($series, [
          'decimals' => (int)$test['decimals'],
          'ref_low'  => $test['ref_low'] === null ? null : (float)$test['ref_low'],
          'ref_high' => $test['ref_high'] === null ? null : (float)$test['ref_high'],
          'timezone' => $app->config['app']['timezone'],
        ]) ?>
  <?php else: ?>
    <p class="empty">Zu wenige Werte für eine Kurve.</p>
  <?php endif; ?>
</div>
<?php View::end($app); ?>
