// Unternehmen Plus – Frontend-JS
(function () {
  'use strict';

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
    btn.disabled = true;
    var list;
    try {
      var resp = await post(url, { _csrf: csrf, action: 'bulk_list', type: type });
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

  recalc();
})();
