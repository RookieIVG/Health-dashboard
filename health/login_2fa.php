<?php
declare(strict_types=1);
require __DIR__ . '/_init.php';

use Health\App;
use Health\Auth;
use Health\Csrf;
use Health\View;

$app   = App::boot();
$auth  = $app->auth;
$stage = $auth->currentStage();

if ($stage === Auth::STAGE_FULL)     { header('Location: ' . App::url('/index.php')); exit; }
if ($stage !== Auth::STAGE_PENDING)  { header('Location: ' . App::url('/login.php'));  exit; }

$error = null;
$useRecovery = isset($_GET['recovery']) || (($_POST['mode'] ?? '') === 'recovery');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::requireValid();
    $result = ($_POST['mode'] ?? '') === 'recovery'
        ? $auth->verifyRecoveryCode((string)($_POST['code'] ?? ''))
        : $auth->verifyTotp((string)($_POST['code'] ?? ''));

    if ($result['ok']) {
        if (isset($result['remaining']) && $result['remaining'] <= 2) {
            $_SESSION['flash'] = "Nur noch {$result['remaining']} Wiederherstellungscodes übrig. "
                               . 'Bitte in den Einstellungen neue erzeugen.';
        }
        if (!empty($_POST['trust_device'])) {
            $auth->trustThisDevice((int)$_SESSION['auth_user_id']);
        }
        header('Location: ' . App::url('/index.php'));
        exit;
    }
    $error = $result['error'];
}

View::startBare($app, 'Bestätigung – ' . $app->config['app']['name']);
?>
<div class="panel">
  <h1>Zwei-Faktor-Bestätigung</h1>
  <p class="sub">
    <?= $useRecovery
          ? 'Gib einen deiner Wiederherstellungscodes ein.'
          : 'Gib den 6-stelligen Code aus deiner Authenticator-App ein.' ?>
  </p>

  <?php View::flash($error, 'error'); ?>

  <form method="post">
    <?= Csrf::field() ?>
    <input type="hidden" name="mode" value="<?= $useRecovery ? 'recovery' : 'totp' ?>">
    <label for="code"><?= $useRecovery ? 'Wiederherstellungscode' : 'Code' ?></label>
    <input type="text" id="code" name="code" class="code" required autofocus
           inputmode="<?= $useRecovery ? 'text' : 'numeric' ?>"
           autocomplete="<?= $useRecovery ? 'off' : 'one-time-code' ?>"
           maxlength="<?= $useRecovery ? 11 : 6 ?>" spellcheck="false">

    <label style="display:flex;align-items:center;gap:8px;font-weight:400;margin-top:12px">
      <input type="checkbox" name="trust_device" value="1" style="width:auto">
      Dieses Gerät <?= (int)($app->config['security']['trusted_device_days'] ?? 30) ?> Tage lang angemeldet lassen
    </label>
    <p class="hint">Nur auf einem Gerät aktivieren, das ausschließlich du benutzt.</p>

    <button type="submit">Bestätigen</button>
  </form>

  <p class="foot" style="margin-top:22px">
    <?php if ($useRecovery): ?>
      <a href="<?= App::url('/login_2fa.php') ?>">Zurück zum App-Code</a>
    <?php else: ?>
      <a href="<?= App::url('/login_2fa.php?recovery=1') ?>">Kein Zugriff auf die App?</a>
    <?php endif; ?>
    &nbsp;·&nbsp; <a href="<?= App::url('/logout.php') ?>">Abbrechen</a>
  </p>
</div>
<?php View::end($app); ?>
