<?php
declare(strict_types=1);
require __DIR__ . '/../_init.php';

use Health\App;
use Health\Csrf;
use Health\View;

$app  = App::boot();
$auth = $app->auth;
$auth->requireAdmin();

$me    = $auth->user();
$error = $ok = null;
$newUserPassword = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::requireValid();
    $action = (string)($_POST['action'] ?? '');
    try {
        if ($action === 'create') {
            $pw = (string)($_POST['password'] ?? '');
            if ($pw === '') {
                $pw = bin2hex(random_bytes(9));
                $newUserPassword = $pw;
            }
            $auth->createUser(
                trim((string)($_POST['username'] ?? '')),
                $pw,
                trim((string)($_POST['email'] ?? '')) ?: null,
                trim((string)($_POST['display_name'] ?? '')) ?: null,
                ($_POST['role'] ?? 'user') === 'admin' ? 'admin' : 'user',
                'active'
            );
            $ok = 'Benutzer angelegt.';

        } elseif ($action === 'status') {
            $id = (int)($_POST['user_id'] ?? 0);
            if ($id === (int)$me['id']) {
                throw new RuntimeException('Der eigene Zugang kann nicht deaktiviert werden.');
            }
            $new = ($_POST['status'] ?? '') === 'active' ? 'active' : 'disabled';
            $app->db->run('UPDATE users SET status = :s WHERE id = :id', [':s' => $new, ':id' => $id]);
            if ($new === 'disabled') {
                $app->db->run('UPDATE user_sessions SET revoked_at = UTC_TIMESTAMP()
                               WHERE user_id = :id AND revoked_at IS NULL', [':id' => $id]);
            }
            $app->audit->log('user.status_changed', $id, (int)$me['id'], 'auth', $id, ['status' => $new]);
            $ok = 'Status geändert.';

        } elseif ($action === 'reset_2fa') {
            $auth->disableTotp((int)($_POST['user_id'] ?? 0));
            $ok = 'Zwei-Faktor zurückgesetzt. Der Benutzer muss es neu einrichten.';
        }
    } catch (\Throwable $e) {
        $error = $e->getMessage();
    }
}

$users = $app->db->all('SELECT * FROM users WHERE deleted_at IS NULL ORDER BY username');

View::start($app, ['title' => 'Benutzer – ' . $app->config['app']['name'], 'active' => 'users']);
?>
<?php View::flash($ok, 'ok'); View::flash($error, 'error'); ?>
<?php if ($newUserPassword): ?>
  <div class="msg warn">Startpasswort (nur jetzt sichtbar):
    <strong><?= App::e($newUserPassword) ?></strong></div>
<?php endif; ?>

<div class="panel">
  <h2>Benutzer</h2>
  <div class="table-wrap">
    <table class="stack">
      <thead>
        <tr><th>Benutzer</th><th>Rolle</th><th>2FA</th><th>Status</th><th>Letzte Anmeldung</th><th></th></tr>
      </thead>
      <tbody>
      <?php foreach ($users as $u): ?>
        <tr>
          <td data-label="Benutzer"><strong><?= App::e($u['username']) ?></strong></td>
          <td data-label="Rolle"><?= App::e($u['role']) ?></td>
          <td data-label="2FA">
            <span class="badge <?= $u['totp_enabled'] ? 'on' : 'off' ?>">
              <?= $u['totp_enabled'] ? 'aktiv' : 'aus' ?></span>
          </td>
          <td data-label="Status"><?= App::e($u['status']) ?></td>
          <td data-label="Letzte Anmeldung"><?= App::e($app->local($u['last_login_at'])) ?></td>
          <td>
            <div class="actions" style="margin:0;justify-content:flex-end">
              <?php if ((int)$u['id'] !== (int)$me['id']): ?>
              <form method="post">
                <?= Csrf::field() ?>
                <input type="hidden" name="action" value="status">
                <input type="hidden" name="user_id" value="<?= (int)$u['id'] ?>">
                <input type="hidden" name="status" value="<?= $u['status'] === 'active' ? 'disabled' : 'active' ?>">
                <button type="submit" class="secondary small">
                  <?= $u['status'] === 'active' ? 'Sperren' : 'Freigeben' ?>
                </button>
              </form>
              <?php endif; ?>
              <?php if ($u['totp_enabled']): ?>
              <form method="post" data-confirm="2FA für <?= App::e($u['username']) ?> zurücksetzen?">
                <?= Csrf::field() ?>
                <input type="hidden" name="action" value="reset_2fa">
                <input type="hidden" name="user_id" value="<?= (int)$u['id'] ?>">
                <button type="submit" class="secondary small">2FA reset</button>
              </form>
              <?php endif; ?>
            </div>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<div class="panel">
  <h2>Neuen Benutzer anlegen</h2>
  <form method="post">
    <?= Csrf::field() ?>
    <input type="hidden" name="action" value="create">
    <label for="username">Benutzername</label>
    <input type="text" id="username" name="username" required autocapitalize="none" spellcheck="false">

    <label for="display_name">Anzeigename</label>
    <input type="text" id="display_name" name="display_name">

    <label for="email">E-Mail (optional)</label>
    <input type="email" id="email" name="email">

    <label for="role">Rolle</label>
    <select id="role" name="role">
      <option value="user">Benutzer</option>
      <option value="admin">Administrator</option>
    </select>

    <label for="password">Passwort (leer = zufällig erzeugen)</label>
    <input type="password" id="password" name="password" autocomplete="new-password">

    <button type="submit" class="auto">Anlegen</button>
  </form>
</div>
<?php View::end($app); ?>
