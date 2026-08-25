<?php
declare(strict_types=1);
require __DIR__ . '/_init.php';

use Health\App;
use Health\Csrf;
use Health\MedicationRepository;
use Health\View;

$app  = App::boot();
$auth = $app->auth;
$auth->requireLogin();

/*
 * "Abzeichnen" wird direkt hier verarbeitet statt über medications.php,
 * damit der Klick auf das Häkchen auf der Übersicht bleibt statt auf
 * die Medikationsseite zu springen. POST-Redirect-GET, damit ein
 * Neuladen der Seite die Einnahme nicht versehentlich noch einmal
 * abschickt.
 */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'take') {
    Csrf::requireValid();
    try {
        $medRepo = new MedicationRepository($app);
        $schedId = (int)($_POST['schedule_id'] ?? 0) ?: null;
        $now = (new DateTimeImmutable('now', new DateTimeZone($app->config['app']['timezone'])));

        if ($schedId !== null && $medRepo->takenOn($schedId, $now)) {
            $_SESSION['flash_ok'] = 'Für heute bereits abgezeichnet.';
        } else {
            $medRepo->logIntake((int)($_POST['medication_id'] ?? 0), $schedId, $now->format('Y-m-d\TH:i'), null);
            $_SESSION['flash_ok'] = 'Abgezeichnet.';
        }
    } catch (\Throwable $e) {
        $_SESSION['flash_err'] = $e->getMessage();
    }
    header('Location: ' . App::url('/index.php'));
    exit;
}

$user = $auth->user();
$profile = $auth->profile((int)$user['id']);
$name = trim(($profile['first_name'] ?? '') . ' ' . ($profile['last_name'] ?? ''))
     ?: ($app->crypto->dec($app->dekFor(), $user['display_name_enc'], 'user.name') ?: $user['username']);

$flash    = $_SESSION['flash'] ?? null;
$flashOk  = $_SESSION['flash_ok'] ?? null;
$flashErr = $_SESSION['flash_err'] ?? null;
unset($_SESSION['flash'], $_SESSION['flash_ok'], $_SESSION['flash_err']);

$force2fa = !empty($app->config['security']['require_2fa']) && (int)$user['totp_enabled'] === 0;

$tlDays = (int)($_GET['tl_days'] ?? 14);
if (!in_array($tlDays, [7, 14, 30, 90], true)) $tlDays = 14;

$cards = $app->modules()->cards($tlDays);

View::start($app, ['title' => $app->config['app']['name'], 'active' => 'dashboard']);
?>
<?php View::flash($flash, 'warn'); ?>
<?php View::flash($flashOk, 'ok'); ?>
<?php View::flash($flashErr, 'error'); ?>

<?php if ($force2fa): ?>
  <div class="msg error">
    Für diesen Zugang ist noch keine Zwei-Faktor-Authentifizierung eingerichtet.
    <a href="<?= App::url('/profile/security.php') ?>">Jetzt einrichten</a>
  </div>
<?php endif; ?>

<div class="panel">
  <h1>Hallo <?= App::e($name) ?></h1>
  <p class="sub" style="margin-bottom:0">
    Letzte Anmeldung: <?= App::e($app->local($user['last_login_at'])) ?>
    <?php if ($user['last_login_ip']): ?>
      · IP: <?= App::e(\Health\Audit::ipString($user['last_login_ip'])) ?>
    <?php endif; ?>
  </p>
</div>

<div class="panel">
  <h2>Module</h2>
  <div class="mod-list">
    <?php foreach ($cards as $c): ?>
      <div class="mod-item<?= $c['url'] ? '' : ' planned' ?><?= $c['key'] === 'timeline' ? ' mod-item--wide' : '' ?>">
        <?php if ($c['url']): ?>
          <a class="n" href="<?= App::url($c['url']) ?>">
            <?php if ($c['color']): ?><?= View::colorDot($c['color']) ?><?php endif; ?>
            <?= App::e($c['label']) ?>
          </a>
        <?php else: ?>
          <div class="n"><?= App::e($c['label']) ?></div>
        <?php endif; ?>

        <?php if (!empty($c['chart'])): ?>
          <div class="filters" style="margin-top:4px">
            <?php foreach ([7 => '7 Tage', 14 => '14 Tage', 30 => '30 Tage', 90 => '90 Tage'] as $dOpt => $dLabel): ?>
              <a class="chip <?= $tlDays === $dOpt ? 'active' : '' ?>"
                 href="<?= App::url('/index.php') ?>?tl_days=<?= $dOpt ?>#timeline"><?= $dLabel ?></a>
            <?php endforeach; ?>
          </div>
          <div class="daybars-wrap" id="timeline" style="margin-top:8px">
            <?= $c['chart']['svg'] ?>
          </div>
          <?php if ($c['chart']['legend']): ?>
            <div class="filters" style="margin-top:8px">
              <?php foreach ($c['chart']['legend'] as $item): ?>
                <span style="display:inline-flex;align-items:center;gap:5px;font-size:.78rem;color:var(--muted)">
                  <span style="width:9px;height:9px;border-radius:2px;background:<?= App::e($item['color']) ?>;display:inline-block"></span>
                  <?= App::e($item['label']) ?>
                </span>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
          <p class="sub" style="margin:6px 0 0"><?= App::e($c['chart']['caption']) ?></p>
        <?php else: ?>
        <?php foreach ($c['summary'] as $line):
              $sev = (int)($line['severity'] ?? 0); ?>
          <div class="row">
            <?php $kStyle = $sev >= 2 ? 'color:var(--danger);font-weight:600' : ''; ?>
            <?php if (!empty($line['url'])): ?>
              <a class="k" style="<?= $kStyle ?>" href="<?= App::url($line['url']) ?>"><?= App::e($line['l']) ?></a>
            <?php else: ?>
              <span class="k" style="<?= $kStyle ?>"><?= App::e($line['l']) ?></span>
            <?php endif; ?>

            <span style="display:inline-flex;align-items:center;gap:6px">
              <?php if ($line['v'] !== ''): ?>
                <span class="v" style="<?= $sev >= 2 ? 'color:var(--danger);font-weight:600' : '' ?>">
                  <?= App::e($line['v']) ?>
                </span>
              <?php endif; ?>

              <?php if (!empty($line['action']) && $line['action']['type'] === 'take'):
                    $canTake = $line['action']['active'] ?? true; ?>
                <?php if ($canTake): ?>
                  <form method="post" action="<?= App::url('/index.php') ?>" style="margin:0">
                    <?= Csrf::field() ?>
                    <input type="hidden" name="action" value="take">
                    <input type="hidden" name="medication_id" value="<?= (int)$line['action']['medication_id'] ?>">
                    <input type="hidden" name="schedule_id" value="<?= (int)$line['action']['schedule_id'] ?>">
                    <button type="submit" class="icon-btn" title="Einnahme abzeichnen" aria-label="Einnahme abzeichnen">✓</button>
                  </form>
                <?php else: ?>
                  <button type="button" class="icon-btn" disabled
                          title="Nicht heute fällig" aria-label="Nicht heute fällig">✓</button>
                <?php endif; ?>
              <?php endif; ?>
            </span>
          </div>
        <?php endforeach; ?>
        <?php endif; ?>
      </div>
    <?php endforeach; ?>
  </div>
  <p class="foot" style="text-align:left;margin-top:14px">
    <a href="<?= App::url('/modules.php') ?>">Reihenfolge ändern</a>
  </p>
</div>
<?php View::end($app); ?>
