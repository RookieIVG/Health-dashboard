<?php
declare(strict_types=1);

namespace Health;

/**
 * Gemeinsames Seitengerüst.
 *
 * Vorher stand die Kopfzeile in sechs Dateien – mit jedem neuen Modul
 * wäre die Zahl gewachsen und die Varianten wären auseinandergelaufen.
 *
 * Das Theme wird serverseitig aus dem Cookie gelesen und direkt ins
 * <html data-theme> geschrieben. Damit gibt es kein kurzes Aufblitzen
 * des hellen Layouts beim Laden, wie es passiert, wenn erst JavaScript
 * nach dem ersten Rendern umschaltet.
 */
final class View
{
    /**
     * Konto und Verwaltung – hinter einem eigenen Menü.
     *
     * Selten benutzte Punkte kosten in der ersten Reihe Platz, den die
     * Module brauchen. Die Modulliste selbst kommt aus ModuleRegistry,
     * damit Kopfzeile und Startseite dieselbe Reihenfolge und dieselben
     * Bezeichnungen verwenden.
     */
    private static array $account = [
        ['key' => 'security', 'url' => '/profile/security.php', 'label' => 'Sicherheit'],
        ['key' => 'modules',  'url' => '/modules.php',          'label' => 'Module ordnen'],
        ['key' => 'users',    'url' => '/admin/users.php',      'label' => 'Benutzer', 'admin' => true],
        ['key' => 'logout',   'url' => '/logout.php',           'label' => 'Abmelden'],
    ];

    /**
     * @param array $opt  title, active, chrome (bool), narrow (bool), head (string)
     */
    public static function start(App $app, array $opt = []): void
    {
        $title  = (string)($opt['title'] ?? $app->config['app']['name']);
        $active = (string)($opt['active'] ?? '');
        $chrome = $opt['chrome'] ?? true;
        $narrow = $opt['narrow'] ?? false;
        $user   = $chrome ? $app->auth->user() : null;
        $navItems = $chrome ? $app->modules()->navItems() : [];
        $theme  = self::theme();

        echo '<!doctype html>' . "\n";
        echo '<html lang="de" data-theme="' . App::e($theme) . '">' . "\n";
        ?>
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="robots" content="noindex, nofollow">
<meta name="color-scheme" content="light dark">
<meta name="theme-color" content="#f4f6f8" media="(prefers-color-scheme: light)">
<meta name="theme-color" content="#14181c" media="(prefers-color-scheme: dark)">
<title><?= App::e($title) ?></title>

<?php /* SVG zuerst: moderne Browser nehmen es und schalten über die
         eingebettete Medienabfrage im Dunkelmodus auf den helleren
         Farbton um. Ältere greifen auf die .ico zurück. */ ?>
<link rel="icon" href="<?= App::e(self::asset('/assets/brand/favicon.svg')) ?>" type="image/svg+xml">
<link rel="icon" href="<?= App::url('/assets/brand/favicon.ico') ?>" sizes="32x32">
<link rel="apple-touch-icon" href="<?= App::url('/assets/brand/icon-180.png') ?>">
<link rel="manifest" href="<?= App::url('/assets/brand/site.webmanifest') ?>">
<link rel="stylesheet" href="<?= App::e(self::asset('/assets/app.css')) ?>">
<?= $opt['head'] ?? '' ?>
</head>
<body>
        <?php if ($chrome): ?>
<header class="top">
  <div class="wrap">
    <a class="brand" href="<?= App::url('/index.php') ?>">
      <img src="<?= App::url('/assets/brand/mark.svg') ?>" alt="" width="28" height="28">
      <span><?= App::e($app->config['app']['name']) ?></span>
    </a>

    <?php /* Checkbox statt <details>: bei <details> blendet der Browser
             alles außer <summary> aus, solange es geschlossen ist – das
             ließe sich per CSS auf Desktopbreite nicht zuverlässig wieder
             einblenden. Die Checkbox-Variante läuft ohne JavaScript. */ ?>
    <input type="checkbox" id="nav-toggle" class="nav-toggle">
    <label for="nav-toggle" class="nav-btn" aria-label="Menü öffnen"><span class="bars"></span></label>

    <nav id="mainnav">
      <?php foreach ($navItems as $item): ?>
        <?php if (!empty($item['external'])): ?>
          <?php /* rel="noopener noreferrer": noopener verhindert den Zugriff
                   der fremden Seite über window.opener, noreferrer unterdrückt
                   zusätzlich den Referer mit der URL dieser Installation. */ ?>
          <a href="<?= App::e($item['url']) ?>" target="_blank" rel="noopener noreferrer"
             class="ext"><?= App::e($item['label']) ?> <span aria-hidden="true">↗</span></a>
        <?php else: ?>
          <a href="<?= App::url($item['url']) ?>"
             class="<?= $active === $item['key'] ? 'active' : '' ?>"><?= App::e($item['label']) ?></a>
        <?php endif; ?>
      <?php endforeach; ?>

      <?php /* Im Klappmenü stehen die Kontopunkte mit drin – ein zweites
               Menü auf dem Telefon wäre nur ein zusätzlicher Tipp. */ ?>
      <span class="nav-sep"></span>
      <?php foreach (self::$account as $item):
            if (!empty($item['admin']) && ($user['role'] ?? '') !== 'admin') continue; ?>
        <a class="acct-only <?= $active === $item['key'] ? 'active' : '' ?>"
           href="<?= App::url($item['url']) ?>"><?= App::e($item['label']) ?></a>
      <?php endforeach; ?>
    </nav>

    <span class="acct-wrap">
      <input type="checkbox" id="acct-toggle" class="nav-toggle">
      <label for="acct-toggle" class="acct-btn" aria-label="Konto"><span aria-hidden="true">☰</span></label>
      <nav id="acctnav">
        <?php foreach (self::$account as $item):
              if (!empty($item['admin']) && ($user['role'] ?? '') !== 'admin') continue; ?>
          <a href="<?= App::url($item['url']) ?>"
             class="<?= $active === $item['key'] ? 'active' : '' ?>"><?= App::e($item['label']) ?></a>
        <?php endforeach; ?>
      </nav>
    </span>

    <button type="button" class="theme-toggle" data-theme-toggle
            aria-label="Darstellung wechseln" title="Hell / Dunkel / Automatisch">
      <span class="ico-auto">A</span>
      <span class="ico-light">☀</span>
      <span class="ico-dark">☾</span>
    </button>
  </div>
</header>
        <?php endif; ?>
<main class="wrap<?= $narrow ? ' narrow' : '' ?>">
        <?php
    }

    public static function end(App $app, bool $withScript = true): void
    {
        if ($withScript) {
            echo '<script src="' . App::e(self::asset('/assets/ui.js')) . '" defer></script>' . "\n";
        }
        echo "</main>\n</body>\n</html>\n";
    }

    /** Für Login-Seiten: schmales Layout ohne Navigation. */
    public static function startBare(App $app, string $title): void
    {
        self::start($app, ['title' => $title, 'chrome' => false, 'narrow' => true]);
    }

    /**
     * Hängt den Änderungszeitstempel an die URL. Ohne das liefert der
     * Browser nach einem Update weiter die alte Datei aus dem Cache –
     * und ein neues Markup trifft auf altes CSS.
     */
    private static function asset(string $path): string
    {
        // Der Webroot kann je nach Hoster anders liegen als public/,
        // deshalb mehrere Kandidaten prüfen.
        $base = rtrim((string)($_SERVER['DOCUMENT_ROOT'] ?? ''), '/');
        $candidates = [
            $base . App::url($path),          // Webroot + base_path
            __DIR__ . '/../../public' . $path,
            __DIR__ . '/../..' . $path,
        ];
        $v = '1';
        foreach ($candidates as $file) {
            if (is_file($file)) { $v = (string)filemtime($file); break; }
        }
        return App::url($path) . '?v=' . $v;
    }

    /**
     * 'auto' | 'light' | 'dark'. Bei 'auto' entscheidet das Betriebssystem
     * per prefers-color-scheme im CSS.
     */
    public static function theme(): string
    {
        $t = $_COOKIE['theme'] ?? 'auto';
        return in_array($t, ['auto', 'light', 'dark'], true) ? $t : 'auto';
    }

    // -----------------------------------------------------------------
    // Bausteine
    // -----------------------------------------------------------------

    public static function flash(?string $text, string $type = 'ok'): void
    {
        if ($text === null || $text === '') return;
        echo '<div class="msg ' . App::e($type) . '">' . App::e($text) . '</div>';
    }

    /** Tabellen brauchen auf schmalen Displays einen Scroll-Container. */
    public static function tableOpen(string $class = ''): void
    {
        echo '<div class="table-wrap"><table class="' . App::e($class) . '">';
    }

    public static function tableClose(): void
    {
        echo '</table></div>';
    }
}
