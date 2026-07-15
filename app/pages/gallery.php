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
            Database::insert(
                'INSERT INTO media_items (cycle_id, uploaded_by, kind, stored_name, original_name, mime, size_bytes)
                 VALUES (?,?,?,?,?,?,?)',
                [$cycleId, Auth::id(), $type['kind'], $stored, mb_substr($name, 0, 255), $type['mime'], $sz]
            );
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
            @unlink(Media::dir() . '/' . basename((string) $item['stored_name']));
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
                @unlink(Media::dir() . '/' . basename((string) $item['stored_name']));
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
        $src = url('media_file', ['id' => $mid]); ?>
        <figure class="gal-tile" data-gal-tile
                data-id="<?= $mid ?>"
                data-kind="<?= e($m['kind']) ?>"
                data-src="<?= e($src) ?>"
                data-title="<?= e((string) ($m['title'] ?? '')) ?>"
                data-uploader="<?= e((string) ($m['uploader_name'] ?? '')) ?>">
          <?php if ($editable): ?>
            <label class="gal-tile__check" title="Auswählen">
              <input type="checkbox" name="ids[]" value="<?= $mid ?>" data-gal-check>
            </label>
          <?php endif; ?>
          <button type="button" class="gal-tile__view" data-gal-open aria-label="Ansehen">
            <?php if ($m['kind'] === Media::KIND_IMAGE): ?>
              <img src="<?= e($src) ?>" alt="<?= e((string) ($m['title'] ?? 'Bild')) ?>" loading="lazy">
            <?php else: ?>
              <span class="gal-tile__video">
                <video src="<?= e($src) ?>#t=0.1" preload="metadata" muted playsinline></video>
                <span class="gal-tile__play" aria-hidden="true">▶</span>
              </span>
            <?php endif; ?>
          </button>
          <figcaption class="gal-tile__cap">
            <?php if ($m['title']): ?><span class="gal-tile__title"><?= e($m['title']) ?></span><?php endif; ?>
            <span class="gal-tile__meta">
              <?= e((string) ($m['uploader_name'] ?? 'Unbekannt')) ?>
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
    <form method="post" action="<?= url('gallery') ?>" enctype="multipart/form-data" class="modal__body" data-gal-upload-form>
      <?= Csrf::field() ?>
      <input type="hidden" name="action" value="upload">
      <input type="hidden" name="cycle_id" value="<?= $selId ?>">
      <div class="field">
        <label>Bilder &amp; Videos</label>
        <label class="gal-drop" data-gal-drop tabindex="0">
          <input type="file" name="files[]" accept="image/*,video/*" multiple hidden data-gal-input>
          <span class="gal-drop__icon" aria-hidden="true">⬆️</span>
          <span class="gal-drop__hint">Dateien hierher ziehen oder klicken – <strong>Mehrfachauswahl möglich</strong></span>
          <span class="gal-drop__list muted" data-gal-list hidden></span>
        </label>
        <p class="muted" style="font-size:13px;margin:8px 0 0"><?= e(Media::allowedHint()) ?></p>
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
  <button type="button" class="gal-lightbox__close" data-gal-lb-close aria-label="Schließen">&times;</button>
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
      cap.textContent = title ? (title + ' · ' + uploader) : uploader;
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

  // --- Upload: Drag & Drop + Dateiliste -----------------------------------
  var drop = document.querySelector('[data-gal-drop]');
  if (drop) {
    var inp = drop.querySelector('[data-gal-input]');
    var list = drop.querySelector('[data-gal-list]');
    function renderList() {
      var files = inp.files;
      if (!files || !files.length) { list.hidden = true; list.textContent = ''; return; }
      var names = [];
      for (var i = 0; i < files.length && i < 8; i++) names.push(files[i].name);
      var extra = files.length > 8 ? (' … +' + (files.length - 8)) : '';
      list.textContent = files.length + ' Datei(en): ' + names.join(', ') + extra;
      list.hidden = false;
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
