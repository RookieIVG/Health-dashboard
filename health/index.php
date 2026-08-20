<?php
declare(strict_types=1);
require __DIR__ . '/_init.php';

use Health\App;
use Health\View;

$app  = App::boot();
$auth = $app->auth;
$auth->requireLogin();

$user = $auth->user();
$name = $app->crypto->dec($app->dekFor(), $user['display_name_enc'], 'user.name') ?: $user['username'];

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

$force2fa = !empty($app->config['security']['require_2fa']) && (int)$user['totp_enabled'] === 0;
$cards    = $app->modules()->cards();

View::start($app, ['title' => $app->config['app']['name'], 'active' => 'dashboard']);
?>
<?php View::flash($flash, 'warn'); ?>

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
      · <?= App::e(\Health\Audit::ipString($user['last_login_ip'])) ?>
    <?php endif; ?>
  </p>
</div>

<div class="panel">
  <h2>Module</h2>
  <div class="mod-list">
    <?php foreach ($cards as $c): ?>
      <?php if ($c['url']): ?>
        <a class="mod-item" href="<?= App::url($c['url']) ?>">
      <?php else: ?>
        <div class="mod-item planned">
      <?php endif; ?>
        <div class="n"><?= App::e($c['label']) ?></div>
        <?php foreach ($c['summary'] as $line): ?>
          <div class="row">
            <span class="k"><?= App::e($line['l']) ?></span>
            <?php if ($line['v'] !== ''): ?><span class="v"><?= App::e($line['v']) ?></span><?php endif; ?>
          </div>
        <?php endforeach; ?>
      <?php if ($c['url']): ?></a><?php else: ?></div><?php endif; ?>
    <?php endforeach; ?>
  </div>
  <p class="foot" style="text-align:left;margin-top:14px">
    <a href="<?= App::url('/modules.php') ?>">Reihenfolge ändern</a>
  </p>
</div>
<?php View::end($app); ?>
