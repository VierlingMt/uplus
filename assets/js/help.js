// Unternehmen Plus – Interaktive Hilfe (F1, kontextbasiert, Tokensuche mit
// Highlight) und geführte Tour.
//
// GRUNDLAGE (wichtig): Hilfe und Tour aktualisieren sich automatisch mit der
// App. Die Tour wird bei jedem Start frisch aus dem DOM erzeugt – jeder Baustein
// bringt seine Erklärung über `data-tour` selbst mit (siehe helpers.php:
// tour_attrs()), und sichtbare Bereiche (Karten) werden zusätzlich automatisch
// erkannt. Das Hilfe-Panel zeigt die Themen zur aktuellen Route (aus der echten
// Navigation) plus eine live aus dem DOM erzeugte „Auf dieser Seite"-Liste.
(function () {
  'use strict';

  var dataEl = document.getElementById('help-data');
  if (!dataEl) return;

  var DATA = {};
  try { DATA = JSON.parse(dataEl.textContent || '{}'); } catch (e) { DATA = {}; }
  var TOPICS = DATA.topics || [];                 // [{title, html, text, route, source}]
  var ROUTE = DATA.route || '';
  var ROUTE_LABEL = DATA.routeLabel || '';
  var TOUR_INTRO = DATA.tourIntro || '';

  // ---------------------------------------------------------------------------
  // Hilfsfunktionen
  // ---------------------------------------------------------------------------
  function el(tag, cls, html) {
    var n = document.createElement(tag);
    if (cls) n.className = cls;
    if (html != null) n.innerHTML = html;
    return n;
  }
  function textOf(node) { return node ? (node.textContent || '').replace(/\s+/g, ' ').trim() : ''; }
  function escapeHtml(s) {
    return String(s).replace(/[&<>"]/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c];
    });
  }
  function escapeRx(s) { return s.replace(/[.*+?^${}()|[\]\\]/g, '\\$&'); }
  function tokenize(q) { return (q || '').toLowerCase().split(/\s+/).filter(Boolean); }

  function isVisible(node) {
    if (!node || node.hidden) return false;
    var r = node.getBoundingClientRect();
    if (r.width < 2 || r.height < 2) return false;
    // Außerhalb des sichtbaren Bereichs nach links/rechts (z. B. Off-Canvas-Menü
    // auf dem Handy) überspringen – vertikal wird zum Element gescrollt.
    if (r.right < 8 || r.left > window.innerWidth - 8) return false;
    var s = window.getComputedStyle(node);
    return s.visibility !== 'hidden' && s.display !== 'none' && s.opacity !== '0';
  }

  // ---------------------------------------------------------------------------
  // Highlight (Tokensuche): Fundstellen im gerenderten Inhalt gelb markieren.
  // ---------------------------------------------------------------------------
  function highlight(root, tokens) {
    if (!tokens.length) return;
    var rx = new RegExp('(' + tokens.map(escapeRx).join('|') + ')', 'gi');
    var walker = document.createTreeWalker(root, NodeFilter.SHOW_TEXT, null);
    var texts = [];
    while (walker.nextNode()) texts.push(walker.currentNode);
    texts.forEach(function (tn) {
      if (!rx.test(tn.nodeValue)) return;
      rx.lastIndex = 0;
      var frag = document.createDocumentFragment();
      var last = 0, m;
      while ((m = rx.exec(tn.nodeValue)) !== null) {
        if (m.index > last) frag.appendChild(document.createTextNode(tn.nodeValue.slice(last, m.index)));
        var mk = el('mark', 'help-hit'); mk.textContent = m[0];
        frag.appendChild(mk);
        last = m.index + m[0].length;
        if (m.index === rx.lastIndex) rx.lastIndex++; // Endlosschleife bei Leertreffern vermeiden
      }
      if (last < tn.nodeValue.length) frag.appendChild(document.createTextNode(tn.nodeValue.slice(last)));
      tn.parentNode.replaceChild(frag, tn);
    });
  }

  // ---------------------------------------------------------------------------
  // Panel aufbauen (einmalig, verzögert beim ersten Öffnen)
  // ---------------------------------------------------------------------------
  var panel, backdrop, searchInput, bodyEl, built = false;

  function build() {
    if (built) return;
    built = true;

    backdrop = el('div', 'help-backdrop');
    backdrop.hidden = true;
    backdrop.addEventListener('click', closePanel);

    panel = el('aside', 'help-panel');
    panel.hidden = true;
    panel.setAttribute('role', 'dialog');
    panel.setAttribute('aria-label', 'Hilfe');

    var head = el('div', 'help-panel__head');
    var titleWrap = el('div');
    titleWrap.appendChild(el('div', 'ctx', 'Hilfe' + (ROUTE_LABEL ? ' · ' + escapeHtml(ROUTE_LABEL) : '')));
    titleWrap.appendChild(el('h2', null, 'Wobei kann ich helfen?'));
    head.appendChild(titleWrap);
    var closeBtn = el('button', 'help-panel__close', '&times;');
    closeBtn.type = 'button';
    closeBtn.setAttribute('aria-label', 'Hilfe schließen');
    closeBtn.addEventListener('click', closePanel);
    head.appendChild(closeBtn);

    var searchWrap = el('div', 'help-panel__search');
    var field = el('label', 'help-search-field');
    searchInput = el('input');
    searchInput.type = 'search';
    searchInput.placeholder = 'In der Hilfe suchen … (mehrere Begriffe möglich)';
    searchInput.setAttribute('aria-label', 'In der Hilfe suchen');
    searchInput.addEventListener('input', function () { renderBody(searchInput.value); });
    field.appendChild(searchInput);
    searchWrap.appendChild(field);

    var tourBtn = el('button', 'btn btn--teal help-panel__tourbtn no-spinner', '🧭 Tour starten');
    tourBtn.type = 'button';
    tourBtn.addEventListener('click', function () { closePanel(); startTour(); });
    searchWrap.appendChild(tourBtn);

    bodyEl = el('div', 'help-panel__body');

    panel.appendChild(head);
    panel.appendChild(searchWrap);
    panel.appendChild(bodyEl);
    document.body.appendChild(backdrop);
    document.body.appendChild(panel);
  }

  // Ein Thema als aufklappbares Element (optional aufgeklappt + hervorgehoben).
  function topicEl(topic, opts) {
    opts = opts || {};
    var d = el('details', 'help-topic');
    if (opts.open) d.open = true;
    var sum = el('summary');
    var badgeCls = 'help-topic__badge' + (topic.route === ROUTE && topic.route ? ' is-page' : '');
    if (opts.showSource && topic.source) badgeCls += ' is-src';
    var badgeLabel = opts.showSource && topic.source
      ? topic.source
      : (topic.route === ROUTE && topic.route ? 'Diese Seite' : (topic.route ? 'Bereich' : 'Allgemein'));
    var titleSpan = el('span', null, escapeHtml(topic.title));
    sum.appendChild(titleSpan);
    sum.appendChild(el('span', badgeCls, escapeHtml(badgeLabel)));
    d.appendChild(sum);
    var content = el('div', 'help-topic__content', topic.html);
    d.appendChild(content);
    if (opts.tokens && opts.tokens.length) {
      highlight(titleSpan, opts.tokens);
      highlight(content, opts.tokens);
    }
    return d;
  }

  // „Auf dieser Seite" – live aus den (gleichen) Tour-Bausteinen erzeugt.
  function onPageBlock() {
    var steps = collectSteps(true); // ohne Intro
    if (!steps.length) return null;
    var wrap = el('div', 'help-onpage');
    wrap.appendChild(el('div', 'help-onpage__title', 'Auf dieser Seite'));
    var ul = el('ul');
    steps.forEach(function (s, i) {
      var li = el('li');
      var a = el('a');
      a.href = '#';
      a.innerHTML = '<span class="idx">' + (i + 1) + '</span><span><strong>'
        + escapeHtml(s.title) + '</strong> <span class="txt">' + escapeHtml(s.text) + '</span></span>';
      a.addEventListener('click', function (ev) {
        ev.preventDefault();
        closePanel();
        startTour(i);
      });
      li.appendChild(a);
      ul.appendChild(li);
    });
    wrap.appendChild(ul);
    return wrap;
  }

  function renderBody(query) {
    var tokens = tokenize(query);
    bodyEl.innerHTML = '';

    if (tokens.length) {
      // Suchmodus: alle Themen, Treffer zuerst hervorgehoben.
      var hits = TOPICS.filter(function (t) {
        var hay = t.text.toLowerCase();
        return tokens.every(function (tok) { return hay.indexOf(tok) >= 0; });
      });
      var info = el('div', 'help-panel__hint',
        hits.length ? (hits.length + ' Treffer für „' + escapeHtml(query.trim()) + '"')
                    : 'Keine Treffer.');
      bodyEl.appendChild(info);
      if (!hits.length) {
        bodyEl.appendChild(el('div', 'help-empty', 'Nichts gefunden. Versuche einen anderen Begriff.'));
        return;
      }
      // Treffer der aktuellen Seite nach oben.
      hits.sort(function (a, b) {
        return (b.route === ROUTE) - (a.route === ROUTE);
      });
      hits.forEach(function (t) {
        bodyEl.appendChild(topicEl(t, { open: true, tokens: tokens, showSource: true }));
      });
      return;
    }

    // Standardansicht: „Auf dieser Seite" + Themen dieser Route + Allgemeines.
    var op = onPageBlock();
    if (op) bodyEl.appendChild(op);

    var pageTopics = TOPICS.filter(function (t) { return t.route === ROUTE && t.route; });
    var commonTopics = TOPICS.filter(function (t) { return !t.route; });

    if (pageTopics.length) {
      bodyEl.appendChild(el('div', 'help-group__title', 'Zu dieser Seite'));
      pageTopics.forEach(function (t, i) {
        bodyEl.appendChild(topicEl(t, { open: i === 0 && !op }));
      });
    }
    bodyEl.appendChild(el('div', 'help-group__title', 'Allgemein'));
    commonTopics.forEach(function (t) { bodyEl.appendChild(topicEl(t, {})); });
  }

  // ---------------------------------------------------------------------------
  // Panel öffnen/schließen
  // ---------------------------------------------------------------------------
  function openPanel() {
    build();
    if (!panel.hidden && panel.classList.contains('is-open')) { closePanel(); return; }
    backdrop.hidden = false;
    panel.hidden = false;
    renderBody('');
    // Reflow erzwingen, damit die Transition greift.
    void panel.offsetWidth;
    backdrop.classList.add('is-open');
    panel.classList.add('is-open');
    document.body.classList.add('modal-open');
    setTimeout(function () { searchInput.focus(); }, 60);
  }
  function closePanel() {
    if (!panel || panel.hidden) return;
    panel.classList.remove('is-open');
    backdrop.classList.remove('is-open');
    if (!tourActive) document.body.classList.remove('modal-open');
    setTimeout(function () {
      panel.hidden = true; backdrop.hidden = true;
      if (searchInput) searchInput.value = '';
    }, 240);
  }

  // ===========================================================================
  // Geführte Tour
  // ===========================================================================
  var tourActive = false, tourIdx = 0, tourSteps = [], overlay, spot, tip, repoRAF = 0;

  // Schritte frisch aus dem DOM sammeln (auto-aktualisierend).
  // withIntro=false liefert nur die echten Element-Schritte (für die Panel-Liste).
  function collectSteps(skipIntro) {
    var steps = [], seen = [];

    // 1) Ausdrücklich markierte Bausteine (data-tour), in Dokumentreihenfolge.
    Array.prototype.forEach.call(document.querySelectorAll('[data-tour]'), function (node) {
      if (!isVisible(node)) return;
      if (node.closest('.help-panel')) return;
      var order = parseFloat(node.getAttribute('data-tour-order'));
      steps.push({
        node: node,
        title: node.getAttribute('data-tour-title') || textOf(node).slice(0, 40) || 'Bereich',
        text: node.getAttribute('data-tour') || '',
        order: isNaN(order) ? 500 : order
      });
      seen.push(node);
    });

    // 2) Sichtbare Karten im Inhalt automatisch ergänzen (auch ohne Markierung),
    //    damit jede Seite ohne Zusatzaufwand erklärt wird.
    var content = document.querySelector('.content');
    if (content) {
      var pageHead = content.querySelector('.page-head, h1');
      if (pageHead && isVisible(pageHead) && seen.indexOf(pageHead) < 0
          && !steps.some(function (s) { return s.node.contains(pageHead); })) {
        steps.push({
          node: pageHead,
          title: textOf(pageHead.querySelector('h1') || pageHead).slice(0, 60) || 'Diese Seite',
          text: 'Der Titel und die Hauptaktion dieser Seite.',
          order: 20
        });
        seen.push(pageHead);
      }
      Array.prototype.forEach.call(content.querySelectorAll('.card'), function (card) {
        if (seen.indexOf(card) >= 0) return;
        if (card.querySelector('[data-tour]')) return;      // innerer Schritt deckt die Karte ab
        if (card.closest('.modal-overlay, .help-panel')) return;
        if (seen.some(function (n) { return n.contains(card) || card.contains(n); })) return;
        if (!isVisible(card)) return;
        var head = card.querySelector('.card__head');
        var title = head ? textOf(head) : (textOf(card.querySelector('h1,h2,h3')) || 'Bereich');
        steps.push({ node: card, title: title.slice(0, 60), text: 'Bereich „' + title.slice(0, 50) + '".', order: 100 });
        seen.push(card);
      });
    }

    // Stabil sortieren: Ordnungszahl, dann Dokumentreihenfolge.
    steps.forEach(function (s, i) { s._i = i; });
    steps.sort(function (a, b) {
      if (a.order !== b.order) return a.order - b.order;
      var pos = a.node.compareDocumentPosition(b.node);
      if (pos & Node.DOCUMENT_POSITION_FOLLOWING) return -1;
      if (pos & Node.DOCUMENT_POSITION_PRECEDING) return 1;
      return a._i - b._i;
    });

    if (!skipIntro && TOUR_INTRO) {
      steps.unshift({ node: null, title: 'Willkommen 👋', text: TOUR_INTRO, order: -1 });
    }
    return steps;
  }

  function buildTourDom() {
    if (overlay) return;
    overlay = el('div', 'tour-overlay');
    overlay.hidden = true;
    spot = el('div', 'tour-spot');
    tip = el('div', 'tour-tooltip');
    tip.innerHTML =
      '<button type="button" class="tour-tooltip__end" aria-label="Tour beenden">&times;</button>' +
      '<div class="tour-tooltip__step"></div>' +
      '<h3 class="tour-tooltip__title"></h3>' +
      '<p class="tour-tooltip__text"></p>' +
      '<div class="tour-tooltip__foot">' +
        '<div class="tour-tooltip__dots" aria-hidden="true"></div>' +
        '<button type="button" class="btn btn--ghost btn--sm no-spinner" data-tour-prev>Zurück</button>' +
        '<button type="button" class="btn btn--teal btn--sm no-spinner" data-tour-next>Weiter</button>' +
      '</div>';
    overlay.appendChild(spot);
    overlay.appendChild(tip);
    document.body.appendChild(overlay);

    tip.querySelector('.tour-tooltip__end').addEventListener('click', endTour);
    tip.querySelector('[data-tour-prev]').addEventListener('click', function () { go(tourIdx - 1); });
    tip.querySelector('[data-tour-next]').addEventListener('click', function () {
      if (tourIdx >= tourSteps.length - 1) endTour(); else go(tourIdx + 1);
    });
  }

  function startTour(startIdx) {
    build();
    buildTourDom();
    tourSteps = collectSteps(false);
    if (!tourSteps.length) return;
    // startIdx bezieht sich auf die Liste OHNE Intro → um den Intro-Offset schieben.
    var offset = (tourSteps[0] && tourSteps[0].node === null) ? 1 : 0;
    tourIdx = (typeof startIdx === 'number') ? Math.min(startIdx + offset, tourSteps.length - 1) : 0;
    tourActive = true;
    overlay.hidden = false;
    document.body.classList.add('modal-open', 'tour-running');
    // Dots aufbauen
    var dots = tip.querySelector('.tour-tooltip__dots');
    dots.innerHTML = '';
    tourSteps.forEach(function () { dots.appendChild(el('i')); });
    window.addEventListener('scroll', reposition, true);
    window.addEventListener('resize', reposition);
    document.addEventListener('keydown', tourKeys, true);
    go(tourIdx);
  }

  function go(idx) {
    if (idx < 0 || idx >= tourSteps.length) return;
    // altes Ziel freigeben
    if (tourSteps[tourIdx] && tourSteps[tourIdx].node) tourSteps[tourIdx].node.classList.remove('tour-target');
    tourIdx = idx;
    var step = tourSteps[idx];

    tip.querySelector('.tour-tooltip__step').textContent = 'Schritt ' + (idx + 1) + ' von ' + tourSteps.length;
    tip.querySelector('.tour-tooltip__title').textContent = step.title;
    tip.querySelector('.tour-tooltip__text').textContent = step.text;
    tip.querySelector('[data-tour-prev]').disabled = idx === 0;
    tip.querySelector('[data-tour-next]').textContent = idx >= tourSteps.length - 1 ? 'Fertig' : 'Weiter';
    Array.prototype.forEach.call(tip.querySelector('.tour-tooltip__dots').children, function (d, i) {
      d.classList.toggle('is-on', i === idx);
    });

    if (step.node) {
      step.node.classList.add('tour-target');
      try { step.node.scrollIntoView({ behavior: 'smooth', block: 'center', inline: 'nearest' }); } catch (e) { step.node.scrollIntoView(); }
      // Nach dem (evtl. sanften) Scrollen positionieren – mehrfach, bis es ruht.
      var tries = 0;
      var settle = function () {
        reposition();
        if (++tries < 14) repoRAF = requestAnimationFrame(settle);
      };
      cancelAnimationFrame(repoRAF);
      settle();
    } else {
      // Intro: Spotlight aus, Tooltip zentriert.
      spot.style.opacity = '0';
      centerTip();
    }
  }

  function reposition() {
    var step = tourSteps[tourIdx];
    if (!step || !step.node) { spot.style.opacity = '0'; centerTip(); return; }
    var r = step.node.getBoundingClientRect();
    var pad = 8;
    spot.style.opacity = '1';
    spot.style.top = Math.max(4, r.top - pad) + 'px';
    spot.style.left = Math.max(4, r.left - pad) + 'px';
    spot.style.width = Math.min(window.innerWidth - 8, r.width + pad * 2) + 'px';
    spot.style.height = (r.height + pad * 2) + 'px';
    placeTip(r);
  }

  function placeTip(r) {
    if (window.innerWidth <= 600) return; // Mobil: per CSS unten fixiert
    var tw = tip.offsetWidth || 340, th = tip.offsetHeight || 160, gap = 14;
    var top, left;
    if (r.bottom + gap + th < window.innerHeight) {
      top = r.bottom + gap;                         // unterhalb
    } else if (r.top - gap - th > 0) {
      top = r.top - gap - th;                       // oberhalb
    } else {
      top = Math.max(12, (window.innerHeight - th) / 2); // mittig
    }
    left = r.left + r.width / 2 - tw / 2;            // an der Elementmitte ausrichten
    left = Math.max(12, Math.min(left, window.innerWidth - tw - 12));
    tip.style.top = top + 'px';
    tip.style.left = left + 'px';
  }

  function centerTip() {
    if (window.innerWidth <= 600) return;
    var tw = tip.offsetWidth || 340, th = tip.offsetHeight || 160;
    tip.style.top = Math.max(12, (window.innerHeight - th) / 2) + 'px';
    tip.style.left = ((window.innerWidth - tw) / 2) + 'px';
  }

  function tourKeys(e) {
    if (!tourActive) return;
    if (e.key === 'Escape') { e.preventDefault(); endTour(); }
    else if (e.key === 'ArrowRight' || e.key === 'Enter') { e.preventDefault(); if (tourIdx >= tourSteps.length - 1) endTour(); else go(tourIdx + 1); }
    else if (e.key === 'ArrowLeft') { e.preventDefault(); go(tourIdx - 1); }
  }

  function endTour() {
    if (!tourActive) return;
    tourActive = false;
    cancelAnimationFrame(repoRAF);
    if (tourSteps[tourIdx] && tourSteps[tourIdx].node) tourSteps[tourIdx].node.classList.remove('tour-target');
    overlay.hidden = true;
    document.body.classList.remove('tour-running');
    if (!panel || panel.hidden) document.body.classList.remove('modal-open');
    window.removeEventListener('scroll', reposition, true);
    window.removeEventListener('resize', reposition);
    document.removeEventListener('keydown', tourKeys, true);
  }

  // ---------------------------------------------------------------------------
  // Auslöser: F1 / ?, Buttons, globale API
  // ---------------------------------------------------------------------------
  document.addEventListener('keydown', function (e) {
    if (e.key === 'F1') { e.preventDefault(); if (!tourActive) openPanel(); return; }
    // „?" (Shift+/), aber nicht während einer Eingabe.
    if (e.key === '?' && !/^(INPUT|TEXTAREA|SELECT)$/.test((e.target.tagName || '')) && !e.target.isContentEditable) {
      if (!tourActive) { e.preventDefault(); openPanel(); }
      return;
    }
    if (e.key === 'Escape' && !tourActive && panel && !panel.hidden) closePanel();
  });

  document.addEventListener('click', function (e) {
    if (e.target.closest('[data-help-open]')) { e.preventDefault(); openPanel(); }
    else if (e.target.closest('[data-tour-start]')) { e.preventDefault(); startTour(); }
  });

  window.UplusHelp = { open: openPanel, close: closePanel, startTour: startTour };
})();
