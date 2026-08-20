<?php
declare(strict_types=1);
require __DIR__ . '/_init.php';

use Health\App;
use Health\AttachmentService;
use Health\Csrf;
use Health\MedicationRepository as Med;
use Health\Modules;
use Health\View;

$app = App::boot();
$app->auth->requireLogin();

$repo = new Med($app);
$id   = (int)($_GET['id'] ?? 0);
$error = $ok = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::requireValid();
    $id = (int)($_POST['id'] ?? $id);
    try {
        switch ($_POST['action'] ?? '') {
            case 'save':
                $repo->store($_POST, $id);
                $ok = 'Gespeichert.';
                break;
            case 'add_schedule':
                $weekdays = implode('', array_map('strval', array_map('intval', (array)($_POST['weekdays'] ?? []))));
                $repo->addScheduleRow(
                    $id,
                    (string)($_POST['period'] ?? ''),
                    (string)($_POST['dose'] ?? ''),
                    (string)($_POST['cycle_type'] ?? 'weekly'),
                    $weekdays,
                    isset($_POST['interval_days']) && $_POST['interval_days'] !== '' ? (int)$_POST['interval_days'] : null,
                    (string)($_POST['anchor_date'] ?? '') ?: null,
                    isset($_POST['dose_qty']) && $_POST['dose_qty'] !== '' ? (float)str_replace(',', '.', (string)$_POST['dose_qty']) : null
                );
                $ok = 'Zum Plan hinzugefügt.';
                break;
            case 'delete_schedule':
                $repo->deleteScheduleRow((int)($_POST['row_id'] ?? 0));
                $ok = 'Aus dem Plan entfernt.';
                break;
            case 'take':
                $now = (new DateTimeImmutable('now', new DateTimeZone($app->config['app']['timezone'])))->format('Y-m-d\TH:i');
                $repo->logIntake($id, (int)($_POST['schedule_id'] ?? 0) ?: null, $now, null);
                $ok = 'Abgezeichnet.';
                break;
            case 'log_intake':
                $repo->logIntake(
                    $id,
                    (int)($_POST['schedule_id'] ?? 0) ?: null,
                    (string)($_POST['taken_at'] ?? ''),
                    isset($_POST['quantity']) && $_POST['quantity'] !== '' ? (float)str_replace(',', '.', (string)$_POST['quantity']) : null,
                    trim((string)($_POST['dose_text'] ?? '')) ?: null,
                    trim((string)($_POST['note'] ?? '')) ?: null
                );
                $ok = 'Einnahme erfasst.';
                break;
            case 'delete_intake':
                $repo->deleteIntake((int)($_POST['intake_id'] ?? 0));
                $ok = 'Eintrag gelöscht, Bestand zurückgebucht.';
                break;
            case 'restock':
                $repo->addRestock(
                    $id,
                    (float)str_replace(',', '.', (string)($_POST['restock_qty'] ?? '0')),
                    (string)($_POST['restock_date'] ?? ''),
                    trim((string)($_POST['restock_note'] ?? '')) ?: null
                );
                $ok = 'Packung erfasst.';
                break;
            case 'delete_restock':
                $repo->deleteRestock((int)($_POST['restock_id'] ?? 0));
                $ok = 'Zukauf entfernt, Bestand angepasst.';
                break;
            case 'upload':
                if (empty($_FILES['file']['name'])) throw new RuntimeException('Keine Datei ausgewählt.');
                $app->attachments()->storeUpload($_FILES['file'], Modules::MEDICATION, $id);
                $ok = 'Datei angehängt.';
                break;
            case 'delete_file':
                $app->attachments()->deleteOne((int)($_POST['att'] ?? 0));
                $ok = 'Datei gelöscht.';
                break;
            case 'delete':
                $repo->delete($id);
                header('Location: ' . App::url('/medications.php'));
                exit;
        }
    } catch (\Throwable $e) {
        $error = $e->getMessage();
    }
}

$m = $repo->detail($id);
if (!$m) { header('Location: ' . App::url('/medications.php')); exit; }

$intakes  = $repo->intakesFor($id, 50);
$restocks = $repo->restocksFor($id, 30);
$tz       = new DateTimeZone($app->config['app']['timezone']);
$todayLocal = new DateTimeImmutable('now', $tz);

View::start($app, ['title' => $m['name'] . ' – Medikation', 'active' => 'medication']);
?>
<?php View::flash($ok, 'ok'); View::flash($error, 'error'); ?>

<div class="panel">
  <h1><?= App::e($m['name']) ?><?= $m['strength'] ? ' ' . App::e($m['strength']) : '' ?></h1>
  <p class="sub">
    <?= App::e(Med::FORMS[$m['form']]) ?> · <?= App::e(Med::STATUS[$m['status']]) ?>
    <?= $m['is_prn'] ? ' · bei Bedarf' : '' ?>
    · seit <?= App::e(date('d.m.Y', strtotime($m['start_date']))) ?>
    <?php if ($m['end_date']): ?> bis <?= App::e(date('d.m.Y', strtotime($m['end_date']))) ?><?php endif; ?>
    · <a href="<?= App::url('/medications.php') ?>">zur Liste</a>
  </p>
  <?php if ($m['purpose']): ?><p><?= App::e($m['purpose']) ?></p><?php endif; ?>
  <?php if ($m['note']): ?><div style="white-space:pre-wrap;color:var(--muted)"><?= App::e($m['note']) ?></div><?php endif; ?>

  <?php if ($m['stock_unit'] !== null): ?>
    <?php
      $low = $m['stock_quantity'] !== null && $m['stock_warn_at'] !== null
             && (float)$m['stock_quantity'] <= (float)$m['stock_warn_at'];
      $qtyFmt = $m['stock_quantity'] !== null
              ? rtrim(rtrim(number_format((float)$m['stock_quantity'], 2, ',', '.'), '0'), ',') : '–';
    ?>
    <p class="sub" style="margin-top:10px;<?= $low ? 'color:var(--danger);font-weight:600' : '' ?>">
      Bestand: <?= App::e($qtyFmt) ?> <?= App::e($m['stock_unit']) ?>
      <?= $low ? ' – bald nachbestellen' : '' ?>
    </p>
  <?php endif; ?>
</div>

<?php if (!$m['is_prn']): ?>
<div class="panel">
  <h2>Einnahmeplan</h2>
  <?php if (!$m['schedule']): ?>
    <p class="sub">Noch kein Plan hinterlegt.</p>
  <?php else: ?>
    <div class="table-wrap">
      <table class="stack">
        <thead><tr><th>Tageszeit</th><th>Dosis</th><th>Zyklus</th><th>Heute</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($m['schedule'] as $s):
              $dueToday   = \Health\MedicationRepository::matchesDate($s, $todayLocal);
              $takenToday = $dueToday && $repo->takenOn((int)$s['id'], $todayLocal); ?>
          <tr>
            <td data-label="Tageszeit"><?= App::e(Med::PERIODS[$s['period']]) ?></td>
            <td data-label="Dosis"><?= App::e($s['dose']) ?></td>
            <td data-label="Zyklus"><?= App::e(Med::cycleLabel($s)) ?></td>
            <td data-label="Heute">
              <?php if (!$dueToday): ?>
                <span style="color:var(--muted)">–</span>
              <?php elseif ($takenToday): ?>
                <span class="badge on">genommen</span>
              <?php else: ?>
                <form method="post" style="margin:0">
                  <?= Csrf::field() ?>
                  <input type="hidden" name="action" value="take">
                  <input type="hidden" name="id" value="<?= $id ?>">
                  <input type="hidden" name="schedule_id" value="<?= (int)$s['id'] ?>">
                  <button type="submit" class="secondary small">Abzeichnen</button>
                </form>
              <?php endif; ?>
            </td>
            <td>
              <form method="post" data-confirm="Diesen Eintrag aus dem Plan entfernen?" style="margin:0">
                <?= Csrf::field() ?>
                <input type="hidden" name="action" value="delete_schedule">
                <input type="hidden" name="id" value="<?= $id ?>">
                <input type="hidden" name="row_id" value="<?= (int)$s['id'] ?>">
                <button type="submit" class="secondary small">Entfernen</button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>

  <form method="post" style="margin-top:16px">
    <?= Csrf::field() ?>
    <input type="hidden" name="action" value="add_schedule">
    <input type="hidden" name="id" value="<?= $id ?>">
    <div class="field-row">
      <div>
        <label for="period">Tageszeit</label>
        <select id="period" name="period">
          <?php foreach (Med::PERIODS as $k => $l): ?>
            <option value="<?= App::e($k) ?>"><?= App::e($l) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div>
        <label for="dose">Dosis</label>
        <input type="text" id="dose" name="dose" required maxlength="60" placeholder="z. B. 1 Tablette">
      </div>
      <div>
        <label for="dose_qty">Menge (für Bestand)</label>
        <input type="text" id="dose_qty" name="dose_qty" inputmode="decimal" placeholder="z. B. 1">
      </div>
      <div>
        <label for="cycle_type">Zyklus</label>
        <select id="cycle_type" name="cycle_type">
          <?php foreach (Med::CYCLES as $k => $l): ?>
            <option value="<?= App::e($k) ?>" <?= $k === 'weekly' ? 'selected' : '' ?>><?= App::e($l) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
    </div>

    <div id="cycle-weekly-wrap">
      <label>Wochentage</label>
      <div class="filters" style="flex-wrap:wrap">
        <?php foreach (Med::WEEKDAYS as $n => $l): ?>
          <label class="chip" style="cursor:pointer">
            <input type="checkbox" name="weekdays[]" value="<?= $n ?>" checked style="width:auto;margin-right:5px">
            <?= App::e($l) ?>
          </label>
        <?php endforeach; ?>
      </div>
    </div>

    <div id="cycle-interval-wrap" hidden>
      <div class="field-row">
        <div>
          <label for="interval_days">Abstand in Tagen</label>
          <input type="number" id="interval_days" name="interval_days" min="1" max="365" placeholder="z. B. 14">
        </div>
        <div>
          <label for="anchor_date">Bezugsdatum</label>
          <input type="date" id="anchor_date" name="anchor_date" value="<?= App::e(date('Y-m-d')) ?>">
        </div>
      </div>
      <p class="hint">Der Zyklus zählt von diesem Datum an – bei 14 Tagen also alle zwei Wochen ab hier, unabhängig vom Wochentag.</p>
    </div>
    <p class="hint">
      Die Menge wird nur für die Bestandsrechnung gebraucht (z. B. "1" bei
      "1 Tablette"). Leer lassen, wenn du keinen Bestand führst.
    </p>

    <button type="submit" class="auto secondary">Zum Plan hinzufügen</button>
  </form>
</div>
<?php endif; ?>

<div class="panel">
  <h2>Einnahme protokollieren</h2>
  <p class="sub">Für "bei Bedarf" oder um eine Einnahme nachzutragen.</p>
  <form method="post">
    <?= Csrf::field() ?>
    <input type="hidden" name="action" value="log_intake">
    <input type="hidden" name="id" value="<?= $id ?>">

    <div class="field-row">
      <div>
        <label for="taken_at">Zeitpunkt</label>
        <input type="datetime-local" id="taken_at" name="taken_at" required
               value="<?= App::e($todayLocal->format('Y-m-d\TH:i')) ?>">
      </div>
      <?php if ($m['schedule']): ?>
      <div>
        <label for="schedule_id">Plan-Zuordnung (optional)</label>
        <select id="schedule_id" name="schedule_id">
          <option value="">ohne Zuordnung</option>
          <?php foreach ($m['schedule'] as $s): ?>
            <option value="<?= (int)$s['id'] ?>">
              <?= App::e(Med::PERIODS[$s['period']]) ?> · <?= App::e($s['dose']) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <?php endif; ?>
    </div>

    <div class="field-row">
      <div>
        <label for="dose_text">Dosis (Anzeigetext)</label>
        <input type="text" id="dose_text" name="dose_text" maxlength="60" placeholder="z. B. 1 Tablette">
      </div>
      <?php if ($m['stock_unit'] !== null): ?>
      <div>
        <label for="quantity">Menge (<?= App::e($m['stock_unit']) ?>)</label>
        <input type="text" id="quantity" name="quantity" inputmode="decimal">
      </div>
      <?php endif; ?>
    </div>

    <label for="note">Notiz</label>
    <input type="text" id="note" name="note" maxlength="255">

    <button type="submit" class="auto secondary">Erfassen</button>
    <p class="hint">
      Bei Zuordnung zu einem Plan-Eintrag werden Dosis und Menge automatisch
      übernommen, falls hier nichts eingetragen wird.
    </p>
  </form>
</div>

<div class="panel">
  <h2>Einnahmeverlauf</h2>
  <?php if (!$intakes): ?>
    <p class="empty">Noch keine Einnahme protokolliert.</p>
  <?php else: ?>
    <div class="table-wrap">
      <table class="stack">
        <thead><tr><th>Zeitpunkt</th><th>Dosis</th><th>Menge</th><th>Notiz</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($intakes as $iv): ?>
          <tr>
            <td data-label="Zeitpunkt"><?= App::e($app->local($iv['taken_at'], 'd.m.Y H:i')) ?></td>
            <td data-label="Dosis"><?= App::e($iv['dose'] ?? '') ?></td>
            <td data-label="Menge">
              <?= $iv['quantity'] !== null
                    ? App::e(rtrim(rtrim(number_format((float)$iv['quantity'],2,',','.'),'0'),',') . ' ' . $m['stock_unit'])
                    : '' ?>
            </td>
            <td data-label="Notiz"><?= App::e($iv['note'] ?? '') ?></td>
            <td>
              <form method="post" data-confirm="Diesen Eintrag löschen? Der Bestand wird zurückgebucht." style="margin:0">
                <?= Csrf::field() ?>
                <input type="hidden" name="action" value="delete_intake">
                <input type="hidden" name="id" value="<?= $id ?>">
                <input type="hidden" name="intake_id" value="<?= (int)$iv['id'] ?>">
                <button type="submit" class="secondary small">Löschen</button>
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
  <h2>Bestand</h2>
  <?php if ($m['stock_unit'] === null): ?>
    <p class="sub">
      Für dieses Präparat ist keine Bestandsführung eingerichtet.
      Unter "Bearbeiten" eine Einheit hinterlegen, um den Bestand zu verfolgen.
    </p>
  <?php else: ?>
    <p class="sub">
      Aktuell: <strong><?= $m['stock_quantity'] !== null
        ? App::e(rtrim(rtrim(number_format((float)$m['stock_quantity'],2,',','.'),'0'),',')) : '0' ?>
        <?= App::e($m['stock_unit']) ?></strong>
      <?php if ($m['stock_warn_at'] !== null): ?>
        · Warnschwelle <?= App::e(rtrim(rtrim(number_format((float)$m['stock_warn_at'],2,',','.'),'0'),',')) ?> <?= App::e($m['stock_unit']) ?>
      <?php endif; ?>
    </p>

    <form method="post">
      <?= Csrf::field() ?>
      <input type="hidden" name="action" value="restock">
      <input type="hidden" name="id" value="<?= $id ?>">
      <div class="field-row">
        <div>
          <label for="restock_qty">Neue Packung: Menge</label>
          <input type="text" id="restock_qty" name="restock_qty" required inputmode="decimal">
        </div>
        <div>
          <label for="restock_date">Datum</label>
          <input type="date" id="restock_date" name="restock_date" value="<?= App::e(date('Y-m-d')) ?>" required>
        </div>
      </div>
      <label for="restock_note">Notiz</label>
      <input type="text" id="restock_note" name="restock_note" maxlength="255" placeholder="z. B. Apotheke, Rezeptnummer">
      <button type="submit" class="auto secondary">Packung erfassen</button>
    </form>

    <?php if ($restocks): ?>
      <div class="table-wrap" style="margin-top:16px">
        <table class="stack">
          <thead><tr><th>Datum</th><th>Menge</th><th>Notiz</th><th></th></tr></thead>
          <tbody>
          <?php foreach ($restocks as $rs): ?>
            <tr>
              <td data-label="Datum"><?= App::e(date('d.m.Y', strtotime($rs['restock_date']))) ?></td>
              <td data-label="Menge"><?= App::e(rtrim(rtrim(number_format((float)$rs['quantity'],2,',','.'),'0'),',')) ?> <?= App::e($m['stock_unit']) ?></td>
              <td data-label="Notiz"><?= App::e($rs['note'] ?? '') ?></td>
              <td>
                <form method="post" data-confirm="Diesen Zukauf entfernen? Der Bestand wird angepasst." style="margin:0">
                  <?= Csrf::field() ?>
                  <input type="hidden" name="action" value="delete_restock">
                  <input type="hidden" name="id" value="<?= $id ?>">
                  <input type="hidden" name="restock_id" value="<?= (int)$rs['id'] ?>">
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
  <h2>Dateien</h2>
  <?php if ($m['attachments']): ?>
    <div class="table-wrap">
      <table class="stack">
        <thead><tr><th>Datei</th><th>Größe</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($m['attachments'] as $f): ?>
          <tr>
            <td data-label="Datei">
              <a href="<?= App::url('/file.php?id=' . (int)$f['id']) ?>" target="_blank" rel="noopener">
                <?= App::e($f['filename']) ?></a>
            </td>
            <td data-label="Größe"><?= App::e(AttachmentService::formatSize((int)$f['size_bytes'])) ?></td>
            <td>
              <form method="post" data-confirm="Diese Datei löschen?" style="margin:0">
                <?= Csrf::field() ?>
                <input type="hidden" name="action" value="delete_file">
                <input type="hidden" name="id" value="<?= $id ?>">
                <input type="hidden" name="att" value="<?= (int)$f['id'] ?>">
                <button type="submit" class="secondary small">Löschen</button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php else: ?>
    <p class="sub">Noch keine Datei angehängt (z. B. Beipackzettel).</p>
  <?php endif; ?>

  <form method="post" enctype="multipart/form-data">
    <?= Csrf::field() ?>
    <input type="hidden" name="action" value="upload">
    <input type="hidden" name="id" value="<?= $id ?>">
    <label for="file">Datei hinzufügen</label>
    <input type="file" id="file" name="file" required
           accept=".pdf,.jpg,.jpeg,.png,.heic,.tif,.tiff,.txt,.csv">
    <button type="submit" class="auto secondary">Hochladen</button>
  </form>
</div>

<div class="panel">
  <h2>Bearbeiten</h2>
  <form method="post">
    <?= Csrf::field() ?>
    <input type="hidden" name="action" value="save">
    <input type="hidden" name="id" value="<?= $id ?>">

    <div class="field-row">
      <div>
        <label for="name">Name</label>
        <input type="text" id="name" name="name" required maxlength="200" value="<?= App::e($m['name']) ?>">
      </div>
      <div>
        <label for="strength">Stärke</label>
        <input type="text" id="strength" name="strength" maxlength="60" value="<?= App::e($m['strength'] ?? '') ?>">
      </div>
      <div>
        <label for="form">Form</label>
        <select id="form" name="form">
          <?php foreach (Med::FORMS as $k => $l): ?>
            <option value="<?= App::e($k) ?>" <?= $m['form'] === $k ? 'selected' : '' ?>><?= App::e($l) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
    </div>

    <label for="purpose">Wofür</label>
    <input type="text" id="purpose" name="purpose" maxlength="300" value="<?= App::e($m['purpose'] ?? '') ?>">

    <div class="field-row">
      <div>
        <label for="start_date">Beginn</label>
        <input type="date" id="start_date" name="start_date" value="<?= App::e($m['start_date']) ?>" required>
      </div>
      <div>
        <label for="end_date">Ende</label>
        <input type="date" id="end_date" name="end_date" value="<?= App::e($m['end_date'] ?? '') ?>">
      </div>
      <div>
        <label for="status">Status</label>
        <select id="status" name="status">
          <?php foreach (Med::STATUS as $k => $l): ?>
            <option value="<?= App::e($k) ?>" <?= $m['status'] === $k ? 'selected' : '' ?>><?= App::e($l) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
    </div>

    <label for="doctor">Verordnet von</label>
    <input type="text" id="doctor" name="doctor" maxlength="200" value="<?= App::e($m['doctor'] ?? '') ?>">

    <label for="note">Notiz</label>
    <textarea id="note" name="note" rows="4"><?= App::e($m['note'] ?? '') ?></textarea>

    <label style="display:flex;align-items:center;gap:8px;font-weight:400">
      <input type="checkbox" name="is_prn" value="1" style="width:auto" <?= $m['is_prn'] ? 'checked' : '' ?>>
      Bei Bedarf, kein fester Einnahmeplan
    </label>

    <h2 style="margin-top:20px">Bestandsführung</h2>
    <div class="field-row">
      <div>
        <label for="stock_unit">Einheit</label>
        <input type="text" id="stock_unit" name="stock_unit" maxlength="24"
               placeholder="z. B. Stück, ml" value="<?= App::e($m['stock_unit'] ?? '') ?>">
      </div>
      <div>
        <label for="stock_quantity">Aktueller Bestand</label>
        <input type="text" id="stock_quantity" name="stock_quantity" inputmode="decimal"
               value="<?= App::e($m['stock_quantity'] !== null ? rtrim(rtrim(number_format((float)$m['stock_quantity'],2,',','.'),'0'),',') : '') ?>">
      </div>
      <div>
        <label for="stock_warn_at">Warnen ab</label>
        <input type="text" id="stock_warn_at" name="stock_warn_at" inputmode="decimal"
               value="<?= App::e($m['stock_warn_at'] !== null ? rtrim(rtrim(number_format((float)$m['stock_warn_at'],2,',','.'),'0'),',') : '') ?>">
      </div>
    </div>
    <p class="hint">
      Einheit leer lassen, um ohne Bestandsführung auszukommen. Ist eine
      Einheit hinterlegt, wird der Bestand bei jeder protokollierten
      Einnahme automatisch verringert.
    </p>

    <button type="submit" class="auto">Speichern</button>
  </form>

  <div class="actions">
    <form method="post" data-confirm="Präparat samt Plan, Dateien und Timeline-Einträgen löschen?">
      <?= Csrf::field() ?>
      <input type="hidden" name="action" value="delete">
      <input type="hidden" name="id" value="<?= $id ?>">
      <button type="submit" class="secondary small">Löschen</button>
    </form>
  </div>
</div>
<?php View::end($app); ?>
