<?php
/**
 * Jury-Feedback – Zeitplan-Skizze je Schule.
 *
 * Aus der Zahl der Schüler-Gruppen (Teams) je Schule und wenigen konfigurierbaren
 * Parametern (Gesprächsdauer, Pausenlänge, Pause nach X Gesprächen, Jury-Gruppen)
 * wird pro Schule eine grobe Gesamtzeit und die Zeit je Jury-Gruppe geschätzt.
 *
 * Rechenmodell (bewusst als Skizze, nicht als Minutenplan):
 *   Gesamtzeit [min]      = n · t + (n / x) · p
 *   Zeit je Jury-Gruppe   = Gesamtzeit / g
 * mit n = Anzahl Schüler-Gruppen, t = Gesprächsdauer, p = Pausenlänge,
 *     x = Pause nach X Gesprächen, g = Anzahl Jury-Gruppen (laufen parallel).
 *
 * Konfiguration (nur Verwaltung): Timing global, Jury-Gruppen/-Mitglieder je Schule.
 * Jury sieht die Übersicht schreibgeschützt.
 */

declare(strict_types=1);

$canEdit = Auth::isManager();

$CFG_KEY  = 'jury_feedback_cfg';
$defaults = ['talk_min' => 15, 'break_min' => 10, 'break_after' => 3, 'members' => 2, 'groups' => 2];

$loadCfg = static function () use ($CFG_KEY): array {
    $raw = (string) Settings::get('jury_feedback_cfg', '');
    $c   = $raw !== '' ? json_decode($raw, true) : null;
    return is_array($c) ? $c : [];
};

if (is_post()) {
    if (!$canEdit) {
        flash('error', 'Nur die Verwaltung darf den Jury-Feedback-Zeitplan ändern.');
        redirect(url('jury_feedback'));
    }
    Csrf::check();

    $cfg = [
        'talk_min'    => max(1, (int) input('talk_min', $defaults['talk_min'])),
        'break_min'   => max(0, (int) input('break_min', $defaults['break_min'])),
        'break_after' => max(0, (int) input('break_after', $defaults['break_after'])),
        'schools'     => [],
    ];
    $inM = (array) input('members', []);
    $inG = (array) input('groups', []);
    foreach ($inG as $sid => $_) {
        $sid = (int) $sid;
        if ($sid <= 0) { continue; }
        $cfg['schools'][$sid] = [
            'members' => max(0, (int) ($inM[$sid] ?? $defaults['members'])),
            'groups'  => max(1, (int) ($inG[$sid] ?? $defaults['groups'])),
        ];
    }
    Settings::set($CFG_KEY, json_encode($cfg, JSON_UNESCAPED_UNICODE));
    Audit::log('jury_feedback.config', 'Jury-Feedback-Zeitplan angepasst');
    flash('success', 'Zeitplan gespeichert.');
    redirect(url('jury_feedback'));
}

$cfg     = $loadCfg();
$talk    = (int) ($cfg['talk_min'] ?? $defaults['talk_min']);
$brk     = (int) ($cfg['break_min'] ?? $defaults['break_min']);
$brkAfter = (int) ($cfg['break_after'] ?? $defaults['break_after']);
$schoolCfg = is_array($cfg['schools'] ?? null) ? $cfg['schools'] : [];

// Teilnehmende Schulen des aktiven Zyklus (mit Team-Zahl); Fallback: alle Schulen
// mit Teams, falls für den Zyklus noch keine Schulen hinterlegt sind.
$cycleId  = Cycle::activeId();
$schoolIds = $cycleId ? Cycle::schoolIds($cycleId) : [];
if ($schoolIds) {
    $ph = implode(',', array_fill(0, count($schoolIds), '?'));
    $schools = Database::all(
        "SELECT s.id, s.name, s.short_name,
                (SELECT COUNT(*) FROM teams t WHERE t.school_id = s.id) AS teams
         FROM schools s WHERE s.id IN ($ph) ORDER BY s.name",
        $schoolIds
    );
} else {
    $schools = Database::all(
        'SELECT s.id, s.name, s.short_name,
                (SELECT COUNT(*) FROM teams t WHERE t.school_id = s.id) AS teams
         FROM schools s
         WHERE EXISTS (SELECT 1 FROM teams t WHERE t.school_id = s.id)
         ORDER BY s.name'
    );
}

// --- Rechenmodell -----------------------------------------------------------
$totalMin = static function (int $n) use ($talk, $brk, $brkAfter): float {
    $t = (float) ($n * $talk);
    if ($brkAfter > 0) {
        $t += ($n / $brkAfter) * $brk;
    }
    return $t;
};
$hrs = static fn(float $min): string => number_format($min / 60, 2, ',', '.');

// Spalten aufbauen: „Gesamt" + je Schule.
$cols = [];
$sumTeams = 0;
$sumGroups = 0;
$sumMin = 0.0;
foreach ($schools as $s) {
    $sid    = (int) $s['id'];
    $n      = (int) $s['teams'];
    $groups = max(1, (int) ($schoolCfg[$sid]['groups'] ?? $defaults['groups']));
    $members = (int) ($schoolCfg[$sid]['members'] ?? $defaults['members']);
    $min    = $totalMin($n);

    $cols[] = [
        'id'      => $sid,
        'label'   => $s['short_name'] ?: $s['name'],
        'name'    => $s['name'],
        'teams'   => $n,
        'members' => $members,
        'groups'  => $groups,
        'min'     => $min,
        'perGroup' => $min / $groups,
    ];
    $sumTeams  += $n;
    $sumGroups += $groups;
    $sumMin    += $min;
}
$sumGroups = max(1, $sumGroups);

ob_start(); ?>
<div class="page-head">
  <h1>Jury-Feedback</h1>
</div>

<p class="muted" style="max-width:70ch;margin:-4px 0 16px">
  Grobe Zeitplan-Skizze je Schule: aus der Zahl der Schüler-Gruppen (Teams) und den
  Feedback-Parametern werden Gesamtzeit und Zeit je Jury-Gruppe geschätzt. Die
  Jury-Gruppen arbeiten parallel.
</p>

<form method="post" action="<?= url('jury_feedback') ?>">
  <?= Csrf::field() ?>

  <?php if ($canEdit): ?>
  <div class="card" style="margin-bottom:16px">
    <div class="card__head">Feedback-Parameter</div>
    <div class="card__body" style="display:flex;flex-wrap:wrap;gap:18px">
      <div class="field" style="margin:0">
        <label>Zeit je Schüler-Gruppe [min]</label>
        <input type="number" name="talk_min" min="1" value="<?= $talk ?>" style="width:120px">
      </div>
      <div class="field" style="margin:0">
        <label>Pausenlänge [min]</label>
        <input type="number" name="break_min" min="0" value="<?= $brk ?>" style="width:120px">
      </div>
      <div class="field" style="margin:0">
        <label>Pause nach X Gesprächen</label>
        <input type="number" name="break_after" min="0" value="<?= $brkAfter ?>" style="width:120px">
      </div>
    </div>
  </div>
  <?php endif; ?>

  <div class="card">
    <div class="card__head">
      Zeitplan je Schule
      <?php if (!$schools): ?><span class="muted"> – keine Schulen mit Teams</span><?php endif; ?>
    </div>
    <div class="table-wrap">
      <table class="data">
        <thead>
          <tr>
            <th style="text-align:left">Jury-Feedback</th>
            <th>Gesamt</th>
            <?php foreach ($cols as $c): ?>
              <th title="<?= e($c['name']) ?>"><?= e($c['label']) ?></th>
            <?php endforeach; ?>
          </tr>
        </thead>
        <tbody>
          <tr>
            <th style="text-align:left">Zeit je Schüler-Gruppe [min]</th>
            <td><?= $talk ?></td>
            <?php foreach ($cols as $c): ?><td><?= $talk ?></td><?php endforeach; ?>
          </tr>
          <tr>
            <th style="text-align:left">Pausenlänge [min]</th>
            <td><?= $brk ?></td>
            <?php foreach ($cols as $c): ?><td><?= $brk ?></td><?php endforeach; ?>
          </tr>
          <tr>
            <th style="text-align:left">Pause nach X Gesprächen</th>
            <td><?= $brkAfter ?></td>
            <?php foreach ($cols as $c): ?><td><?= $brkAfter ?></td><?php endforeach; ?>
          </tr>
          <tr>
            <th style="text-align:left">Anzahl Schüler-Gruppen</th>
            <td><strong><?= $sumTeams ?></strong></td>
            <?php foreach ($cols as $c): ?><td><?= $c['teams'] ?></td><?php endforeach; ?>
          </tr>
          <tr>
            <th style="text-align:left">Gesamtzeit [h]</th>
            <td><strong><?= $hrs($sumMin) ?></strong></td>
            <?php foreach ($cols as $c): ?><td><?= $hrs($c['min']) ?></td><?php endforeach; ?>
          </tr>
          <tr>
            <th style="text-align:left">Anzahl Jurymitglieder je Gruppe <span class="muted">(min&nbsp;2!)</span></th>
            <td class="muted">—</td>
            <?php foreach ($cols as $c): ?>
              <td>
                <?php if ($canEdit): ?>
                  <input type="number" name="members[<?= $c['id'] ?>]" min="0" value="<?= $c['members'] ?>"
                         style="width:72px;text-align:center<?= $c['members'] < 2 ? ';border-color:#d9534f' : '' ?>">
                <?php else: ?>
                  <span<?= $c['members'] < 2 ? ' style="color:#d9534f;font-weight:600"' : '' ?>><?= $c['members'] ?></span>
                <?php endif; ?>
              </td>
            <?php endforeach; ?>
          </tr>
          <tr>
            <th style="text-align:left">Anzahl Jury-Gruppen</th>
            <td><strong><?= $sumGroups ?></strong></td>
            <?php foreach ($cols as $c): ?>
              <td>
                <?php if ($canEdit): ?>
                  <input type="number" name="groups[<?= $c['id'] ?>]" min="1" value="<?= $c['groups'] ?>" style="width:72px;text-align:center">
                <?php else: ?>
                  <?= $c['groups'] ?>
                <?php endif; ?>
              </td>
            <?php endforeach; ?>
          </tr>
          <tr>
            <th style="text-align:left">Zeit je Jury-Gruppe [h]</th>
            <td><strong><?= $hrs($sumMin / $sumGroups) ?></strong></td>
            <?php foreach ($cols as $c): ?><td><?= $hrs($c['perGroup']) ?></td><?php endforeach; ?>
          </tr>
        </tbody>
      </table>
    </div>
    <?php if ($canEdit && $schools): ?>
      <div class="card__body" style="text-align:right">
        <button class="btn btn--primary">Speichern</button>
      </div>
    <?php endif; ?>
  </div>
</form>

<p class="muted" style="max-width:70ch;margin-top:14px;font-size:13px">
  Rechenmodell: Gesamtzeit = n&nbsp;·&nbsp;Gesprächsdauer + (n&nbsp;/&nbsp;Pause-nach-X)&nbsp;·&nbsp;Pausenlänge,
  Zeit je Jury-Gruppe = Gesamtzeit&nbsp;/&nbsp;Anzahl Jury-Gruppen. „Gesamt" fasst alle Schulen zusammen.
  Die Anzahl der Schüler-Gruppen kommt aus den erfassten Teams je Schule.
</p>
<?php
$content = ob_get_clean();
$title = 'Jury-Feedback';
require APP_PATH . '/pages/_layout.php';
