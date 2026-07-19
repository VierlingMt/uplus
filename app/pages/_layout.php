<?php
/** Haupt-Layout (Sidebar + Topbar). Erwartet $content und optional $title. */
declare(strict_types=1);

$u = Auth::user();
$role = Auth::role();
$current = $_GET['r'] ?? 'dashboard';

// Menü nach Rollen gruppiert – jede Gruppe erscheint nur, wenn die aktuelle Rolle
// mindestens einen Punkt darin sieht. So ist transparent, wer was sieht.
$navGroups = [
    ['Für alle', [
        ['dashboard', 'Dashboard', '▦', ['admin', 'lead', 'teacher', 'juror']],
        ['plans',     'Businesspläne', '📄', ['admin', 'lead', 'teacher', 'juror']],
        ['materials', 'Material & Vorlagen', '📎', ['admin', 'lead', 'teacher', 'juror']],
        ['gallery', 'Mediengalerie', '📸', ['admin', 'lead', 'teacher', 'juror']],
        ['presentation', 'Präsentation', '🖥', ['admin', 'lead', 'teacher', 'juror']],
        ['contact',   'Kontakt', '✉', ['admin', 'lead', 'teacher', 'juror']],
    ]],
    ['Projekttermine', [
        ['kickoff', 'Kick-Off', '🚀', ['admin', 'lead', 'teacher', 'juror']],
        ['closing', 'Project-Closing', '🎯', ['admin', 'lead', 'teacher', 'juror']],
    ]],
    ['Lehrkraft', [
        ['teams', 'Teams & Schüler', '👥', ['admin', 'lead', 'teacher', 'juror']],
    ]],
    ['Jury', [
        ['jury_feedback', 'Jury-Feedback', '🗣', ['admin', 'lead', 'juror']],
        ['ranking',       'Bewertung & Ranking', '★', ['admin', 'lead', 'juror']],
        ['pitch',         'PitchDay', '🎤', ['admin', 'lead', 'juror']],
    ]],
    ['Verwaltung', [
        ['cycles',   'Wettbewerbsjahre', '🏆', ['admin', 'lead']],
        ['event',    'PitchDay-Orga', '🎤', ['admin', 'lead']],
        ['moderation', 'Moderationskärtchen', '🗂', ['admin', 'lead']],
        ['schools',  'Schulen', '🏫', ['admin', 'lead', 'juror']],
        ['jurors',   'Jury & Nutzer', '⚖', ['admin', 'lead', 'juror']],
        ['sponsors', 'Sponsoren', '🤝', ['admin', 'lead']],
        ['audit',    'Audit-Log', '🧾', ['admin', 'lead']],
        ['admin',    'Admin', '⚙', ['admin', 'lead']],
        ['access',   'Zugriffsmatrix', '🔐', ['admin']],
    ]],
];

// Sichtbarkeit eines Menüpunkts: governte Module über die Zugriffsmatrix
// (Lesen genügt), alle übrigen (z. B. der Editor selbst) über die Rollenliste.
$canSeeNav = function (string $route, array $roles): bool {
    if (class_exists('Access') && array_key_exists($route, Access::MODULES)) {
        return Access::canRead($route);
    }
    return (bool) array_intersect($roles, Auth::roles());
};
// Alle Rollen des Nutzers als Label (Mehrfachrollen möglich).
$roleLabel = class_exists('Roles') && Auth::roles()
    ? implode(' · ', array_map([Roles::class, 'label'], Auth::roles()))
    : (['admin' => 'Admin', 'lead' => 'Projektleitung', 'teacher' => 'Lehrkraft', 'juror' => 'Jury'][$role] ?? $role);

// Route → Anzeigename direkt aus der Navigation ableiten. So wachsen die
// kontextbasierte Hilfe und ihre Suche automatisch mit: Ein neuer Menüpunkt ist
// sofort korrekt beschriftet, ohne dass die Hilfe angepasst werden muss.
$routeLabels = [];
foreach ($navGroups as [$__g, $__items]) {
    foreach ($__items as [$__r, $__label]) { $routeLabels[$__r] = $__label; }
}
$routeLabels += ['profile' => 'Mein Profil', 'changelog' => 'Änderungsverlauf'];

// Datenquelle für Hilfe & Tour (help.js). Die Tour selbst entsteht im Browser
// live aus dem DOM – hier reisen nur die durchsuchbaren Hilfetexte mit.
$helpData = class_exists('Help') ? [
    'route'      => $current,
    'routeLabel' => $routeLabels[$current] ?? ucfirst((string) $current),
    'topics'     => Help::all($routeLabels),
    'tourIntro'  => Help::TOUR_INTRO,
] : null;
?>
<!doctype html>
<html lang="de" data-base="<?= e(rtrim(cfg('base_path', ''), '/')) ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($title ?? 'Unternehmen Plus') ?> – Unternehmen Plus</title>
<link rel="icon" href="<?= asset('img/logo.svg') ?>">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Chivo:wght@400;700;900&family=Bitter:wght@400;500;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= asset('vendor/cropperjs/cropper.min.css') ?>">
<link rel="stylesheet" href="<?= asset('css/app.css') ?>">
<link rel="stylesheet" href="<?= asset('css/help.css') ?>">
<?php require __DIR__ . '/_pwa_head.php'; ?>
</head>
<body>
<div class="app">
  <div class="nav-overlay" data-nav-close hidden></div>
  <aside class="sidebar" id="sidebar">
    <div class="sidebar__brand">
      <img src="<?= asset('img/logo.svg') ?>" alt="Unternehmen Plus">
      <span>Unternehmen<br>Plus</span>
    </div>
    <nav class="nav"<?= tour_attrs('Navigation', 'Über die Seitenleiste erreichst du alle Bereiche – nach Themen gruppiert. Oben links lässt sie sich schmal klappen bzw. auf dem Handy als Menü öffnen.', 10) ?>>
      <?php foreach ($navGroups as [$groupLabel, $items]): ?>
        <?php $visible = array_filter($items, fn($it) => $canSeeNav($it[0], $it[3])); ?>
        <?php if ($visible): ?>
          <div class="nav__group"><?= e($groupLabel) ?></div>
          <?php foreach ($visible as [$r, $label, $ic, $roles]): ?>
            <a href="<?= url($r) ?>" class="<?= $current === $r ? 'active' : '' ?>" title="<?= e($label) ?>"><span class="ic"><?= $ic ?></span><span class="lbl"><?= e($label) ?></span></a>
          <?php endforeach; ?>
        <?php endif; ?>
      <?php endforeach; ?>
    </nav>
    <div class="sidebar__foot">
      <?php $__cycle = class_exists('Cycle') ? Cycle::active() : null; ?>
      <?php if ($__cycle): ?>
        <div style="margin-bottom:8px;color:#cfe0ff">Wettbewerbsjahr <strong><?= e($__cycle['year_label']) ?></strong></div>
      <?php endif; ?>
      Businessplanwettbewerb<br>der Wirtschaftsjunioren Forchheim
      <div style="margin-top:8px">
        <a href="<?= url('changelog') ?>" style="color:#9fb2d6;text-decoration:none" title="Changelog anzeigen">Version <?= e(APP_VERSION) ?> ↗</a>
      </div>
      <div style="margin-top:10px;font-size:11px;line-height:1.5;color:#8194b5" title="App entwickelt von Martin Vierling. Konzept des Wettbewerbs: Wirtschaftsjunioren Forchheim.">
        <?= e(copyright_notice(false)) ?>
      </div>
    </div>
  </aside>

  <div class="main">
    <div class="topbar">
      <button type="button" class="nav-toggle" data-nav-toggle aria-label="Menü ein-/ausklappen" aria-expanded="false" aria-controls="sidebar">
        <span class="nav-toggle__bars" aria-hidden="true"></span>
      </button>
      <div class="topbar__title"><?= e($title ?? 'Dashboard') ?></div>
      <div class="topbar__user">
        <div class="topbar__tools">
          <button type="button" class="help-trigger help-trigger--tour" data-tour-start title="Geführte Tour starten">
            <span aria-hidden="true">🧭</span><span class="help-trigger__lbl">Tour</span>
          </button>
          <button type="button" class="help-trigger help-trigger--round" data-help-open title="Hilfe (F1)" aria-label="Hilfe öffnen"<?= tour_attrs('Hilfe & Suche', 'Hier öffnest du jederzeit die Hilfe (auch mit der Taste F1) und durchsuchst sie – die Fundstellen werden gelb hervorgehoben.', 900) ?>>?</button>
        </div>
        <span class="badge-role"><?= e($roleLabel) ?></span>
        <a href="<?= url('profile') ?>" class="topbar__profile" title="Mein Profil"<?= tour_attrs('Mein Profil', 'Deine eigenen Daten, Foto und Passkey – und über „Abmelden" verlässt du die App.', 910) ?>>
          <?php if (!empty($u['photo_path'])): ?>
            <img class="avatar avatar--sm" src="<?= asset($u['photo_path']) ?>" alt="">
          <?php else: ?>
            <span class="avatar avatar--sm avatar--ph" aria-hidden="true"><?= e(mb_strtoupper(mb_substr((string) ($u['name'] ?? '?'), 0, 1))) ?></span>
          <?php endif; ?>
          <span class="topbar__name"><?= e($u['name'] ?? '') ?></span></a>
        <a href="<?= url('logout') ?>" class="btn btn--ghost btn--sm">Abmelden</a>
      </div>
    </div>

    <?php if (Auth::isImpersonating()): $imp = Auth::impersonator(); ?>
      <div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap;background:#f4c430;color:#3a2d00;padding:10px 16px;font-size:14px;font-weight:500;border-bottom:1px solid #d9ae1f">
        <span>👁 <strong>Ansehen als:</strong> <?= e($u['name'] ?? '') ?> (<?= e($roleLabel) ?>) – Nur-Lese-Ansicht<?php if ($imp): ?> · angemeldet als <?= e($imp['name']) ?><?php endif; ?></span>
        <a href="<?= url('viewstop') ?>" class="btn btn--sm" style="margin-left:auto;background:#3a2d00;color:#fff">Sicht beenden</a>
      </div>
    <?php endif; ?>

    <div class="content">
      <?php foreach (flashes() as $f): ?>
        <div class="flash <?= e($f['type']) ?>"><?= e($f['message']) ?></div>
      <?php endforeach; ?>
      <?= $content ?>
    </div>
  </div>
</div>
<script src="<?= asset('vendor/cropperjs/cropper.min.js') ?>"></script>
<script src="<?= asset('js/app.js') ?>"></script>
<?php if ($helpData !== null): ?>
<script type="application/json" id="help-data"><?= json_encode($helpData, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS) ?></script>
<script src="<?= asset('js/help.js') ?>"></script>
<?php endif; ?>
</body>
</html>
