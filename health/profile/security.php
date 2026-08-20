<?php
declare(strict_types=1);
require __DIR__ . '/../_init.php';

use Health\App;
use Health\Csrf;
use Health\View;

$app  = App::boot();
$auth = $app->auth;
$auth->requireLogin();

$userId = (int)$auth->user()['id'];
$error = $ok = null;
$setup = $_SESSION['totp_setup'] ?? null;
$codes = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::requireValid();
    $action = (string)($_POST['action'] ?? '');
    try {
        if ($action === 'start') {
            $setup = $auth->beginTotpSetup($userId);
            $_SESSION['totp_setup'] = $setup;

        } elseif ($action === 'confirm') {
            $codes = $auth->confirmTotpSetup($userId, (string)($_POST['code'] ?? ''));
            unset($_SESSION['totp_setup'], $setup);
            $ok = 'Zwei-Faktor-Authentifizierung ist aktiv.';

        } elseif ($action === 'disable') {
            $auth->disableTotp($userId);
            $ok = 'Zwei-Faktor-Authentifizierung wurde deaktiviert.';

        } elseif ($action === 'regen') {
            $codes = $auth->regenerateRecoveryCodes($userId);
            $ok = 'Neue Wiederherstellungscodes erzeugt. Die alten sind ungültig.';

        } elseif ($action === 'password') {
            $auth->changePassword($userId, (string)($_POST['new_password'] ?? ''),
                                  (string)($_POST['current_password'] ?? ''));
            $ok = 'Passwort geändert. Andere Sitzungen wurden abgemeldet.';
        }
    } catch (\Throwable $e) {
        $error = $e->getMessage();
    }
}

$user      = $app->db->one('SELECT * FROM users WHERE id = :id', [':id' => $userId]);
$twoFaOn   = (int)$user['totp_enabled'] === 1;
$remaining = (int)$app->db->value(
    'SELECT COUNT(*) FROM user_recovery_codes WHERE user_id = :u AND used_at IS NULL',
    [':u' => $userId]
);
$sessions = $app->db->all(
    'SELECT * FROM user_sessions WHERE user_id = :u AND revoked_at IS NULL
       AND expires_at > UTC_TIMESTAMP() ORDER BY last_seen_at DESC',
    [':u' => $userId]
);

View::start($app, ['title' => 'Sicherheit – ' . $app->config['app']['name'], 'active' => 'security']);
?>
<?php View::flash($ok, 'ok'); View::flash($error, 'error'); ?>

<?php if ($codes): ?>
<div class="panel">
  <h2>Wiederherstellungscodes</h2>
  <p class="sub">Diese Codes werden <strong>nur jetzt</strong> angezeigt. Jeder Code funktioniert
     einmal. Bitte ausdrucken oder im Passwortmanager ablegen.</p>
  <ul class="codes">
    <?php foreach ($codes as $c): ?><li><?= App::e($c) ?></li><?php endforeach; ?>
  </ul>
</div>
<?php endif; ?>

<div class="panel">
  <h2>Zwei-Faktor-Authentifizierung
    <span class="badge <?= $twoFaOn ? 'on' : 'off' ?>"><?= $twoFaOn ? 'aktiv' : 'inaktiv' ?></span>
  </h2>

  <?php if ($twoFaOn): ?>
    <p class="sub">Verbleibende Wiederherstellungscodes: <?= $remaining ?></p>
    <div class="actions">
      <form method="post">
        <?= Csrf::field() ?>
        <input type="hidden" name="action" value="regen">
        <button type="submit" class="secondary small">Neue Codes erzeugen</button>
      </form>
      <form method="post" data-confirm="2FA wirklich deaktivieren?">
        <?= Csrf::field() ?>
        <input type="hidden" name="action" value="disable">
        <button type="submit" class="secondary small">Deaktivieren</button>
      </form>
    </div>

  <?php elseif ($setup): ?>
    <p class="sub">Secret in der Authenticator-App anlegen und danach den erzeugten Code bestätigen.</p>
    <div class="secret"><?= App::e($setup['formatted']) ?></div>
    <p class="foot" style="text-align:left;margin-top:10px">
      Oder direkt öffnen: <a href="<?= App::e($setup['uri']) ?>">In App hinzufügen</a>
    </p>
    <form method="post">
      <?= Csrf::field() ?>
      <input type="hidden" name="action" value="confirm">
      <label for="code">Code aus der App</label>
      <input type="text" id="code" name="code" class="code" required
             inputmode="numeric" maxlength="6" autocomplete="one-time-code">
      <button type="submit">Aktivieren</button>
    </form>

  <?php else: ?>
    <p class="sub">Schützt den Zugang zusätzlich zum Passwort mit einem Einmalcode.</p>
    <form method="post">
      <?= Csrf::field() ?>
      <input type="hidden" name="action" value="start">
      <button type="submit" class="auto">Einrichten</button>
    </form>
  <?php endif; ?>
</div>

<div class="panel">
  <h2>Passwort ändern</h2>
  <form method="post">
    <?= Csrf::field() ?>
    <input type="hidden" name="action" value="password">
    <label for="cp">Aktuelles Passwort</label>
    <input type="password" id="cp" name="current_password" required autocomplete="current-password">
    <label for="np">Neues Passwort (mind. <?= (int)$app->config['security']['password_min_length'] ?> Zeichen)</label>
    <input type="password" id="np" name="new_password" required autocomplete="new-password">
    <button type="submit" class="auto">Passwort ändern</button>
  </form>
</div>

<div class="panel">
  <h2>Aktive Sitzungen</h2>
  <div class="table-wrap">
    <table class="stack">
      <thead>
        <tr><th>Gerät</th><th>IP</th><th>Angemeldet</th><th>Zuletzt aktiv</th></tr>
      </thead>
      <tbody>
        <?php foreach ($sessions as $s): ?>
        <tr>
          <td data-label="Gerät"><?= App::e(mb_substr((string)$s['user_agent'], 0, 60)) ?></td>
          <td data-label="IP"><?= App::e(\Health\Audit::ipString($s['ip'])) ?></td>
          <td data-label="Angemeldet"><?= App::e($app->local($s['created_at'])) ?></td>
          <td data-label="Zuletzt aktiv"><?= App::e($app->local($s['last_seen_at'])) ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php View::end($app); ?>
