<?php
/**
 * PitchDay-Bewertung (Jury): schlankes Mini-Ranking – nur die Teams, die auf die
 * Bühne kommen (nominiert) plus Nachrücker. Bewusst getrennt von „Bewertung &
 * Ranking" (dort stehen ALLE Teams): hier arbeitet die Jury am Veranstaltungstag
 * fokussiert nur die Pitch-Teams ab.
 */
declare(strict_types=1);

Access::requireRead('pitch');
$isAdmin = Auth::isManager();
$jurorId = (int) Auth::id();

// „Endergebnis einfrieren" wird bewusst NUR hier (PitchDay) bedient – nach den
// Pitches wird das Ranking festgeschrieben. Freigabe nur als 15-Minuten-
// Notausstieg (Verklick). Nur Verwaltung (Admin/Projektleitung).
if (is_post() && $isAdmin) {
    Access::requireWrite('pitch');
    Csrf::check();
    $action = (string) input('action');
    if ($action === 'freeze') {
        Settings::set('ranking_frozen', '1');
        Settings::set('ranking_frozen_at', date('Y-m-d H:i:s'));
        Audit::log('ranking.freeze', 'Endergebnis eingefroren – Ranking festgeschrieben.');
        flash('success', 'Endergebnis eingefroren – das Ranking ist festgeschrieben. Die Jury kann nichts mehr ändern. Versehentlich? Innerhalb von 15 Minuten noch rückgängig zu machen.');
    } elseif ($action === 'unfreeze') {
        $frozenAt = Settings::get('ranking_frozen_at');
        if ($frozenAt !== null && $frozenAt !== '' && (time() - strtotime((string) $frozenAt)) <= 900) {
            Settings::set('ranking_frozen', '0');
            Settings::set('ranking_frozen_at', null);
            Audit::log('ranking.unfreeze', 'Endergebnis wieder freigegeben (innerhalb 15-Minuten-Fenster).');
            flash('success', 'Endergebnis wieder freigegeben – die Jury kann wieder bewerten.');
        } else {
            flash('error', 'Freigabe nicht mehr möglich: Das 15-Minuten-Fenster ist abgelaufen. Das Ranking bleibt festgeschrieben.');
        }
    }
    redirect(url('pitch'));
}

$frozen = Settings::getInt('ranking_frozen', 0) === 1;
// Freigabe (Rückgängig) nur innerhalb von 15 Minuten nach dem Einfrieren.
$frozenAt = $frozen ? Settings::get('ranking_frozen_at') : null;
$unfreezeSecsLeft = ($frozenAt !== null && $frozenAt !== '') ? (900 - (time() - strtotime((string) $frozenAt))) : 0;
$canUnfreeze = $frozen && $unfreezeSecsLeft > 0;
$unfreezeMinLeft = (int) ceil($unfreezeSecsLeft / 60);

// Nur Pitch-Teams (nominiert + Nachrücker), inkl. Jury-Mittelwerte.
// Ø nur aus Jury/Projektleitung (kein KI, kein reiner Admin), analog zum Ranking.
$rows = Database::all(
    "SELECT t.id, t.name, t.idea_name, t.status, t.pitch_order, s.short_name, s.name AS school_name,
            (SELECT AVG(e.bp_total)    FROM evaluations e JOIN users ju ON ju.id=e.juror_id WHERE e.team_id=t.id AND e.bp_submitted=1    AND EXISTS (SELECT 1 FROM user_roles ur WHERE ur.user_id = ju.id AND ur.role IN ('lead','juror'))) AS avg_bp,
            (SELECT AVG(e.pitch_total) FROM evaluations e JOIN users ju ON ju.id=e.juror_id WHERE e.team_id=t.id AND e.pitch_submitted=1 AND EXISTS (SELECT 1 FROM user_roles ur WHERE ur.user_id = ju.id AND ur.role IN ('lead','juror'))) AS avg_pitch,
            (SELECT COUNT(*)           FROM evaluations e JOIN users ju ON ju.id=e.juror_id WHERE e.team_id=t.id AND e.pitch_submitted=1 AND EXISTS (SELECT 1 FROM user_roles ur WHERE ur.user_id = ju.id AND ur.role IN ('lead','juror'))) AS n_pitch,
            (SELECT e.id FROM evaluations e WHERE e.team_id=t.id AND e.juror_id=? AND e.pitch_submitted=1) AS my_pitch,
            bp.id AS bp_id
     FROM teams t
     JOIN schools s ON s.id=t.school_id
     LEFT JOIN business_plans bp ON bp.team_id=t.id AND bp.is_current=1
     WHERE t.status IN ('nominated','fallback')",
    [$jurorId]
);
foreach ($rows as &$r) {
    $r['grand'] = Criteria::grandTotal((float) $r['avg_bp'], (float) ($r['avg_pitch'] ?? 0));
}
unset($r);
// Nach Gesamtwertung absteigend (Mini-Ranking), Gleichstand über Ø BP.
usort($rows, fn($a, $b) => ($b['grand'] <=> $a['grand']) ?: (($b['avg_bp'] ?? 0) <=> ($a['avg_bp'] ?? 0)));
$nominated = array_values(array_filter($rows, fn($r) => $r['status'] === 'nominated'));
$fallback  = array_values(array_filter($rows, fn($r) => $r['status'] === 'fallback'));

$totalJurors = (int) Database::value("SELECT COUNT(*) FROM users u WHERE u.is_active=1 AND EXISTS (SELECT 1 FROM user_roles ur WHERE ur.user_id = u.id AND ur.role IN ('lead','juror'))");
$fmt = fn($n) => $n === null ? '–' : rtrim(rtrim(number_format((float) $n, 1, ',', ''), '0'), ',');

// Wie viele Pitch-Teams sind noch nicht vollständig gepitch-bewertet?
$pitchPending = 0;
foreach ($nominated as $r) { if ($totalJurors === 0 || (int) $r['n_pitch'] < $totalJurors) { $pitchPending++; } }

// Eine Team-Zeile rendern (mit optionaler Platznummer + Medaille).
$rowHtml = function (array $r, ?int $place) use ($fmt, $frozen, $isAdmin) {
    $medal = $place !== null ? ([1 => '🥇', 2 => '🥈', 3 => '🥉'][$place] ?? '') : '';
    ob_start(); ?>
    <tr>
      <?php if ($place !== null): ?>
        <td data-label="Platz" class="place"><?php if ($medal): ?><span class="place__medal"><?= $medal ?></span><?php endif; ?><span class="place__num"><?= $place ?>.</span></td>
      <?php else: ?>
        <td data-label="Bühne"><?php if ($r['pitch_order']): ?><span class="pill teal">P<?= (int) $r['pitch_order'] ?></span><?php else: ?><span class="muted">–</span><?php endif; ?></td>
      <?php endif; ?>
      <td data-label="Team">
        <?php if ($r['bp_id']): ?>
          <a class="pdf-link" href="<?= url('bp_download', ['id' => $r['bp_id']]) ?>"
             data-pdf-url="<?= url('bp_download', ['id' => $r['bp_id']]) ?>"
             data-pdf-title="<?= e($r['name'] . ($r['idea_name'] ? ' – ' . $r['idea_name'] : '')) ?>"
             title="Businessplan-PDF ansehen"><strong><?= e($r['name']) ?></strong></a>
        <?php else: ?><strong><?= e($r['name']) ?></strong><?php endif; ?>
        <?php if ($place !== null && $r['pitch_order']): ?> <span class="pill teal" title="Bühnenreihenfolge">P<?= (int) $r['pitch_order'] ?></span><?php endif; ?>
        <?php if ($r['idea_name']): ?><br><span class="muted" style="font-size:12px"><?= e($r['idea_name']) ?></span><?php endif; ?>
      </td>
      <td data-label="Schule"><?= e($r['short_name'] ?: $r['school_name']) ?></td>
      <td data-label="Ø BP /50"><strong><?= $fmt($r['avg_bp']) ?></strong></td>
      <td data-label="Ø Pitch /40"><?= $fmt($r['avg_pitch']) ?></td>
      <td data-label="Gesamt /140"><strong style="color:var(--wj-blue)"><?= $fmt($r['grand']) ?></strong></td>
      <td class="row-actions" style="white-space:nowrap;text-align:right">
        <?php if ($r['bp_id']): ?>
          <?php if ($frozen && !$isAdmin): ?>
            <a class="btn btn--ghost btn--sm" href="<?= url('evaluate', ['team' => $r['id']]) ?>">🔒 Ansehen</a>
          <?php else: ?>
            <a class="btn btn--<?= $r['my_pitch'] ? 'ghost' : 'teal' ?> btn--sm" href="<?= url('evaluate', ['team' => $r['id']]) ?>"><?= $r['my_pitch'] ? '✓ bewertet' : 'Bewerten' ?></a>
          <?php endif; ?>
        <?php else: ?><span class="muted" style="font-size:12px">kein Plan</span><?php endif; ?>
      </td>
    </tr>
    <?php return ob_get_clean();
};

ob_start(); ?>
<div class="page-head">
  <h1>PitchDay</h1>
  <div style="display:flex;gap:8px;flex-wrap:wrap">
    <?php if ($isAdmin && ($nominated || $fallback)): ?>
      <?php if (!$frozen): ?>
        <form method="post" action="<?= url('pitch') ?>" data-confirm="Endergebnis jetzt einfrieren? Das Ranking wird festgeschrieben – nur die Verwaltung kann danach noch Änderungen vornehmen.">
          <?= Csrf::field() ?><input type="hidden" name="action" value="freeze">
          <button class="btn btn--ghost">🔒 Endergebnis einfrieren</button>
        </form>
      <?php elseif ($canUnfreeze): ?>
        <form method="post" action="<?= url('pitch') ?>" data-confirm="Endergebnis wieder freigeben? Die Jury kann danach ihre Bewertungen wieder ändern.">
          <?= Csrf::field() ?><input type="hidden" name="action" value="unfreeze">
          <button class="btn btn--ghost">🔓 Freigeben (noch <?= $unfreezeMinLeft ?> Min)</button>
        </form>
      <?php endif; ?>
    <?php endif; ?>
    <a href="<?= url('ranking') ?>" class="btn btn--ghost">★ Ganzes Ranking</a>
  </div>
</div>

<?php if ($frozen): ?>
<div class="card" style="border-left:4px solid var(--wj-blue)"><div class="card__body" style="display:flex;align-items:center;gap:10px">
  <span style="font-size:20px">🔒</span>
  <div><strong>Endergebnis eingefroren – das Ranking ist festgeschrieben.</strong>
    <span class="muted"><?php if (!$isAdmin): ?>Die Bewertungen sind gespeichert und können nicht mehr geändert werden.<?php elseif ($canUnfreeze): ?>Die Jury kann nichts mehr ändern. Verklickt? Noch <?= $unfreezeMinLeft ?> Minute<?= $unfreezeMinLeft === 1 ? '' : 'n' ?> lang oben wieder freizugeben; danach bleibt es endgültig festgeschrieben.<?php else: ?>Die Jury kann nichts mehr ändern. Das 15-Minuten-Fenster zum Freigeben ist abgelaufen – das Endergebnis bleibt festgeschrieben.<?php endif; ?></span></div>
</div></div>
<?php endif; ?>

<?php if (!$nominated && !$fallback): ?>
  <div class="card"><div class="card__body">
    <p class="muted" style="margin:0">Noch keine Pitch-Teams festgelegt. Die Nominierung erfolgt unter
      <a href="<?= url('ranking') ?>">Bewertung &amp; Ranking</a>.</p>
  </div></div>
<?php else: ?>

<div class="card">
  <div class="card__head">
    Auf der Bühne <span class="muted" style="font-weight:400;font-size:13px">· <?= count($nominated) ?> nominierte Teams · Gesamt = 2 × Businessplan + 1 × Pitch (max 140)</span>
  </div>
  <?php if ($pitchPending): ?>
    <div class="pitch__hint">Mini-Ranking noch vorläufig – bei <?= $pitchPending ?> von <?= count($nominated) ?> Team<?= count($nominated) === 1 ? '' : 's' ?> fehlt noch mindestens eine Pitch-Bewertung.</div>
  <?php endif; ?>
  <div class="table-wrap">
    <table class="data data--cards data--tight">
      <thead><tr>
        <th style="width:64px">Platz</th><th>Team</th><th>Schule</th>
        <th>Ø BP<br>/50</th><th>Ø Pitch<br>/40</th><th>Gesamt<br>/140</th><th></th>
      </tr></thead>
      <tbody>
        <?php foreach ($nominated as $i => $r) { echo $rowHtml($r, $i + 1); } ?>
      </tbody>
    </table>
  </div>
</div>

<?php if ($fallback): ?>
<div class="card">
  <div class="card__head">Nachrücker <span class="muted" style="font-weight:400;font-size:13px">· springen ein, falls ein Team ausfällt</span></div>
  <div class="table-wrap">
    <table class="data data--cards data--tight">
      <thead><tr>
        <th style="width:64px">Bühne</th><th>Team</th><th>Schule</th>
        <th>Ø BP<br>/50</th><th>Ø Pitch<br>/40</th><th>Gesamt<br>/140</th><th></th>
      </tr></thead>
      <tbody>
        <?php foreach ($fallback as $r) { echo $rowHtml($r, null); } ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<?php endif; ?>

<style>
.pitch__hint{padding:8px 16px;color:#8a6d00;background:#fff8e1;font-size:13px;border-bottom:1px solid var(--line)}
.place{white-space:nowrap;font-weight:700;font-size:15px}
.place__medal{font-size:20px;margin-right:3px;vertical-align:middle}
.place__num{color:var(--wj-blue)}
</style>
<?php
$content = ob_get_clean();
$title = 'PitchDay';
require APP_PATH . '/pages/_layout.php';
