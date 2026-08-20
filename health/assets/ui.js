/*
 * Oberflächen-Skript: Darstellungsumschalter, Sicherheitsabfragen, Menü.
 *
 * Umschalter für die Darstellung: automatisch -> hell -> dunkel -> automatisch
 *
 * Die Auswahl liegt in einem Cookie, nicht in localStorage. Grund: PHP
 * liest das Cookie beim Rendern und setzt data-theme direkt ins <html>.
 * Mit localStorage könnte die Seite erst nach dem ersten Rendern
 * umschalten – bei dunkler Einstellung blitzt dann kurz die helle
 * Variante auf.
 *
 * Externes Skript, weil die Content-Security-Policy Inline-JavaScript
 * ohne Nonce nicht zulässt.
 */
(function () {
  'use strict';

  var ORDER = ['auto', 'light', 'dark'];
  var root = document.documentElement;

  /* Basispfad aus der eigenen Skript-URL ableiten: die Datei liegt unter
     {base}/assets/theme.js. So braucht es keine Inline-Variable, die die
     Content-Security-Policy ohnehin blockieren würde. */
  var BASE = (function () {
    var el = document.currentScript;
    if (!el) {
      var all = document.getElementsByTagName('script');
      el = all[all.length - 1];
    }
    var src = (el && el.getAttribute('src')) || '';
    var i = src.indexOf('/assets/ui.js');
    return i > 0 ? src.slice(0, i) : '';
  })();

  function setCookie(value) {
    var base = BASE + '/';
    var secure = location.protocol === 'https:' ? '; Secure' : '';
    // Ein Jahr, damit die Einstellung Geräteneustarts überlebt.
    document.cookie = 'theme=' + value
      + '; path=' + base
      + '; max-age=31536000; SameSite=Lax' + secure;
  }

  function current() {
    var t = root.getAttribute('data-theme');
    return ORDER.indexOf(t) >= 0 ? t : 'auto';
  }

  function apply(value) {
    root.setAttribute('data-theme', value);
    setCookie(value);
    updateMeta(value);
  }

  /* Farbe der Browser-Leiste auf iOS und Android mitziehen. */
  function updateMeta(value) {
    var dark = value === 'dark'
      || (value === 'auto' && window.matchMedia
          && window.matchMedia('(prefers-color-scheme: dark)').matches);

    var tags = document.querySelectorAll('meta[name="theme-color"]');
    for (var i = 0; i < tags.length; i++) {
      tags[i].setAttribute('content', dark ? '#14181c' : '#f4f6f8');
      tags[i].removeAttribute('media');
    }
  }

  function next() {
    var i = ORDER.indexOf(current());
    return ORDER[(i + 1) % ORDER.length];
  }

  document.addEventListener('click', function (e) {
    var btn = e.target.closest ? e.target.closest('[data-theme-toggle]') : null;
    if (!btn) return;
    e.preventDefault();
    apply(next());
  });

  /* Bei "automatisch" auf Systemwechsel reagieren, ohne Neuladen. */
  if (window.matchMedia) {
    var mq = window.matchMedia('(prefers-color-scheme: dark)');
    var onChange = function () {
      if (current() === 'auto') updateMeta('auto');
    };
    if (mq.addEventListener) mq.addEventListener('change', onChange);
    else if (mq.addListener) mq.addListener(onChange);
  }


  /* --------------------------------------------------------------
     Vitalwerte: Eingabefelder an die gewählte Messgröße anpassen.

     Die nötigen Angaben stehen als data-Attribute an den <option>-
     Elementen. Damit braucht es weder einen zusätzlichen Request noch
     ein Inline-Skript mit eingebetteten Daten – letzteres würde die
     Content-Security-Policy ohnehin blockieren.

     Ohne JavaScript bleibt es beim serverseitig gerenderten Zustand:
     das zweite Feld ist dann für die vorausgewählte Messgröße sichtbar
     und sonst ausgeblendet. Die Prüfung findet in jedem Fall serverseitig
     statt.
     -------------------------------------------------------------- */
  function syncVitalFields() {
    var sel = document.getElementById('metric_id');
    if (!sel) return;

    var opt = sel.options[sel.selectedIndex];
    if (!opt) return;

    var second = opt.getAttribute('data-second') === '1';
    var wrap   = document.getElementById('value2-wrap');
    var v1lbl  = document.getElementById('value-label');
    var v2lbl  = document.getElementById('value2-label');
    var v1     = document.getElementById('value');
    var v2     = document.getElementById('value2');
    if (!wrap || !v1 || !v2) return;

    if (second) {
      wrap.removeAttribute('hidden');
      v2.setAttribute('required', 'required');
      if (v1lbl) v1lbl.textContent = opt.getAttribute('data-label1') || 'Wert';
      if (v2lbl) v2lbl.textContent = opt.getAttribute('data-label2') || 'Zweiter Wert';
    } else {
      wrap.setAttribute('hidden', 'hidden');
      /* required entfernen, sonst blockiert ein unsichtbares Pflichtfeld
         das Absenden – der Browser kann es nicht anspringen und meldet
         nur, dass ein Feld nicht ausgefüllt sei. */
      v2.removeAttribute('required');
      v2.value = '';
      if (v1lbl) v1lbl.textContent = 'Wert';
    }

    v1.setAttribute('placeholder', opt.getAttribute('data-unit') || '');
  }

  var metricSel = document.getElementById('metric_id');
  if (metricSel) {
    metricSel.addEventListener('change', syncVitalFields);
    syncVitalFields();
  }


  /* --------------------------------------------------------------
     Medikation: Wochentage bzw. Intervall-Felder je nach gewähltem
     Zyklus ein-/ausblenden. Ohne JavaScript bleiben beide Blöcke
     sichtbar – funktioniert weiterhin, ist nur weniger aufgeräumt.
     Geprüft wird in jedem Fall serverseitig in addScheduleRow().
     -------------------------------------------------------------- */
  function syncCycleFields() {
    var sel = document.getElementById('cycle_type');
    var weekly = document.getElementById('cycle-weekly-wrap');
    var interval = document.getElementById('cycle-interval-wrap');
    if (!sel || !weekly || !interval) return;

    var intervalInput = document.getElementById('interval_days');

    if (sel.value === 'interval') {
      weekly.setAttribute('hidden', 'hidden');
      interval.removeAttribute('hidden');
      if (intervalInput) intervalInput.setAttribute('required', 'required');
    } else {
      interval.setAttribute('hidden', 'hidden');
      if (intervalInput) intervalInput.removeAttribute('required');
      if (sel.value === 'daily') {
        weekly.setAttribute('hidden', 'hidden');
      } else {
        weekly.removeAttribute('hidden');
      }
    }
  }

  var cycleSel = document.getElementById('cycle_type');
  if (cycleSel) {
    cycleSel.addEventListener('change', syncCycleFields);
    syncCycleFields();
  }

  updateMeta(current());

  /* Sicherheitsabfrage vor kritischen Aktionen.
     Muss hier stehen und nicht als onsubmit-Attribut: Inline-Handler
     sind Inline-JavaScript und werden von der Content-Security-Policy
     ohne 'unsafe-inline' blockiert – das Formular würde dann ohne
     jede Rückfrage abschicken. */
  document.addEventListener('submit', function (e) {
    var form = e.target;
    var question = form.getAttribute && form.getAttribute('data-confirm');
    if (question && !window.confirm(question)) {
      e.preventDefault();
    }
  });

  /* Komfort, kein Muss: aufgeklapptes Menü schließen, sobald daneben
     getippt wird oder Escape kommt. Ohne JavaScript bleibt es offen, bis
     erneut auf das Symbol getippt wird – das funktioniert weiterhin. */
  function closeNav() {
    ['nav-toggle', 'acct-toggle'].forEach(function (id) {
      var cb = document.getElementById(id);
      if (cb) cb.checked = false;
    });
  }

  /* Kontomenü: gleiche Behandlung wie das Hauptmenü */
  document.addEventListener('click', function (e) {
    var cb = document.getElementById('acct-toggle');
    if (!cb || !cb.checked || e.target === cb) return;
    var nav = document.getElementById('acctnav');
    var btn = document.querySelector('.acct-btn');
    if (nav && !nav.contains(e.target) && btn && !btn.contains(e.target)) cb.checked = false;
  });

  document.addEventListener('click', function (e) {
    var cb = document.getElementById('nav-toggle');
    if (!cb || !cb.checked) return;
    /* Wichtig: die Checkbox selbst ausnehmen. Ein Tippen auf das Label
       erzeugt zusätzlich ein weitergeleitetes Klick-Ereignis mit der
       Checkbox als Ziel. Ohne diese Ausnahme würde der Handler das
       gerade geöffnete Menü sofort wieder schließen. */
    if (e.target === cb) return;

    var nav = document.getElementById('mainnav');
    var btn = document.querySelector('.nav-btn');
    if (nav && !nav.contains(e.target) && btn && !btn.contains(e.target)) closeNav();
  });

  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') closeNav();
  });
})();
