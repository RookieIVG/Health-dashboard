/*
 * Web-Push-Anmeldung. Eigene Datei statt in ui.js, weil das hier
 * optional ist und Browser-APIs anfasst, die nicht überall existieren –
 * soll nicht laden/ausgeführt werden, wo es nicht gebraucht wird.
 *
 * Wichtig für iOS Safari: Notification.requestPermission() und
 * pushManager.subscribe() funktionieren nur, wenn die Seite über "Zum
 * Home-Bildschirm hinzufügen" installiert wurde UND direkt aus einem
 * Klick-Handler aufgerufen werden (kein setTimeout, kein await davor).
 */
(function () {
  'use strict';

  var btnEnable = document.getElementById('push-enable');
  var btnTest = document.getElementById('push-test');
  var statusEl = document.getElementById('push-status');
  var hintEl = document.getElementById('push-ios-hint');

  if (!btnEnable) return; // Panel nicht auf dieser Seite

  function isStandalone() {
    return window.matchMedia('(display-mode: standalone)').matches
        || window.navigator.standalone === true; // ältere iOS-Safari-Eigenheit
  }

  function supported() {
    return 'serviceWorker' in navigator && 'PushManager' in window && 'Notification' in window;
  }

  function setStatus(text) {
    if (statusEl) statusEl.textContent = text;
  }

  function urlBase64ToUint8Array(base64url) {
    var padding = '='.repeat((4 - (base64url.length % 4)) % 4);
    var base64 = (base64url + padding).replace(/-/g, '+').replace(/_/g, '/');
    var raw = window.atob(base64);
    var out = new Uint8Array(raw.length);
    for (var i = 0; i < raw.length; i++) out[i] = raw.charCodeAt(i);
    return out;
  }

  function bufferToBase64Url(buffer) {
    var bytes = new Uint8Array(buffer);
    var str = '';
    for (var i = 0; i < bytes.length; i++) str += String.fromCharCode(bytes[i]);
    return window.btoa(str).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
  }

  /**
   * subscription.toJSON() ist in manchen Safari-Versionen unzuverlässig,
   * besonders wenn schon ein bestehendes Abonnement erneut abgerufen
   * wird (kein neues Abonnement, sondern dasselbe wie zuvor) – dann
   * wirft toJSON() genau die kryptische "did not match the expected
   * pattern"-Meldung, obwohl die Anmeldung selbst längst funktioniert.
   * Deshalb hier lieber direkt aus endpoint/getKey() zusammensetzen,
   * das ist in jeder unterstützten Umgebung verlässlich vorhanden.
   */
  function subscriptionToJSON(sub) {
    return {
      endpoint: sub.endpoint,
      keys: {
        p256dh: bufferToBase64Url(sub.getKey('p256dh')),
        auth: bufferToBase64Url(sub.getKey('auth')),
      },
    };
  }

  if (!supported()) {
    btnEnable.disabled = true;
    setStatus('Push wird auf diesem Gerät/Browser nicht unterstützt.');
    return;
  }
  if (!isStandalone() && hintEl) {
    hintEl.hidden = false;
  }

  // Sichtbare Markierung ohne Entwicklertools: zeigt sofort beim Öffnen
  // der Seite, ob dieses Gerät wirklich das aktuelle Skript ausführt,
  // statt das erst nach einem Klick indirekt zu erfahren.
  setStatus('Bereit (Skriptstand: 2026-08-21-a).');

  btnEnable.addEventListener('click', function () {
    setStatus('Wird eingerichtet …');

    var swReg = null;

    navigator.serviceWorker.register(btnEnable.dataset.swUrl)
      .catch(function (err) { throw new Error('Registrierung: ' + err); })
      .then(function (reg) {
        swReg = reg;
        return Notification.requestPermission()
          .catch(function (err) { throw new Error('Berechtigung: ' + err); });
      })
      .then(function (perm) {
        if (perm !== 'granted') {
          var e = new Error('permission-denied'); e.isPermission = true; throw e;
        }
        var key;
        try {
          key = urlBase64ToUint8Array(btnEnable.dataset.vapidKey);
        } catch (err) {
          throw new Error('Schlüsselumwandlung: ' + err + ' (Rohwert: ' + btnEnable.dataset.vapidKey + ')');
        }
        return swReg.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: key })
          .catch(function (err) { throw new Error('Abonnieren: ' + err); });
      })
      .then(function (subscription) {
        return fetch(btnEnable.dataset.subscribeUrl, {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({
            _csrf: btnEnable.dataset.csrf,
            subscription: subscriptionToJSON(subscription),
            label: navigator.userAgent.slice(0, 120),
          }),
        }).catch(function (err) { throw new Error('Übertragung ans Backend: ' + err); });
      })
      .then(function (res) {
        return res.text().then(function (text) {
          try { return JSON.parse(text); }
          catch (e) { throw new Error('Antwort vom Server: ' + text.slice(0, 200)); }
        });
      })
      .then(function (data) {
        if (data.ok) {
          setStatus('Aktiviert. Testbenachrichtigung senden, um es zu prüfen.');
          window.location.reload();
        } else {
          setStatus('Fehler: ' + (data.error || 'unbekannt'));
        }
      })
      .catch(function (err) {
        if (err && err.isPermission) {
          setStatus('Erlaubnis wurde nicht erteilt. In den iOS-Einstellungen unter Mitteilungen nachträglich möglich.');
        } else {
          // Bewusst der vollständige, unveränderte Fehlertext – bei Safaris
          // generischen Meldungen ist genau das die einzige Chance, die
          // eigentliche Ursache später einzugrenzen.
          setStatus('Fehler beim Einrichten: ' + (err && err.message ? err.message : err));
        }
      });
  });

  if (btnTest) {
    btnTest.addEventListener('click', function () {
      setStatus('Sende Testbenachrichtigung …');
      fetch(btnTest.dataset.testUrl, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ _csrf: btnTest.dataset.csrf }),
      })
        .then(function (res) {
        return res.text().then(function (text) {
          try { return JSON.parse(text); }
          catch (e) { throw new Error('Antwort vom Server: ' + text.slice(0, 200)); }
        });
      })
        .then(function (data) {
          setStatus(data.ok
            ? 'Testbenachrichtigung verschickt (' + data.sent + ' Gerät[e]).'
            : 'Fehlgeschlagen: ' + (data.error || (data.failed + ' Gerät(e) ohne Erfolg')));
        })
        .catch(function (err) { setStatus('Fehler: ' + err); });
    });
  }
})();
