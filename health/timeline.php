<?php
declare(strict_types=1);
require __DIR__ . '/_init.php';

use Health\App;
use Health\Modules;
use Health\View;

$app  = App::boot();
$auth = $app->auth;
$auth->requireLogin();

$tz     = new DateTimeZone($app->config['app']['timezone']);
$preset = (string)($_GET['range'] ?? '30d');
$modules = array_values(array_filter(
    (array)($_GET['m'] ?? []),
    fn($m) => Modules::isValid((string)$m)
));

$now  = new DateTimeImmutable('now', $tz);
$from = match ($preset) {
    '7d'    => $now->modify('-7 days'),
    '90d'   => $now->modify('-90 days'),
    '1y'    => $now->modify('-1 year'),
    'all'   => null,
    default => $now->modify('-30 days'),
};
$fromUtc = $from?->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');

$events = $app->timeline()->range($fromUtc, null, $modules ?: null, 300);
$byDay  = $app->timeline()->groupedByDay($events);
$counts = $app->timeline()->countsByModule($fromUtc);
$bounds = $app->timeline()->bounds();

$WEEKDAYS = ['Monday'=>'Montag','Tuesday'=>'Dienstag','Wednesday'=>'Mittwoch',
             'Thursday'=>'Donnerstag','Friday'=>'Freitag','Saturday'=>'Samstag','Sunday'=>'Sonntag'];

View::start($app, ['title' => 'Timeline – ' . $app->config['app']['name'], 'active' => 'timeline']);
?>
<div class="panel">
  <h1>Timeline</h1>
  <p class="sub">
    <?php if ($bounds['first']): ?>
      Einträge von <?= App::e($app->local($bounds['first'], 'd.m.Y')) ?>
      bis <?= App::e($app->local($bounds['last'], 'd.m.Y')) ?>
    <?php else: ?>
      Noch keine Einträge – die Module füllen die Achse, sobald sie stehen.
    <?php endif; ?>
  </p>

  <div class="filters" style="margin-bottom:12px">
    <?php foreach (['7d'=>'7 Tage','30d'=>'30 Tage','90d'=>'90 Tage','1y'=>'1 Jahr','all'=>'Alles'] as $k=>$l):
          $q = array_filter(['range'=>$k, 'm'=>$modules]); ?>
      <a class="chip <?= $preset === $k ? 'active' : '' ?>"
         href="?<?= App::e(http_build_query($q)) ?>"><?= App::e($l) ?></a>
    <?php endforeach; ?>
  </div>

  <div class="filters">
    <a class="chip <?= $modules ? '' : 'active' ?>"
       href="?<?= App::e(http_build_query(['range' => $preset])) ?>">Alle</a>
    <?php foreach ($counts as $mod => $n):
          $sel  = in_array($mod, $modules, true);
          $next = $sel ? array_values(array_diff($modules, [$mod])) : array_merge($modules, [$mod]); ?>
      <a class="chip <?= $sel ? 'active' : '' ?>"
         href="?<?= App::e(http_build_query(array_filter(['range'=>$preset,'m'=>$next]))) ?>">
        <?= App::e(Modules::label($mod)) ?><span class="n"><?= (int)$n ?></span>
      </a>
    <?php endforeach; ?>
  </div>
</div>

<div class="panel">
  <?php if (!$byDay): ?>
    <p class="empty">Keine Einträge im gewählten Zeitraum.</p>
  <?php else: ?>
    <?php foreach ($byDay as $day => $list): $d = new DateTimeImmutable($day, $tz); ?>
      <div class="day">
        <div class="day-head">
          <?= App::e($WEEKDAYS[$d->format('l')] ?? $d->format('l')) ?>, <?= App::e($d->format('d.m.Y')) ?>
        </div>
        <?php foreach ($list as $e): ?>
          <div class="ev sev<?= (int)$e['severity'] ?>">
            <div class="t"><?= App::e($e['local_time']) ?></div>
            <div class="body">
              <div class="title">
                <?= App::e($e['title']) ?>
                <span class="mod"><?= App::e($e['module_label']) ?></span>
              </div>
              <?php if (!empty($e['summary'])): ?>
                <div class="sum"><?= App::e($e['summary']) ?></div>
              <?php endif; ?>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endforeach; ?>
  <?php endif; ?>
</div>
<?php View::end($app); ?>
