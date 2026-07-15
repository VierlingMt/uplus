<?php
/**
 * Mediengalerie – schöne Galerie je Wettbewerbsjahr.
 *
 * - Jede:r angemeldete Nutzer:in sieht alle Galerien (alle Jahre).
 * - Hochladen (mit Mehrfachauswahl) für das eigene Wettbewerbsjahr.
 * - Bearbeiten/Löschen nur der eigenen Beiträge; Projektleitung & Admin alle.
 */
declare(strict_types=1);

Access::requireRead('gallery');

// --- Schreibaktionen ------------------------------------------------------
if (is_post()) {
    // Übersteigt ein (Mehrfach-)Upload die post_max_size, verwirft PHP den
    // gesamten Request-Body: $_POST UND $_FILES sind leer. Ohne Sonderfall
    // würde die anschließende CSRF-Prüfung eine irreführende Meldung zeigen.
    // Die Ziel-Jahr-ID reist in der Formular-Action als Query mit ($_GET).
    if (!$_POST && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
        flash('error', 'Die Auswahl war insgesamt zu groß (Serverlimit '
            . (string) ini_get('post_max_size') . '). Bitte lade weniger oder kleinere Dateien auf einmal hoch.');
        redirect(url('gallery', ['cycle' => (int) input('cycle', 0)]));
    }

    Access::requireWrite('gallery');
    Csrf::check();
    $action = (string) input('action');

    // Mehrere Dateien auf einmal hochladen.
    if ($action === 'upload') {
        $cycleId = (int) input('cycle_id');
        $cycle   = Cycle::find($cycleId);
        if (!$cycle) {
            flash('error', 'Wettbewerbsjahr nicht gefunden.');
            redirect(url('gallery'));
        }
        if (!Media::canUploadTo($cycleId)) {
            flash('error', 'In dieses Wettbewerbsjahr darfst du nicht hochladen.');
            redirect(url('gallery', ['cycle' => $cycleId]));
        }
        $files = $_FILES['files'] ?? null;
        $names = is_array($files['name'] ?? null) ? $files['name'] : [];
        $hasAny = false;
        foreach ($names as $nm) { if ((string) $nm !== '') { $hasAny = true; break; } }
        if (!$hasAny) {
            flash('error', 'Bitte mindestens eine Datei auswählen.');
            redirect(url('gallery', ['cycle' => $cycleId]));
        }
        if (!Media::ensureDir()) {
            flash('error', 'Upload-Ordner fehlt oder ist nicht beschreibbar.');
            redirect(url('gallery', ['cycle' => $cycleId]));
        }

        $count = count($names);
        $ok = 0; $skipped = 0;

        for ($i = 0; $i < $count; $i++) {
            $err  = (int) ($files['error'][$i] ?? UPLOAD_ERR_NO_FILE);
            $name = (string) ($files['name'][$i] ?? '');
            $tmp  = (string) ($files['tmp_name'][$i] ?? '');
            $sz   = (int) ($files['size'][$i] ?? 0);

            if ($err === UPLOAD_ERR_NO_FILE || $name === '') {
                continue;
            }
            if ($err !== UPLOAD_ERR_OK || !is_uploaded_file($tmp)) {
                $skipped++;
                continue;
            }
            if ($sz > Media::maxBytes()) {
                $skipped++;
                continue;
            }
            $type = Media::typeFor($name);
            if ($type === null) {
                $skipped++;
                continue;
            }
            // Bilder zusätzlich validieren (echtes Bild statt umbenannter Datei).
            if ($type['kind'] === Media::KIND_IMAGE && @getimagesize($tmp) === false) {
                $skipped++;
                continue;
            }

            $stored = bin2hex(random_bytes(12)) . '.' . $type['ext'];
            if (!move_uploaded_file($tmp, Media::dir() . '/' . $stored)) {
                $skipped++;
                continue;
            }
            $taken = Media::extractTakenAt(Media::dir() . '/' . $stored, $type['kind'], $type['mime']);
            Database::insert(
                'INSERT INTO media_items (cycle_id, uploaded_by, kind, stored_name, original_name, mime, size_bytes, taken_at)
                 VALUES (?,?,?,?,?,?,?,?)',
                [$cycleId, Auth::id(), $type['kind'], $stored, mb_substr($name, 0, 255), $type['mime'], $sz, $taken]
            );
            // Vorschau-/Ansichtsvarianten erzeugen (nur Bilder; best effort).
            Media::buildDerivatives(['stored_name' => $stored, 'kind' => $type['kind']]);
            $ok++;
        }

        Audit::log('gallery.upload', $ok . ' Medien hochgeladen (' . (string) $cycle['year_label'] . ')', 'cycle', $cycleId);
        if ($ok > 0) {
            $msg = $ok . ($ok === 1 ? ' Datei' : ' Dateien') . ' hochgeladen.';
            if ($skipped > 0) { $msg .= ' ' . $skipped . ' übersprungen (Format/Größe).'; }
            flash('success', $msg);
        } else {
            flash('error', 'Keine Datei übernommen. Erlaubt: ' . Media::allowedHint() . '.');
        }
        redirect(url('gallery', ['cycle' => $cycleId]));
    }

    // Titel/Bildunterschrift speichern.
    if ($action === 'save') {
        $item = Media::find((int) input('id'));
        if (!$item) {
            flash('error', 'Medium nicht gefunden.');
            redirect(url('gallery'));
        }
        if (!Media::canEdit($item)) {
            flash('error', 'Nur eigene Beiträge sind bearbeitbar.');
            redirect(url('gallery', ['cycle' => (int) $item['cycle_id']]));
        }
        $title = trim((string) input('title'));
        Database::run('UPDATE media_items SET title = ? WHERE id = ?', [$title !== '' ? mb_substr($title, 0, 255) : null, (int) $item['id']]);
        Audit::log('gallery.update', 'Medium bearbeitet #' . (int) $item['id'], 'media', (int) $item['id']);
        flash('success', 'Gespeichert.');
        redirect(url('gallery', ['cycle' => (int) $item['cycle_id']]));
    }

    // Einzelnes Medium löschen.
    if ($action === 'delete') {
        $item = Media::find((int) input('id'));
        if ($item && Media::canEdit($item)) {
            Media::deleteFiles($item);
            Database::run('DELETE FROM media_items WHERE id = ?', [(int) $item['id']]);
            Audit::log('gallery.delete', 'Medium gelöscht #' . (int) $item['id'], 'media', (int) $item['id']);
            flash('success', 'Medium gelöscht.');
        } else {
            flash('error', $item ? 'Nur eigene Beiträge sind löschbar.' : 'Medium nicht gefunden.');
        }
        redirect(url('gallery', ['cycle' => (int) ($item['cycle_id'] ?? 0)]));
    }

    // Mehrfachauswahl löschen.
    if ($action === 'bulk_delete') {
        $ids = array_map('intval', (array) input('ids', []));
        $cycleId = (int) input('cycle_id');
        $del = 0;
        foreach ($ids as $id) {
            if ($id <= 0) { continue; }
            $item = Media::find($id);
            if ($item && Media::canEdit($item)) {
                Media::deleteFiles($item);
                Database::run('DELETE FROM media_items WHERE id = ?', [$id]);
                $del++;
            }
        }
        Audit::log('gallery.bulk_delete', $del . ' Medien gelöscht', 'cycle', $cycleId);
        flash($del > 0 ? 'success' : 'error', $del > 0 ? ($del . ' Medien gelöscht.') : 'Nichts gelöscht.');
        redirect(url('gallery', ['cycle' => $cycleId]));
    }

    redirect(url('gallery'));
}

// --- Anzeige --------------------------------------------------------------
$counts = Media::counts();
$active = Cycle::active();

// Jahre für die Auswahl: alle Zyklen (nach Jahr), damit auch leere Jahre
// (v. a. das aktive, in das man hochladen kann) erreichbar sind.
$cycles = Cycle::all();
if (!$cycles) {
    ob_start(); ?>
    <div class="page-head"><h1>Mediengalerie</h1></div>
    <div class="card"><div class="card__body muted">Noch kein Wettbewerbsjahr angelegt.</div></div>
    <?php
    $content = ob_get_clean();
    $title = 'Mediengalerie';
    require APP_PATH . '/pages/_layout.php';
    return;
}

// Ausgewähltes Jahr bestimmen (Query > aktiv > erstes mit Medien > erstes).
$selId = (int) input('cycle');
$byId = [];
foreach ($cycles as $c) { $byId[(int) $c['id']] = $c; }
if (!isset($byId[$selId])) {
    $selId = 0;
    if ($active && isset($byId[(int) $active['id']])) {
        $selId = (int) $active['id'];
    } else {
        foreach ($cycles as $c) {
            if (($counts[(int) $c['id']] ?? 0) > 0) { $selId = (int) $c['id']; break; }
        }
        if ($selId === 0) { $selId = (int) $cycles[0]['id']; }
    }
}
$selCycle = $byId[$selId];
$items = Media::forCycle($selId);
$canUpload = Media::canUploadTo($selId);
$canManage = Media::canManage();

// post_max_size in Bytes – für die clientseitige Vorprüfung des Uploads.
$postMaxBytes = (static function (): int {
    $v = trim((string) ini_get('post_max_size'));
    if ($v === '') { return 0; }
    $num  = (int) $v;
    return match (strtolower(substr($v, -1))) {
        'g'     => $num * 1024 * 1024 * 1024,
        'm'     => $num * 1024 * 1024,
        'k'     => $num * 1024,
        default => (int) $v,
    };
})();

// Anzeige-Datum: Aufnahmedatum, ersatzweise Upload-Zeit.
$mediaDate = static function (array $m): string {
    $src = !empty($m['taken_at']) ? (string) $m['taken_at'] : (string) ($m['created_at'] ?? '');
    $ts = $src !== '' ? strtotime($src) : false;
    return $ts ? date('d.m.Y', $ts) : '';
};

// Vorbefüllung fürs Bearbeiten-Modal.
$fill = fn(array $m) => e(json_encode([
    'id'    => (int) $m['id'],
    'title' => (string) ($m['title'] ?? ''),
], JSON_UNESCAPED_UNICODE));

ob_start(); ?>
<div class="page-head">
  <h1>Mediengalerie</h1>
  <?php if ($canUpload): ?>
    <button type="button" class="btn btn--teal" data-modal-open="uploadModal">+ Medien hochladen</button>
  <?php endif; ?>
</div>

<div class="gal-years"<?= tour_attrs('Jahr wählen', 'Wechsle hier zwischen den Wettbewerbsjahren. Jede:r sieht die Galerien aller Jahre.', 20) ?>>
  <?php foreach ($cycles as $c): $cid = (int) $c['id']; $n = $counts[$cid] ?? 0; ?>
    <a href="<?= url('gallery', ['cycle' => $cid]) ?>"
       class="gal-year<?= $cid === $selId ? ' gal-year--active' : '' ?>">
      <?= e($c['year_label']) ?>
      <?php if (!empty($c['is_active'])): ?><span class="gal-year__dot" title="Aktuelles Jahr">●</span><?php endif; ?>
      <span class="gal-year__count"><?= (int) $n ?></span>
    </a>
  <?php endforeach; ?>
</div>

<?php if ($selCycle['title']): ?>
  <p class="muted" style="margin:-6px 0 16px"><?= e($selCycle['title']) ?></p>
<?php endif; ?>

<?php if (!$items): ?>
  <div class="card"><div class="card__body muted" style="text-align:center;padding:40px 20px">
    <div style="font-size:38px;line-height:1">📸</div>
    <p style="margin:10px 0 0">Noch keine Medien für <strong><?= e($selCycle['year_label']) ?></strong>.</p>
    <?php if ($canUpload): ?>
      <p style="margin:6px 0 0"><button type="button" class="btn btn--teal btn--sm" data-modal-open="uploadModal">Jetzt Bilder &amp; Videos hochladen</button></p>
    <?php endif; ?>
  </div></div>
<?php else: ?>

  <form method="post" action="<?= url('gallery') ?>" id="bulkForm" data-confirm="Ausgewählte Medien wirklich löschen?">
    <?= Csrf::field() ?>
    <input type="hidden" name="action" value="bulk_delete">
    <input type="hidden" name="cycle_id" value="<?= $selId ?>">

    <div class="gal-toolbar">
      <span class="muted"><?= count($items) ?> <?= count($items) === 1 ? 'Medium' : 'Medien' ?></span>
      <div class="gal-toolbar__sp"></div>
      <button type="button" class="btn btn--ghost btn--sm no-spinner" data-gal-select-toggle>Mehrfachauswahl</button>
      <div class="gal-bulk" hidden data-gal-bulk>
        <span class="muted"><span data-gal-count>0</span> ausgewählt</span>
        <button class="btn btn--danger btn--sm" data-gal-bulk-delete disabled>Löschen</button>
      </div>
    </div>

    <div class="gal-grid" data-gal-grid<?= tour_attrs('Galerie', 'Klicke auf ein Bild oder Video, um es groß anzusehen. Eigene Beiträge kannst du bearbeiten oder löschen; die Projektleitung verwaltet alle.', 30) ?>>
      <?php foreach ($items as $m): $mid = (int) $m['id']; $editable = Media::canEdit($m);
        $isImg    = $m['kind'] === Media::KIND_IMAGE;
        $thumbUrl = url('media_file', ['id' => $mid, 'v' => 'thumb']);
        $viewUrl  = url('media_file', ['id' => $mid, 'v' => 'view']);
        $playUrl  = url('media_file', ['id' => $mid]);
        $origUrl  = url('media_file', ['id' => $mid, 'download' => 1]);
        $lbSrc    = $isImg ? $viewUrl : $playUrl; // Lightbox: Bild = Ansicht, Video = Original-Stream
        $dispDate = $mediaDate($m); ?>
        <figure class="gal-tile" data-gal-tile
                data-id="<?= $mid ?>"
                data-kind="<?= e($m['kind']) ?>"
                data-src="<?= e($lbSrc) ?>"
                data-download="<?= e($origUrl) ?>"
                data-title="<?= e((string) ($m['title'] ?? '')) ?>"
                data-uploader="<?= e((string) ($m['uploader_name'] ?? '')) ?>"
                data-date="<?= e($dispDate) ?>">
          <?php if ($editable): ?>
            <label class="gal-tile__check" title="Auswählen">
              <input type="checkbox" name="ids[]" value="<?= $mid ?>" data-gal-check>
            </label>
          <?php endif; ?>
          <button type="button" class="gal-tile__view" data-gal-open aria-label="Ansehen">
            <?php if ($isImg): ?>
              <img src="<?= e($thumbUrl) ?>" alt="<?= e((string) ($m['title'] ?? 'Bild')) ?>" loading="lazy">
            <?php else: ?>
              <span class="gal-tile__video">
                <video src="<?= e($playUrl) ?>#t=0.1" preload="metadata" muted playsinline></video>
                <span class="gal-tile__play" aria-hidden="true">▶</span>
              </span>
            <?php endif; ?>
          </button>
          <figcaption class="gal-tile__cap">
            <?php if ($m['title']): ?><span class="gal-tile__title"><?= e($m['title']) ?></span><?php endif; ?>
            <span class="gal-tile__meta">
              <?php if ($dispDate !== ''): ?><span class="gal-tile__date">📅 <?= e($dispDate) ?></span> · <?php endif; ?><?= e((string) ($m['uploader_name'] ?? 'Unbekannt')) ?>
              <?php if ($editable): ?>
                · <button type="button" class="linklike" data-modal-open="editModal" data-fill="<?= $fill($m) ?>">Bearbeiten</button>
              <?php endif; ?>
            </span>
          </figcaption>
        </figure>
      <?php endforeach; ?>
    </div>
  </form>
<?php endif; ?>

<?php if ($canUpload): ?>
<div class="modal-overlay" id="uploadModal" hidden>
  <div class="modal modal--form" role="dialog" aria-modal="true" aria-labelledby="uploadModalTitle">
    <div class="modal__head">
      <h3 id="uploadModalTitle">Medien hochladen – <?= e($selCycle['year_label']) ?></h3>
      <button type="button" class="modal__close" data-modal-close aria-label="Schließen">&times;</button>
    </div>
    <form method="post" action="<?= url('gallery', ['cycle' => $selId]) ?>" enctype="multipart/form-data" class="modal__body" data-gal-upload-form>
      <?= Csrf::field() ?>
      <input type="hidden" name="action" value="upload">
      <input type="hidden" name="cycle_id" value="<?= $selId ?>">
      <div class="field">
        <label>Bilder &amp; Videos</label>
        <label class="gal-drop" data-gal-drop
               data-gal-postmax="<?= (int) $postMaxBytes ?>"
               data-gal-maxupload="<?= (int) Media::maxUploadBytes() ?>"
               data-gal-chunk-url="<?= e(url('media_chunk')) ?>"
               data-gal-return="<?= e(url('gallery', ['cycle' => $selId])) ?>"
               data-gal-cycle="<?= $selId ?>" tabindex="0">
          <input type="file" name="files[]" accept="image/*,video/*" multiple hidden data-gal-input>
          <span class="gal-drop__icon" aria-hidden="true">⬆️</span>
          <span class="gal-drop__hint">Dateien hierher ziehen oder klicken – <strong>Mehrfachauswahl möglich</strong></span>
          <span class="gal-drop__list muted" data-gal-list hidden></span>
        </label>
        <p class="muted" style="font-size:13px;margin:8px 0 0"><?= e(Media::allowedHint()) ?></p>
        <div class="gal-progress" data-gal-progress hidden></div>
      </div>
      <div class="modal__foot">
        <button type="button" class="btn btn--ghost" data-modal-close>Abbrechen</button>
        <button class="btn btn--primary" data-gal-upload-submit>Hochladen</button>
      </div>
    </form>
  </div>
</div>
<?php endif; ?>

<div class="modal-overlay" id="editModal" hidden>
  <div class="modal modal--form" role="dialog" aria-modal="true" aria-labelledby="editModalTitle">
    <div class="modal__head">
      <h3 id="editModalTitle">Medium bearbeiten</h3>
      <button type="button" class="modal__close" data-modal-close aria-label="Schließen">&times;</button>
    </div>
    <form method="post" action="<?= url('gallery') ?>" class="modal__body" data-modal-form>
      <?= Csrf::field() ?>
      <input type="hidden" name="action" value="save">
      <input type="hidden" name="id" value="0">
      <div class="field"><label>Titel / Bildunterschrift</label><input type="text" name="title" maxlength="255" placeholder="z. B. Siegerehrung PitchDay"></div>
      <div class="modal__foot" style="justify-content:space-between">
        <button type="button" class="btn btn--danger" data-gal-edit-delete>Löschen</button>
        <span>
          <button type="button" class="btn btn--ghost" data-modal-close>Abbrechen</button>
          <button class="btn btn--primary">Speichern</button>
        </span>
      </div>
    </form>
  </div>
</div>

<!-- Verstecktes Formular zum Löschen aus dem Bearbeiten-Dialog -->
<form method="post" action="<?= url('gallery') ?>" id="galDeleteForm" data-confirm="Dieses Medium wirklich löschen?" hidden>
  <?= Csrf::field() ?>
  <input type="hidden" name="action" value="delete">
  <input type="hidden" name="id" value="0" data-gal-delete-id>
</form>

<!-- Lightbox -->
<div class="gal-lightbox" id="galLightbox" hidden>
  <div class="gal-lightbox__tools">
    <a class="gal-lightbox__tool" data-gal-lb-dl download title="Original herunterladen" aria-label="Original herunterladen">⬇</a>
    <button type="button" class="gal-lightbox__tool gal-lightbox__close" data-gal-lb-close aria-label="Schließen">&times;</button>
  </div>
  <button type="button" class="gal-lightbox__nav gal-lightbox__nav--prev" data-gal-lb-prev aria-label="Vorheriges">‹</button>
  <div class="gal-lightbox__stage" data-gal-lb-stage></div>
  <button type="button" class="gal-lightbox__nav gal-lightbox__nav--next" data-gal-lb-next aria-label="Nächstes">›</button>
  <div class="gal-lightbox__cap" data-gal-lb-cap></div>
</div>

<script>
(function () {
  var grid = document.querySelector('[data-gal-grid]');

  // --- Lightbox -----------------------------------------------------------
  var lb = document.getElementById('galLightbox');
  if (grid && lb) {
    var stage = lb.querySelector('[data-gal-lb-stage]');
    var cap = lb.querySelector('[data-gal-lb-cap]');
    var dl = lb.querySelector('[data-gal-lb-dl]');
    var tiles = [], idx = -1;

    function collect() {
      tiles = Array.prototype.slice.call(grid.querySelectorAll('[data-gal-tile]'));
    }
    function show(i) {
      collect();
      if (i < 0) i = tiles.length - 1;
      if (i >= tiles.length) i = 0;
      idx = i;
      var t = tiles[i];
      if (!t) return;
      var kind = t.getAttribute('data-kind');
      var src = t.getAttribute('data-src');
      var title = t.getAttribute('data-title') || '';
      var uploader = t.getAttribute('data-uploader') || '';
      var date = t.getAttribute('data-date') || '';
      stage.innerHTML = '';
      var el;
      if (kind === 'video') {
        el = document.createElement('video');
        el.src = src; el.controls = true; el.autoplay = true; el.playsInline = true;
      } else {
        el = document.createElement('img');
        el.src = src; el.alt = title || 'Bild';
      }
      el.className = 'gal-lightbox__media';
      stage.appendChild(el);
      if (dl) dl.setAttribute('href', t.getAttribute('data-download') || t.getAttribute('data-src') || '#');
      var parts = [];
      if (title) parts.push(title);
      if (date) parts.push(date);
      if (uploader) parts.push(uploader);
      cap.textContent = parts.join(' · ');
    }
    function openLb(i) { show(i); lb.hidden = false; document.body.classList.add('modal-open'); }
    function closeLb() {
      lb.hidden = true; stage.innerHTML = '';
      if (!document.querySelector('.modal-overlay:not([hidden])')) document.body.classList.remove('modal-open');
    }

    grid.addEventListener('click', function (e) {
      var opener = e.target.closest('[data-gal-open]');
      if (!opener) return;
      var tile = opener.closest('[data-gal-tile]');
      collect();
      openLb(tiles.indexOf(tile));
    });
    lb.querySelector('[data-gal-lb-close]').addEventListener('click', closeLb);
    lb.querySelector('[data-gal-lb-prev]').addEventListener('click', function () { show(idx - 1); });
    lb.querySelector('[data-gal-lb-next]').addEventListener('click', function () { show(idx + 1); });
    lb.addEventListener('click', function (e) { if (e.target === lb) closeLb(); });
    document.addEventListener('keydown', function (e) {
      if (lb.hidden) return;
      if (e.key === 'Escape') closeLb();
      else if (e.key === 'ArrowLeft') show(idx - 1);
      else if (e.key === 'ArrowRight') show(idx + 1);
    });
  }

  // --- Mehrfachauswahl (Bulk) --------------------------------------------
  var bulkForm = document.getElementById('bulkForm');
  if (bulkForm) {
    var toggle = bulkForm.querySelector('[data-gal-select-toggle]');
    var bulkBox = bulkForm.querySelector('[data-gal-bulk]');
    var countEl = bulkForm.querySelector('[data-gal-count]');
    var delBtn = bulkForm.querySelector('[data-gal-bulk-delete]');
    var checks = Array.prototype.slice.call(bulkForm.querySelectorAll('[data-gal-check]'));

    function refresh() {
      var n = checks.filter(function (c) { return c.checked; }).length;
      countEl.textContent = n;
      delBtn.disabled = n === 0;
    }
    if (toggle) {
      toggle.addEventListener('click', function () {
        var on = bulkForm.classList.toggle('gal--selecting');
        if (bulkBox) bulkBox.hidden = !on;
        toggle.classList.toggle('btn--teal', on);
        if (!on) { checks.forEach(function (c) { c.checked = false; }); refresh(); }
      });
    }
    checks.forEach(function (c) { c.addEventListener('change', refresh); });
    if (delBtn) {
      delBtn.addEventListener('click', function (e) {
        if (!checks.some(function (c) { return c.checked; })) { e.preventDefault(); }
      });
    }
  }

  // --- Löschen aus dem Bearbeiten-Dialog ----------------------------------
  var editModal = document.getElementById('editModal');
  var delForm = document.getElementById('galDeleteForm');
  if (editModal && delForm) {
    var delBtn2 = editModal.querySelector('[data-gal-edit-delete]');
    if (delBtn2) {
      delBtn2.addEventListener('click', function () {
        var id = editModal.querySelector('[name="id"]').value;
        delForm.querySelector('[data-gal-delete-id]').value = id;
        if (typeof delForm.requestSubmit === 'function') delForm.requestSubmit();
        else delForm.submit();
      });
    }
  }

  // --- Upload: Drag & Drop, Dateiliste, Chunk-Upload ----------------------
  var drop = document.querySelector('[data-gal-drop]');
  if (drop) {
    var inp = drop.querySelector('[data-gal-input]');
    var list = drop.querySelector('[data-gal-list]');
    var uploadForm = drop.closest('form');
    var progress = uploadForm ? uploadForm.querySelector('[data-gal-progress]') : null;
    var postMax = parseInt(drop.getAttribute('data-gal-postmax'), 10) || 0;
    var maxUpload = parseInt(drop.getAttribute('data-gal-maxupload'), 10) || 0;
    var chunkUrl = drop.getAttribute('data-gal-chunk-url');
    var returnUrl = drop.getAttribute('data-gal-return');
    var cycleId = drop.getAttribute('data-gal-cycle');
    var csrfInput = uploadForm ? uploadForm.querySelector('[name="_csrf"]') : null;
    var CHUNK = 5 * 1024 * 1024;
    // Chunk-Upload nur, wenn der Browser die nötigen APIs beherrscht.
    var supportsChunk = !!(window.fetch && window.File && window.Blob &&
      Blob.prototype.slice && window.FormData && window.Promise && chunkUrl);

    function fmt(b) {
      var u = ['B', 'KB', 'MB', 'GB'], i = 0, n = b;
      while (n >= 1024 && i < u.length - 1) { n /= 1024; i++; }
      return (Math.round(n * 10) / 10) + ' ' + u[i];
    }
    function totalBytes() {
      var t = 0, f = inp.files;
      for (var i = 0; f && i < f.length; i++) t += f[i].size;
      return t;
    }
    function renderList() {
      var files = inp.files;
      list.classList.remove('gal-drop__list--warn');
      if (!files || !files.length) { list.hidden = true; list.textContent = ''; return; }
      var names = [];
      for (var i = 0; i < files.length && i < 8; i++) names.push(files[i].name);
      var extra = files.length > 8 ? (' … +' + (files.length - 8)) : '';
      var total = totalBytes();
      list.textContent = files.length + ' Datei(en) · ' + fmt(total) + ': ' + names.join(', ') + extra;
      // Ohne Chunk-Support gilt das Server-Limit; sonst darf es groß sein.
      if (!supportsChunk && postMax > 0 && total > postMax * 0.95) {
        list.textContent += ' — zu groß (Limit ' + fmt(postMax) + '). Bitte weniger auf einmal.';
        list.classList.add('gal-drop__list--warn');
      }
      list.hidden = false;
    }

    // Ein File in Stücken hochladen; onProgress(0..1).
    function postForm(fd) {
      return fetch(chunkUrl, { method: 'POST', body: fd, credentials: 'same-origin' })
        .then(function (res) {
          return res.text().then(function (text) {
            var data;
            try { data = JSON.parse(text); } catch (e) { throw new Error('Serverantwort ungültig (' + res.status + ')'); }
            if (!res.ok || !data.ok) { var err = new Error(data && data.error ? data.error : ('Fehler ' + res.status)); err.data = data; throw err; }
            return data;
          });
        });
    }
    function randId() {
      if (window.crypto && crypto.getRandomValues) {
        var a = new Uint8Array(16); crypto.getRandomValues(a);
        return Array.prototype.map.call(a, function (b) { return ('0' + b.toString(16)).slice(-2); }).join('');
      }
      return (Date.now().toString(16) + Math.floor(Math.random() * 1e9).toString(16) + 'abcdef').slice(0, 24);
    }
    function delay(ms) { return new Promise(function (r) { setTimeout(r, ms); }); }

    function uploadFile(file, onProgress) {
      var id = randId();
      var offset = 0;
      function sendNext() {
        if (offset >= file.size) {
          var ff = new FormData();
          ff.append('phase', 'finalize');
          ff.append('cycle_id', cycleId);
          if (csrfInput) ff.append('_csrf', csrfInput.value);
          ff.append('upload_id', id);
          ff.append('name', file.name);
          ff.append('size', String(file.size));
          return postForm(ff);
        }
        var end = Math.min(offset + CHUNK, file.size);
        var blob = file.slice(offset, end);
        var fd = new FormData();
        fd.append('phase', 'chunk');
        fd.append('cycle_id', cycleId);
        if (csrfInput) fd.append('_csrf', csrfInput.value);
        fd.append('upload_id', id);
        fd.append('offset', String(offset));
        fd.append('chunk', blob, 'chunk');
        var tries = 0;
        function attempt() {
          return postForm(fd).then(function (data) {
            offset = (typeof data.received === 'number') ? data.received : end;
            if (onProgress) onProgress(Math.min(offset / file.size, 1));
            return sendNext();
          }).catch(function (err) {
            // Offset-Konflikt: Server hat schon mehr → resynchronisieren.
            if (err.data && typeof err.data.received === 'number') {
              offset = err.data.received;
              if (offset >= file.size) return sendNext();
            }
            tries++;
            if (tries >= 4) throw err;
            return delay(400 * tries).then(attempt);
          });
        }
        return attempt();
      }
      return sendNext();
    }

    function runChunkUpload(files) {
      drop.style.display = 'none';
      list.hidden = true;
      progress.hidden = false;
      progress.innerHTML = '';
      var submitBtn = uploadForm.querySelector('[data-gal-upload-submit]');
      if (submitBtn) { submitBtn.disabled = true; }
      var rows = [], errors = 0;

      files.forEach(function (file) {
        var row = document.createElement('div');
        row.className = 'gal-prog';
        row.innerHTML = '<span class="gal-prog__name"></span><span class="gal-prog__bar"><i></i></span><span class="gal-prog__pct">0%</span>';
        row.querySelector('.gal-prog__name').textContent = file.name + ' (' + fmt(file.size) + ')';
        progress.appendChild(row);
        rows.push(row);
      });

      var chain = Promise.resolve();
      files.forEach(function (file, idx) {
        chain = chain.then(function () {
          var row = rows[idx];
          var bar = row.querySelector('i');
          var pct = row.querySelector('.gal-prog__pct');
          if (maxUpload > 0 && file.size > maxUpload) {
            row.classList.add('gal-prog--err');
            pct.textContent = '✗';
            errors++;
            var m = document.createElement('div');
            m.className = 'gal-prog__msg';
            m.textContent = file.name + ': zu groß (max. ' + fmt(maxUpload) + ').';
            progress.appendChild(m);
            return;
          }
          return uploadFile(file, function (p) {
            var v = Math.round(p * 100);
            bar.style.width = v + '%';
            pct.textContent = v + '%';
          }).then(function () {
            row.classList.add('gal-prog--done');
            bar.style.width = '100%';
            pct.textContent = '✓';
          }).catch(function (err) {
            row.classList.add('gal-prog--err');
            pct.textContent = '✗';
            errors++;
            var m = document.createElement('div');
            m.className = 'gal-prog__msg';
            m.textContent = file.name + ': ' + (err.message || 'Fehler');
            progress.appendChild(m);
          });
        });
      });

      chain.then(function () {
        if (errors === 0) {
          window.location = returnUrl;
          return;
        }
        // Bei Fehlern nicht automatisch neu laden – Meldungen sichtbar lassen.
        var done = document.createElement('button');
        done.type = 'button';
        done.className = 'btn btn--primary';
        done.style.marginTop = '12px';
        done.textContent = 'Galerie aktualisieren';
        done.addEventListener('click', function () { window.location = returnUrl; });
        progress.appendChild(done);
        if (submitBtn) { submitBtn.disabled = false; }
      });
    }

    if (uploadForm) {
      uploadForm.addEventListener('submit', function (e) {
        var files = inp.files;
        if (!files || !files.length) { return; } // nichts gewählt → Server meldet es
        if (supportsChunk) {
          e.preventDefault();
          e.stopPropagation(); // kein hängender Lade-Spinner (app.js)
          runChunkUpload(Array.prototype.slice.call(files));
          return;
        }
        // Fallback ohne Chunk-Support: klassischer Post, aber Größe prüfen.
        var total = totalBytes();
        if (postMax > 0 && total > postMax * 0.95) {
          e.preventDefault();
          e.stopPropagation();
          renderList();
        }
      });
    }

    inp.addEventListener('change', renderList);
    ['dragenter', 'dragover'].forEach(function (ev) {
      drop.addEventListener(ev, function (e) { e.preventDefault(); drop.classList.add('gal-drop--over'); });
    });
    ['dragleave', 'drop'].forEach(function (ev) {
      drop.addEventListener(ev, function (e) { e.preventDefault(); drop.classList.remove('gal-drop--over'); });
    });
    drop.addEventListener('drop', function (e) {
      if (e.dataTransfer && e.dataTransfer.files && e.dataTransfer.files.length) {
        inp.files = e.dataTransfer.files;
        renderList();
      }
    });
    drop.addEventListener('keydown', function (e) {
      if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); inp.click(); }
    });
  }
})();
</script>
<?php
$content = ob_get_clean();
$title = 'Mediengalerie';
require APP_PATH . '/pages/_layout.php';
