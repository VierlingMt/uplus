<?php
/**
 * Projektpräsentation „Unternehmen Plus" – Bildschirmansicht.
 *
 * Zeigt die Foliensammlung für ein wählbares Wettbewerbsjahr als Deck mit
 * Vor/Zurück-Navigation und Vollbild („Präsentieren"). Dynamische Folien
 * (Titel/Jahr, Projektablauf, Preise, Team, Kontakt, Sponsoren) ziehen ihre
 * Inhalte live aus dem jeweiligen Zyklus; die Textfolien pflegt die Verwaltung
 * direkt hier (je Jahr überschreibbar, mit globaler Vorlage als Rückfall).
 * Export als PDF erfolgt über presentation_print.php.
 */

declare(strict_types=1);

Access::requireRead('presentation');

$cycles = Cycle::all();
$cycleId = (int) input('cycle', 0);
if ($cycleId <= 0 || Cycle::find($cycleId) === null) {
    $cycleId = Cycle::activeId();
}

// ---- Textfolien speichern / zurücksetzen (nur Verwaltung mit Schreibrecht) ----
if (is_post()) {
    Access::requireWrite('presentation');
    Csrf::check();
    $action = (string) input('action');
    $key    = (string) input('slide_key');
    $def    = Presentation::slideDef($key);

    if ($def && Presentation::isEditable($def['type'])) {
        if ($action === 'save_slide') {
            Presentation::saveText(
                $key,
                $cycleId,
                trim((string) input('title')),
                trim((string) input('subtitle')),
                (string) input('body')
            );
            Audit::log('presentation.slide_save', 'Präsentationsfolie gespeichert: ' . $key, 'cycle', $cycleId);
            flash('success', 'Folie gespeichert.');
        } elseif ($action === 'reset_slide') {
            Presentation::resetText($key, $cycleId);
            Audit::log('presentation.slide_reset', 'Präsentationsfolie auf Vorlage zurückgesetzt: ' . $key, 'cycle', $cycleId);
            flash('success', 'Folie auf die Vorlage zurückgesetzt.');
        }
    }
    redirect(url('presentation', ['cycle' => $cycleId]));
}

$canWrite = Access::canWrite('presentation');
$ctx  = Presentation::context($cycleId);
$year = $ctx['year_label'];

// Folien vorab rendern (inkl. Textdaten je Folie – für Anzeige und Edit-Modal).
$slides = [];
foreach (Presentation::SLIDES as $def) {
    $editable = Presentation::isEditable($def['type']);
    $text = $editable ? Presentation::text($def['key'], $cycleId)
                      : ['title' => $def['title'], 'subtitle' => '', 'body' => '', 'is_override' => false];
    $slides[] = [
        'def'      => $def,
        'text'     => $text,
        'editable' => $editable,
        'html'     => Presentation::renderSlide($def, $ctx, $text),
    ];
}

ob_start(); ?>
<link rel="stylesheet" href="<?= asset('css/presentation.css') ?>">
<div class="page-head">
  <h1>Präsentation</h1>
  <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
    <?php if (count($cycles) > 1): ?>
      <form method="get" action="<?= url('presentation') ?>" style="margin:0">
        <input type="hidden" name="r" value="presentation">
        <select name="cycle" onchange="this.form.submit()" title="Wettbewerbsjahr wählen">
          <?php foreach ($cycles as $cy): ?>
            <option value="<?= (int) $cy['id'] ?>" <?= (int) $cy['id'] === $cycleId ? 'selected' : '' ?>>
              <?= e($cy['year_label']) ?><?= $cy['is_active'] ? ' •' : '' ?>
            </option>
          <?php endforeach; ?>
        </select>
      </form>
    <?php endif; ?>
    <a class="btn btn--teal" href="<?= url('presentation_print', ['cycle' => $cycleId]) ?>" target="_blank" rel="noopener">🖨 Als PDF / Drucken</a>
  </div>
</div>

<?php if (!$cycleId): ?>
  <div class="card"><div class="card__body">
    <p class="muted">Noch kein Wettbewerbsjahr angelegt. Unter „Wettbewerbsjahre" ein Jahr anlegen, dann steht die Präsentation zur Verfügung.</p>
  </div></div>
<?php else: ?>

<div class="ps-deck" id="psDeck" data-count="<?= count($slides) ?>">
  <div class="ps-stage">
    <?php foreach ($slides as $i => $s): ?>
      <div class="ps-slide" data-idx="<?= $i ?>"<?= $i === 0 ? '' : ' hidden' ?>>
        <?= $s['html'] ?>
        <?php if ($canWrite && $s['editable']): ?>
          <button type="button" class="ps-edit-btn" data-modal-open="psEdit_<?= e($s['def']['key']) ?>" title="Diese Folie bearbeiten">✎ Bearbeiten</button>
          <?php if (!empty($s['text']['is_override'])): ?>
            <span class="ps-override" title="Für <?= e($year) ?> individuell angepasst (weicht von der Vorlage ab)">angepasst</span>
          <?php endif; ?>
        <?php endif; ?>
      </div>
    <?php endforeach; ?>
  </div>

  <div class="ps-controls">
    <button type="button" class="ps-nav" data-ps-prev aria-label="Vorherige Folie">‹</button>
    <div class="ps-dots" data-ps-dots>
      <?php foreach ($slides as $i => $s): ?>
        <button type="button" class="ps-dot<?= $i === 0 ? ' is-active' : '' ?>" data-ps-go="<?= $i ?>" title="<?= e($s['text']['title'] ?: $s['def']['title']) ?>"><span></span></button>
      <?php endforeach; ?>
    </div>
    <button type="button" class="ps-nav" data-ps-next aria-label="Nächste Folie">›</button>
    <span class="ps-counter"><span data-ps-cur>1</span> / <?= count($slides) ?></span>
    <button type="button" class="ps-nav ps-nav--full" data-ps-full title="Vollbild präsentieren">⛶ Vollbild</button>
  </div>
</div>

<p class="muted mt" style="font-size:13px">
  Pfeiltasten ← → blättern, <kbd>F</kbd> startet die Vollbild-Präsentation.
  Dynamische Folien (Jahr, Projektablauf, Preise, Team, Kontakt, Sponsoren) füllen sich automatisch aus dem gewählten Wettbewerbsjahr.
  <?php if ($canWrite): ?>Die Textfolien lassen sich je Jahr über „Bearbeiten" pflegen.<?php endif; ?>
</p>

<?php if ($canWrite): ?>
  <?php foreach ($slides as $s): if (!$s['editable']) continue; $k = $s['def']['key']; $t = $s['text']; ?>
    <div class="modal-overlay" id="psEdit_<?= e($k) ?>" hidden>
      <div class="modal modal--form" role="dialog" aria-modal="true">
        <div class="modal__head">
          <h3>Folie „<?= e($s['def']['title']) ?>" bearbeiten <span class="muted" style="font-weight:400">· <?= e($year) ?></span></h3>
          <button type="button" class="modal__close" data-modal-close aria-label="Schließen">&times;</button>
        </div>
        <form method="post" action="<?= url('presentation', ['cycle' => $cycleId]) ?>" class="modal__body">
          <?= Csrf::field() ?>
          <input type="hidden" name="action" value="save_slide">
          <input type="hidden" name="slide_key" value="<?= e($k) ?>">
          <div class="field"><label>Titel</label><input type="text" name="title" value="<?= e($t['title']) ?>"></div>
          <div class="field"><label>Untertitel</label><input type="text" name="subtitle" value="<?= e($t['subtitle']) ?>"></div>
          <div class="field">
            <label>Text</label>
            <textarea name="body" rows="10" style="font-family:inherit"><?= e($t['body']) ?></textarea>
            <div class="muted" style="font-size:12px;margin-top:4px">Einfaches Markdown: <code>- </code> für Aufzählungen, <code>**fett**</code>, Leerzeile = neuer Absatz.</div>
          </div>
          <div class="modal__foot" style="justify-content:space-between">
            <?php if (!empty($t['is_override'])): ?>
              <button type="submit" name="action" value="reset_slide" class="btn btn--ghost" formnovalidate
                      data-confirm="Diese Folie für <?= e($year) ?> auf die globale Vorlage zurücksetzen?">↺ Auf Vorlage zurücksetzen</button>
            <?php else: ?><span></span><?php endif; ?>
            <span style="display:flex;gap:8px">
              <button type="button" class="btn btn--ghost" data-modal-close>Abbrechen</button>
              <button class="btn btn--primary">Speichern</button>
            </span>
          </div>
        </form>
      </div>
    </div>
  <?php endforeach; ?>
<?php endif; ?>

<style>
  /* --- Bildschirm-Chrome des Decks (Navigation, Vollbild, Edit-Buttons) --- */
  .ps-deck { max-width: 1100px; margin: 0 auto; }
  .ps-stage { position: relative; }
  .ps-slide { margin: 0 auto; }
  .ps-edit-btn { position: absolute; top: 12px; right: 12px; z-index: 5; border: 0; cursor: pointer;
    background: rgba(0,53,148,.9); color: #fff; font: 600 13px/1 "Chivo", system-ui, sans-serif;
    padding: 8px 12px; border-radius: 8px; font-family: system-ui, sans-serif; }
  .ps-edit-btn:hover { background: #003594; }
  .ps-override { position: absolute; top: 14px; right: 118px; z-index: 5; background: #f4c430; color: #3a2d00;
    font: 700 11px/1 system-ui, sans-serif; padding: 5px 8px; border-radius: 6px; }
  .ps-controls { display: flex; align-items: center; gap: 12px; justify-content: center; margin: 16px 0 4px; flex-wrap: wrap; }
  .ps-nav { border: 1px solid var(--line, #d8dee9); background: #fff; cursor: pointer; border-radius: 10px;
    width: 42px; height: 42px; font-size: 22px; line-height: 1; color: #003594; }
  .ps-nav--full { width: auto; padding: 0 14px; font-size: 14px; font-weight: 600; }
  .ps-nav:hover { background: #eef3fb; }
  .ps-counter { font-variant-numeric: tabular-nums; color: #5b6472; font-size: 14px; }
  .ps-dots { display: flex; gap: 6px; flex-wrap: wrap; max-width: 420px; justify-content: center; }
  .ps-dot { border: 0; background: none; cursor: pointer; padding: 6px 2px; }
  .ps-dot span { display: block; width: 22px; height: 5px; border-radius: 3px; background: #cdd6e5; transition: background .15s; }
  .ps-dot:hover span { background: #9fb2d6; }
  .ps-dot.is-active span { background: #003594; }
  kbd { background: #eef1f6; border: 1px solid #d8dee9; border-radius: 4px; padding: 1px 5px; font-size: 12px; }

  /* Vollbild-Präsentation */
  .ps-deck:fullscreen { max-width: none; background: #0b1626; display: flex; flex-direction: column;
    justify-content: center; padding: 2vh 3vw; }
  .ps-deck:fullscreen .ps-stage { flex: 0 0 auto; }
  .ps-deck:fullscreen .ps-slide { max-width: min(94vw, 166vh); box-shadow: 0 20px 60px rgba(0,0,0,.5); }
  .ps-deck:fullscreen .ps-edit-btn, .ps-deck:fullscreen .ps-override { display: none; }
  .ps-deck:fullscreen .ps-controls { margin-top: 3vh; }
  .ps-deck:fullscreen .ps-nav { background: rgba(255,255,255,.12); color: #fff; border-color: transparent; }
  .ps-deck:fullscreen .ps-counter { color: #cdd9f2; }
  .ps-deck:fullscreen .ps-dot span { background: rgba(255,255,255,.3); }
  .ps-deck:fullscreen .ps-dot.is-active span { background: #fff; }
</style>

<script>
(function () {
  var deck = document.getElementById('psDeck');
  if (!deck) return;
  var slides = Array.prototype.slice.call(deck.querySelectorAll('.ps-slide'));
  var dots   = Array.prototype.slice.call(deck.querySelectorAll('[data-ps-go]'));
  var curEl  = deck.querySelector('[data-ps-cur]');
  var n = slides.length, i = 0;

  function show(idx) {
    i = Math.max(0, Math.min(n - 1, idx));
    slides.forEach(function (s, k) { s.hidden = k !== i; });
    dots.forEach(function (d, k) { d.classList.toggle('is-active', k === i); });
    if (curEl) curEl.textContent = (i + 1);
  }
  deck.querySelector('[data-ps-prev]').addEventListener('click', function () { show(i - 1); });
  deck.querySelector('[data-ps-next]').addEventListener('click', function () { show(i + 1); });
  dots.forEach(function (d) { d.addEventListener('click', function () { show(+d.getAttribute('data-ps-go')); }); });

  var full = deck.querySelector('[data-ps-full]');
  function toggleFull() {
    if (document.fullscreenElement) { document.exitFullscreen(); }
    else if (deck.requestFullscreen) { deck.requestFullscreen(); }
  }
  if (full) full.addEventListener('click', toggleFull);

  document.addEventListener('keydown', function (e) {
    // Nicht blättern, während ein Bearbeiten-Dialog offen ist oder ein Feld fokussiert.
    if (document.querySelector('.modal-overlay:not([hidden])')) return;
    var t = e.target.tagName;
    if (t === 'INPUT' || t === 'TEXTAREA' || t === 'SELECT') return;
    if (e.key === 'ArrowLeft') { show(i - 1); }
    else if (e.key === 'ArrowRight' || e.key === ' ') { show(i + 1); e.preventDefault(); }
    else if (e.key === 'Home') { show(0); }
    else if (e.key === 'End') { show(n - 1); }
    else if (e.key === 'f' || e.key === 'F') { toggleFull(); }
  });
})();
</script>
<?php endif; ?>
<?php
$content = ob_get_clean();
$title = 'Präsentation';
require APP_PATH . '/pages/_layout.php';
