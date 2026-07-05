<?php
/** Schulen verwalten (nur Projektleitung). */
declare(strict_types=1);

Auth::require('admin');

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
        if ($name === '') {
            flash('error', 'Name ist erforderlich.');
        } elseif ($id > 0) {
            Database::run('UPDATE schools SET name=?, short_name=?, city=?, note=? WHERE id=?',
                [$name, $short ?: null, $city ?: null, $note ?: null, $id]);
            flash('success', 'Schule aktualisiert.');
        } else {
            Database::run('INSERT INTO schools (name, short_name, city, note) VALUES (?,?,?,?)',
                [$name, $short ?: null, $city ?: null, $note ?: null]);
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

ob_start(); ?>
<div class="page-head"><h1>Schulen</h1></div>
<div class="grid cols-2">
  <div class="card">
    <div class="card__head"><?= $edit ? 'Schule bearbeiten' : 'Neue Schule' ?></div>
    <div class="card__body">
      <form method="post" action="<?= url('schools') ?>">
        <?= Csrf::field() ?>
        <input type="hidden" name="id" value="<?= (int) ($edit['id'] ?? 0) ?>">
        <div class="field"><label>Name *</label><input type="text" name="name" required value="<?= e($edit['name'] ?? '') ?>"></div>
        <div class="field"><label>Kürzel</label><input type="text" name="short_name" value="<?= e($edit['short_name'] ?? '') ?>" placeholder="z. B. EGF"></div>
        <div class="field"><label>Ort</label><input type="text" name="city" value="<?= e($edit['city'] ?? '') ?>"></div>
        <div class="field"><label>Notiz</label><textarea name="note" rows="2"><?= e($edit['note'] ?? '') ?></textarea></div>
        <button class="btn btn--primary"><?= $edit ? 'Speichern' : 'Anlegen' ?></button>
        <?php if ($edit): ?><a href="<?= url('schools') ?>" class="btn btn--ghost">Abbrechen</a><?php endif; ?>
      </form>
    </div>
  </div>
  <div class="card">
    <div class="card__head"><?= count($schools) ?> Schulen</div>
    <div class="table-wrap">
      <table class="data">
        <thead><tr><th>Schule</th><th>Ort</th><th>Teams</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($schools as $s): ?>
          <tr>
            <td><strong><?= e($s['name']) ?></strong><?php if ($s['short_name']): ?> <span class="pill muted"><?= e($s['short_name']) ?></span><?php endif; ?></td>
            <td><?= e($s['city'] ?? '—') ?></td>
            <td><?= (int) $s['teams'] ?></td>
            <td style="white-space:nowrap;text-align:right">
              <a href="<?= url('schools', ['edit' => $s['id']]) ?>" class="btn btn--ghost btn--sm">Bearbeiten</a>
              <form method="post" action="<?= url('schools') ?>" style="display:inline" data-confirm="Schule „<?= e($s['name']) ?>“ inkl. Teams wirklich löschen?">
                <?= Csrf::field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int) $s['id'] ?>">
                <button class="btn btn--danger btn--sm">Löschen</button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$schools): ?><tr><td colspan="4" class="muted">Noch keine Schulen.</td></tr><?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>
<?php
$content = ob_get_clean();
$title = 'Schulen';
require APP_PATH . '/pages/_layout.php';
