<?php
declare(strict_types=1);
require __DIR__ . '/_init.php';

use Health\App;

// Der Service Worker als .php statt statischer .js-Datei, damit die
// Klick-Ziel-URL (App::url()) den tatsächlichen base_path kennt – genau
// aus demselben Grund wie beim Manifest. Wichtig: diese Datei muss vom
// App-Wurzelverzeichnis aus ausgeliefert werden (also hier, nicht unter
// /assets/), sonst deckt der Standard-Scope des Service Workers nicht
// die ganze App ab.
$app = App::boot();
header('Content-Type: application/javascript; charset=utf-8');
// Vollständige URLs, nicht nur Pfade: self.clients.matchAll() liefert
// vollständige URLs (Schema + Host) je offenem Tab zurück, ein reiner
// Pfad würde beim Vergleich nie treffen und immer ein neues Fenster
// öffnen statt ein vorhandenes zu fokussieren.
$fallbackUrl = App::absUrl('/medications.php');
$iconUrl     = App::absUrl('/assets/brand/icon-192.png');
?>
self.addEventListener('install', function (event) {
  self.skipWaiting();
});

self.addEventListener('activate', function (event) {
  event.waitUntil(self.clients.claim());
});

self.addEventListener('push', function (event) {
  var data = { title: 'Erinnerung', body: '', url: <?= json_encode($fallbackUrl) ?> };
  try {
    if (event.data) data = Object.assign(data, event.data.json());
  } catch (e) { /* Nutzlast war kein JSON – Vorgabewerte behalten */ }

  event.waitUntil(
    self.registration.showNotification(data.title, {
      body: data.body,
      icon: <?= json_encode($iconUrl) ?>,
      badge: <?= json_encode($iconUrl) ?>,
      data: { url: data.url },
      tag: data.tag || 'health-reminder',
    })
  );
});

self.addEventListener('notificationclick', function (event) {
  event.notification.close();
  var url = (event.notification.data && event.notification.data.url) || <?= json_encode($fallbackUrl) ?>;

  event.waitUntil(
    self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then(function (list) {
      for (var i = 0; i < list.length; i++) {
        if (list[i].url === url && 'focus' in list[i]) return list[i].focus();
      }
      if (self.clients.openWindow) return self.clients.openWindow(url);
    })
  );
});
