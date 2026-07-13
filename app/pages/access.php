<?php
/**
 * Zugriffsmatrix: je Modul und Rolle die Stufe „Kein Zugriff / Lesen / Schreiben"
 * festlegen. Nur für den Admin (Super-Admin). Steuert Menüsichtbarkeit und den
 * Lese-/Schreibzugriff der Module. Die admin-Rolle ist immer „Schreiben" und
 * nicht änderbar; das Dashboard bleibt für alle mindestens lesbar.
 */

declare(strict_types=1);

Auth::require();
if (!Auth::isAdmin()) {
    http_response_code(403);
    render('error', ['title' => 'Kein Zugriff', 'message' => 'Die Zugriffsmatrix kann nur der Admin bearbeiten.']);
    return;
}

if (is_post()) {
    Csrf::check();
    Access::save((array) input('level', []));
    Audit::log('access.update', 'Zugriffsmatrix aktualisiert');
    flash('success', 'Zugriffsmatrix gespeichert.');
    redirect(url('access'));
}

$matrix = Access::matrix();
$levelLabels = ['none' => 'Kein Zugriff', 'read' => 'Lesen', 'write' => 'Schreiben'];

ob_start(); ?>
<div class="page-head">
  <h1>Zugriffsmatrix</h1>
</div>

<p class="muted" style="max-width:720px;margin:-8px 0 16px">
  Lege je <strong>Modul</strong> und <strong>Rolle</strong> fest, ob der Bereich <strong>nicht sichtbar</strong>
  (kein Zugriff), <strong>nur lesbar</strong> oder <strong>bearbeitbar</strong> ist. Die Einstellung steuert das
  Menü und den Zugriff auf die Seiten. Der <strong>Admin</strong> hat immer vollen Zugriff und kann nicht gesperrt
  werden. Das <strong>Dashboard</strong> bleibt für alle mindestens lesbar. Änderungen wirken sofort.
</p>
<div class="flash info" style="max-width:720px">
  ⚖ <strong>Jury sieht nur die eigenen Wettbewerbsjahrgänge:</strong> Unabhängig von dieser Matrix sehen
  Juror:innen unter „Jury &amp; Nutzer" ausschließlich Personen aus ihren eigenen Wettbewerbsjahren.
</div>

<form method="post" action="<?= url('access') ?>">
  <?= Csrf::field() ?>
  <div class="card">
    <div class="table-wrap">
      <table class="data">
        <thead>
          <tr>
            <th>Modul</th>
            <?php foreach (Access::ROLES as $rk => $rlabel): ?>
              <th><?= e($rlabel) ?></th>
            <?php endforeach; ?>
          </tr>
        </thead>
        <tbody>
        <?php foreach (Access::MODULES as $mk => $mlabel): ?>
          <tr>
            <td data-label="Modul"><strong><?= e($mlabel) ?></strong></td>
            <?php foreach (Access::ROLES as $rk => $rlabel): ?>
              <td data-label="<?= e($rlabel) ?>">
                <?php if ($rk === 'admin'): ?>
                  <span class="pill blue" title="Der Admin hat immer vollen Zugriff.">Voll</span>
                <?php else:
                  $cur = $matrix[$mk][$rk] ?? 'none';
                  $isDash = $mk === 'dashboard'; ?>
                  <select name="level[<?= e($mk) ?>][<?= e($rk) ?>]" style="min-width:130px">
                    <?php foreach ($levelLabels as $lv => $ll): ?>
                      <?php if ($isDash && $lv === 'none') { continue; } // Dashboard nie sperrbar ?>
                      <option value="<?= e($lv) ?>" <?= $cur === $lv ? 'selected' : '' ?>><?= e($ll) ?></option>
                    <?php endforeach; ?>
                  </select>
                <?php endif; ?>
              </td>
            <?php endforeach; ?>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <div class="card__body" style="display:flex;justify-content:flex-end;gap:10px">
      <button class="btn btn--primary">Speichern</button>
    </div>
  </div>
</form>
<?php
$content = ob_get_clean();
$title = 'Zugriffsmatrix';
require APP_PATH . '/pages/_layout.php';
