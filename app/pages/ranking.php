<?php
/** Bewertungsübersicht, Ranking und Nominierung (Admin + Jury). */
declare(strict_types=1);

Auth::require('admin', 'lead', 'juror');
$isAdmin = Auth::isManager(); // Admin oder Projektleitung = volle Verwaltung
$jurorId = (int) Auth::id();

$pitchSlots = Settings::getInt('pitch_slots', 7);
$fallbackSlots = Settings::getInt('fallback_slots', 2);
// KI-Vorbewertung nur für Verwaltung – oder für Jury, falls im Admin freigegeben.
$showAiEval = $isAdmin || Settings::getInt('ai_eval_jurors', 0) === 1;
$cols = $showAiEval ? 10 : 9;

/** Ranking-Daten laden (inkl. Mittelwerte je Team). */
$loadRows = function () use ($jurorId): array {
    $rows = Database::all(
        "SELECT t.id, t.name, t.idea_name, t.status, t.pitch_order, s.short_name, s.name AS school_name,
                (SELECT AVG(e.bp_total)   FROM evaluations e WHERE e.team_id=t.id AND e.bp_submitted=1)    AS avg_bp,
                (SELECT COUNT(*)          FROM evaluations e WHERE e.team_id=t.id AND e.bp_submitted=1)    AS n_bp,
                (SELECT AVG(e.pitch_total)FROM evaluations e WHERE e.team_id=t.id AND e.pitch_submitted=1) AS avg_pitch,
                (SELECT COUNT(*)          FROM evaluations e WHERE e.team_id=t.id AND e.pitch_submitted=1) AS n_pitch,
                (SELECT e.id FROM evaluations e WHERE e.team_id=t.id AND e.juror_id=? AND e.bp_submitted=1) AS my_eval,
                bp.id AS bp_id,
                ai.total_score AS ai_score
         FROM teams t
         JOIN schools s ON s.id=t.school_id
         LEFT JOIN business_plans bp ON bp.team_id=t.id AND bp.is_current=1
         LEFT JOIN ai_evaluations ai ON ai.id=(SELECT id FROM ai_evaluations WHERE business_plan_id=bp.id ORDER BY id DESC LIMIT 1)",
        [$jurorId]
    );
    foreach ($rows as &$r) {
        $r['grand'] = Criteria::grandTotal((float) $r['avg_bp'], (float) ($r['avg_pitch'] ?? 0));
    }
    unset($r);
    // nach Gesamt (bzw. Ø BP vor dem Pitch) absteigend
    usort($rows, fn($a, $b) => ($b['grand'] <=> $a['grand']) ?: (($b['avg_bp'] ?? 0) <=> ($a['avg_bp'] ?? 0)));
    return $rows;
};

if (is_post() && $isAdmin) {
    Csrf::check();
    $action = (string) input('action');

    if ($action === 'auto_nominate') {
        $rows = array_values(array_filter($loadRows(), fn($r) => $r['status'] !== 'eliminated' && $r['n_bp'] > 0));
        Database::run("UPDATE teams SET status='submitted', pitch_order=NULL WHERE status IN ('nominated','fallback')");
        $i = 0;
        foreach ($rows as $r) {
            if ($i < $pitchSlots) {
                Database::run('UPDATE teams SET status=?, pitch_order=? WHERE id=?', ['nominated', $i + 1, $r['id']]);
            } elseif ($i < $pitchSlots + $fallbackSlots) {
                Database::run('UPDATE teams SET status=?, pitch_order=NULL WHERE id=?', ['fallback', $r['id']]);
            } else {
                break;
            }
            $i++;
        }
        flash('success', "Top {$pitchSlots} nominiert, {$fallbackSlots} Nachrücker gesetzt.");
    } elseif ($action === 'set_status') {
        $allowed = ['submitted', 'nominated', 'fallback', 'eliminated'];
        $st = (string) input('status');
        if (in_array($st, $allowed, true)) {
            $ord = $st === 'nominated' ? ((int) input('pitch_order') ?: null) : null;
            Database::run('UPDATE teams SET status=?, pitch_order=? WHERE id=?', [$st, $ord, (int) input('team_id')]);
            flash('success', 'Status aktualisiert.');
        }
    }
    redirect(url('ranking'));
}

$rows = $loadRows();
$fmt = fn($n) => $n === null ? '–' : rtrim(rtrim(number_format((float) $n, 1, ',', ''), '0'), ',');
$totalJurors = (int) Database::value("SELECT COUNT(*) FROM users WHERE role IN ('admin','lead','juror') AND is_active=1");
$phaseLabels = ['submitted' => ['eingereicht', 'muted'], 'nominated' => ['Pitch', 'teal'], 'fallback' => ['Nachrücker', 'amber'], 'eliminated' => ['raus', 'muted'], 'draft' => ['Entwurf', 'muted']];

ob_start(); ?>
<div class="page-head">
  <h1>Bewertung &amp; Ranking</h1>
  <?php if ($isAdmin): ?>
    <form method="post" action="<?= url('ranking') ?>" data-confirm="Automatisch Top <?= $pitchSlots ?> nominieren und <?= $fallbackSlots ?> Nachrücker setzen? Bestehende Nominierungen werden überschrieben.">
      <?= Csrf::field() ?><input type="hidden" name="action" value="auto_nominate">
      <button class="btn btn--teal">★ Top <?= $pitchSlots ?> (+<?= $fallbackSlots ?>) nominieren</button>
    </form>
  <?php endif; ?>
</div>

<div class="card">
  <div class="card__head">Ranking <span class="muted" style="font-weight:400;font-size:13px">· Gesamt = 2 × Businessplan + 1 × Pitch (max 140) · <?= $totalJurors ?> Bewertende</span></div>
  <div class="table-wrap">
    <table class="data data--cards">
      <thead><tr>
        <th style="width:40px">#</th><th>Team</th><th>Schule</th><th>Jury</th>
        <th>Ø BP<br>/50</th><th>Ø Pitch<br>/40</th><th>Gesamt<br>/140</th><?php if ($showAiEval): ?><th>KI</th><?php endif; ?><th>Status</th><th></th>
      </tr></thead>
      <tbody>
      <?php foreach ($rows as $i => $r): [$sl, $sc] = $phaseLabels[$r['status']] ?? [$r['status'], 'muted']; ?>
        <tr>
          <td data-label="Platz"><strong><?= $i + 1 ?></strong><?php if ($r['pitch_order']): ?> <span class="pill teal" title="Pitch-Reihenfolge">P<?= (int) $r['pitch_order'] ?></span><?php endif; ?></td>
          <td data-label="Team">
            <?php if ($r['bp_id']): ?>
              <a class="pdf-link" href="<?= url('bp_download', ['id' => $r['bp_id']]) ?>"
                 data-pdf-url="<?= url('bp_download', ['id' => $r['bp_id']]) ?>"
                 data-pdf-title="<?= e($r['name'] . ($r['idea_name'] ? ' – ' . $r['idea_name'] : '')) ?>"
                 title="Businessplan-PDF ansehen"><strong><?= e($r['name']) ?></strong></a>
            <?php else: ?><strong><?= e($r['name']) ?></strong><?php endif; ?>
            <?php if ($r['idea_name']): ?><br><span class="muted" style="font-size:12px"><?= e($r['idea_name']) ?></span><?php endif; ?>
          </td>
          <td data-label="Schule"><?= e($r['short_name'] ?: $r['school_name']) ?></td>
          <td data-label="Jury"><?= (int) $r['n_bp'] ?>/<?= $totalJurors ?><?php if ($r['n_pitch']): ?> <span class="muted">(P:<?= (int) $r['n_pitch'] ?>)</span><?php endif; ?></td>
          <td data-label="Ø BP /50"><strong><?= $fmt($r['avg_bp']) ?></strong></td>
          <td data-label="Ø Pitch /40"><?= $fmt($r['avg_pitch']) ?></td>
          <td data-label="Gesamt /140"><strong style="color:var(--wj-blue)"><?= $fmt($r['grand']) ?></strong></td>
          <?php if ($showAiEval): ?><td class="muted" data-label="KI"><?= $r['ai_score'] !== null ? $fmt($r['ai_score']) . '/50' : '–' ?></td><?php endif; ?>
          <td data-label="Status"><span class="pill <?= $sc ?>"><?= e($sl) ?></span></td>
          <td class="row-actions" style="white-space:nowrap;text-align:right">
            <?php if ($r['bp_id']): ?>
              <a class="btn btn--<?= $r['my_eval'] ? 'ghost' : 'teal' ?> btn--sm" href="<?= url('evaluate', ['team' => $r['id']]) ?>"><?= $r['my_eval'] ? '✓ bewertet' : 'Bewerten' ?></a>
            <?php else: ?><span class="muted" style="font-size:12px">kein Plan</span><?php endif; ?>
          </td>
        </tr>
        <?php if ($isAdmin): ?>
        <tr class="admin-row"><td colspan="<?= $cols ?>" style="padding-top:0">
          <form method="post" action="<?= url('ranking') ?>" style="display:flex;gap:8px;align-items:center;justify-content:flex-end">
            <?= Csrf::field() ?><input type="hidden" name="action" value="set_status"><input type="hidden" name="team_id" value="<?= (int) $r['id'] ?>">
            <span class="muted" style="font-size:12px">Status setzen:</span>
            <select name="status" style="width:auto;padding:5px 8px">
              <?php foreach (['submitted'=>'eingereicht','nominated'=>'Pitch','fallback'=>'Nachrücker','eliminated'=>'raus'] as $vk=>$vl): ?>
                <option value="<?= $vk ?>" <?= $r['status']===$vk?'selected':'' ?>><?= $vl ?></option>
              <?php endforeach; ?>
            </select>
            <input type="number" name="pitch_order" min="1" placeholder="P#" value="<?= e((string)($r['pitch_order']??'')) ?>" style="width:70px;padding:5px 8px" title="Pitch-Reihenfolge">
            <button class="btn btn--ghost btn--sm">OK</button>
          </form>
        </td></tr>
        <?php endif; ?>
      <?php endforeach; ?>
      <?php if (!$rows): ?><tr><td colspan="<?= $cols ?>" class="muted">Noch keine Teams.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</div>
<style>.admin-row td{border-bottom:2px solid var(--line)}.admin-row form{opacity:.75}.admin-row:hover form{opacity:1}</style>
<?php
$content = ob_get_clean();
$title = 'Bewertung & Ranking';
require APP_PATH . '/pages/_layout.php';
