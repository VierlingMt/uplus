<?php
/** Material & Vorlagen: Downloads + Erklärvideo. Verwaltung nur Projektleitung. */
declare(strict_types=1);

Auth::require();
$isAdmin = Auth::isManager(); // Admin oder Projektleitung = volle Verwaltung

if (is_post()) {
    Auth::requireManager();
    Csrf::check();
    $action = (string) input('action');

    if ($action === 'delete') {
        $m = Database::one('SELECT * FROM materials WHERE id = ?', [(int) input('id')]);
        if ($m) {
            if ($m['stored_name']) { @unlink(UPLOAD_PATH . '/materials/' . $m['stored_name']); }
            Database::run('DELETE FROM materials WHERE id = ?', [(int) $m['id']]);
            Audit::log('material.delete', 'Material gelöscht: ' . (string) $m['title'], 'material', (int) $m['id']);
            flash('success', 'Material gelöscht.');
        }
        redirect(url('materials'));
    }

    // Eingangstext (Markdown) speichern.
    if ($action === 'save_intro') {
        Settings::set('materials_intro', trim((string) input('intro')));
        Audit::log('material.intro', 'Material-Eingangstext bearbeitet');
        flash('success', 'Eingangstext gespeichert.');
        redirect(url('materials'));
    }

    // Reihenfolge der Downloads & Links ändern (hoch/runter). Immer neu
    // durchnummerieren, damit auch bei bisher gleicher sort_order getauscht wird.
    if ($action === 'move') {
        $id  = (int) input('id');
        $dir = input('dir') === 'up' ? -1 : 1;
        $ids = array_map(
            static fn($r) => (int) $r['id'],
            Database::all('SELECT id FROM materials ORDER BY sort_order, id')
        );
        $pos = array_search($id, $ids, true);
        if ($pos !== false) {
            $swap = $pos + $dir;
            if ($swap >= 0 && $swap < count($ids)) {
                [$ids[$pos], $ids[$swap]] = [$ids[$swap], $ids[$pos]];
                foreach ($ids as $i => $mid) {
                    Database::run('UPDATE materials SET sort_order = ? WHERE id = ?', [$i + 1, $mid]);
                }
            }
        }
        redirect(url('materials'));
    }

    // Anlegen oder Bearbeiten (id > 0 = Bearbeiten).
    $id    = (int) input('id', 0);
    $title = trim((string) input('title'));
    $desc  = trim((string) input('description'));
    $link  = trim((string) input('link_url'));
    $vis   = (string) input('visibility', 'all');
    $removeFile = (bool) input('remove_file');
    if (!in_array($vis, ['all','teacher','juror','admin'], true)) { $vis = 'all'; }
    if ($title === '') { flash('error', 'Titel erforderlich.'); redirect(url('materials')); }

    $storedName = null; $origName = null;
    if (!empty($_FILES['file']['name']) && is_uploaded_file($_FILES['file']['tmp_name'])) {
        $f = $_FILES['file'];
        if ($f['size'] > (int) cfg('upload_max_bytes')) {
            flash('error', 'Datei zu groß.'); redirect(url('materials'));
        }
        $dir = UPLOAD_PATH . '/materials';
        if (!is_dir($dir)) { @mkdir($dir, 0775, true); }
        $ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
        $storedName = bin2hex(random_bytes(12)) . ($ext ? '.' . preg_replace('/[^a-z0-9]/', '', $ext) : '');
        if (!move_uploaded_file($f['tmp_name'], $dir . '/' . $storedName)) {
            flash('error', 'Upload fehlgeschlagen.'); redirect(url('materials'));
        }
        $origName = $f['name'];
    }

    if ($id > 0) {
        $m = Database::one('SELECT * FROM materials WHERE id = ?', [$id]);
        if (!$m) { flash('error', 'Material nicht gefunden.'); redirect(url('materials')); }

        // Dateizustand bestimmen: neue Datei ersetzt die alte; „entfernen" löscht sie.
        $keepStored = $m['stored_name']; $keepOrig = $m['original_name'];
        if ($storedName) {
            if ($m['stored_name']) { @unlink(UPLOAD_PATH . '/materials/' . $m['stored_name']); }
            $keepStored = $storedName; $keepOrig = $origName;
        } elseif ($removeFile && $m['stored_name']) {
            @unlink(UPLOAD_PATH . '/materials/' . $m['stored_name']);
            $keepStored = null; $keepOrig = null;
        }

        Database::run(
            'UPDATE materials SET title=?, description=?, original_name=?, stored_name=?, link_url=?, visibility=? WHERE id=?',
            [$title, $desc ?: null, $keepOrig, $keepStored, $link ?: null, $vis, $id]
        );
        Audit::log('material.update', 'Material bearbeitet: ' . $title, 'material', $id);
        flash('success', 'Material aktualisiert.');
        redirect(url('materials'));
    }

    $newId = Database::insert(
        'INSERT INTO materials (title, description, original_name, stored_name, link_url, visibility, uploaded_by)
         VALUES (?,?,?,?,?,?,?)',
        [$title, $desc ?: null, $origName, $storedName, $link ?: null, $vis, Auth::id()]
    );
    Audit::log('material.create', 'Material hinzugefügt: ' . $title, 'material', $newId);
    flash('success', 'Material hinzugefügt.');
    redirect(url('materials'));
}

// Sichtbarkeit je Rolle
$role = Auth::role();
$visClause = $isAdmin ? '' : "WHERE visibility = 'all' OR visibility = " . Database::pdo()->quote($role);
$materials = Database::all("SELECT * FROM materials $visClause ORDER BY sort_order, id");

/** YouTube-ID aus URL extrahieren. */
$ytId = function (?string $u): ?string {
    if (!$u) return null;
    if (preg_match('~(?:youtube\.com/watch\?v=|youtu\.be/|youtube\.com/embed/)([A-Za-z0-9_-]{11})~', $u, $m)) return $m[1];
    return null;
};

$intro = (string) Settings::get('materials_intro', '');

// Daten zum Vorbefüllen des Bearbeiten-Modals.
$fill = fn(array $m) => e(json_encode([
    'id'          => (int) $m['id'],
    'title'       => $m['title'],
    'description' => $m['description'],
    'link_url'    => $m['link_url'],
    'visibility'  => $m['visibility'],
], JSON_UNESCAPED_UNICODE));

ob_start(); ?>
<div class="page-head">
  <h1>Material &amp; Vorlagen</h1>
  <?php if ($isAdmin): ?><button type="button" class="btn btn--teal" data-modal-open="materialModal">+ Neu</button><?php endif; ?>
</div>

<?php if (trim($intro) !== ''): ?>
  <div class="card mb"><div class="card__body materials-intro"><?= render_markdown($intro) ?></div></div>
  <style>
  .materials-intro h2{margin:16px 0 4px;font-size:20px;color:var(--wj-blue)}
  .materials-intro h2:first-child,.materials-intro h3:first-child,.materials-intro > :first-child{margin-top:0}
  .materials-intro h3{margin:12px 0 4px;font-size:15px;color:var(--wj-teal-d)}
  .materials-intro ul{margin:4px 0 10px;padding-left:20px}
  .materials-intro li{margin:3px 0}
  .materials-intro p{margin:6px 0}
  </style>
<?php endif; ?>

<?php if ($isAdmin): ?>
  <details class="card mb"<?= trim($intro) === '' ? ' open' : '' ?>>
    <style>details > summary.card__head{cursor:pointer;list-style:none}details > summary.card__head::-webkit-details-marker{display:none}</style>
    <summary class="card__head">
      Eingangstext bearbeiten <span class="muted" style="font-weight:400;font-size:13px">(Markdown)</span>
    </summary>
    <div class="card__body">
      <form method="post" action="<?= url('materials') ?>">
        <?= Csrf::field() ?><input type="hidden" name="action" value="save_intro">
        <div class="field">
          <textarea name="intro" rows="6" placeholder="# Überschrift&#10;&#10;Kurzer Einführungstext … **fett**, Listen mit - und [Links](https://…)."><?= e($intro) ?></textarea>
        </div>
        <p class="muted" style="font-size:13px;margin:0 0 10px">
          Unterstützt: Überschriften (#), Listen (-), **fett** und [Text](https://…)-Links.
        </p>
        <button class="btn btn--primary">Eingangstext speichern</button>
      </form>
    </div>
  </details>
<?php endif; ?>

<?php
$video = null;
foreach ($materials as $m) { if ($vid = $ytId($m['link_url'])) { $video = $vid; break; } }
if ($video): ?>
  <div class="card mb"><div class="card__head">Erklärvideo</div>
    <div class="card__body">
      <div style="position:relative;padding-top:40%;max-width:720px">
        <iframe src="https://www.youtube-nocookie.com/embed/<?= e($video) ?>" title="Erklärvideo"
          style="position:absolute;inset:0;width:100%;height:100%;border:0;border-radius:10px"
          allow="accelerometer;autoplay;clipboard-write;encrypted-media;gyroscope;picture-in-picture" allowfullscreen></iframe>
      </div>
    </div>
  </div>
<?php endif; ?>

<div class="grid cols-1">
  <div class="card">
    <div class="card__head">Downloads &amp; Links</div>
    <div class="table-wrap">
      <table class="data data--cards">
        <tbody>
        <?php foreach ($materials as $i => $m): ?>
          <tr>
            <td>
              <strong><?= e($m['title']) ?></strong>
              <?php if ($m['description']): ?><br><span class="muted" style="font-size:13px"><?= e($m['description']) ?></span><?php endif; ?>
              <?php if ($isAdmin && $m['visibility'] !== 'all'): ?> <span class="pill muted"><?= e($m['visibility']) ?></span><?php endif; ?>
            </td>
            <td class="row-actions" style="text-align:right;white-space:nowrap">
              <?php if ($isAdmin && count($materials) > 1): ?>
                <form method="post" action="<?= url('materials') ?>" style="display:inline">
                  <?= Csrf::field() ?><input type="hidden" name="action" value="move"><input type="hidden" name="id" value="<?= (int) $m['id'] ?>"><input type="hidden" name="dir" value="up">
                  <button class="btn btn--ghost btn--sm no-spinner" title="Nach oben" aria-label="Nach oben"<?= $i === 0 ? ' disabled' : '' ?>>↑</button>
                </form>
                <form method="post" action="<?= url('materials') ?>" style="display:inline">
                  <?= Csrf::field() ?><input type="hidden" name="action" value="move"><input type="hidden" name="id" value="<?= (int) $m['id'] ?>"><input type="hidden" name="dir" value="down">
                  <button class="btn btn--ghost btn--sm no-spinner" title="Nach unten" aria-label="Nach unten"<?= $i === count($materials) - 1 ? ' disabled' : '' ?>>↓</button>
                </form>
              <?php endif; ?>
              <?php if ($m['stored_name']): ?>
                <a class="btn btn--ghost btn--sm" href="<?= url('material_download', ['id' => $m['id']]) ?>">Download</a>
              <?php elseif ($m['link_url']): ?>
                <a class="btn btn--ghost btn--sm" href="<?= e($m['link_url']) ?>" target="_blank" rel="noopener">Öffnen ↗</a>
              <?php endif; ?>
              <?php if ($isAdmin): ?>
                <button type="button" class="btn btn--ghost btn--sm" data-modal-open="materialModal" data-fill="<?= $fill($m) ?>" data-file="<?= e((string) ($m['original_name'] ?? '')) ?>">Bearbeiten</button>
                <form method="post" action="<?= url('materials') ?>" style="display:inline" data-confirm="„<?= e($m['title']) ?>“ wirklich löschen?">
                  <?= Csrf::field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int) $m['id'] ?>">
                  <button class="btn btn--danger btn--sm">Löschen</button>
                </form>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$materials): ?><tr><td class="muted">Noch kein Material.</td></tr><?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>

</div>

<?php if ($isAdmin): ?>
<div class="modal-overlay" id="materialModal" hidden>
  <div class="modal modal--form" role="dialog" aria-modal="true" aria-labelledby="materialModalTitle">
    <div class="modal__head">
      <h3 id="materialModalTitle" data-modal-title data-title-new="Material hinzufügen" data-title-edit="Material bearbeiten">Material hinzufügen</h3>
      <button type="button" class="modal__close" data-modal-close aria-label="Schließen">&times;</button>
    </div>
    <form method="post" action="<?= url('materials') ?>" enctype="multipart/form-data" class="modal__body" data-modal-form>
      <?= Csrf::field() ?>
      <input type="hidden" name="id" value="0">
      <div class="field"><label>Titel *</label><input type="text" name="title" required></div>
      <div class="field"><label>Beschreibung</label><textarea name="description" rows="2"></textarea></div>
      <div class="field">
        <label>Datei (PDF/DOCX …)</label>
        <input type="file" name="file">
        <p class="muted" data-material-file-hint hidden style="font-size:13px;margin:6px 0 0">
          Aktuelle Datei: <strong data-material-file-name></strong> – bleibt erhalten, sofern keine neue gewählt wird.
          <label style="display:inline-flex;align-items:center;gap:6px;margin-left:8px;font-weight:400">
            <input type="checkbox" name="remove_file" value="1"> Datei entfernen
          </label>
        </p>
      </div>
      <div class="field"><label>… oder Link (z. B. YouTube)</label><input type="text" name="link_url" placeholder="https://…"></div>
      <div class="field"><label>Sichtbar für</label>
        <select name="visibility">
          <option value="all">Alle</option>
          <option value="teacher">Lehrkräfte</option>
          <option value="juror">Jury</option>
          <option value="admin">Nur Leitung (Admin &amp; Projektleitung)</option>
        </select>
      </div>
      <div class="modal__foot">
        <button type="button" class="btn btn--ghost" data-modal-close>Abbrechen</button>
        <button class="btn btn--primary" data-label-new="Hinzufügen" data-label-edit="Speichern">Hinzufügen</button>
      </div>
    </form>
  </div>
</div>
<script>
// Beim Öffnen des Modals: bei „Bearbeiten" die aktuelle Datei anzeigen, bei „Neu" ausblenden.
document.addEventListener('click', function (e) {
  var opener = e.target.closest('[data-modal-open="materialModal"]');
  if (!opener) return;
  var modal = document.getElementById('materialModal');
  var hint = modal.querySelector('[data-material-file-hint]');
  var nameEl = modal.querySelector('[data-material-file-name]');
  var file = opener.getAttribute('data-file') || '';
  var isEdit = opener.hasAttribute('data-fill');
  if (isEdit && file) {
    nameEl.textContent = file;
    hint.hidden = false;
  } else {
    hint.hidden = true;
  }
  var chk = modal.querySelector('[name="remove_file"]');
  if (chk) chk.checked = false;
}, true);
</script>
<?php endif; ?>
<?php
$content = ob_get_clean();
$title = 'Material & Vorlagen';
require APP_PATH . '/pages/_layout.php';
