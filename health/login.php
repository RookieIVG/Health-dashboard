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
  <img class="logo" src="<?= App::url('/assets/brand/logo.svg') ?>"
       alt="<?= App::e($app->config['app']['name']) ?>" width="430" height="100">
  <p class="sub" style="margin-top:14px">Bitte melde dich an.</p>

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
