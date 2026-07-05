// Unternehmen Plus – minimales Frontend-JS
(function () {
  'use strict';

  // Bestaetigung vor Loeschaktionen
  document.addEventListener('submit', function (e) {
    var f = e.target;
    if (f.matches('[data-confirm]')) {
      if (!window.confirm(f.getAttribute('data-confirm'))) {
        e.preventDefault();
      }
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
