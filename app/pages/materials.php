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
            flash('success', 'Material gelöscht.');
        }
        redirect(url('materials'));
    }

    $title = trim((string) input('title'));
    $desc  = trim((string) input('description'));
    $link  = trim((string) input('link_url'));
    $vis   = (string) input('visibility', 'all');
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

    Database::run(
        'INSERT INTO materials (title, description, original_name, stored_name, link_url, visibility, uploaded_by)
         VALUES (?,?,?,?,?,?,?)',
        [$title, $desc ?: null, $origName, $storedName, $link ?: null, $vis, Auth::id()]
    );
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

ob_start(); ?>
<div class="page-head"><h1>Material &amp; Vorlagen</h1></div>

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

<div class="grid <?= $isAdmin ? 'cols-2' : 'cols-1' ?>">
  <div class="card">
    <div class="card__head">Downloads &amp; Links</div>
    <div class="table-wrap">
      <table class="data data--cards">
        <tbody>
        <?php foreach ($materials as $m): ?>
          <tr>
            <td>
              <strong><?= e($m['title']) ?></strong>
              <?php if ($m['description']): ?><br><span class="muted" style="font-size:13px"><?= e($m['description']) ?></span><?php endif; ?>
              <?php if ($isAdmin && $m['visibility'] !== 'all'): ?> <span class="pill muted"><?= e($m['visibility']) ?></span><?php endif; ?>
            </td>
            <td class="row-actions" style="text-align:right;white-space:nowrap">
              <?php if ($m['stored_name']): ?>
                <a class="btn btn--ghost btn--sm" href="<?= url('material_download', ['id' => $m['id']]) ?>">Download</a>
              <?php elseif ($m['link_url']): ?>
                <a class="btn btn--ghost btn--sm" href="<?= e($m['link_url']) ?>" target="_blank" rel="noopener">Öffnen ↗</a>
              <?php endif; ?>
              <?php if ($isAdmin): ?>
                <form method="post" action="<?= url('materials') ?>" style="display:inline" data-confirm="Löschen?">
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

  <?php if ($isAdmin): ?>
  <div class="card">
    <div class="card__head">Material hinzufügen</div>
    <div class="card__body">
      <form method="post" action="<?= url('materials') ?>" enctype="multipart/form-data">
        <?= Csrf::field() ?>
        <div class="field"><label>Titel *</label><input type="text" name="title" required></div>
        <div class="field"><label>Beschreibung</label><textarea name="description" rows="2"></textarea></div>
        <div class="field"><label>Datei (PDF/DOCX …)</label><input type="file" name="file"></div>
        <div class="field"><label>… oder Link (z. B. YouTube)</label><input type="text" name="link_url" placeholder="https://…"></div>
        <div class="field"><label>Sichtbar für</label>
          <select name="visibility">
            <option value="all">Alle</option>
            <option value="teacher">Lehrkräfte</option>
            <option value="juror">Jury</option>
            <option value="admin">Nur Leitung (Admin &amp; Projektleitung)</option>
          </select>
        </div>
        <button class="btn btn--primary">Hinzufügen</button>
      </form>
    </div>
  </div>
  <?php endif; ?>
</div>
<?php
$content = ob_get_clean();
$title = 'Material & Vorlagen';
require APP_PATH . '/pages/_layout.php';
