<?php
declare(strict_types=1);
require __DIR__ . '/_init.php';

use Health\App;

// Statt einer statischen .webmanifest-Datei, damit start_url und die
// Icon-Pfade den tatsächlichen base_path der Installation kennen. Eine
// fix "/index.php" eingetragene start_url würde bei jeder Installation
// in einem Unterverzeichnis (wie hier: web/health/) ins Leere zeigen –
// das Symbol am Homescreen würde dann die Domain-Wurzel statt der App
// öffnen, und iOS würde Web Push in diesem falschen Kontext gar nicht
// erst anbieten.
$app = App::boot();

header('Content-Type: application/manifest+json');

echo json_encode([
    'name'             => $app->config['app']['name'],
    'short_name'       => 'Health',
    'start_url'        => App::url('/index.php'),
    'scope'            => App::url('/'),
    'display'          => 'standalone',
    'background_color' => '#f4f6f8',
    'theme_color'      => '#0f6e63',
    'icons' => [
        ['src' => App::url('/assets/brand/icon-192.png'), 'sizes' => '192x192', 'type' => 'image/png'],
        ['src' => App::url('/assets/brand/icon-512.png'), 'sizes' => '512x512', 'type' => 'image/png'],
        ['src' => App::url('/assets/brand/mark.svg'), 'sizes' => 'any', 'type' => 'image/svg+xml', 'purpose' => 'any'],
    ],
], JSON_UNESCAPED_SLASHES);
