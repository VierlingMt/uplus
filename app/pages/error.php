<?php /** Fehlerseite. Erwartet $title, $message, optional $detail. */ ?>
<div class="page-head"><h1><?= e($title ?? 'Fehler') ?></h1></div>
<div class="card"><div class="card__body">
  <p><?= e($message ?? 'Es ist ein Fehler aufgetreten.') ?></p>
  <?php if (!empty($detail)): ?>
    <pre style="background:#f4f6f8;padding:14px;border-radius:8px;overflow:auto;font-size:12px"><?= e($detail) ?></pre>
  <?php endif; ?>
  <a href="<?= url('dashboard') ?>" class="btn btn--ghost mt">Zum Dashboard</a>
</div></div>
