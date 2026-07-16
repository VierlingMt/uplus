<?php
/** Sponsoren verwalten (Admin & Projektleitung): Stammdaten, Logo, Beiträge je Jahr. */
declare(strict_types=1);

Access::requireRead('sponsors');

// Wettbewerbsjahr = aktiver Zyklus (einzige Quelle). Beiträge hängen am Zyklus.
$cycles      = Cycle::all();
$activeCycle  = Cycle::active();
$activeCycleId = (int) ($activeCycle['id'] ?? 0);
$activeLabel  = (string) ($activeCycle['year_label'] ?? '—');

if (is_post()) {
    Access::requireWrite('sponsors');
    Csrf::check();
    $action = (string) input('action');

    if ($action === 'save_sponsor') {
        $id = (int) input('id', 0);
        $name = trim((string) input('name'));
        if ($name === '') { flash('error', 'Name ist erforderlich.'); redirect(url('sponsors')); }
        $fields = [
            $name, trim((string) input('address')) ?: null, trim((string) input('contact_name')) ?: null,
            trim((string) input('email')) ?: null, trim((string) input('website')) ?: null,
            trim((string) input('notes')) ?: null,
        ];
        $logo = save_image('logo', 'sp', 'logos');
        if ($id > 0) {
            Database::run('UPDATE sponsors SET name=?, address=?, contact_name=?, email=?, website=?, notes=? WHERE id=?',
                array_merge($fields, [$id]));
            if ($logo) { Database::run('UPDATE sponsors SET logo_path=? WHERE id=?', [$logo, $id]); }
            Audit::log('sponsor.update', 'Sponsor bearbeitet: ' . $name, 'sponsor', $id);
            flash('success', 'Sponsor gespeichert.');
            redirect(url('sponsors', ['edit' => $id]));
        } else {
            $nid = Database::insert('INSERT INTO sponsors (name, address, contact_name, email, website, notes, logo_path) VALUES (?,?,?,?,?,?,?)',
                array_merge($fields, [$logo]));
            Audit::log('sponsor.create', 'Sponsor angelegt: ' . $name, 'sponsor', $nid);
            flash('success', 'Sponsor angelegt.');
            redirect(url('sponsors', ['edit' => $nid]));
        }
    }

    if ($action === 'delete_sponsor') {
        $sid = (int) input('id');
        $sn = (string) Database::value('SELECT name FROM sponsors WHERE id = ?', [$sid]);
        Database::run('DELETE FROM sponsors WHERE id = ?', [$sid]);
        Audit::log('sponsor.delete', 'Sponsor gelöscht: ' . ($sn ?: ('#' . $sid)), 'sponsor', $sid);
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
            Audit::log('sponsor.contribution_add', 'Sponsorenbeitrag erfasst (' . ($amount !== null ? number_format($amount, 2, ',', '.') . ' €' : $desc) . ')', 'sponsor', $sid);
            flash('success', 'Beitrag hinzugefügt.');
        } else {
            flash('error', 'Wettbewerbsjahr und Betrag oder Sachleistung angeben.');
        }
        redirect(url('sponsors', ['edit' => $sid]));
    }

    if ($action === 'del_contribution') {
        $c = Database::one('SELECT * FROM sponsor_contributions WHERE id = ?', [(int) input('id')]);
        if ($c) {
            Database::run('DELETE FROM sponsor_contributions WHERE id = ?', [(int) $c['id']]);
            Audit::log('sponsor.contribution_del', 'Sponsorenbeitrag gelöscht', 'sponsor', (int) $c['sponsor_id']);
        }
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
            (SELECT COALESCE(SUM(amount),0) FROM sponsor_contributions c WHERE c.sponsor_id=s.id AND c.cycle_id=?) AS amount_now,
            (SELECT COUNT(*) FROM sponsor_contributions c WHERE c.sponsor_id=s.id) AS n_contrib
     FROM sponsors s ORDER BY s.name", [$activeCycleId, $activeCycleId]
);
$sumNow = array_sum(array_map(fn($s) => (float) $s['amount_now'], $sponsors));
$money = fn($a) => number_format((float) $a, 2, ',', '.') . ' €';

$fill = fn(array $s) => e(json_encode([
    'id' => (int) $s['id'], 'name' => $s['name'], 'address' => $s['address'],
    'contact_name' => $s['contact_name'], 'email' => $s['email'], 'website' => $s['website'],
    'notes' => $s['notes'] ?? '',
], JSON_UNESCAPED_UNICODE));
$imgs = fn(array $s) => $s['logo_path'] ? e(json_encode(['logo' => asset($s['logo_path'])], JSON_UNESCAPED_UNICODE)) : '';
$noteAuthor = trim((string) (Auth::user()['name'] ?? ''));
ob_start(); ?>
<div class="page-head">
  <h1>Sponsoren</h1>
  <?php if (!$edit): ?><button type="button" class="btn btn--teal" data-modal-open="sponsorModal">+ Neu</button><?php endif; ?>
</div>

<?php if ($edit !== null): ?>
  <div style="margin-bottom:14px"><a href="<?= url('sponsors') ?>" class="btn btn--ghost btn--sm">← Zurück zur Übersicht</a></div>
  <div class="grid cols-2">
    <div class="card">
      <div class="card__head" style="display:flex;align-items:center;justify-content:space-between;gap:10px">
        <span>Sponsor</span>
        <button type="button" class="btn btn--ghost btn--sm" data-modal-open="sponsorModal" data-fill="<?= $fill($edit) ?>"<?= $imgs($edit) ? ' data-images="' . $imgs($edit) . '"' : '' ?>>Bearbeiten</button>
      </div>
      <div class="card__body">
        <?php if ($edit['logo_path']): ?><img src="<?= asset($edit['logo_path']) ?>" alt="" style="max-height:60px;margin-bottom:12px"><?php endif; ?>
        <h2 style="margin:0 0 10px;font-size:22px"><?= e($edit['name']) ?></h2>
        <?php if ($edit['contact_name']): ?><div class="field" style="margin-bottom:10px"><label>Ansprechpartner</label><div><?= e($edit['contact_name']) ?></div></div><?php endif; ?>
        <?php if ($edit['address']): ?><div class="field" style="margin-bottom:10px"><label>Anschrift</label><div class="muted"><?= nl2br(e($edit['address'])) ?></div></div><?php endif; ?>
        <div class="grid cols-2">
          <?php if ($edit['email']): ?><div class="field" style="margin:0"><label>E-Mail</label><div><a href="mailto:<?= e($edit['email']) ?>"><?= e($edit['email']) ?></a></div></div><?php endif; ?>
          <?php if ($edit['website']): ?><div class="field" style="margin:0"><label>Website</label><div><?= e($edit['website']) ?></div></div><?php endif; ?>
        </div>
        <?php if (trim((string) ($edit['notes'] ?? '')) !== ''): ?>
          <div class="field" style="margin:12px 0 0"><label>Notizen / Absprachen</label><div class="muted" style="white-space:pre-wrap"><?= e($edit['notes']) ?></div></div>
        <?php endif; ?>
      </div>
    </div>

    <div class="card">
      <div class="card__head">Beiträge / Zuwendungen</div>
      <div class="card__body">
          <table class="data data--cards" style="margin-bottom:14px">
            <thead><tr><th>Wettbewerbsjahr</th><th>Betrag</th><th>Leistung</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($contribs as $c): ?>
              <tr>
                <td data-label="Wettbewerbsjahr"><strong><?= e($c['year_label']) ?></strong></td>
                <td data-label="Betrag"><?= $c['amount'] !== null ? $money($c['amount']) : '<span class="muted">–</span>' ?></td>
                <td data-label="Leistung"><?= e($c['description'] ?? '') ?></td>
                <td class="row-actions" style="text-align:right"><form method="post" action="<?= url('sponsors') ?>" style="display:inline" data-confirm="Beitrag löschen?">
                  <?= Csrf::field() ?><input type="hidden" name="action" value="del_contribution"><input type="hidden" name="id" value="<?= (int) $c['id'] ?>">
                  <button class="btn btn--ghost btn--sm">Löschen</button></form></td>
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
      </div>
    </div>
  </div>
<?php else: ?>
  <div class="card">
    <div class="table-wrap">
      <table class="data data--cards">
        <thead><tr><th>Sponsor</th><th>Ansprechpartner</th><th>E-Mail</th><th>Beitrag <?= e($activeLabel) ?></th><th>Beiträge</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($sponsors as $s): ?>
          <tr>
            <td data-label="Sponsor">
              <div class="sponsor-cell">
                <?php if ($s['logo_path']): ?>
                  <img class="sponsor-cell__logo" src="<?= asset($s['logo_path']) ?>" alt="Logo <?= e($s['name']) ?>">
                <?php else: ?>
                  <span class="sponsor-cell__logo sponsor-cell__logo--empty" aria-hidden="true"><?= e(mb_strtoupper(mb_substr((string) $s['name'], 0, 1))) ?></span>
                <?php endif; ?>
                <strong><?= e($s['name']) ?></strong>
              </div>
            </td>
            <td data-label="Ansprechpartner"><?= e($s['contact_name'] ?? '—') ?></td>
            <td data-label="E-Mail"><?= $s['email'] ? '<a href="mailto:' . e($s['email']) . '">' . e($s['email']) . '</a>' : '—' ?></td>
            <td data-label="Beitrag <?= e($activeLabel) ?>"><?= (float) $s['amount_now'] > 0 ? '<strong>' . e($money($s['amount_now'])) . '</strong>' : '<span class="muted">–</span>' ?></td>
            <td data-label="Beiträge"><?= (int) $s['n_contrib'] ?></td>
            <td class="row-actions" style="white-space:nowrap;text-align:right">
              <a href="<?= url('sponsors', ['edit' => $s['id']]) ?>" class="btn btn--ghost btn--sm" title="Sponsor öffnen: Beiträge verwalten">💶 Beiträge</a>
              <button type="button" class="btn btn--ghost btn--sm" data-modal-open="sponsorModal" data-fill="<?= $fill($s) ?>"<?= $imgs($s) ? ' data-images="' . $imgs($s) . '"' : '' ?>>Bearbeiten</button>
              <form method="post" action="<?= url('sponsors') ?>" style="display:inline" data-confirm="Sponsor „<?= e($s['name']) ?>“ löschen?">
                <?= Csrf::field() ?><input type="hidden" name="action" value="delete_sponsor"><input type="hidden" name="id" value="<?= (int) $s['id'] ?>">
                <button class="btn btn--danger btn--sm">Löschen</button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$sponsors): ?><tr><td colspan="6" class="muted">Noch keine Sponsoren.</td></tr><?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
  <?php if ($sponsors): ?>
    <div style="text-align:right;margin-top:10px;font-size:15px">Summe <strong><?= e($activeLabel) ?></strong>: <span class="pill teal" style="font-size:15px"><?= e($money($sumNow)) ?></span></div>
  <?php endif; ?>
  <p class="muted mt" style="font-size:13px">Logos erscheinen automatisch im Dashboard, sobald ein Sponsor im aktiven Wettbewerbsjahr (<?= e($activeLabel) ?>) eine Leistung erbringt. Das aktive Jahr wird unter „Wettbewerbsjahre“ festgelegt.</p>
<?php endif; ?>

<div class="modal-overlay" id="sponsorModal" hidden>
  <div class="modal modal--form" role="dialog" aria-modal="true" aria-labelledby="sponsorModalTitle">
    <div class="modal__head">
      <h3 id="sponsorModalTitle" data-modal-title data-title-new="Neuer Sponsor" data-title-edit="Sponsor bearbeiten">Neuer Sponsor</h3>
      <button type="button" class="modal__close" data-modal-close aria-label="Schließen">&times;</button>
    </div>
    <form method="post" action="<?= url('sponsors') ?>" enctype="multipart/form-data" class="modal__body" data-modal-form>
      <?= Csrf::field() ?><input type="hidden" name="action" value="save_sponsor"><input type="hidden" name="id" value="0">
      <div class="field"><label>Name *</label><input type="text" name="name" required></div>
      <div class="field"><label>Anschrift</label><textarea name="address" rows="2"></textarea></div>
      <div class="field"><label>Ansprechpartner</label><input type="text" name="contact_name"></div>
      <div class="grid cols-2">
        <div class="field"><label>E-Mail</label><input type="email" name="email"></div>
        <div class="field"><label>Website</label><input type="text" name="website"></div>
      </div>
      <div class="field">
        <label>Notizen / Absprachen</label>
        <textarea name="notes" rows="5" data-note-log data-note-author="<?= e($noteAuthor) ?>" placeholder="Beim Klick ins Feld wird automatisch eine neue Zeile mit Datum und Name eingefügt."></textarea>
      </div>
      <?= image_field('logo', null, [
          'label' => 'Logo', 'aspect' => null, 'shape' => 'rect', 'format' => 'png',
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
$title = 'Sponsoren';
require APP_PATH . '/pages/_layout.php';
