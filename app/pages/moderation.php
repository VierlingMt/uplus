<?php
/**
 * Moderationskärtchen „Unternehmen Plus" – Bildschirmansicht.
 *
 * Werkzeug für die Moderation des PitchDays: die Projektleitung blättert durch
 * ihre Moderationskärtchen (DIN A5 quer) – als digitaler Spickzettel (inkl.
 * Vollbild) oder gedruckt über moderation_print.php. Freie Textkarten pflegt sie
 * hier direkt (anlegen, bearbeiten, sortieren, löschen); Bausteinkarten (Gäste,
 * Redner, Jury, Ablauf, Teams, Preise, Zahlen) ziehen ihren Inhalt live aus dem
 * jeweiligen Wettbewerbsjahr. Eine Vorlage spielt den kompletten Ablauf ein.
 */

declare(strict_types=1);

Access::requireRead('moderation');

$cycles  = Cycle::all();
$cycleId = (int) input('cycle', 0);
if ($cycleId <= 0 || Cycle::find($cycleId) === null) {
    $cycleId = Cycle::activeId();
}

// ---- Karten pflegen (nur Verwaltung mit Schreibrecht) ----
if (is_post()) {
    Access::requireWrite('moderation');
    Csrf::check();
    if (!$cycleId) {
        flash('error', 'Zuerst ein Wettbewerbsjahr anlegen.');
        redirect(url('moderation'));
    }
    $action = (string) input('action');

    if ($action === 'seed_cards') {
        if (ModerationCards::count($cycleId) === 0) {
            $n = ModerationCards::seed($cycleId);
            Audit::log('moderation.seed', "Moderationskärtchen aus Vorlage erstellt ($n)", 'cycle', $cycleId);
            flash('success', "$n Moderationskärtchen aus der Vorlage erstellt.");
        }
    } elseif ($action === 'save_card') {
        $id = ModerationCards::save(
            $cycleId,
            (int) input('id'),
            (string) input('card_type'),
            trim((string) input('title')),
            trim((string) input('subtitle')),
            (string) input('body')
        );
        Audit::log('moderation.card_save', 'Moderationskarte gespeichert', 'cycle', $cycleId);
        flash('success', 'Karte gespeichert.');
    } elseif ($action === 'delete_card') {
        ModerationCards::delete((int) input('id'), $cycleId);
        Audit::log('moderation.card_delete', 'Moderationskarte gelöscht', 'cycle', $cycleId);
        flash('success', 'Karte gelöscht.');
    } elseif ($action === 'move_card') {
        ModerationCards::move($cycleId, (int) input('id'), input('dir') === 'up' ? 'up' : 'down');
    }
    redirect(url('moderation', ['cycle' => $cycleId]));
}

$canWrite = Access::canWrite('moderation');
$ctx   = ModerationCards::context($cycleId);
$year  = $ctx['year_label'];
$cards = $cycleId ? ModerationCards::all($cycleId) : [];
$total = count($cards);

// Auswahl der Kartentypen fürs Anlegen/Bearbeiten.
$typeOptions = ['text' => ModerationCards::typeLabel('text')];
foreach (ModerationCards::BLOCKS as $k => $label) {
    $typeOptions[$k] = $label;
}

ob_start(); ?>
<link rel="stylesheet" href="<?= asset('css/moderation.css') ?>">
<div class="page-head">
  <h1>🗂 Moderationskärtchen</h1>
  <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
    <?php if (count($cycles) > 1): ?>
      <form method="get" action="<?= url('moderation') ?>" style="margin:0">
        <input type="hidden" name="r" value="moderation">
        <select name="cycle" onchange="this.form.submit()" title="Wettbewerbsjahr wählen">
          <?php foreach ($cycles as $cy): ?>
            <option value="<?= (int) $cy['id'] ?>" <?= (int) $cy['id'] === $cycleId ? 'selected' : '' ?>>
              <?= e($cy['year_label']) ?><?= $cy['is_active'] ? ' •' : '' ?>
            </option>
          <?php endforeach; ?>
        </select>
      </form>
    <?php endif; ?>
    <?php if ($total > 0): ?>
      <a class="btn btn--teal" href="<?= url('moderation_print', ['cycle' => $cycleId]) ?>" target="_blank" rel="noopener">🖨 Als PDF (A5 quer)</a>
    <?php endif; ?>
  </div>
</div>

<?php if (!$cycleId): ?>
  <div class="card"><div class="card__body">
    <p class="muted">Noch kein Wettbewerbsjahr angelegt. Unter „Wettbewerbsjahre" ein Jahr anlegen, dann stehen die Moderationskärtchen zur Verfügung.</p>
  </div></div>

<?php elseif ($total === 0): ?>
  <div class="card"><div class="card__body">
    <p>Für <strong><?= e($year) ?></strong> gibt es noch keine Moderationskärtchen.</p>
    <?php if ($canWrite): ?>
      <div style="display:flex;gap:8px;margin-top:12px;flex-wrap:wrap">
        <form method="post" action="<?= url('moderation') ?>" data-confirm="Kompletten Moderationsablauf aus der Vorlage erstellen?">
          <?= Csrf::field() ?><input type="hidden" name="action" value="seed_cards"><input type="hidden" name="cycle" value="<?= $cycleId ?>">
          <button class="btn btn--teal">🗂 Aus Vorlage erstellen</button>
        </form>
        <button type="button" class="btn btn--ghost" data-modal-open="mcEdit">+ Eigene Karte</button>
      </div>
      <p class="muted" style="font-size:13px;margin-top:12px">Die Vorlage enthält den kompletten, bewährten Moderationsablauf (Begrüßung, Dank, Ehrengäste, Ablauf, Grußworte, Jury, Teams, Preise, Ausklang …) und ist danach frei anpassbar.</p>
    <?php else: ?>
      <p class="muted">Die Moderationskärtchen legt die Projektleitung an.</p>
    <?php endif; ?>
  </div></div>

<?php else: ?>

<?php if ($canWrite): ?>
  <div style="display:flex;gap:8px;margin-bottom:14px;flex-wrap:wrap">
    <button type="button" class="btn btn--teal btn--sm" data-modal-open="mcEdit">+ Karte</button>
    <span class="muted" style="font-size:13px;align-self:center">Reihenfolge über ↑/↓ · Bausteinkarten (Gäste, Redner, Jury, Ablauf, Teams, Preise, Zahlen) füllen sich automatisch aus dem Wettbewerbsjahr.</span>
  </div>
<?php endif; ?>

<div class="mc-deck" id="mcDeck" data-count="<?= $total ?>">
  <div class="mc-stage">
    <?php foreach ($cards as $i => $card): ?>
      <div class="mc-card" data-idx="<?= $i ?>"<?= $i === 0 ? '' : ' hidden' ?>>
        <?= ModerationCards::renderCard($card, $ctx, $i, $total) ?>
        <?php if ($canWrite): ?>
          <?php $fill = e(json_encode([
            'id' => (int) $card['id'], 'card_type' => $card['card_type'], 'title' => $card['title'],
            'subtitle' => $card['subtitle'], 'body' => $card['body'],
          ], JSON_UNESCAPED_UNICODE)); ?>
          <div class="mc-tools">
            <form method="post" action="<?= url('moderation') ?>" style="display:inline">
              <?= Csrf::field() ?><input type="hidden" name="action" value="move_card"><input type="hidden" name="cycle" value="<?= $cycleId ?>"><input type="hidden" name="id" value="<?= (int) $card['id'] ?>"><input type="hidden" name="dir" value="up">
              <button class="mc-toolbtn" title="nach vorne" <?= $i === 0 ? 'disabled' : '' ?>>↑</button>
            </form>
            <form method="post" action="<?= url('moderation') ?>" style="display:inline">
              <?= Csrf::field() ?><input type="hidden" name="action" value="move_card"><input type="hidden" name="cycle" value="<?= $cycleId ?>"><input type="hidden" name="id" value="<?= (int) $card['id'] ?>"><input type="hidden" name="dir" value="down">
              <button class="mc-toolbtn" title="nach hinten" <?= $i === $total - 1 ? 'disabled' : '' ?>>↓</button>
            </form>
            <button type="button" class="mc-toolbtn" data-modal-open="mcEdit" data-fill="<?= $fill ?>" title="Karte bearbeiten">✎</button>
            <form method="post" action="<?= url('moderation') ?>" style="display:inline" data-confirm="Diese Karte löschen?">
              <?= Csrf::field() ?><input type="hidden" name="action" value="delete_card"><input type="hidden" name="cycle" value="<?= $cycleId ?>"><input type="hidden" name="id" value="<?= (int) $card['id'] ?>">
              <button class="mc-toolbtn mc-toolbtn--del" title="Karte löschen">×</button>
            </form>
          </div>
        <?php endif; ?>
      </div>
    <?php endforeach; ?>
  </div>

  <div class="mc-controls">
    <button type="button" class="mc-nav" data-mc-prev aria-label="Vorherige Karte">‹</button>
    <div class="mc-dots" data-mc-dots>
      <?php foreach ($cards as $i => $card): ?>
        <button type="button" class="mc-dot<?= $i === 0 ? ' is-active' : '' ?>" data-mc-go="<?= $i ?>" title="<?= e((string) ($card['title'] ?: ModerationCards::typeLabel((string) $card['card_type']))) ?>"><span></span></button>
      <?php endforeach; ?>
    </div>
    <button type="button" class="mc-nav" data-mc-next aria-label="Nächste Karte">›</button>
    <span class="mc-counter"><span data-mc-cur>1</span> / <?= $total ?></span>
    <button type="button" class="mc-nav mc-nav--full" data-mc-full title="Vollbild">⛶ Vollbild</button>
  </div>
</div>

<p class="muted mt" style="font-size:13px">
  Pfeiltasten ← → blättern, <kbd>F</kbd> startet die Vollbild-Ansicht – ideal als digitaler Spickzettel am Rednerpult.
  <?php if ($canWrite): ?>Über „🖨 Als PDF (A5 quer)" die Karten ausdrucken.<?php endif; ?>
</p>

<style>
  /* --- Bildschirm-Chrome des Kartendecks --- */
  .mc-deck { max-width: 1000px; margin: 0 auto; }
  .mc-stage { position: relative; }
  .mc-card { margin: 0 auto; }
  .mc-tools { position: absolute; top: 12px; right: 12px; z-index: 5; display: flex; gap: 6px; }
  .mc-toolbtn { border: 0; cursor: pointer; background: rgba(0,53,148,.9); color: #fff;
    font: 700 15px/1 system-ui, sans-serif; width: 32px; height: 32px; border-radius: 8px; }
  .mc-toolbtn:hover { background: #003594; }
  .mc-toolbtn:disabled { opacity: .35; cursor: default; }
  .mc-toolbtn--del { background: rgba(197,48,48,.9); }
  .mc-toolbtn--del:hover { background: #c53030; }
  .mc-controls { display: flex; align-items: center; gap: 12px; justify-content: center; margin: 16px 0 4px; flex-wrap: wrap; }
  .mc-nav { border: 1px solid var(--line, #d8dee9); background: #fff; cursor: pointer; border-radius: 10px;
    width: 42px; height: 42px; font-size: 22px; line-height: 1; color: #003594; }
  .mc-nav--full { width: auto; padding: 0 14px; font-size: 14px; font-weight: 600; }
  .mc-nav:hover { background: #eef3fb; }
  .mc-counter { font-variant-numeric: tabular-nums; color: #5b6472; font-size: 14px; }
  .mc-dots { display: flex; gap: 6px; flex-wrap: wrap; max-width: 420px; justify-content: center; }
  .mc-dot { border: 0; background: none; cursor: pointer; padding: 6px 2px; }
  .mc-dot span { display: block; width: 20px; height: 5px; border-radius: 3px; background: #cdd6e5; transition: background .15s; }
  .mc-dot:hover span { background: #9fb2d6; }
  .mc-dot.is-active span { background: #003594; }
  kbd { background: #eef1f6; border: 1px solid #d8dee9; border-radius: 4px; padding: 1px 5px; font-size: 12px; }

  /* Vollbild-Ansicht (digitaler Spickzettel) */
  .mc-deck:fullscreen { max-width: none; background: #0b1626; display: flex; flex-direction: column;
    justify-content: center; padding: 2vh 3vw; }
  .mc-deck:fullscreen .mc-stage { flex: 0 0 auto; }
  .mc-deck:fullscreen .mc-card { max-width: min(94vw, 133vh); box-shadow: 0 20px 60px rgba(0,0,0,.5); }
  .mc-deck:fullscreen .mc-tools { display: none; }
  .mc-deck:fullscreen .mc-controls { margin-top: 3vh; }
  .mc-deck:fullscreen .mc-nav { background: rgba(255,255,255,.12); color: #fff; border-color: transparent; }
  .mc-deck:fullscreen .mc-counter { color: #cdd9f2; }
  .mc-deck:fullscreen .mc-dot span { background: rgba(255,255,255,.3); }
  .mc-deck:fullscreen .mc-dot.is-active span { background: #fff; }
</style>

<script>
(function () {
  var deck = document.getElementById('mcDeck');
  if (!deck) return;
  var cards = Array.prototype.slice.call(deck.querySelectorAll('.mc-card'));
  var dots  = Array.prototype.slice.call(deck.querySelectorAll('[data-mc-go]'));
  var curEl = deck.querySelector('[data-mc-cur]');
  var n = cards.length, i = 0;

  function show(idx) {
    i = Math.max(0, Math.min(n - 1, idx));
    cards.forEach(function (s, k) { s.hidden = k !== i; });
    dots.forEach(function (d, k) { d.classList.toggle('is-active', k === i); });
    if (curEl) curEl.textContent = (i + 1);
  }
  deck.querySelector('[data-mc-prev]').addEventListener('click', function () { show(i - 1); });
  deck.querySelector('[data-mc-next]').addEventListener('click', function () { show(i + 1); });
  dots.forEach(function (d) { d.addEventListener('click', function () { show(+d.getAttribute('data-mc-go')); }); });

  var full = deck.querySelector('[data-mc-full]');
  function toggleFull() {
    if (document.fullscreenElement) { document.exitFullscreen(); }
    else if (deck.requestFullscreen) { deck.requestFullscreen(); }
  }
  if (full) full.addEventListener('click', toggleFull);

  document.addEventListener('keydown', function (e) {
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

<?php // Bearbeiten-/Anlegen-Modal – für Leer- und Deck-Zustand gemeinsam. ?>
<?php if ($canWrite && $cycleId): ?>
  <div class="modal-overlay" id="mcEdit" hidden>
    <div class="modal modal--form" role="dialog" aria-modal="true">
      <div class="modal__head">
        <h3 data-modal-title data-title-new="Neue Karte<?= $year !== '' ? ' · ' . e($year) : '' ?>" data-title-edit="Karte bearbeiten<?= $year !== '' ? ' · ' . e($year) : '' ?>">Karte<?= $year !== '' ? ' · ' . e($year) : '' ?></h3>
        <button type="button" class="modal__close" data-modal-close aria-label="Schließen">&times;</button>
      </div>
      <form method="post" action="<?= url('moderation', ['cycle' => $cycleId]) ?>" class="modal__body" data-modal-form>
        <?= Csrf::field() ?>
        <input type="hidden" name="action" value="save_card">
        <input type="hidden" name="id" value="">
        <div class="field">
          <label>Kartentyp</label>
          <select name="card_type" data-mc-type>
            <?php foreach ($typeOptions as $val => $label): ?>
              <option value="<?= e($val) ?>"><?= e($label) ?><?= $val !== 'text' ? ' — Baustein (Live-Daten)' : '' ?></option>
            <?php endforeach; ?>
          </select>
          <div class="muted" style="font-size:12px;margin-top:4px" data-mc-typehint>Freie Textkarte: nur dein Text erscheint auf der Karte.</div>
        </div>
        <div class="field"><label>Titel</label><input type="text" name="title" value=""></div>
        <div class="field"><label>Untertitel</label><input type="text" name="subtitle" value=""></div>
        <div class="field">
          <label>Text / Moderations-Notiz</label>
          <textarea name="body" rows="8" style="font-family:inherit"></textarea>
          <div class="muted" style="font-size:12px;margin-top:4px">Einfaches Markdown: <code>- </code> für Aufzählungen, <code>**fett**</code>, Leerzeile = neuer Absatz. Bei Bausteinkarten steht dieser Text als Notiz <em>über</em> den Live-Daten.</div>
        </div>
        <div class="modal__foot" style="justify-content:flex-end">
          <span style="display:flex;gap:8px">
            <button type="button" class="btn btn--ghost" data-modal-close>Abbrechen</button>
            <button class="btn btn--primary">Speichern</button>
          </span>
        </div>
      </form>
    </div>
  </div>
  <script>
  (function () {
    // Typ-Hinweis aktualisieren. Das globale Modal-System (app.js) füllt die
    // Felder und löst dabei 'change' aus – so stimmt der Hinweis auch nach dem
    // Befüllen einer bestehenden Karte.
    var typeSel = document.querySelector('#mcEdit [data-mc-type]');
    var hint = document.querySelector('#mcEdit [data-mc-typehint]');
    if (!typeSel || !hint) return;
    var updHint = function () {
      hint.textContent = typeSel.value === 'text'
        ? 'Freie Textkarte: nur dein Text erscheint auf der Karte.'
        : 'Bausteinkarte: die Daten kommen live aus dem System – dein Text steht als Notiz darüber.';
    };
    typeSel.addEventListener('change', updHint);
    updHint();
  })();
  </script>
<?php endif; ?>
<?php
$content = ob_get_clean();
$title = 'Moderationskärtchen';
require APP_PATH . '/pages/_layout.php';
