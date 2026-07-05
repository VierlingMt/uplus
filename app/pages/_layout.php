<?php
/** Haupt-Layout (Sidebar + Topbar). Erwartet $content und optional $title. */
declare(strict_types=1);

$u = Auth::user();
$role = Auth::role();
$current = $_GET['r'] ?? 'dashboard';

$nav = [
    ['dashboard', 'Dashboard', '▦', ['admin', 'teacher', 'juror']],
    ['plans',     'Businesspläne', '📄', ['admin', 'teacher', 'juror']],
    ['ranking',   'Bewertung & Ranking', '★', ['admin', 'juror']],
    ['teams',     'Teams & Schüler', '👥', ['admin', 'teacher']],
    ['schools',   'Schulen', '🏫', ['admin']],
    ['jurors',    'Jury & Nutzer', '⚖', ['admin']],
    ['sponsors',  'Sponsoren', '🤝', ['admin']],
    ['materials', 'Material & Vorlagen', '📎', ['admin', 'teacher', 'juror']],
    ['admin',     'Admin', '⚙', ['admin']],
];
$roleLabel = ['admin' => 'Projektleitung', 'teacher' => 'Lehrkraft', 'juror' => 'Jury'][$role] ?? $role;
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
<link rel="stylesheet" href="<?= asset('css/app.css') ?>">
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
    <nav class="nav">
      <?php foreach ($nav as [$r, $label, $ic, $roles]): ?>
        <?php if (in_array($role, $roles, true)): ?>
          <a href="<?= url($r) ?>" class="<?= $current === $r ? 'active' : '' ?>" title="<?= e($label) ?>"><span class="ic"><?= $ic ?></span><span class="lbl"><?= e($label) ?></span></a>
        <?php endif; ?>
      <?php endforeach; ?>
    </nav>
    <div class="sidebar__foot">
      Businessplanwettbewerb<br>der Wirtschaftsjunioren Forchheim
      <div style="margin-top:8px">
        <a href="<?= url('changelog') ?>" style="color:#9fb2d6;text-decoration:none" title="Changelog anzeigen">Version <?= e(APP_VERSION) ?> ↗</a>
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
        <span class="badge-role"><?= e($roleLabel) ?></span>
        <a href="<?= url('profile') ?>" style="text-decoration:none;color:var(--ink)"><?= e($u['name'] ?? '') ?></a>
        <a href="<?= url('logout') ?>" class="btn btn--ghost btn--sm">Abmelden</a>
      </div>
    </div>

    <div class="content">
      <?php foreach (flashes() as $f): ?>
        <div class="flash <?= e($f['type']) ?>"><?= e($f['message']) ?></div>
      <?php endforeach; ?>
      <?= $content ?>
    </div>
  </div>
</div>
<script src="<?= asset('js/app.js') ?>"></script>
</body>
</html>
