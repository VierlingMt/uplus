<?php /** Platzhalter fuer Module im Aufbau. Erwartet $route, $title. */ ?>
<div class="page-head"><h1><?= e($title) ?></h1></div>
<div class="card"><div class="card__body">
  <div class="empty">
    <div class="ic">🚧</div>
    <h3>Dieses Modul wird gerade gebaut</h3>
    <p class="muted">Das Grundgerüst (Deploy, Datenbank, Login) steht. Dieses Modul
      (<strong><?= e($route) ?></strong>) folgt als Nächstes.</p>
  </div>
</div></div>
