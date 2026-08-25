<?php
declare(strict_types=1);
require __DIR__ . '/_init.php';

use Health\App;
use Health\Auth;
use Health\Csrf;
use Health\View;

$app  = App::boot();
$auth = $app->auth;

if ($auth->currentStage() === Auth::STAGE_FULL) {
    header('Location: ' . App::url('/index.php'));
    exit;
}

$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::requireValid();
    $result = $auth->attemptLogin((string)($_POST['username'] ?? ''), (string)($_POST['password'] ?? ''));

    if ($result['ok']) {
        header('Location: ' . App::url($result['stage'] === Auth::STAGE_PENDING ? '/login_2fa.php' : '/index.php'));
        exit;
    }
    $error = $result['error'];
}

View::startBare($app, 'Anmeldung – ' . $app->config['app']['name']);
?>
<div class="panel">
  <div style="text-align:center;margin-bottom:4px">
    <img src="<?= App::url('/assets/brand/mark.svg') ?>" alt="" width="72" height="72" style="display:block;margin:0 auto">
    <div style="font-weight:700;font-size:1.3rem;letter-spacing:-.01em;color:var(--ink);margin-top:8px">
      <?= App::e($app->config['app']['name']) ?>
    </div>
    <div style="color:var(--muted);font-size:.88rem;margin-top:2px">persönliche Gesundheitsakte</div>
  </div>
  <p class="sub" style="margin-top:14px;text-align:center">Bitte melde dich an.</p>

  <?php View::flash($error, 'error'); ?>

  <form method="post" autocomplete="on">
    <?= Csrf::field() ?>
    <label for="username">Benutzername</label>
    <input type="text" id="username" name="username" required autofocus
           autocomplete="username" autocapitalize="none" spellcheck="false"
           value="<?= App::e($_POST['username'] ?? '') ?>">

    <label for="password">Passwort</label>
    <input type="password" id="password" name="password" required autocomplete="current-password">

    <button type="submit">Anmelden</button>
  </form>
</div>
<p class="foot">Verbindung verschlüsselt · Daten ruhend verschlüsselt</p>
<?php View::end($app); ?>
