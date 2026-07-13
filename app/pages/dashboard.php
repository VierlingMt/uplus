<?php /** Dashboard-View. Erwartet $stats, $timeline. */ ?>
<div class="page-head">
  <h1>Willkommen, <?= e(explode(' ', (string) (Auth::user()['name'] ?? ''))[0]) ?> 👋</h1>
  <a href="<?= url('plans') ?>" class="btn btn--teal">Businesspläne ansehen</a>
</div>

<div class="grid cols-4 mb">
  <?php
  // Kennzahl-Kacheln. Verlinkt wird nur, wenn die aktuelle Rolle das Zielmodul
  // auch öffnen darf – sonst reine Info-Kachel (kein toter Link auf „Kein Zugriff").
  $cards = [
    ['schools', 'Schulen', $stats['schools'], ['admin', 'lead']],
    ['teams', 'Teams', $stats['teams'], ['admin', 'lead', 'teacher']],
    ['plans', 'Eingereichte Pläne', $stats['plans'], ['admin', 'lead', 'teacher', 'juror']],
    ['jurors', 'Juror:innen', $stats['jurors'], ['admin', 'lead']],
  ];
  foreach ($cards as [$r, $label, $n, $roles]):
    $canOpen = Auth::is(...$roles); ?>
    <?php if ($canOpen): ?><a href="<?= url($r) ?>" style="text-decoration:none"><?php endif; ?>
      <div class="card stat"><div class="bar"></div><div class="card__body">
        <div class="n"><?= (int) $n ?></div><div class="l"><?= e($label) ?></div>
      </div></div>
    <?php if ($canOpen): ?></a><?php endif; ?>
  <?php endforeach; ?>
</div>

<div class="card mb">
  <div class="card__head">Projektablauf<?= $year !== '' ? ' ' . e($year) : '' ?></div>
  <div class="card__body">
    <?php if (!empty($timeline)): ?>
      <div class="timeline">
        <?php foreach ($timeline as [$phase, $date, $state]):
          // „Pitch Day"-Meilenstein öffnet das Handout-PDF (nur Verwaltung mit
          // angelegtem PitchDay).
          $pitchLink = (stripos($phase, 'pitch') !== false && !empty($pitchday) && !empty($activeCycleId))
            ? url('event_print', ['cycle' => $activeCycleId, 'kind' => 'handout']) : null;
        ?>
          <?php if ($pitchLink): ?><a href="<?= e($pitchLink) ?>" target="_blank" rel="noopener" class="tl-step-link" style="text-decoration:none;color:inherit" title="Ablaufplan / Handout als PDF öffnen"><?php endif; ?>
          <div class="tl-step <?= e($state) ?><?= $pitchLink ? ' tl-step--link' : '' ?>">
            <?php if ($state === 'done'): ?><span class="tick">✓</span><?php endif; ?>
            <div class="ph"><?= e($phase) ?><?= $pitchLink ? ' 📄' : '' ?></div>
            <div class="dt"><?= e($date) ?></div>
          </div>
          <?php if ($pitchLink): ?></a><?php endif; ?>
        <?php endforeach; ?>
      </div>
    <?php else: ?>
      <p class="muted">
        Für dieses Wettbewerbsjahr sind noch keine Meilensteine hinterlegt.
        <?php if (Auth::isManager()): ?>
          Unter <a href="<?= url('cycles') ?>">Wettbewerbsjahre</a> lassen sich Meilensteine mit Datum oder Zeitraum eintragen.
        <?php endif; ?>
      </p>
    <?php endif; ?>
  </div>
</div>

<?php if (!empty($pitchday)): ?>
<a href="<?= url('event') ?>" style="text-decoration:none;color:inherit">
  <div class="card mb">
    <div class="card__head" style="display:flex;align-items:center;justify-content:space-between;gap:10px">
      <span>🎤 <?= e($pitchday['title']) ?><?= $pitchday['date'] ? ' am ' . e(date('d.m.Y', strtotime($pitchday['date']))) : '' ?></span>
      <?php if ($pitchday['days'] !== null && $pitchday['days'] >= 0): ?>
        <span class="pill blue"><?= $pitchday['days'] === 0 ? 'heute!' : 'in ' . (int) $pitchday['days'] . ' Tagen' ?></span>
      <?php endif; ?>
    </div>
    <div class="card__body">
      <div style="display:flex;gap:10px;flex-wrap:wrap">
        <span class="pill muted"><?= (int) $pitchday['open'] ?> offene Aufgaben</span>
        <?php if ($pitchday['overdue'] > 0): ?><span class="pill red"><?= (int) $pitchday['overdue'] ?> überfällig</span><?php endif; ?>
        <?php if ($pitchday['soon'] > 0): ?><span class="pill amber"><?= (int) $pitchday['soon'] ?> fällig in ≤14 Tagen</span><?php endif; ?>
        <?php if ($pitchday['date'] === null): ?><span class="pill amber">Datum eintragen</span><?php endif; ?>
      </div>
    </div>
  </div>
</a>
<?php endif; ?>

<div class="card">
  <div class="card__head">Partner &amp; Sponsoren <?= e((string) $year) ?></div>
  <div class="card__body">
    <?php if (!empty($sponsors)): ?>
      <div class="partner-bar">
        <img src="<?= asset('img/wj/wj-forchheim.png') ?>" alt="WJ Forchheim">
        <?php foreach ($sponsors as $sp): ?>
          <img src="<?= asset($sp['logo_path']) ?>" alt="<?= e($sp['name']) ?>" title="<?= e($sp['name']) ?>">
        <?php endforeach; ?>
      </div>
    <?php else: ?>
      <p class="muted">Für <?= e((string) $year) ?> sind noch keine Sponsoren mit Leistung hinterlegt.</p>
    <?php endif; ?>
  </div>
</div>
