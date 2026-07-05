<?php
/** Schulen verwalten (Admin & Projektleitung). */
declare(strict_types=1);

Auth::requireManager();

if (is_post()) {
    Csrf::check();
    $action = (string) input('action');
    if ($action === 'delete') {
        Database::run('DELETE FROM schools WHERE id = ?', [(int) input('id')]);
        flash('success', 'Schule gelöscht.');
    } else {
        $id   = (int) input('id', 0);
        $name = trim((string) input('name'));
        $short = trim((string) input('short_name'));
        $city  = trim((string) input('city'));
        $note  = trim((string) input('note'));
        $logo = save_image('logo', 'sch', 'logos');
        if ($name === '') {
            flash('error', 'Name ist erforderlich.');
        } elseif ($id > 0) {
            Database::run('UPDATE schools SET name=?, short_name=?, city=?, note=? WHERE id=?',
                [$name, $short ?: null, $city ?: null, $note ?: null, $id]);
            if ($logo) { Database::run('UPDATE schools SET logo_path=? WHERE id=?', [$logo, $id]); }
            flash('success', 'Schule aktualisiert.');
        } else {
            Database::run('INSERT INTO schools (name, short_name, city, note, logo_path) VALUES (?,?,?,?,?)',
                [$name, $short ?: null, $city ?: null, $note ?: null, $logo]);
            flash('success', 'Schule angelegt.');
        }
    }
    redirect(url('schools'));
}

$edit = null;
if ($eid = (int) input('edit', 0)) {
    $edit = Database::one('SELECT * FROM schools WHERE id = ?', [$eid]);
}
$schools = Database::all(
    'SELECT s.*, (SELECT COUNT(*) FROM teams t WHERE t.school_id = s.id) AS teams,
            (SELECT COUNT(*) FROM users u WHERE u.school_id = s.id AND u.role = "teacher") AS teachers
     FROM schools s ORDER BY s.name'
);

$fill = fn(array $s) => e(json_encode([
    'id' => (int) $s['id'], 'name' => $s['name'], 'short_name' => $s['short_name'],
    'city' => $s['city'], 'note' => $s['note'],
], JSON_UNESCAPED_UNICODE));
$imgs = fn(array $s) => $s['logo_path'] ? e(json_encode(['logo' => asset($s['logo_path'])], JSON_UNESCAPED_UNICODE)) : '';

ob_start(); ?>
<div class="page-head">
  <h1>Schulen</h1>
  <button type="button" class="btn btn--teal" data-modal-open="schoolModal">+ Neu</button>
</div>
<div class="card">
  <div class="card__head"><?= count($schools) ?> Schulen</div>
  <div class="table-wrap">
    <table class="data">
      <thead><tr><th></th><th>Schule</th><th>Ort</th><th>Teams</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($schools as $s): ?>
        <tr>
          <td style="width:52px"><?php if ($s['logo_path']): ?><img src="<?= asset($s['logo_path']) ?>" alt="" style="width:40px;height:40px;object-fit:contain"><?php endif; ?></td>
          <td><strong><?= e($s['name']) ?></strong><?php if ($s['short_name']): ?> <span class="pill muted"><?= e($s['short_name']) ?></span><?php endif; ?></td>
          <td><?= e($s['city'] ?? '—') ?></td>
          <td><?= (int) $s['teams'] ?></td>
          <td style="white-space:nowrap;text-align:right">
            <a href="<?= url('school_teachers', ['school' => $s['id']]) ?>" class="btn btn--ghost btn--sm">👩‍🏫 Projektlehrer (<?= (int) $s['teachers'] ?>)</a>
            <button type="button" class="btn btn--ghost btn--sm" data-modal-open="schoolModal" data-fill="<?= $fill($s) ?>"<?= $imgs($s) ? ' data-images="' . $imgs($s) . '"' : '' ?>>Bearbeiten</button>
            <form method="post" action="<?= url('schools') ?>" style="display:inline" data-confirm="Schule „<?= e($s['name']) ?>“ inkl. Teams wirklich löschen?">
              <?= Csrf::field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int) $s['id'] ?>">
              <button class="btn btn--danger btn--sm">Löschen</button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$schools): ?><tr><td colspan="5" class="muted">Noch keine Schulen.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<div class="modal-overlay" id="schoolModal" hidden>
  <div class="modal modal--form" role="dialog" aria-modal="true" aria-labelledby="schoolModalTitle">
    <div class="modal__head">
      <h3 id="schoolModalTitle" data-modal-title data-title-new="Neue Schule" data-title-edit="Schule bearbeiten">Neue Schule</h3>
      <button type="button" class="modal__close" data-modal-close aria-label="Schließen">&times;</button>
    </div>
    <form method="post" action="<?= url('schools') ?>" enctype="multipart/form-data" class="modal__body" data-modal-form>
      <?= Csrf::field() ?>
      <input type="hidden" name="id" value="0">
      <div class="field"><label>Name *</label><input type="text" name="name" required></div>
      <div class="field"><label>Kürzel</label><input type="text" name="short_name" placeholder="z. B. EGF"></div>
      <div class="field"><label>Ort</label><input type="text" name="city"></div>
      <div class="field"><label>Notiz</label><textarea name="note" rows="2"></textarea></div>
      <?= image_field('logo', null, [
          'label' => 'Schul-Logo', 'aspect' => null, 'shape' => 'rect', 'format' => 'png',
      ]) ?>
      <div class="modal__foot">
        <button type="button" class="btn btn--ghost" data-modal-close>Abbrechen</button>
        <button class="btn btn--primary" data-label-new="Anlegen" data-label-edit="Speichern">Anlegen</button>
      </div>
    </form>
  </div>
</div>
<?php
$content = ob_get_clean();
$title = 'Schulen';
require APP_PATH . '/pages/_layout.php';
