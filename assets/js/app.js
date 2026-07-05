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
  recalc();
})();
