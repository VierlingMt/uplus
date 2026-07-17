/**
 * Moderationskärtchen – Auto-Layout (kein Scrollbalken).
 *
 * Jede Karte (DIN A5 quer) soll ihren Inhalt vollständig und ohne Scrollbalken
 * zeigen. Dazu:
 *   1. Schrift verkleinern: der Faktor --mc-scale wird so weit reduziert, bis der
 *      Inhalt in die Karte passt (bis zu einer lesbaren Untergrenze).
 *   2. Reicht das nicht, wird der überstehende Rest auf eine zweite (Fortsetzungs-)
 *      Karte umgebrochen – so oft wie nötig.
 *
 * Weil Bildschirm-Karte und A5-Druckseite dasselbe Seitenverhältnis (210/148)
 * haben und die Schrift in cqw (Anteil der Kartenbreite) skaliert, gilt die einmal
 * berechnete Anpassung proportional für jede Kartengröße – Ansicht, Vollbild und
 * Druck/PDF gleichermaßen. Deshalb genügt EIN Layout-Durchlauf nach dem Laden.
 */
(function () {
  'use strict';

  var MIN_SCALE = 0.45;   // untere Grenze der Schrift-Skalierung (Lesbarkeit)
  var STEP = 0.03;        // Schrittweite beim Verkleinern

  function overflowing(card) {
    var c = card.querySelector('.mc-content');
    if (!c) return false;
    return (c.scrollHeight - c.clientHeight) > 1;
  }

  /** Schrift der Karte so weit verkleinern, bis der Inhalt passt (bis MIN_SCALE). */
  function shrinkToFit(card) {
    var inner = card.querySelector('.mc-card__inner');
    if (!inner) return;
    inner.style.setProperty('--mc-scale', '1');
    if (!overflowing(card)) return;
    var s = 1;
    while (overflowing(card) && s > MIN_SCALE) {
      s -= STEP;
      inner.style.setProperty('--mc-scale', s.toFixed(3));
    }
  }

  /** Letztes „Zeilen"-Element (Listeneintrag/Tabellenzeile) im Inhalt. */
  function lastLeaf(content) {
    var ls = content.querySelectorAll('li, tr');
    return ls.length ? ls[ls.length - 1] : null;
  }

  /**
   * Zielcontainer im Fortsetzungs-Inhalt finden bzw. (strukturgleich) anlegen,
   * damit ein verschobener Eintrag in derselben Art Liste/Tabelle landet.
   */
  function matchingContainer(leaf, destContent) {
    if (leaf.tagName === 'TR') {
      var srcTable = leaf.closest('table');
      var cls = srcTable ? srcTable.className : '';
      var tables = destContent.querySelectorAll('table');
      for (var i = 0; i < tables.length; i++) {
        if (tables[i].className === cls) {
          return tables[i].tBodies[0] || tables[i].appendChild(document.createElement('tbody'));
        }
      }
      var table = document.createElement('table');
      table.className = cls;
      var tb = document.createElement('tbody');
      table.appendChild(tb);
      destContent.insertBefore(table, destContent.firstChild);
      return tb;
    }
    var src = leaf.parentNode;                 // ul / ol
    var sel = src.tagName.toLowerCase();
    var cands = destContent.querySelectorAll(sel);
    for (var j = 0; j < cands.length; j++) {
      if (cands[j].className === src.className) return cands[j];
    }
    var dest = document.createElement(sel);
    dest.className = src.className;
    destContent.insertBefore(dest, destContent.firstChild);
    return dest;
  }

  /**
   * Überstehende Zeilen der Karte auf eine neue Fortsetzungskarte auslagern.
   * Gibt die neue Karte zurück.
   */
  function splitCard(card) {
    var content = card.querySelector('.mc-content');
    var next = card.cloneNode(true);
    var nextContent = next.querySelector('.mc-content');
    nextContent.innerHTML = '';

    // Fortsetzungskarte: Bearbeiten-Werkzeuge entfernen (kein eigener Datensatz),
    // Kennzeichnung „· Forts." im Titel.
    var tools = next.querySelector('.mc-tools');
    if (tools) tools.parentNode.removeChild(tools);
    var h = next.querySelector('.mc-h');
    if (h && !/·\s*Forts\./.test(h.textContent)) {
      h.textContent = h.textContent.replace(/\s*·\s*Forts\.\s*$/, '') + ' · Forts.';
    }
    card.parentNode.insertBefore(next, card.nextSibling);

    var guard = 0;
    while (overflowing(card) && guard++ < 2000) {
      var leaf = lastLeaf(content);
      if (!leaf) break;
      var dest = matchingContainer(leaf, nextContent);
      dest.insertBefore(leaf, dest.firstChild);   // vom Ende genommen, vorne eingefügt → Reihenfolge bleibt
    }

    // Nummerierung fortlaufender Listen (z. B. Pitch-Reihenfolge) fortführen.
    Array.prototype.forEach.call(nextContent.querySelectorAll('ol'), function (ol) {
      var orig = null, os = content.querySelectorAll('ol');
      for (var i = 0; i < os.length; i++) {
        if (os[i].className === ol.className) { orig = os[i]; break; }
      }
      ol.setAttribute('start', (orig ? orig.children.length : 0) + 1);
    });
    return next;
  }

  /** Zähler oben („X / Y") und Seitenzahl unten („Seite X / Y") neu durchnummerieren. */
  function renumber(deck) {
    var cards = deck.querySelectorAll('.mc-card');
    var n = cards.length;
    Array.prototype.forEach.call(cards, function (card, i) {
      var step = card.querySelector('.mc-step');
      if (step) step.textContent = (i + 1) + ' / ' + n;
      var pageno = card.querySelector('.mc-pageno');
      if (pageno) pageno.textContent = 'Seite ' + (i + 1) + ' / ' + n;
    });
  }

  /** Alle Karten eines Decks anpassen (schrumpfen + ggf. umbrechen). */
  function apply(deck) {
    if (!deck) return;
    // Für die Messung müssen alle Karten sichtbar sein (in der Deck-Ansicht sind
    // alle außer der ersten per [hidden]/display:none ausgeblendet und daher nicht
    // messbar). Wir blenden sie zum Messen ein; die aufrufende Deck-Logik
    // (show(0)) blendet sie unmittelbar danach synchron wieder aus – kein Flackern.
    Array.prototype.forEach.call(deck.querySelectorAll('.mc-card'), function (c) { c.hidden = false; });
    var cards = Array.prototype.slice.call(deck.querySelectorAll('.mc-card'));
    cards.forEach(function (card) {
      shrinkToFit(card);
      var passes = 0;
      while (overflowing(card) && passes++ < 8) {
        var next = splitCard(card);
        shrinkToFit(card);
        shrinkToFit(next);
        card = next;   // Rest weiter umbrechen, falls die Fortsetzung noch übersteht
      }
    });
    renumber(deck);
  }

  window.MCLayout = { apply: apply, renumber: renumber };
})();
