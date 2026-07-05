<?php /** Dashboard-View. Erwartet $stats, $timeline. */ ?>
<div class="page-head">
  <h1>Willkommen, <?= e(explode(' ', (string) (Auth::user()['name'] ?? ''))[0]) ?> 👋</h1>
  <a href="<?= url('plans') ?>" class="btn btn--teal">Businesspläne ansehen</a>
</div>

<div class="grid cols-4 mb">
  <?php
  $cards = [
    ['schools', 'Schulen', $stats['schools']],
    ['teams', 'Teams', $stats['teams']],
    ['plans', 'Eingereichte Pläne', $stats['plans']],
    ['jurors', 'Juror:innen', $stats['jurors']],
  ];
  foreach ($cards as [$r, $label, $n]): ?>
    <a href="<?= url($r) ?>" style="text-decoration:none">
      <div class="card stat"><div class="bar"></div><div class="card__body">
        <div class="n"><?= (int) $n ?></div><div class="l"><?= e($label) ?></div>
      </div></div>
    </a>
  <?php endforeach; ?>
</div>

<div class="card mb">
  <div class="card__head">Projektablauf 2025/2026</div>
  <div class="card__body">
    <div class="timeline">
      <?php foreach ($timeline as [$phase, $date, $state]): ?>
        <div class="tl-step <?= e($state) ?>">
          <?php if ($state === 'done'): ?><span class="tick">✓</span><?php endif; ?>
          <div class="ph"><?= e($phase) ?></div>
          <div class="dt"><?= e($date) ?></div>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
</div>

<div class="card">
  <div class="card__head">Partner & Sponsoren</div>
  <div class="card__body">
    <div class="partner-bar">
      <img src="<?= asset('img/wj/wj-forchheim.png') ?>" alt="WJ Forchheim">
      <img src="<?= asset('img/sponsors/sparkasse.png') ?>" alt="Sparkasse Forchheim">
      <img src="<?= asset('img/sponsors/medical-valley.png') ?>" alt="Medical Valley">
      <img src="<?= asset('img/sponsors/bildungsregion.png') ?>" alt="Bildungsregion Forchheim">
      <img src="<?= asset('img/sponsors/vierling.jpg') ?>" alt="Vierling">
      <img src="<?= asset('img/sponsors/stadtwerke-ebs.png') ?>" alt="Stadtwerke Ebermannstadt">
    </div>
  </div>
</div>
