<?php
declare(strict_types=1);
require __DIR__ . '/_init.php';

use Health\App;
use Health\DiaryRepository;
use Health\View;

$app  = App::boot();
$app->auth->requireLogin();

$repo  = new DiaryRepository($app);
$types = $repo->types(true);

$sourceId   = (int)($_GET['source'] ?? 0);
$source2Id  = (int)($_GET['source2'] ?? 0);
$outcomeId  = (int)($_GET['outcome'] ?? 0);
$window     = max(1, min(168, (int)($_GET['window'] ?? 24)));
$minOcc     = max(1, min(50, (int)($_GET['min'] ?? 3)));

$result  = null;
$result2 = null;
if ($sourceId && $outcomeId) {
    $result = $repo->analyzeCorrelation($sourceId, $outcomeId, $window, $minOcc);
}
if ($source2Id && $outcomeId && $source2Id !== $sourceId) {
    $result2 = $repo->analyzeCorrelation($source2Id, $outcomeId, $window, $minOcc);
}

$typeLabel = fn(int $id) => (function () use ($types, $id) {
    foreach ($types as $t) if ((int)$t['id'] === $id) return $t['label'];
    return '';
})();

View::start($app, ['title' => 'Auswertung – ' . $app->config['app']['name'], 'active' => 'diary']);
?>

<div class="panel">
  <h1>Muster erkennen</h1>
  <p class="sub">
    Vergleicht Einträge eines Tagebuchs mit dem, was in einem anderen
    Tagebuch danach passiert ist – z.&nbsp;B. Ernährung gegen Stuhl- oder
    Schmerztagebuch.
  </p>
  <div class="msg" style="background:var(--panel-2);border:1px solid var(--line)">
    Das hier zeigt Auffälligkeiten in deinen eigenen Aufzeichnungen,
    <strong>keine Diagnose und keine medizinische Bewertung</strong>. Bei
    wenigen Einträgen ist ein Unterschied oft Zufall, kein echtes Muster –
    je mehr du erfasst, desto verlässlicher wird das Bild. Auffälligkeiten
    sind ein Gesprächsanlass für Arzt oder Ärztin, kein Ergebnis für sich.
  </div>

  <form method="get" style="margin-top:18px">
    <div class="field-row">
      <div>
        <label for="source">Auslöser-Tagebuch</label>
        <select id="source" name="source" required>
          <option value="">– wählen –</option>
          <?php foreach ($types as $t): ?>
            <option value="<?= (int)$t['id'] ?>" <?= $sourceId === (int)$t['id'] ? 'selected' : '' ?>>
              <?= App::e($t['label']) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div>
        <label for="source2">2. Auslöser-Tagebuch (optional)</label>
        <select id="source2" name="source2">
          <option value="">– keins –</option>
          <?php foreach ($types as $t): ?>
            <option value="<?= (int)$t['id'] ?>" <?= $source2Id === (int)$t['id'] ? 'selected' : '' ?>>
              <?= App::e($t['label']) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
    </div>
    <div class="field-row">
      <div>
        <label for="outcome">Wirkung-Tagebuch</label>
        <select id="outcome" name="outcome" required>
          <option value="">– wählen –</option>
          <?php foreach ($types as $t): ?>
            <option value="<?= (int)$t['id'] ?>" <?= $outcomeId === (int)$t['id'] ? 'selected' : '' ?>>
              <?= App::e($t['label']) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
    </div>
    <div class="field-row">
      <div>
        <label for="window">Zeitfenster danach (Stunden)</label>
        <input type="number" id="window" name="window" min="1" max="168" value="<?= (int)$window ?>">
      </div>
      <div>
        <label for="min">Mindestanzahl je Stichwort</label>
        <input type="number" id="min" name="min" min="1" max="50" value="<?= (int)$minOcc ?>">
      </div>
    </div>
    <button type="submit" class="auto">Auswerten</button>
  </form>
</div>

<?php
$panels = [];
if ($result)  $panels[] = ['srcId' => $sourceId,  'res' => $result];
if ($result2) $panels[] = ['srcId' => $source2Id, 'res' => $result2];

foreach ($panels as $panel): $res = $panel['res']; $srcId = $panel['srcId']; ?>

<div class="panel">
  <h2><?= App::e($typeLabel($srcId)) ?> → <?= App::e($typeLabel($outcomeId)) ?></h2>
  <p class="sub">
    <?= (int)$res['sourceCount'] ?> Einträge im Auslöser-Tagebuch,
    davon <?= (int)$res['usableCount'] ?> mit einer Aufzeichnung im
    Wirkung-Tagebuch innerhalb von <?= (int)$window ?> Stunden danach.
  </p>

  <?php if ($res['usableCount'] === 0): ?>
    <p class="empty">
      Keine auswertbaren Fälle – entweder fehlen Einträge, oder im
      Zeitfenster danach steht im Wirkung-Tagebuch nichts.
    </p>
  <?php endif; ?>
</div>

<?php if ($res['buckets']): ?>
<div class="panel">
  <h2>Nach Leitwert: <?= App::e($res['primaryField']['label'] ?? '') ?></h2>
  <p class="sub">Ohne Zusatzeingabe, direkt aus dem Leitwert des Auslöser-Tagebuchs.</p>
  <div class="table-wrap">
    <table class="stack">
      <thead>
        <tr>
          <th>Gruppe</th><th>Einträge</th>
          <?php foreach ($res['outcomeFields'] as $f): ?>
            <th>⌀ <?= App::e($f['label']) ?></th>
          <?php endforeach; ?>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($res['buckets'] as $b): ?>
          <tr>
            <td data-label="Gruppe"><?= App::e($b['label']) ?></td>
            <td data-label="Einträge"><?= (int)$b['n'] ?></td>
            <?php foreach ($res['outcomeFields'] as $f): $v = $b['avg'][(int)$f['id']] ?? null; ?>
              <td data-label="⌀ <?= App::e($f['label']) ?>"><?= $v !== null ? number_format($v, 1, ',', '.') : '–' ?></td>
            <?php endforeach; ?>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<?php if ($res['tags']): ?>
<div class="panel">
  <h2>Nach Stichwort / Zutat</h2>
  <p class="sub">
    Nur Stichworte mit mindestens <?= (int)$minOcc ?> Einträgen. "Mit" vs.
    "ohne" vergleicht Tage mit diesem Stichwort gegen alle anderen
    auswertbaren Tage.
  </p>
  <div class="table-wrap">
    <table class="stack">
      <thead>
        <tr>
          <th>Stichwort</th><th>Einträge</th>
          <?php foreach ($res['outcomeFields'] as $f): ?>
            <th>⌀ mit <?= App::e($f['label']) ?></th>
            <th>⌀ ohne</th>
          <?php endforeach; ?>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($res['tags'] as $t): ?>
          <tr>
            <td data-label="Stichwort">
              <?= App::e($t['name']) ?>
              <?php if ($t['n'] < 5): ?><span class="badge off">wenige Einträge</span><?php endif; ?>
            </td>
            <td data-label="Einträge"><?= (int)$t['n'] ?> / <?= (int)$t['nWithout'] ?> ohne</td>
            <?php foreach ($res['outcomeFields'] as $f):
                  $fid = (int)$f['id'];
                  $with = $t['avgWith'][$fid] ?? null;
                  $without = $t['avgWithout'][$fid] ?? null; ?>
              <td data-label="⌀ mit <?= App::e($f['label']) ?>"><?= $with !== null ? number_format($with, 1, ',', '.') : '–' ?></td>
              <td data-label="⌀ ohne"><?= $without !== null ? number_format($without, 1, ',', '.') : '–' ?></td>
            <?php endforeach; ?>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php elseif ($res['usableCount'] > 0): ?>
<div class="panel">
  <p class="empty">
    Keine Stichworte mit ausreichend Einträgen. Trage bei den Einträgen im
    Auslöser-Tagebuch Zutaten/Stichworte ein, dann erscheinen sie hier.
  </p>
</div>
<?php endif; ?>

<?php endforeach; ?>

<?php View::end($app); ?>
