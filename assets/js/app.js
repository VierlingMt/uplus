// Unternehmen Plus – minimales Frontend-JS
(function () {
  'use strict';

  // Bestaetigung vor Aktionen + Lade-Spinner am Submit-Button
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
      // erst nach dem Absenden deaktivieren, damit der Button-Wert mitgesendet wird
      setTimeout(function () { btn.disabled = true; }, 0);
    }
  });

  // Punkte-Slider/Inputs: Live-Summe (falls vorhanden)
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
  recalc();
})();
