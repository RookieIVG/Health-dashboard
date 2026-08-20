<?php
declare(strict_types=1);
require __DIR__ . '/_init.php';

use Health\App;
use Health\Auth;
use Health\Csrf;
use Health\View;

$app = App::boot();

// Nicht requireLogin(): das verlangt die volle Anmeldung und würde beim
// Abbrechen im 2FA-Schritt zurück auf login_2fa.php leiten – eine
// Schleife, aus der man nur über das Löschen des Cookies herauskommt.
$stage = $app->auth->currentStage();
if ($stage === Auth::STAGE_NONE) {
    header('Location: ' . App::url('/login.php'));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::requireValid();
    $app->auth->logout('user');
    header('Location: ' . App::url('/login.php'));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    header('Allow: GET, POST');
    exit('Methode nicht erlaubt.');
}

View::startBare($app, 'Abmelden – ' . $app->config['app']['name']);
?>
<div class="panel">
  <h1>Abmelden?</h1>
  <p class="sub">Möchtest du die aktuelle Sitzung wirklich beenden?</p>
  <form method="post">
    <?= Csrf::field() ?>
    <button type="submit">Abmelden</button>
  </form>
  <p class="foot" style="margin-top:22px">
    <a href="<?= App::url('/index.php') ?>">Abbrechen</a>
  </p>
</div>
<?php View::end($app); ?>
