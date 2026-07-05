<?php
/** Changelog-Anzeige (aus CHANGELOG.md gerendert). */
declare(strict_types=1);

Auth::require();

$md = @file_get_contents(ROOT_PATH . '/CHANGELOG.md');
$body = $md ? render_markdown($md) : '<p class="muted">Kein Changelog gefunden.</p>';

ob_start(); ?>
<div class="page-head">
  <h1>Changelog</h1>
  <span class="pill blue">Version <?= e(APP_VERSION) ?></span>
</div>
<div class="card"><div class="card__body changelog"><?= $body ?></div></div>
<style>
.changelog h2{margin:22px 0 4px;font-size:20px;color:var(--wj-blue);border-top:1px solid var(--line);padding-top:16px}
.changelog h2:first-child{border-top:none;padding-top:0}
.changelog h3{margin:14px 0 4px;font-size:15px;color:var(--wj-teal-d)}
.changelog ul{margin:4px 0 10px;padding-left:20px}
.changelog li{margin:3px 0}
.changelog p{margin:6px 0;color:var(--muted)}
</style>
<?php
$content = ob_get_clean();
$title = 'Changelog';
require APP_PATH . '/pages/_layout.php';
