<?php
/** Sponsoren verwalten (Admin & Projektleitung): Stammdaten, Logo, Beiträge je Jahr. */
declare(strict_types=1);

Auth::requireManager();

// Wettbewerbsjahr = aktiver Zyklus (einzige Quelle). Beiträge hängen am Zyklus.
$cycles      = Cycle::all();
$activeCycle  = Cycle::active();
$activeCycleId = (int) ($activeCycle['id'] ?? 0);
$activeLabel  = (string) ($activeCycle['year_label'] ?? '—');

/** Bild-Upload verarbeiten → gibt logo_path (relativ zu assets/) zurück oder null. */
$handleLogo = function (): ?string {
    if (empty($_FILES['logo']['name']) || !is_uploaded_file($_FILES['logo']['tmp_name'])) {
        return null;
    }
    $f = $_FILES['logo'];
    if ($f['error'] !== UPLOAD_ERR_OK || $f['size'] > (int) cfg('upload_max_bytes')) {
        flash('error', 'Logo konnte nicht hochgeladen werden (zu groß?).');
        return null;
    }
    $ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
    $allowed = ['png', 'jpg', 'jpeg', 'gif', 'webp', 'svg'];
    if (!in_array($ext, $allowed, true)) {
        flash('error', 'Nur Bilddateien (PNG, JPG, WEBP, GIF, SVG).');
        return null;
    }
    $dir = ROOT_PATH . '/assets/uploads/logos';
    if (!is_dir($dir)) { @mkdir($dir, 0775, true); }
    $stored = 'sp_' . bin2hex(random_bytes(8)) . '.' . preg_replace('/[^a-z0-9]/', '', $ext);
    if (!move_uploaded_file($f['tmp_name'], $dir . '/' . $stored)) {
        flash('error', 'Logo-Upload fehlgeschlagen.');
        return null;
    }
    return 'uploads/logos/' . $stored;
};

if (is_post()) {
    Csrf::check();
    $action = (string) input('action');

    if ($action === 'save_sponsor') {
        $id = (int) input('id', 0);
        $name = trim((string) input('name'));
        if ($name === '') { flash('error', 'Name ist erforderlich.'); redirect(url('sponsors')); }
        $fields = [
            $name, trim((string) input('address')) ?: null, trim((string) input('contact_name')) ?: null,
            trim((string) input('email')) ?: null, trim((string) input('website')) ?: null,
        ];
        $logo = $handleLogo();
        if ($id > 0) {
            Database::run('UPDATE sponsors SET name=?, address=?, contact_name=?, email=?, website=? WHERE id=?',
                array_merge($fields, [$id]));
            if ($logo) { Database::run('UPDATE sponsors SET logo_path=? WHERE id=?', [$logo, $id]); }
            flash('success', 'Sponsor gespeichert.');
            redirect(url('sponsors', ['edit' => $id]));
        } else {
            $nid = Database::insert('INSERT INTO sponsors (name, address, contact_name, email, website, logo_path) VALUES (?,?,?,?,?,?)',
                array_merge($fields, [$logo]));
            flash('success', 'Sponsor angelegt.');
            redirect(url('sponsors', ['edit' => $nid]));
        }
    }

    if ($action === 'delete_sponsor') {
        Database::run('DELETE FROM sponsors WHERE id = ?', [(int) input('id')]);
        flash('success', 'Sponsor gelöscht.');
        redirect(url('sponsors'));
    }

    if ($action === 'add_contribution') {
        $sid = (int) input('sponsor_id');
        $cycleId = (int) input('cycle_id', $activeCycleId);
        $amountRaw = trim((string) input('amount'));
        $amount = $amountRaw === '' ? null : (float) str_replace(',', '.', $amountRaw);
        $desc = trim((string) input('description')) ?: null;
        $cycleOk = $cycleId > 0 && Cycle::find($cycleId) !== null;
        if ($sid && $cycleOk && ($amount !== null || $desc)) {
            Database::run('INSERT INTO sponsor_contributions (sponsor_id, cycle_id, amount, description) VALUES (?,?,?,?)',
                [$sid, $cycleId, $amount, $desc]);
            flash('success', 'Beitrag hinzugefügt.');
        } else {
            flash('error', 'Wettbewerbsjahr und Betrag oder Sachleistung angeben.');
        }
        redirect(url('sponsors', ['edit' => $sid]));
    }

    if ($action === 'del_contribution') {
        $c = Database::one('SELECT * FROM sponsor_contributions WHERE id = ?', [(int) input('id')]);
        if ($c) { Database::run('DELETE FROM sponsor_contributions WHERE id = ?', [(int) $c['id']]); }
        redirect(url('sponsors', ['edit' => (int) ($c['sponsor_id'] ?? 0)]));
    }
}

$edit = null; $contribs = [];
if ($eid = (int) input('edit', 0)) {
    $edit = Database::one('SELECT * FROM sponsors WHERE id = ?', [$eid]);
    if ($edit) {
        $contribs = Database::all(
            'SELECT c.*, cy.year_label FROM sponsor_contributions c
             JOIN competition_cycles cy ON cy.id = c.cycle_id
             WHERE c.sponsor_id = ? ORDER BY cy.year_label DESC, c.id', [$eid]
        );
    }
}
$sponsors = Database::all(
    "SELECT s.*, (SELECT COUNT(*) FROM sponsor_contributions c WHERE c.sponsor_id=s.id AND c.cycle_id=?) AS active_now,
            (SELECT COUNT(*) FROM sponsor_contributions c WHERE c.sponsor_id=s.id) AS n_contrib
     FROM sponsors s ORDER BY s.name", [$activeCycleId]
);
$money = fn($a) => number_format((float) $a, 2, ',', '.') . ' €';

ob_start(); ?>
<div class="page-head">
  <h1>Sponsoren</h1>
  <?php if (!$edit): ?><a href="<?= url('sponsors', ['edit' => 'new']) ?>" class="btn btn--teal">+ Neuer Sponsor</a><?php endif; ?>
</div>

<?php if ($edit !== null || input('edit') === 'new'): $isNew = ($edit === null); ?>
  <div class="grid cols-2">
    <div class="card">
      <div class="card__head"><?= $isNew ? 'Neuer Sponsor' : 'Sponsor bearbeiten' ?></div>
      <div class="card__body">
        <?php if (!$isNew && $edit['logo_path']): ?>
          <img src="<?= asset($edit['logo_path']) ?>" alt="" style="max-height:60px;margin-bottom:12px">
        <?php endif; ?>
        <form method="post" action="<?= url('sponsors') ?>" enctype="multipart/form-data">
          <?= Csrf::field() ?><input type="hidden" name="action" value="save_sponsor"><input type="hidden" name="id" value="<?= (int) ($edit['id'] ?? 0) ?>">
          <div class="field"><label>Name *</label><input type="text" name="name" required value="<?= e($edit['name'] ?? '') ?>"></div>
          <div class="field"><label>Anschrift</label><textarea name="address" rows="2"><?= e($edit['address'] ?? '') ?></textarea></div>
          <div class="field"><label>Ansprechpartner</label><input type="text" name="contact_name" value="<?= e($edit['contact_name'] ?? '') ?>"></div>
          <div class="grid cols-2">
            <div class="field"><label>E-Mail</label><input type="email" name="email" value="<?= e($edit['email'] ?? '') ?>"></div>
            <div class="field"><label>Website</label><input type="text" name="website" value="<?= e($edit['website'] ?? '') ?>"></div>
          </div>
          <div class="field"><label>Logo <?= $isNew ? '' : '(ersetzen)' ?></label><input type="file" name="logo" accept="image/*"></div>
          <button class="btn btn--primary">Speichern</button>
          <a href="<?= url('sponsors') ?>" class="btn btn--ghost">Zurück</a>
        </form>
      </div>
    </div>

    <div class="card">
      <div class="card__head">Beiträge / Zuwendungen</div>
      <div class="card__body">
        <?php if ($isNew): ?>
          <p class="muted">Sponsor zuerst speichern, dann Beiträge erfassen.</p>
        <?php else: ?>
          <table class="data" style="margin-bottom:14px">
            <thead><tr><th>Wettbewerbsjahr</th><th>Betrag</th><th>Leistung</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($contribs as $c): ?>
              <tr>
                <td><strong><?= e($c['year_label']) ?></strong></td>
                <td><?= $c['amount'] !== null ? $money($c['amount']) : '<span class="muted">–</span>' ?></td>
                <td><?= e($c['description'] ?? '') ?></td>
                <td style="text-align:right"><form method="post" action="<?= url('sponsors') ?>" style="display:inline">
                  <?= Csrf::field() ?><input type="hidden" name="action" value="del_contribution"><input type="hidden" name="id" value="<?= (int) $c['id'] ?>">
                  <button class="btn btn--ghost btn--sm">×</button></form></td>
              </tr>
            <?php endforeach; ?>
            <?php if (!$contribs): ?><tr><td colspan="4" class="muted">Noch keine Beiträge.</td></tr><?php endif; ?>
            </tbody>
          </table>
          <?php if (!$cycles): ?>
            <p class="muted">Zuerst unter „Wettbewerbsjahre“ ein Jahr anlegen, dann Beiträge erfassen.</p>
          <?php else: ?>
          <form method="post" action="<?= url('sponsors') ?>">
            <?= Csrf::field() ?><input type="hidden" name="action" value="add_contribution"><input type="hidden" name="sponsor_id" value="<?= (int) $edit['id'] ?>">
            <div style="display:flex;gap:8px;align-items:end;flex-wrap:wrap">
              <div><label>Wettbewerbsjahr</label>
                <select name="cycle_id" style="min-width:120px">
                  <?php foreach ($cycles as $cy): ?>
                    <option value="<?= (int) $cy['id'] ?>" <?= (int) $cy['id'] === $activeCycleId ? 'selected' : '' ?>><?= e($cy['year_label']) ?><?= $cy['is_active'] ? ' •' : '' ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div><label>Betrag (€)</label><input type="text" name="amount" placeholder="z. B. 500" style="width:110px"></div>
              <div style="flex:1;min-width:160px"><label>oder Sachleistung</label><input type="text" name="description" placeholder="z. B. kostenfreier Bustransfer"></div>
              <button class="btn btn--teal">+</button>
            </div>
          </form>
          <?php endif; ?>
        <?php endif; ?>
      </div>
    </div>
  </div>
<?php else: ?>
  <div class="card">
    <div class="table-wrap">
      <table class="data">
        <thead><tr><th></th><th>Sponsor</th><th>Ansprechpartner</th><th>E-Mail</th><th><?= e($activeLabel) ?> aktiv</th><th>Beiträge</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($sponsors as $s): ?>
          <tr>
            <td style="width:52px"><?php if ($s['logo_path']): ?><img src="<?= asset($s['logo_path']) ?>" alt="" style="width:44px;height:36px;object-fit:contain"><?php endif; ?></td>
            <td><strong><?= e($s['name']) ?></strong></td>
            <td><?= e($s['contact_name'] ?? '—') ?></td>
            <td><?= $s['email'] ? '<a href="mailto:' . e($s['email']) . '">' . e($s['email']) . '</a>' : '—' ?></td>
            <td><?= $s['active_now'] ? '<span class="pill teal">ja</span>' : '<span class="pill muted">nein</span>' ?></td>
            <td><?= (int) $s['n_contrib'] ?></td>
            <td style="white-space:nowrap;text-align:right">
              <a href="<?= url('sponsors', ['edit' => $s['id']]) ?>" class="btn btn--ghost btn--sm">Öffnen</a>
              <form method="post" action="<?= url('sponsors') ?>" style="display:inline" data-confirm="Sponsor „<?= e($s['name']) ?>“ löschen?">
                <?= Csrf::field() ?><input type="hidden" name="action" value="delete_sponsor"><input type="hidden" name="id" value="<?= (int) $s['id'] ?>">
                <button class="btn btn--danger btn--sm">×</button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$sponsors): ?><tr><td colspan="7" class="muted">Noch keine Sponsoren.</td></tr><?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
  <p class="muted mt" style="font-size:13px">Logos erscheinen automatisch im Dashboard, sobald ein Sponsor im aktiven Wettbewerbsjahr (<?= e($activeLabel) ?>) eine Leistung erbringt. Das aktive Jahr wird unter „Wettbewerbsjahre“ festgelegt.</p>
<?php endif; ?>
<?php
$content = ob_get_clean();
$title = 'Sponsoren';
require APP_PATH . '/pages/_layout.php';
