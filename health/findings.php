<?php
declare(strict_types=1);
require __DIR__ . '/_init.php';

use Health\App;
use Health\Csrf;
use Health\FindingsRepository;
use Health\ContactsRepository;
use Health\Modules;
use Health\View;

$app = App::boot();
$app->auth->requireLogin();

$repo  = new FindingsRepository($app);
$institutions = (new ContactsRepository($app))->listAll();
$error = $ok = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::requireValid();
    try {
        if (($_POST['action'] ?? '') === 'create') {
            $id = $repo->store($_POST);

            // Direkt mitgeschickte Datei gleich anhängen
            if (!empty($_FILES['file']['name'])) {
                $app->attachments()->storeUpload($_FILES['file'], \Health\Modules::FINDING, $id);
            }
            header('Location: ' . App::url('/finding.php?id=' . $id));
            exit;
        }
    } catch (\Throwable $e) {
        $error = $e->getMessage();
    }
}

$filters = [
    'category'  => (string)($_GET['cat'] ?? ''),
    'q'         => (string)($_GET['q'] ?? ''),
    'archived'  => !empty($_GET['archived']),
    'important' => !empty($_GET['important']),
    'tag_id'    => (int)($_GET['tag'] ?? 0) ?: null,
];

$list   = $repo->search($filters);
$counts = $repo->countsByCategory($filters['archived']);
$attCnt = $repo->attachmentCounts(array_column($list, 'id'));
$due    = $repo->dueFollowUps();
$tags   = $app->tags()->all(null, true);

$today    = (new DateTimeImmutable('now', new DateTimeZone($app->config['app']['timezone'])));
$nowLocal = $today->format('Y-m-d\TH:i');

function fq(array $over): string {
    $q = array_merge($_GET, $over);
    return '?' . http_build_query(array_filter($q, fn($v) => $v !== null && $v !== '' && $v !== false));
}

View::start($app, ['title' => 'Befunde – ' . $app->config['app']['name'], 'active' => 'findings']);
?>
<?php View::flash($ok, 'ok'); View::flash($error, 'error'); ?>

<?php if ($due): ?>
<div class="panel">
  <h2>Wiedervorlage</h2>
  <?php foreach ($due as $d):
        $overdue = $d['follow_up_at'] < $today->format('Y-m-d'); ?>
    <div class="ev">
      <div class="t" style="width:80px;<?= $overdue ? 'color:var(--danger);font-weight:600' : '' ?>">
        <?= App::e(date('d.m.y', strtotime($d['follow_up_at']))) ?>
      </div>
      <div class="body">
        <div class="title">
          <a href="<?= App::url('/finding.php?id=' . (int)$d['id']) ?>"><?= App::e($d['title']) ?></a>
        </div>
        <div class="sum"><?= App::e($overdue ? 'überfällig' : 'anstehend') ?></div>
      </div>
    </div>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<div class="panel">
  <h1><?= View::moduleDot(Modules::FINDING) ?>Befunde</h1>
  <p class="sub"><?= count($list) ?> Einträge<?= $filters['archived'] ? ' im Archiv' : '' ?></p>

  <form method="get" style="margin-bottom:12px">
    <?php if ($filters['category'] !== ''): ?>
      <input type="hidden" name="cat" value="<?= App::e($filters['category']) ?>">
    <?php endif; ?>
    <?php if ($filters['archived']): ?><input type="hidden" name="archived" value="1"><?php endif; ?>
    <label for="q">Suche</label>
    <input type="text" id="q" name="q" value="<?= App::e($filters['q']) ?>"
           placeholder="Titel, Arzt, Einrichtung, Text" autocomplete="off">
    <p class="hint">
      Durchsucht die entschlüsselten Inhalte im Server-Speicher. Die Datenbank
      selbst kann in verschlüsselten Feldern nicht suchen – deshalb wirkt die
      Suche erst nach Kategorie- und Archivfilter.
    </p>
    <button type="submit" class="auto secondary">Suchen</button>
  </form>

  <div class="filters">
    <a class="chip <?= $filters['category'] === '' ? 'active' : '' ?>"
       href="<?= App::e(fq(['cat' => null])) ?>">Alle</a>
    <?php foreach (FindingsRepository::CATEGORIES as $key => $label):
          if (empty($counts[$key])) continue; ?>
      <a class="chip <?= $filters['category'] === $key ? 'active' : '' ?>"
         href="<?= App::e(fq(['cat' => $key])) ?>">
        <?= App::e($label) ?><span class="n"><?= (int)$counts[$key] ?></span>
      </a>
    <?php endforeach; ?>
  </div>

  <div class="filters" style="margin-top:8px">
    <a class="chip <?= $filters['important'] ? 'active' : '' ?>"
       href="<?= App::e(fq(['important' => $filters['important'] ? null : 1])) ?>">★ Wichtig</a>
    <a class="chip <?= $filters['archived'] ? 'active' : '' ?>"
       href="<?= App::e(fq(['archived' => $filters['archived'] ? null : 1, 'cat' => null])) ?>">Archiv</a>
    <?php foreach ($tags as $t): if ($t['usage_count'] === 0) continue; ?>
      <a class="chip <?= $filters['tag_id'] === (int)$t['id'] ? 'active' : '' ?>"
         href="<?= App::e(fq(['tag' => $filters['tag_id'] === (int)$t['id'] ? null : (int)$t['id']])) ?>">
        <?= App::e($t['name']) ?>
      </a>
    <?php endforeach; ?>
  </div>
</div>

<div class="panel">
  <?php if (!$list): ?>
    <p class="empty">Keine Befunde gefunden.</p>
  <?php else: ?>
    <?php
    $lastYear = null;
    foreach ($list as $f):
        $year = substr($app->local($f['occurred_at'], 'Y'), 0, 4);
        if ($year !== $lastYear): $lastYear = $year; ?>
          <div class="day-head" style="margin-top:14px"><?= App::e($year) ?></div>
        <?php endif; ?>
      <div class="ev">
        <div class="t" style="width:56px"><?= App::e($app->local($f['occurred_at'], 'd.m.')) ?></div>
        <div class="body">
          <div class="title">
            <a href="<?= App::url('/finding.php?id=' . (int)$f['id']) ?>"><?= App::e($f['title']) ?></a>
            <?php if ($f['is_important']): ?><span class="mod">★</span><?php endif; ?>
            <span class="mod"><?= App::e(FindingsRepository::categoryLabel($f['category'])) ?></span>
            <?php if (!empty($attCnt[(int)$f['id']])): ?>
              <span class="mod"><?= (int)$attCnt[(int)$f['id']] ?> Datei(en)</span>
            <?php endif; ?>
          </div>
          <?php $meta = array_filter([$f['institution'], $f['doctor'], $f['summary']]); ?>
          <?php if ($meta): ?>
            <div class="sum"><?= App::e(mb_substr(implode(' · ', $meta), 0, 160)) ?></div>
          <?php endif; ?>
        </div>
      </div>
    <?php endforeach; ?>
  <?php endif; ?>
</div>

<div class="panel">
  <h2>Neuer Befund</h2>
  <form method="post" enctype="multipart/form-data">
    <?= Csrf::field() ?>
    <input type="hidden" name="action" value="create">

    <label for="title">Titel</label>
    <input type="text" id="title" name="title" required maxlength="200"
           placeholder="z. B. MRT Lendenwirbelsäule">

    <div class="field-row">
      <div>
        <label for="category">Art</label>
        <select id="category" name="category">
          <?php foreach (FindingsRepository::CATEGORIES as $k => $l): ?>
            <option value="<?= App::e($k) ?>"><?= App::e($l) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div>
        <label for="occurred_at">Befunddatum</label>
        <input type="datetime-local" id="occurred_at" name="occurred_at"
               value="<?= App::e($nowLocal) ?>" required>
      </div>
    </div>

    <label for="contact_id">Einrichtung / Kontakt</label>
    <select id="contact_id" name="contact_id">
      <option value="">– keine Auswahl –</option>
      <?php foreach ($institutions as $inst): ?>
        <option value="<?= (int)$inst['id'] ?>"><?= App::e($inst['name']) ?></option>
      <?php endforeach; ?>
    </select>
    <label for="contact_new">oder neu anlegen</label>
    <input type="text" id="contact_new" name="contact_new" maxlength="200"
           placeholder="nur ausfüllen, wenn nicht in der Liste oben">
    <p class="hint">
      Kontakte werden zentral verwaltet – eine Umbenennung wirkt sich
      überall aus, wo sie zugeordnet sind. Verwaltung unter
      <a href="<?= App::url('/contacts.php') ?>">Kontakte</a>.
    </p>

    <label for="doctor">Ärztin / Arzt</label>
    <input type="text" id="doctor" name="doctor" maxlength="200">

    <label for="summary">Kurzfassung</label>
    <input type="text" id="summary" name="summary" maxlength="400"
           placeholder="Das Wesentliche in einem Satz">

    <label for="text">Befundtext / Notizen</label>
    <textarea id="text" name="text" rows="6"></textarea>

    <div class="field-row">
      <div>
        <label for="follow_up_at">Wiedervorlage</label>
        <input type="date" id="follow_up_at" name="follow_up_at">
      </div>
      <div>
        <label for="tags">Tags (Komma-getrennt)</label>
        <input type="text" id="tags" name="tags" autocomplete="off">
      </div>
    </div>

    <label for="file">Datei anhängen (optional)</label>
    <input type="file" id="file" name="file"
           accept=".pdf,.jpg,.jpeg,.png,.heic,.tif,.tiff,.txt,.csv,.dcm">

    <label style="display:flex;align-items:center;gap:8px;font-weight:400">
      <input type="checkbox" name="is_important" value="1" style="width:auto">
      Als wichtig kennzeichnen
    </label>

    <button type="submit" class="auto">Anlegen</button>
  </form>
</div>
<?php View::end($app); ?>
