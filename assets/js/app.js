// Unternehmen Plus – Frontend-JS
(function () {
  'use strict';

  var lsGet = function (k) { try { return localStorage.getItem(k); } catch (e) { return null; } };
  var lsSet = function (k, v) { try { localStorage.setItem(k, v); } catch (e) {} };

  // ---------------------------------------------------------------------------
  // Navigation: einklappbar (Desktop) bzw. Burger-Drawer (mobil)
  // ---------------------------------------------------------------------------
  (function initNav() {
    var app = document.querySelector('.app');
    var toggle = document.querySelector('[data-nav-toggle]');
    if (!app || !toggle) return; // z. B. Login-Seite ohne Sidebar

    var mqMobile = window.matchMedia('(max-width: 900px)');
    var COLLAPSE_KEY = 'uplus_nav_collapsed';

    // Gemerkten Desktop-Zustand anwenden.
    if (lsGet(COLLAPSE_KEY) === '1') app.classList.add('nav-collapsed');

    function setExpanded(open) {
      toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
    }

    function closeDrawer() {
      app.classList.remove('nav-open');
      setExpanded(false);
    }

    toggle.addEventListener('click', function () {
      if (mqMobile.matches) {
        // Mobil: Drawer auf/zu
        var open = app.classList.toggle('nav-open');
        setExpanded(open);
      } else {
        // Desktop: schmale Leiste ein-/ausklappen (gemerkt)
        var collapsed = app.classList.toggle('nav-collapsed');
        lsSet(COLLAPSE_KEY, collapsed ? '1' : '0');
      }
    });

    // Overlay-Klick schließt den Drawer.
    var overlay = document.querySelector('[data-nav-close]');
    if (overlay) overlay.addEventListener('click', closeDrawer);

    // Klick auf einen Menüpunkt schließt den mobilen Drawer.
    document.querySelectorAll('.nav a').forEach(function (a) {
      a.addEventListener('click', function () { if (mqMobile.matches) closeDrawer(); });
    });

    // Escape schließt den Drawer.
    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape' && app.classList.contains('nav-open')) closeDrawer();
    });

    // Beim Wechsel auf Desktop einen offenen Drawer sauber schließen.
    var onChange = function () { if (!mqMobile.matches) closeDrawer(); };
    if (mqMobile.addEventListener) mqMobile.addEventListener('change', onChange);
    else if (mqMobile.addListener) mqMobile.addListener(onChange);
  })();

  // ---------------------------------------------------------------------------
  // PWA: Service Worker registrieren + Installations-Hinweis (Toast)
  // ---------------------------------------------------------------------------
  (function initPwa() {
    var base = (document.documentElement.getAttribute('data-base') || '').replace(/\/$/, '');

    if ('serviceWorker' in navigator) {
      window.addEventListener('load', function () {
        navigator.serviceWorker.register(base + '/sw.js', { scope: base + '/' })
          .catch(function () { /* SW optional – App funktioniert auch ohne */ });
      });
    }

    var DISMISS_KEY = 'uplus_install_dismissed';
    var deferredPrompt = null;

    function isStandalone() {
      return (window.matchMedia && window.matchMedia('(display-mode: standalone)').matches)
        || window.navigator.standalone === true;
    }
    function suppressed() { return isStandalone() || lsGet(DISMISS_KEY) === '1'; }
    function dismissForever() { lsSet(DISMISS_KEY, '1'); }

    function showToast(opts) {
      var host = document.querySelector('.toast-host');
      if (!host) {
        host = document.createElement('div');
        host.className = 'toast-host';
        document.body.appendChild(host);
      }
      var t = document.createElement('div');
      t.className = 'toast';
      var actions = opts.actionsHtml ? '<div class="toast__actions">' + opts.actionsHtml + '</div>' : '';
      t.innerHTML =
        '<div class="toast__icon">' + (opts.icon || '📲') + '</div>' +
        '<div class="toast__body"><strong></strong><span></span>' + actions + '</div>' +
        '<button type="button" class="toast__close" aria-label="Schließen">&times;</button>';
      // Text sicher setzen (kein HTML aus Variablen).
      t.querySelector('strong').textContent = opts.title;
      t.querySelector('.toast__body > span').textContent = opts.text;
      host.appendChild(t);

      function close() {
        t.classList.add('is-hiding');
        setTimeout(function () { if (t.parentNode) t.parentNode.removeChild(t); }, 220);
      }
      t.querySelector('.toast__close').addEventListener('click', function () {
        close();
        if (opts.onDismiss) opts.onDismiss();
      });
      return { el: t, close: close };
    }

    function showInstallToast() {
      if (suppressed() || document.querySelector('.toast--install')) return;
      var toast = showToast({
        icon: '📲',
        title: 'App installieren',
        text: 'Installiere Unternehmen Plus auf deinem Gerät – für ein super Erlebnis.',
        actionsHtml:
          '<button type="button" class="btn btn--teal btn--sm no-spinner" data-install>Installieren</button>' +
          '<button type="button" class="btn btn--ghost btn--sm no-spinner" data-later>Später</button>',
        onDismiss: dismissForever
      });
      toast.el.classList.add('toast--install');
      toast.el.querySelector('[data-install]').addEventListener('click', function () {
        toast.close();
        if (!deferredPrompt) return;
        deferredPrompt.prompt();
        deferredPrompt.userChoice.then(function (choice) {
          if (choice && choice.outcome === 'accepted') dismissForever();
          deferredPrompt = null;
        });
      });
      toast.el.querySelector('[data-later]').addEventListener('click', function () {
        toast.close();
        dismissForever();
      });
    }

    function showIosToast() {
      if (suppressed()) return;
      showToast({
        icon: '📲',
        title: 'Zum Home-Bildschirm',
        text: 'Für ein super Erlebnis: unten das Teilen-Symbol tippen und „Zum Home-Bildschirm“ wählen.',
        onDismiss: dismissForever
      });
    }

    // Chromium/Edge/Android: eigener Installations-Prompt.
    window.addEventListener('beforeinstallprompt', function (e) {
      e.preventDefault();
      deferredPrompt = e;
      if (suppressed()) return;
      showInstallToast();
    });

    window.addEventListener('appinstalled', function () {
      deferredPrompt = null;
      dismissForever();
    });

    // iOS-Safari kennt kein beforeinstallprompt -> eigener Hinweis.
    var ua = window.navigator.userAgent || '';
    var isIOS = /iPad|iPhone|iPod/.test(ua) || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);
    var isSafari = isIOS && /Safari/.test(ua) && !/CriOS|FxiOS|EdgiOS/.test(ua);
    if (isSafari && !suppressed()) {
      setTimeout(showIosToast, 1500);
    }
  })();

  // ---------------------------------------------------------------------------
  // Bestaetigung vor Aktionen + Lade-Spinner am Submit-Button
  // ---------------------------------------------------------------------------
  document.addEventListener('submit', function (e) {
    var f = e.target;
    if (f.matches('[data-confirm]') && !window.confirm(f.getAttribute('data-confirm'))) {
      e.preventDefault();
      return;
    }
    var btn = e.submitter || f.querySelector('button[type="submit"], button:not([type])');
    if (btn && !btn.classList.contains('no-spinner') && !btn.disabled) {
      var loadingLabel = btn.getAttribute('data-loading') || btn.textContent.trim();
      btn.dataset.orig = btn.innerHTML;
      btn.innerHTML = '<span class="spinner"></span> ' + loadingLabel;
      btn.classList.add('is-loading');
      setTimeout(function () { btn.disabled = true; }, 0);
    }
  });

  // ---------------------------------------------------------------------------
  // Live-Summe bei Bewertungs-Eingaben
  // ---------------------------------------------------------------------------
  function recalc() {
    var totalEl = document.querySelector('[data-score-total]');
    if (!totalEl) return;
    var sum = 0;
    document.querySelectorAll('[data-score]').forEach(function (i) {
      var v = parseInt(i.value, 10);
      if (!isNaN(v)) sum += v;
    });
    totalEl.textContent = sum;
  }
  document.addEventListener('input', function (e) {
    if (e.target.matches('[data-score]')) recalc();
  });

  // ---------------------------------------------------------------------------
  // Tabellen: sortierbare Spalten (deutsche Zahlen/Datumswerte) + Tokensuche.
  // Wird automatisch auf jede <table class="data"> angewandt.
  // ---------------------------------------------------------------------------

  // Sortierwert einer Zelle bestimmen (Datum > Zahl > Text).
  function cellValue(td) {
    var t = (td.getAttribute('data-sort') || td.textContent || '').trim();
    var low = t.toLowerCase();
    // Datum TT.MM.JJJJ [HH:MM]
    var d = t.match(/(\d{2})\.(\d{2})\.(\d{4})(?:\s+(\d{2}):(\d{2}))?/);
    if (d) {
      return { n: parseInt(d[3] + d[2] + d[1] + (d[4] || '00') + (d[5] || '00'), 10), s: low, empty: false };
    }
    // Deutsche Zahl: 1.234,5 oder 33,5 oder 12
    var m = t.match(/-?\d{1,3}(?:\.\d{3})+(?:,\d+)?|-?\d+(?:,\d+)?|-?,\d+/);
    var n = null;
    if (m) {
      var num = parseFloat(m[0].replace(/\./g, '').replace(',', '.'));
      if (!isNaN(num)) n = num;
    }
    return { n: n, s: low, empty: (t === '' || t === '—' || t === '–') };
  }

  function buildGroups(tbody) {
    var groups = [], cur = null;
    Array.prototype.forEach.call(tbody.rows, function (tr) {
      var placeholder = tr.cells.length === 1 && tr.cells[0].hasAttribute('colspan');
      var sub = tr.classList.contains('admin-row') || tr.classList.contains('subrow');
      if (placeholder) { groups.push({ rows: [tr], placeholder: true }); cur = null; return; }
      if (sub && cur) { cur.rows.push(tr); return; }
      cur = { rows: [tr], main: tr, placeholder: false };
      groups.push(cur);
    });
    return groups;
  }

  function enhanceTable(table) {
    var thead = table.tHead, tbody = table.tBodies[0];
    if (!thead || !tbody) return;
    var headRow = thead.rows[thead.rows.length - 1];
    var groups = buildGroups(tbody);
    var dataGroups = groups.filter(function (g) { return !g.placeholder; });

    // --- Sortierbare Kopfzellen ---
    Array.prototype.forEach.call(headRow.cells, function (th, idx) {
      if (th.classList.contains('no-sort') || th.textContent.trim() === '') return;
      th.classList.add('sortable');
      var ind = document.createElement('span');
      ind.className = 'sort-ind';
      ind.textContent = '↕';
      th.appendChild(ind);
      th.addEventListener('click', function () { sortByColumn(table, idx, th, headRow); });
    });

    // --- Tokensuche (nur bei größeren Tabellen) ---
    if (dataGroups.length > 4) addSearch(table, tbody);
  }

  function sortByColumn(table, idx, th, headRow) {
    var tbody = table.tBodies[0];
    var groups = buildGroups(tbody);
    var placeholders = groups.filter(function (g) { return g.placeholder; });
    var data = groups.filter(function (g) { return !g.placeholder; });

    var dir = (table.__sortCol === idx && table.__sortDir === 1) ? -1 : 1;
    table.__sortCol = idx; table.__sortDir = dir;

    // numerisch, wenn die Mehrheit der befüllten Zellen numerisch ist
    var numCount = 0, filled = 0;
    data.forEach(function (g) {
      var td = g.main.cells[idx]; if (!td) return;
      var v = cellValue(td);
      if (!v.empty) { filled++; if (v.n !== null) numCount++; }
    });
    var numeric = filled > 0 && numCount >= filled * 0.6;

    data.sort(function (ga, gb) {
      var a = cellValue(ga.main.cells[idx] || document.createElement('td'));
      var b = cellValue(gb.main.cells[idx] || document.createElement('td'));
      if (a.empty && !b.empty) return 1;      // Leere immer nach unten
      if (b.empty && !a.empty) return -1;
      if (numeric) {
        var an = a.n === null ? -Infinity : a.n, bn = b.n === null ? -Infinity : b.n;
        return dir * (an - bn);
      }
      return dir * a.s.localeCompare(b.s, 'de');
    });

    data.forEach(function (g) { g.rows.forEach(function (r) { tbody.appendChild(r); }); });
    placeholders.forEach(function (g) { g.rows.forEach(function (r) { tbody.appendChild(r); }); });

    Array.prototype.forEach.call(headRow.cells, function (c) { c.classList.remove('sort-asc', 'sort-desc'); });
    th.classList.add(dir === 1 ? 'sort-asc' : 'sort-desc');
  }

  function addSearch(table, tbody) {
    var wrap = table.closest('.table-wrap') || table;
    var bar = document.createElement('div');
    bar.className = 'table-toolbar';
    var input = document.createElement('input');
    input.type = 'search';
    input.className = 'table-search';
    input.placeholder = 'Suchen … (mehrere Begriffe möglich)';
    var count = document.createElement('span');
    count.className = 'table-count';
    bar.appendChild(input);
    bar.appendChild(count);
    wrap.parentNode.insertBefore(bar, wrap);

    input.addEventListener('input', function () {
      var tokens = this.value.toLowerCase().split(/\s+/).filter(Boolean);
      var groups = buildGroups(tbody);
      var visible = 0, total = 0;
      groups.forEach(function (g) {
        if (g.placeholder) return;
        total++;
        var text = g.rows.map(function (r) { return r.textContent.toLowerCase(); }).join(' ');
        var show = tokens.every(function (t) { return text.indexOf(t) >= 0; });
        g.rows.forEach(function (r) { r.style.display = show ? '' : 'none'; });
        if (show) visible++;
      });
      count.textContent = tokens.length ? (visible + ' / ' + total) : '';
    });
  }

  document.querySelectorAll('table.data').forEach(enhanceTable);

  // ---------------------------------------------------------------------------
  // Bulk-Verarbeitung mit Fortschrittsbalken (Struktur-Check / KI-Vorbewertung)
  // ---------------------------------------------------------------------------
  function post(url, data) {
    var body = new URLSearchParams(data);
    return fetch(url, {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      credentials: 'same-origin',
      body: body.toString()
    }).then(function (r) { return r.json(); });
  }

  function progressModal(title) {
    var ov = document.createElement('div');
    ov.className = 'modal-overlay';
    ov.innerHTML =
      '<div class="modal"><h3>' + title + '</h3>' +
      '<div class="progress"><div class="progress__bar" style="width:0%"></div></div>' +
      '<p class="progress__label muted">Starte …</p>' +
      '<div class="progress__foot" style="display:none"></div>' +
      '<div style="text-align:right;margin-top:14px"><button class="btn btn--ghost btn--sm" data-close>Abbrechen</button></div></div>';
    document.body.appendChild(ov);
    var bar = ov.querySelector('.progress__bar');
    var label = ov.querySelector('.progress__label');
    var foot = ov.querySelector('.progress__foot');
    var closeBtn = ov.querySelector('[data-close]');
    var cancelled = false;
    closeBtn.addEventListener('click', function () { cancelled = true; ov.remove(); });
    return {
      set: function (done, total, name) {
        bar.style.width = Math.round((done / total) * 100) + '%';
        label.textContent = 'Plan ' + done + ' von ' + total + (name ? ': ' + name : '');
      },
      finish: function (msg) {
        bar.style.width = '100%';
        label.textContent = 'Fertig.';
        foot.style.display = '';
        foot.innerHTML = '<div class="flash success">' + msg + '</div>';
        closeBtn.textContent = 'Schließen & aktualisieren';
        closeBtn.className = 'btn btn--primary btn--sm';
        closeBtn.addEventListener('click', function () { location.reload(); });
      },
      cancelled: function () { return cancelled; }
    };
  }

  async function runBulk(btn) {
    var type = btn.dataset.bulk, url = btn.dataset.url, csrf = btn.dataset.csrf;
    var scope = btn.dataset.scope || 'pending';
    btn.disabled = true;
    var list;
    try {
      var resp = await post(url, { _csrf: csrf, action: 'bulk_list', type: type, scope: scope });
      list = resp.items || [];
    } catch (e) { alert('Konnte Liste nicht laden.'); btn.disabled = false; return; }
    if (!list.length) { alert('Keine offenen Pläne.'); btn.disabled = false; return; }

    var m = progressModal(btn.dataset.title || 'Verarbeitung');
    var below = 0, err = 0, done = 0;
    for (var i = 0; i < list.length; i++) {
      if (m.cancelled()) { btn.disabled = false; return; }
      m.set(i + 1, list.length, list[i].name);
      try {
        var r = await post(url, { _csrf: csrf, action: 'process_one', type: type, bp_id: list[i].id });
        done++;
        if (r.below) below++;
        if (!r.ok) err++;
      } catch (e) { err++; }
    }
    m.finish(done + ' verarbeitet' + (below ? ', davon ' + below + ' unter Mindeststandard' : '') + (err ? ', ' + err + ' Fehler' : '') + '.');
  }

  document.querySelectorAll('[data-bulk]').forEach(function (btn) {
    btn.addEventListener('click', function () { runBulk(btn); });
  });

  // ---------------------------------------------------------------------------
  // PDF-Vorschau im Modal (Businesspläne). Ausgelöst über [data-pdf-url].
  // ---------------------------------------------------------------------------
  (function initPdfModal() {
    function openPdf(url, title) {
      var ov = document.createElement('div');
      ov.className = 'modal-overlay';
      ov.innerHTML =
        '<div class="modal modal--pdf">' +
          '<div class="pdf-modal__head">' +
            '<h3></h3>' +
            '<div class="pdf-modal__actions">' +
              '<a class="btn btn--ghost btn--sm no-spinner" target="_blank" rel="noopener" data-open>Neuer Tab ↗</a>' +
              '<button type="button" class="btn btn--ghost btn--sm no-spinner" data-close>Schließen</button>' +
            '</div>' +
          '</div>' +
          '<div class="pdf-modal__body"><iframe title="Businessplan (PDF)"></iframe></div>' +
        '</div>';
      ov.querySelector('h3').textContent = title || 'Businessplan';
      ov.querySelector('[data-open]').href = url;
      ov.querySelector('iframe').src = url;
      document.body.appendChild(ov);
      document.body.classList.add('modal-open');

      function close() {
        ov.remove();
        document.body.classList.remove('modal-open');
        document.removeEventListener('keydown', onKey);
      }
      function onKey(e) { if (e.key === 'Escape') close(); }
      ov.querySelector('[data-close]').addEventListener('click', close);
      ov.addEventListener('click', function (e) { if (e.target === ov) close(); });
      document.addEventListener('keydown', onKey);
    }

    document.addEventListener('click', function (e) {
      var trg = e.target.closest('[data-pdf-url]');
      if (!trg) return;
      e.preventDefault();
      openPdf(trg.getAttribute('data-pdf-url'), trg.getAttribute('data-pdf-title'));
    });
  })();

  recalc();
})();

// =============================================================================
// Bild-Ablage per Drag & Drop + Zuschnitt (Cropper.js)
// Felder werden serverseitig über image_field() erzeugt: [data-imgdrop] mit
// verstecktem <input type="file"> und <input type="hidden" name="{field}_cropped">.
// Rasterbilder öffnen den Zuschnitt-Dialog (Zoom/Drehen/Crop); SVG/Vektor wird
// unverändert als Datei-Upload übernommen.
// =============================================================================
(function initImageDrop() {
  'use strict';
  var drops = document.querySelectorAll('[data-imgdrop]');
  if (!drops.length) return;

  var hasCropper = typeof window.Cropper !== 'undefined';
  var modal = null; // gemeinsam genutzter Zuschnitt-Dialog

  function buildModal() {
    if (modal) return modal;
    var ov = document.createElement('div');
    ov.className = 'crop-modal';
    ov.hidden = true;
    ov.innerHTML =
      '<div class="crop-modal__box" role="dialog" aria-modal="true" aria-label="Bild zuschneiden">' +
        '<div class="crop-modal__head">Bild zuschneiden</div>' +
        '<div class="crop-modal__stage"><img alt=""></div>' +
        '<div class="crop-modal__tools">' +
          '<button type="button" data-crop="rotl" title="Nach links drehen">⟲</button>' +
          '<button type="button" data-crop="rotr" title="Nach rechts drehen">⟳</button>' +
          '<button type="button" data-crop="zin" title="Vergrößern">＋</button>' +
          '<button type="button" data-crop="zout" title="Verkleinern">－</button>' +
          '<input type="range" data-crop="zoom" min="0" max="1" step="0.01" value="0" aria-label="Zoom">' +
          '<button type="button" data-crop="reset" title="Zurücksetzen">↺</button>' +
        '</div>' +
        '<div class="crop-modal__foot">' +
          '<button type="button" class="btn btn--ghost" data-crop="cancel">Abbrechen</button>' +
          '<button type="button" class="btn btn--primary" data-crop="apply">Übernehmen</button>' +
        '</div>' +
      '</div>';
    document.body.appendChild(ov);
    modal = {
      el: ov,
      img: ov.querySelector('.crop-modal__stage img'),
      zoom: ov.querySelector('[data-crop="zoom"]'),
      cropper: null,
      onApply: null
    };

    var q = function (a) { return ov.querySelector('[data-crop="' + a + '"]'); };
    q('rotl').addEventListener('click', function () { modal.cropper && modal.cropper.rotate(-90); });
    q('rotr').addEventListener('click', function () { modal.cropper && modal.cropper.rotate(90); });
    q('zin').addEventListener('click', function () { modal.cropper && modal.cropper.zoom(0.1); });
    q('zout').addEventListener('click', function () { modal.cropper && modal.cropper.zoom(-0.1); });
    q('reset').addEventListener('click', function () { modal.cropper && modal.cropper.reset(); });
    modal.zoom.addEventListener('input', function () {
      if (modal.cropper) modal.cropper.zoomTo(parseFloat(modal.zoom.value));
    });
    // Zoom über Buttons/Mausrad hält den Slider synchron.
    modal.img.addEventListener('zoom', function (e) {
      if (e && e.detail && typeof e.detail.ratio === 'number') modal.zoom.value = e.detail.ratio;
    });
    q('cancel').addEventListener('click', closeModal);
    q('apply').addEventListener('click', function () {
      if (modal.onApply) modal.onApply();
    });
    ov.addEventListener('click', function (e) { if (e.target === ov) closeModal(); });
    document.addEventListener('keydown', function (e) {
      if (!ov.hidden && e.key === 'Escape') closeModal();
    });
    return modal;
  }

  function closeModal() {
    if (!modal) return;
    if (modal.cropper) { modal.cropper.destroy(); modal.cropper = null; }
    modal.onApply = null;
    modal.el.hidden = true;
    modal.img.removeAttribute('src');
    document.body.classList.remove('modal-open');
  }

  function openCropper(dataUrl, aspect, format, apply) {
    var m = buildModal();
    m.el.hidden = false;
    document.body.classList.add('modal-open');
    m.img.src = dataUrl;
    if (m.cropper) { m.cropper.destroy(); m.cropper = null; }

    // Ergebnis-Handler VOR dem Cropper festlegen – falls die Initialisierung
    // ausnahmsweise scheitert, bleibt „Übernehmen" trotzdem funktionsfähig.
    m.onApply = function () {
      if (!m.cropper) { closeModal(); return; }
      var canvas = m.cropper.getCroppedCanvas({
        maxWidth: 1400, maxHeight: 1400, imageSmoothingEnabled: true, imageSmoothingQuality: 'high'
      });
      if (!canvas) { closeModal(); return; }
      var mime = format === 'jpeg' ? 'image/jpeg' : 'image/png';
      var out = canvas.toDataURL(mime, format === 'jpeg' ? 0.9 : undefined);
      apply(out);
      closeModal();
    };

    m.cropper = new window.Cropper(m.img, {
      viewMode: 1,
      dragMode: 'move',
      aspectRatio: aspect || NaN,
      autoCropArea: 1,
      background: false,
      responsive: true,
      zoomable: true,
      // Zoom-Slider erst hier initialisieren – der Cropper ist jetzt bereit.
      ready: function () {
        var d = m.cropper.getImageData();
        var base = d && d.naturalWidth ? d.width / d.naturalWidth : 1;
        m.zoom.min = (base * 0.5).toFixed(4);
        m.zoom.max = (base * 3).toFixed(4);
        m.zoom.step = (base / 50 || 0.01).toFixed(4);
        m.zoom.value = base;
      }
    });
  }

  function wire(drop) {
    var fileInput = drop.querySelector('.imgdrop__file');
    var dataInput = drop.querySelector('.imgdrop__data');
    var img = drop.querySelector('.imgdrop__img');
    var ph = drop.querySelector('.imgdrop__placeholder');
    var clearBtn = drop.querySelector('[data-imgdrop-clear]');
    var aspect = parseFloat(drop.getAttribute('data-aspect')) || 0;
    var format = drop.getAttribute('data-format') || 'png';
    var originalSrc = img ? (img.getAttribute('src') || '') : '';

    function showPreview(src) {
      if (img) { img.src = src; img.hidden = !src; }
      if (ph) ph.hidden = !!src;
      if (clearBtn) clearBtn.hidden = !src;
    }

    function accept(file) {
      if (!file || file.type.indexOf('image/') !== 0) return;
      // SVG/Vektor: nicht zuschneiden, als Datei-Upload übernehmen.
      if (file.type === 'image/svg+xml' || !hasCropper) {
        dataInput.value = '';
        try {
          var dt = new DataTransfer();
          dt.items.add(file);
          fileInput.files = dt.files;
        } catch (e) { /* Fallback: Datei bleibt im Input, falls per Dialog gewählt */ }
        showPreview(URL.createObjectURL(file));
        return;
      }
      var reader = new FileReader();
      reader.onload = function () {
        openCropper(String(reader.result), aspect, format, function (out) {
          dataInput.value = out;      // zugeschnittenes Ergebnis an den Server
          fileInput.value = '';       // Roh-Datei nicht zusätzlich senden
          showPreview(out);
        });
      };
      reader.readAsDataURL(file);
    }

    drop.addEventListener('click', function (e) {
      if (e.target === clearBtn) return;
      fileInput.click();
    });
    drop.addEventListener('keydown', function (e) {
      if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); fileInput.click(); }
    });
    fileInput.addEventListener('change', function () {
      if (fileInput.files && fileInput.files[0]) accept(fileInput.files[0]);
    });
    ['dragenter', 'dragover'].forEach(function (ev) {
      drop.addEventListener(ev, function (e) { e.preventDefault(); drop.classList.add('is-drag'); });
    });
    ['dragleave', 'dragend'].forEach(function (ev) {
      drop.addEventListener(ev, function () { drop.classList.remove('is-drag'); });
    });
    drop.addEventListener('drop', function (e) {
      e.preventDefault();
      drop.classList.remove('is-drag');
      if (e.dataTransfer && e.dataTransfer.files && e.dataTransfer.files[0]) accept(e.dataTransfer.files[0]);
    });
    if (clearBtn) {
      clearBtn.addEventListener('click', function (e) {
        e.stopPropagation();
        dataInput.value = '';
        fileInput.value = '';
        showPreview(originalSrc);
      });
    }
  }

  drops.forEach(wire);
})();
