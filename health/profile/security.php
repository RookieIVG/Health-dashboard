<?php
declare(strict_types=1);
require __DIR__ . '/../_init.php';

use Health\App;
use Health\Csrf;
use Health\View;
use Health\WebPush;

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
        if ($action === 'update_profile') {
            $auth->updateProfile(
                $userId,
                (string)($_POST['first_name'] ?? ''),
                (string)($_POST['last_name'] ?? ''),
                (string)($_POST['birthdate'] ?? ''),
                (string)($_POST['sex'] ?? 'unknown'),
                (string)($_POST['email'] ?? '')
            );
            $ok = 'Persönliche Daten gespeichert.';

        } elseif ($action === 'update_notify') {
            $app->db->run(
                'UPDATE users SET notify_appt_mail = :m, notify_appt_push = :p,
                    med_reminder_offset1 = :o1, med_reminder_offset2 = :o2 WHERE id = :u',
                [
                    ':m' => !empty($_POST['notify_appt_mail']) ? 1 : 0,
                    ':p' => !empty($_POST['notify_appt_push']) ? 1 : 0,
                    ':o1' => ($_POST['med_reminder_offset1'] ?? '') !== '' ? max(1, min(1440, (int)$_POST['med_reminder_offset1'])) : null,
                    ':o2' => ($_POST['med_reminder_offset2'] ?? '') !== '' ? max(1, min(1440, (int)$_POST['med_reminder_offset2'])) : null,
                    ':u' => $userId,
                ]
            );
            $ok = 'Erinnerungseinstellungen gespeichert.';

        } elseif ($action === 'revoke_device') {
            $found = $auth->revokeTrustedDevice($userId, (int)($_POST['device_id'] ?? 0));
            $ok = $found ? 'Gerät entfernt – dort ist beim nächsten Login wieder 2FA nötig.'
                         : 'Gerät nicht gefunden – vermutlich bereits entfernt.';

        } elseif ($action === 'revoke_session') {
            $sid = (int)($_POST['session_id'] ?? 0);
            $wasCurrent = $auth->isCurrentSession($sid);
            $found = $auth->revokeSession($userId, $sid);

            if ($wasCurrent && $found) {
                $auth->logout('user');
                header('Location: ' . App::url('/login.php'));
                exit;
            }
            $ok = $found ? 'Sitzung abgemeldet.' : 'Sitzung nicht gefunden – vermutlich bereits beendet.';

        } elseif ($action === 'push_remove') {
            $app->db->run(
                'DELETE FROM push_subscriptions WHERE id = :id AND user_id = :u',
                [':id' => (int)($_POST['subscription_id'] ?? 0), ':u' => $userId]
            );
            $ok = 'Gerät entfernt.';

        } elseif ($action === 'start') {
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
$profile   = $auth->profile($userId);
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
$trustedDevices = $auth->listTrustedDevices($userId);

$pushDevices = $app->db->all(
    'SELECT * FROM push_subscriptions WHERE user_id = :u ORDER BY created_at DESC', [':u' => $userId]
);
$vapidPublicKey = null;
try {
    $vapidSubject = 'mailto:' . ($app->config['app']['vapid_subject'] ?? 'noreply@example.com');
    $vapidPublicKey = (new WebPush($app->keyDir(), $vapidSubject))->publicKeyBase64Url();
} catch (\Throwable $e) {
    error_log('[health] VAPID-Schlüssel: ' . $e->getMessage());
}

View::start($app, ['title' => 'Konto – ' . $app->config['app']['name'], 'active' => 'security']);
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
  <h1>Konto</h1>
  <p class="sub">Persönliche Daten, Anmeldesicherheit und Sitzungen.</p>
</div>

<div class="panel">
  <h2>Persönliche Daten</h2>
  <form method="post">
    <?= Csrf::field() ?>
    <input type="hidden" name="action" value="update_profile">

    <div class="field-row">
      <div>
        <label for="first_name">Vorname</label>
        <input type="text" id="first_name" name="first_name" maxlength="100"
               value="<?= App::e($profile['first_name'] ?? '') ?>">
      </div>
      <div>
        <label for="last_name">Nachname</label>
        <input type="text" id="last_name" name="last_name" maxlength="100"
               value="<?= App::e($profile['last_name'] ?? '') ?>">
      </div>
    </div>

    <div class="field-row">
      <div>
        <label for="birthdate">Geburtsdatum</label>
        <input type="date" id="birthdate" name="birthdate"
               value="<?= App::e($profile['birthdate'] ?? '') ?>" max="<?= App::e(date('Y-m-d')) ?>">
      </div>
      <div>
        <label for="sex">Geschlecht</label>
        <select id="sex" name="sex">
          <?php foreach (\Health\Auth::SEX_LABELS as $k => $l): ?>
            <option value="<?= App::e($k) ?>" <?= ($profile['sex'] ?? 'unknown') === $k ? 'selected' : '' ?>>
              <?= App::e($l) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
    </div>
    <p class="hint">
    Alle Felder sind optional. Das Geburtsdatum wird derzeit für die
    altersabhängige FSME-Auffrischung im Impfpass verwendet – ohne
    Angabe gilt dort vorsorglich das kürzere Intervall unter 60 Jahren.
    </p>

    <label for="email">E-Mail-Adresse</label>
    <input type="email" id="email" name="email" maxlength="200"
           value="<?= App::e($profile['email'] ?? '') ?>" autocomplete="email">
    <p class="hint">
      Wird für Termin-Erinnerungen per E-Mail verwendet. E-Mail-Versand ist
      unverschlüsselt – anders als die Daten in dieser App liegt der
      Inhalt der Erinnerung nicht in deiner Kontrolle, sobald sie den
      Server verlässt. Deshalb enthält die Erinnerung nur Titel, Zeitpunkt
      und Ort, keine Notizen oder Diagnosebezüge.
    </p>

    <button type="submit" class="auto">Speichern</button>
  </form>
</div>

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
  <h2>Erinnerungen</h2>

  <form method="post">
    <?= Csrf::field() ?>
    <input type="hidden" name="action" value="update_notify">

    <label>Termin-Erinnerung per</label>
    <label style="display:flex;align-items:center;gap:8px;font-weight:400">
      <input type="checkbox" name="notify_appt_mail" value="1" style="width:auto"
             <?= !empty($user['notify_appt_mail']) ? 'checked' : '' ?>> E-Mail
    </label>
    <label style="display:flex;align-items:center;gap:8px;font-weight:400">
      <input type="checkbox" name="notify_appt_push" value="1" style="width:auto"
             <?= !empty($user['notify_appt_push']) ? 'checked' : '' ?>> Push
    </label>

    <label for="med_reminder_offset1" style="margin-top:16px">Erinnerungsfenster (Minuten nach der Dosis)</label>
    <input type="number" id="med_reminder_offset1" name="med_reminder_offset1" min="1" max="1440"
           value="<?= App::e((string)($user['med_reminder_offset1'] ?? '')) ?>" placeholder="leer = deaktiviert">

    <label for="med_reminder_offset2">Erneute Erinnerung (Minuten nach der Dosis)</label>
    <input type="number" id="med_reminder_offset2" name="med_reminder_offset2" min="1" max="1440"
           value="<?= App::e((string)($user['med_reminder_offset2'] ?? '')) ?>" placeholder="leer = deaktiviert">
    <p class="hint">
      Eine Erinnerung zum Einnahmezeitpunkt selbst kommt immer, dafür ist
      keine Einstellung nötig. Die weiteren Erinnerungen sind optional –
      Feld leer lassen, um sie abzuschalten. Sie kommen nur, solange die
      Einnahme noch nicht abgezeichnet ist.
    </p>

    <button type="submit" class="auto">Speichern</button>
  </form>
</div>

<div class="panel">
  <h2>Push-Benachrichtigungen</h2>
  <p class="sub">Erinnerung an fällige Medikamente direkt am Gerät, unabhängig davon, ob die App gerade offen ist.</p>

  <?php if (!$vapidPublicKey): ?>
    <p class="sub">Push ist auf diesem Server derzeit nicht verfügbar.</p>
  <?php else: ?>
    <div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:8px">
      <button type="button" id="push-enable" class="secondary"
              data-sw-url="<?= App::e(App::url('/sw.php')) ?>"
              data-vapid-key="<?= App::e($vapidPublicKey) ?>"
              data-subscribe-url="<?= App::e(App::url('/push_subscribe.php')) ?>"
              data-csrf="<?= App::e(Csrf::token()) ?>">
        Aktivieren
      </button>
      <?php if ($pushDevices): ?>
        <button type="button" id="push-test" class="secondary"
                data-test-url="<?= App::e(App::url('/push_test.php')) ?>"
                data-csrf="<?= App::e(Csrf::token()) ?>">
          Test senden
        </button>
      <?php endif; ?>
    </div>
    <p id="push-ios-hint" hidden class="hint">
    Auf dem iPhone erst zum Home-Bildschirm hinzufügen (Teilen-Symbol in
    Safari → „Zum Home-Bildschirm") und die App von dort aus öffnen –
    erst dann bietet iOS Benachrichtigungen für diese Seite an.
    </p>
    <p id="push-status" class="hint"></p>

    <?php if ($pushDevices): ?>
      <div class="table-wrap" style="margin-top:12px">
        <table class="stack">
          <thead><tr><th>Gerät</th><th>Angemeldet</th><th></th></tr></thead>
          <tbody>
          <?php foreach ($pushDevices as $d): ?>
            <tr>
              <td data-label="Gerät"><?= App::e(mb_substr((string)($d['device_label'] ?? 'Gerät'), 0, 60)) ?></td>
              <td data-label="Angemeldet"><?= App::e($app->local($d['created_at'])) ?></td>
              <td>
                <form method="post" data-confirm="Dieses Gerät entfernen?" style="margin:0">
                  <?= Csrf::field() ?>
                  <input type="hidden" name="action" value="push_remove">
                  <input type="hidden" name="subscription_id" value="<?= (int)$d['id'] ?>">
                  <button type="submit" class="secondary small">Entfernen</button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  <?php endif; ?>
</div>

<div class="panel">
  <h2>Vertraute Geräte</h2>
  <p class="sub">
    Geräte, auf denen "angemeldet bleiben" beim Login angehakt wurde –
    dort wird für die angegebene Dauer keine 2FA mehr abgefragt.
  </p>
  <?php if (!$trustedDevices): ?>
    <p class="empty">Kein vertrautes Gerät hinterlegt.</p>
  <?php else: ?>
    <div class="table-wrap">
      <table class="stack">
        <thead><tr><th>Gerät</th><th>Angelegt</th><th>Zuletzt verwendet</th><th>Läuft ab</th><th></th></tr></thead>
        <tbody>
          <?php foreach ($trustedDevices as $d): ?>
          <tr>
            <td data-label="Gerät"><?= App::e((string)($d['label'] ?? 'Unbekanntes Gerät')) ?></td>
            <td data-label="Angelegt"><?= App::e($app->local($d['created_at'])) ?></td>
            <td data-label="Zuletzt verwendet">
              <?= $d['last_used_at'] ? App::e($app->local($d['last_used_at'])) : '–' ?>
            </td>
            <td data-label="Läuft ab"><?= App::e($app->local($d['expires_at'])) ?></td>
            <td>
              <form method="post" data-confirm="Dieses Gerät entfernen? Dort ist beim nächsten Login wieder 2FA nötig." style="margin:0">
                <?= Csrf::field() ?>
                <input type="hidden" name="action" value="revoke_device">
                <input type="hidden" name="device_id" value="<?= (int)$d['id'] ?>">
                <button type="submit" class="secondary small">Entfernen</button>
              </form>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>

<div class="panel">
  <h2>Aktive Sitzungen</h2>
  <div class="table-wrap">
    <table class="stack">
      <thead>
        <tr><th>Gerät</th><th>IP</th><th>Angemeldet</th><th>Zuletzt aktiv</th><th></th></tr>
      </thead>
      <tbody>
        <?php foreach ($sessions as $s):
              $isCurrent = $auth->isCurrentSession((int)$s['id']); ?>
        <tr>
          <td data-label="Gerät">
            <?= App::e(mb_substr((string)$s['user_agent'], 0, 60)) ?>
            <?php if ($isCurrent): ?><span class="badge on">diese Sitzung</span><?php endif; ?>
          </td>
          <td data-label="IP"><?= App::e(\Health\Audit::ipString($s['ip'])) ?></td>
          <td data-label="Angemeldet"><?= App::e($app->local($s['created_at'])) ?></td>
          <td data-label="Zuletzt aktiv"><?= App::e($app->local($s['last_seen_at'])) ?></td>
          <td>
            <form method="post" style="margin:0"
                  <?= $isCurrent
                      ? 'data-confirm="Das meldet dich hier sofort ab. Fortfahren?"'
                      : 'data-confirm="Dieses Gerät abmelden?"' ?>>
              <?= Csrf::field() ?>
              <input type="hidden" name="action" value="revoke_session">
              <input type="hidden" name="session_id" value="<?= (int)$s['id'] ?>">
              <button type="submit" class="secondary small">Abmelden</button>
            </form>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php View::end($app); ?>
