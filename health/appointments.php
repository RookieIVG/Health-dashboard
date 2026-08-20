<?php
declare(strict_types=1);
require __DIR__ . '/_init.php';

use Health\App;
use Health\AppointmentsRepository as Appt;
use Health\ContactsRepository;
use Health\Csrf;
use Health\Ics;
use Health\View;

$app = App::boot();
$app->auth->requireLogin();

$repo     = new Appt($app);
$contacts = (new ContactsRepository($app))->listAll(true);
$error    = $ok = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::requireValid();
    try {
        switch ($_POST['action'] ?? '') {
            case 'create':
                $id = $repo->store($_POST);
                header('Location: ' . App::url('/appointment.php?id=' . $id));
                exit;
            case 'new_token':
                Ics::token($app, null, true);
                $ok = 'Neue Kalenderadresse erzeugt. Das alte Abo funktioniert nicht mehr.';
                break;
        }
    } catch (\Throwable $e) {
        $error = $e->getMessage();
    }
}

$upcoming = $repo->upcoming();
$past     = $repo->past(30);

$tz    = new DateTimeZone($app->config['app']['timezone']);
$now   = new DateTimeImmutable('now', $tz);
$start = $now->modify('+1 day')->setTime(9, 0)->format('Y-m-d\TH:i');

$icsUrl = rtrim($app->config['app']['base_url'], '/')
        . App::url('/calendar.php?t=' . Ics::token($app));

View::start($app, ['title' => 'Termine – ' . $app->config['app']['name'], 'active' => 'appointments']);
?>
<?php View::flash($ok, 'ok'); View::flash($error, 'error'); ?>

<div class="panel">
  <h1>Termine</h1>
  <p class="sub">
    <?= count($upcoming) ?> anstehend
    · <a href="<?= App::url('/contacts.php') ?>">Ärzte und Kontakte</a>
  </p>

  <?php if (!$upcoming): ?>
    <p class="empty">Kein Termin geplant.</p>
  <?php else: ?>
    <?php foreach ($upcoming as $a):
          $ts = strtotime($a['starts_at'] . ' UTC');
          $inDays = (int)floor(($ts - time()) / 86400); ?>
      <div class="ev">
        <div class="t" style="width:74px">
          <?= App::e($app->local($a['starts_at'], 'd.m.')) ?><br>
          <span style="font-size:.9em"><?= App::e($app->local($a['starts_at'], 'H:i')) ?></span>
        </div>
        <div class="body">
          <div class="title">
            <a href="<?= App::url('/appointment.php?id=' . (int)$a['id']) ?>"><?= App::e($a['title']) ?></a>
            <?php if ($inDays <= 2): ?><span class="mod">bald</span><?php endif; ?>
          </div>
          <div class="sum">
            <?php
            $bits = array_filter([
                $a['contact']['name'] ?? null,
                $a['location'] ?? null,
                $a['prep'] ? 'Vorbereitung notiert' : null,
            ]);
            echo App::e(implode(' · ', $bits));
            ?>
          </div>
        </div>
      </div>
    <?php endforeach; ?>
  <?php endif; ?>
</div>

<div class="panel">
  <h2>Neuer Termin</h2>
  <form method="post">
    <?= Csrf::field() ?>
    <input type="hidden" name="action" value="create">

    <label for="title">Titel</label>
    <input type="text" id="title" name="title" required maxlength="200"
           placeholder="z. B. Kontrolle Kardiologie">

    <div class="field-row">
      <div>
        <label for="starts_at">Beginn</label>
        <input type="datetime-local" id="starts_at" name="starts_at"
               value="<?= App::e($start) ?>" required>
      </div>
      <div>
        <label for="ends_at">Ende (optional)</label>
        <input type="datetime-local" id="ends_at" name="ends_at">
      </div>
    </div>

    <div class="field-row">
      <div>
        <label for="contact_id">Kontakt</label>
        <select id="contact_id" name="contact_id">
          <option value="">—</option>
          <?php foreach ($contacts as $c): ?>
            <option value="<?= (int)$c['id'] ?>"><?= App::e($c['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div>
        <label for="reminder_min">Erinnerung</label>
        <select id="reminder_min" name="reminder_min">
          <?php foreach (Appt::REMINDERS as $k => $l): ?>
            <option value="<?= App::e((string)$k) ?>" <?= $k === '1440' ? 'selected' : '' ?>>
              <?= App::e($l) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
    </div>

    <label for="location">Ort</label>
    <input type="text" id="location" name="location" maxlength="200">

    <label for="purpose">Anlass</label>
    <input type="text" id="purpose" name="purpose" maxlength="300">

    <label for="prep">Vorbereitung, Fragen</label>
    <textarea id="prep" name="prep" rows="4"></textarea>

    <button type="submit" class="auto">Anlegen</button>
  </form>
</div>

<div class="panel">
  <h2>Kalender am Telefon</h2>
  <p class="sub">
    Diese Adresse in der Kalender-App als Abo hinzufügen
    (iPhone: Einstellungen → Apps → Kalender → Accounts → Account hinzufügen
    → Andere → Kalenderabo).
  </p>
  <div class="secret"><?= App::e($icsUrl) ?></div>
  <p class="hint">
    Das Abo ist einseitig: Änderungen hier landen im Telefon, umgekehrt nicht.
    <strong>Wer die Adresse kennt, sieht deine Termine im Klartext</strong> –
    ohne Anmeldung, denn Kalender-Apps können sich nicht anmelden. Nicht
    weitergeben; bei Verdacht neu erzeugen.
  </p>
  <div class="actions">
    <form method="post" data-confirm="Neue Adresse erzeugen? Bestehende Abos hören auf zu aktualisieren.">
      <?= Csrf::field() ?>
      <input type="hidden" name="action" value="new_token">
      <button type="submit" class="secondary small">Adresse neu erzeugen</button>
    </form>
  </div>
</div>

<?php if ($past): ?>
<div class="panel">
  <h2>Vergangene Termine</h2>
  <?php foreach ($past as $a): ?>
    <div class="ev">
      <div class="t" style="width:74px"><?= App::e($app->local($a['starts_at'], 'd.m.y')) ?></div>
      <div class="body">
        <div class="title">
          <a href="<?= App::url('/appointment.php?id=' . (int)$a['id']) ?>"><?= App::e($a['title']) ?></a>
          <span class="mod"><?= App::e(Appt::STATUS[$a['status']]) ?></span>
        </div>
        <?php if (!empty($a['result'])): ?>
          <div class="sum"><?= App::e(mb_substr((string)$a['result'], 0, 140)) ?></div>
        <?php endif; ?>
      </div>
    </div>
  <?php endforeach; ?>
</div>
<?php endif; ?>
<?php View::end($app); ?>
