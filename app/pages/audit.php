<?php
/**
 * Audit-Log (Verwaltung): wer hat wann was geändert – inkl. Login und
 * Login-Versuchen. Filter- und sortierbare Tabelle mit Paginierung.
 */
declare(strict_types=1);

Access::requireRead('audit');

// --- Filter aus der URL ---------------------------------------------------
$q       = trim((string) input('q', ''));
$fAction = trim((string) input('action', ''));   // exakte Aktion (z. B. login.success)
$fGroup  = trim((string) input('group', ''));     // Aktions-Gruppe (Präfix vor dem Punkt)
$from    = trim((string) input('from', ''));      // Datum von (YYYY-MM-DD)
$to      = trim((string) input('to', ''));        // Datum bis (YYYY-MM-DD)
$page    = max(1, (int) input('p', 1));
$perPage = 50;

// Lesbare Namen der Aktions-Gruppen (Präfixe).
$groupLabels = [
    'login'       => 'Login & Anmeldung',
    'logout'      => 'Abmeldung',
    'impersonate' => 'Ansehen-als',
    'eval'        => 'Bewertungen',
    'plan'        => 'Businesspläne',
    'team'        => 'Teams',
    'student'     => 'Schüler',
    'school'      => 'Schulen',
    'teacher'     => 'Projektlehrer',
    'user'        => 'Jury & Nutzer',
    'cycle'       => 'Wettbewerbsjahre',
    'sponsor'     => 'Sponsoren',
    'contribution'=> 'Sponsoren-Beiträge',
    'material'    => 'Material',
    'settings'    => 'Einstellungen',
    'profile'     => 'Profil (E-Mail/Handy/Foto)',
];

// Menschlich lesbare Beschriftung einer Aktion.
$actionLabel = function (string $a): string {
    static $map = [
        'login.success'      => 'Login erfolgreich',
        'login.logout'       => 'Abgemeldet',
        'login.link_requested' => 'Login-Link angefordert',
        'login.link_invalid' => 'Login-Link ungültig',
        'login.sms_requested'=> 'SMS-Code angefordert',
        'login.sms_failed'   => 'SMS-Code fehlgeschlagen',
    ];
    return $map[$a] ?? $a;
};

// --- WHERE dynamisch aufbauen --------------------------------------------
$where  = [];
$params = [];
if ($q !== '') {
    $where[] = '(actor LIKE ? OR summary LIKE ? OR action LIKE ?)';
    $like = '%' . $q . '%';
    array_push($params, $like, $like, $like);
}
if ($fAction !== '') {
    $where[] = 'action = ?';
    $params[] = $fAction;
} elseif ($fGroup !== '') {
    $where[] = 'action LIKE ?';
    $params[] = $fGroup . '.%';
}
if ($from !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) {
    $where[] = 'created_at >= ?';
    $params[] = $from . ' 00:00:00';
}
if ($to !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
    $where[] = 'created_at <= ?';
    $params[] = $to . ' 23:59:59';
}
$whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

$total = (int) Database::value("SELECT COUNT(*) FROM audit_log $whereSql", $params);
$pages = max(1, (int) ceil($total / $perPage));
$page  = min($page, $pages);
$offset = ($page - 1) * $perPage;

$rows = Database::all(
    "SELECT * FROM audit_log $whereSql ORDER BY id DESC LIMIT $perPage OFFSET $offset",
    $params
);

// Vorhandene Aktions-Gruppen (für das Dropdown) aus dem Log ableiten.
$groupsPresent = array_map(
    static fn($r) => (string) $r['g'],
    Database::all("SELECT DISTINCT SUBSTRING_INDEX(action, '.', 1) AS g FROM audit_log ORDER BY g")
);

// Icon je Gruppe für die kompakte Ansicht.
$groupIcon = [
    'login' => '🔑', 'logout' => '🚪', 'impersonate' => '👁', 'eval' => '★',
    'plan' => '📄', 'team' => '👥', 'student' => '🎓', 'school' => '🏫',
    'teacher' => '👩‍🏫', 'user' => '⚖', 'cycle' => '🏆', 'sponsor' => '🤝',
    'contribution' => '🤝', 'material' => '📎', 'settings' => '⚙', 'profile' => '👤',
];

// Hilfsfunktion: Link mit übernommenen Filtern (nur $page ändert sich).
$pageUrl = function (int $p) use ($q, $fAction, $fGroup, $from, $to): string {
    $args = ['p' => $p];
    if ($q !== '')       { $args['q'] = $q; }
    if ($fAction !== '') { $args['action'] = $fAction; }
    if ($fGroup !== '')  { $args['group'] = $fGroup; }
    if ($from !== '')    { $args['from'] = $from; }
    if ($to !== '')      { $args['to'] = $to; }
    return url('audit', $args);
};

ob_start(); ?>
<div class="page-head">
  <h1>Audit-Log</h1>
  <span class="muted" style="font-size:13px"><?= number_format($total, 0, ',', '.') ?> Einträge</span>
</div>
<p class="muted" style="margin-top:-6px;max-width:720px">
  Protokoll aller Änderungen – wer wann was geändert hat – inklusive Anmeldungen und
  Anmelde-Versuchen. Neueste zuerst. Spalten lassen sich per Klick sortieren.
</p>

<div class="card mb">
  <div class="card__body">
    <form method="get" action="<?= url('audit') ?>" class="audit-filter">
      <input type="hidden" name="r" value="audit">
      <div class="field">
        <label>Suche (Akteur, Beschreibung, Aktion)</label>
        <input type="search" name="q" value="<?= e($q) ?>" placeholder="z. B. Name, E-Mail, „gelöscht“ …">
      </div>
      <div class="field">
        <label>Bereich</label>
        <select name="group">
          <option value="">– alle Bereiche –</option>
          <?php foreach ($groupsPresent as $g): if ($g === '') continue; ?>
            <option value="<?= e($g) ?>" <?= $fGroup === $g ? 'selected' : '' ?>><?= e($groupLabels[$g] ?? $g) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="field">
        <label>Von</label>
        <input type="date" name="from" value="<?= e($from) ?>">
      </div>
      <div class="field">
        <label>Bis</label>
        <input type="date" name="to" value="<?= e($to) ?>">
      </div>
      <div class="audit-filter__actions">
        <button class="btn btn--primary">Filtern</button>
        <?php if ($q !== '' || $fGroup !== '' || $fAction !== '' || $from !== '' || $to !== ''): ?>
          <a href="<?= url('audit') ?>" class="btn btn--ghost">Zurücksetzen</a>
        <?php endif; ?>
      </div>
    </form>
    <?php if ($fAction !== ''): ?>
      <p class="muted" style="font-size:13px;margin:10px 0 0">Gefiltert nach Aktion
        <strong><?= e($actionLabel($fAction)) ?></strong> · <a href="<?= url('audit', array_filter(['q' => $q, 'group' => $fGroup, 'from' => $from, 'to' => $to])) ?>">Aktionsfilter entfernen</a></p>
    <?php endif; ?>
  </div>
</div>

<div class="card">
  <div class="table-wrap">
    <table class="data data--cards" id="auditTable">
      <thead><tr><th>Zeitpunkt</th><th>Akteur</th><th>Aktion</th><th>Beschreibung</th><th>IP</th></tr></thead>
      <tbody>
      <?php foreach ($rows as $r):
          $g = explode('.', (string) $r['action'])[0];
          $ts = strtotime((string) $r['created_at']);
      ?>
        <tr>
          <td data-label="Zeitpunkt" data-sort="<?= e((string) $r['created_at']) ?>" style="white-space:nowrap">
            <?= e(date('d.m.Y', $ts)) ?> <span class="muted"><?= e(date('H:i', $ts)) ?></span>
          </td>
          <td data-label="Akteur"><?= $r['actor'] ? e((string) $r['actor']) : '<span class="muted">System / anonym</span>' ?></td>
          <td data-label="Aktion">
            <span class="pill muted" title="<?= e((string) $r['action']) ?>"><?= e(($groupIcon[$g] ?? '•') . ' ' . ($groupLabels[$g] ?? $g)) ?></span>
          </td>
          <td data-label="Beschreibung"><?= $r['summary'] ? e((string) $r['summary']) : '<span class="muted">—</span>' ?></td>
          <td data-label="IP" class="muted" style="font-size:12px;white-space:nowrap"><?= e((string) ($r['ip'] ?? '')) ?></td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$rows): ?><tr><td colspan="5" class="muted">Keine Einträge für diese Filter.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php if ($pages > 1): ?>
  <div class="audit-pager">
    <?php if ($page > 1): ?><a class="btn btn--ghost btn--sm" href="<?= $pageUrl($page - 1) ?>">← neuer</a><?php endif; ?>
    <span class="muted" style="font-size:13px">Seite <?= $page ?> / <?= $pages ?></span>
    <?php if ($page < $pages): ?><a class="btn btn--ghost btn--sm" href="<?= $pageUrl($page + 1) ?>">älter →</a><?php endif; ?>
  </div>
<?php endif; ?>

<style>
.audit-filter{display:grid;grid-template-columns:2fr 1.4fr 1fr 1fr;gap:12px;align-items:end}
.audit-filter .field{margin:0}
.audit-filter__actions{grid-column:1/-1;display:flex;gap:8px}
.audit-pager{display:flex;align-items:center;justify-content:center;gap:14px;margin-top:14px}
@media(max-width:680px){.audit-filter{grid-template-columns:1fr 1fr}.audit-filter .field:first-child{grid-column:1/-1}}
</style>
<?php
$content = ob_get_clean();
$title = 'Audit-Log';
require APP_PATH . '/pages/_layout.php';
