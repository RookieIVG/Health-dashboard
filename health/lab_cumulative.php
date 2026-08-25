<?php
declare(strict_types=1);
require __DIR__ . '/_init.php';

use Health\App;
use Health\LabRepository;
use Health\View;

$app = App::boot();
$app->auth->requireLogin();

$repo = new LabRepository($app);
$n    = (int)($_GET['n'] ?? 8);
$n    = in_array($n, [6, 8, 12, 20], true) ? $n : 8;
$data = $repo->cumulative($n);

View::start($app, ['title' => 'Kumulativbefund – ' . $app->config['app']['name'], 'active' => 'lab']);
?>
<div class="panel">
  <h1>Kumulativbefund</h1>
  <p class="sub"><a href="<?= App::url('/lab.php') ?>">zur Übersicht</a></p>
  <div class="filters">
    <?php foreach ([6, 8, 12, 20] as $k): ?>
      <a class="chip <?= $n === $k ? 'active' : '' ?>" href="?n=<?= $k ?>"><?= $k ?> Termine</a>
    <?php endforeach; ?>
  </div>
</div>

<div class="panel">
  <?php if (!$data['visits']): ?>
    <p class="empty">Noch keine Befundtermine.</p>
  <?php else: ?>
  <div class="table-wrap">
    <table>
      <thead>
        <tr>
          <th>Laborparameter</th>
          <?php foreach ($data['visits'] as $v): ?>
            <th><?= App::e(date('d.m.y', strtotime($v['visit_date']))) ?></th>
          <?php endforeach; ?>
        </tr>
      </thead>
      <tbody>
        <?php $lastCat = null; foreach ($data['rows'] as $tid => $row):
              $m = $row['meta'];
              if ($m['category'] !== $lastCat): $lastCat = $m['category']; ?>
          <tr><td colspan="<?= count($data['visits']) + 1 ?>" style="color:var(--muted);padding-top:14px">
            <?= App::e($lastCat ?: 'Sonstiges') ?></td></tr>
        <?php endif; ?>
        <tr>
          <td>
            <a href="<?= App::url('/lab_test.php?id=' . $tid) ?>">
              <?= App::e($m['label']) ?><?= $m['unit'] !== '' ? ' (' . App::e($m['unit']) . ')' : '' ?>
            </a>
          </td>
          <?php foreach ($data['visits'] as $v):
                $r = $row['values'][(int)$v['id']] ?? null; ?>
            <td style="<?= $r && $r['flag'] ? 'font-weight:600;color:' . ($r['flag'] === 'H' ? 'var(--danger)' : 'var(--warn)') : '' ?>">
              <?= $r && $r['num'] !== null ? App::e(number_format((float)$r['num'], (int)$m['decimals'], ',', '.')) : ($r ? '·' : '–') ?>
              <?= $r && $r['flag'] ? App::e($r['flag']) : '' ?>
            </td>
          <?php endforeach; ?>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <p class="hint">L = unter, H = über dem Referenzbereich. Ersetzt keine ärztliche Einordnung.</p>
  <?php endif; ?>
</div>
<?php View::end($app); ?>
