<?php
/** Jury-Bewertung eines Teams durch die/den angemeldete:n Juror:in (Leitung/Jury). */
declare(strict_types=1);

Auth::require('admin', 'lead', 'juror');
$jurorId = (int) Auth::id();

$teamId = (int) input('team');
$team = Database::one(
    'SELECT t.*, s.name AS school_name FROM teams t JOIN schools s ON s.id=t.school_id WHERE t.id=?',
    [$teamId]
);
if (!$team) { http_response_code(404); render('error', ['title' => 'Nicht gefunden', 'message' => 'Team existiert nicht.']); return; }

$isPitch = in_array($team['status'], ['nominated', 'fallback'], true);
$plan = Database::one('SELECT id FROM business_plans WHERE team_id=? AND is_current=1', [$teamId]);

// Bestehende Bewertung + Scores laden
$eval = Database::one('SELECT * FROM evaluations WHERE juror_id=? AND team_id=?', [$jurorId, $teamId]);
$scores = [];
if ($eval) {
    foreach (Database::all('SELECT criterion_key, points, notes FROM evaluation_scores WHERE evaluation_id=?', [(int) $eval['id']]) as $r) {
        $scores[$r['criterion_key']] = $r;
    }
}

if (is_post()) {
    Csrf::check();

    // Bewertung (Kopf) sicherstellen
    if (!$eval) {
        $evalId = Database::insert('INSERT INTO evaluations (juror_id, team_id) VALUES (?,?)', [$jurorId, $teamId]);
    } else {
        $evalId = (int) $eval['id'];
    }

    $upsert = Database::pdo()->prepare(
        'INSERT INTO evaluation_scores (evaluation_id, criterion_key, phase, points, notes)
         VALUES (?,?,?,?,?)
         ON DUPLICATE KEY UPDATE points=VALUES(points), notes=VALUES(notes), phase=VALUES(phase)'
    );

    $bpTotal = 0;
    foreach (Criteria::BUSINESSPLAN as $k => $c) {
        $p = max(0, min(10, (int) input('pts_' . $k, 0)));
        $upsert->execute([$evalId, $k, 'businessplan', $p, trim((string) input('note_' . $k)) ?: null]);
        $bpTotal += $p;
    }

    $pitchTotal = null;
    $pitchSubmitted = 0;
    if ($isPitch) {
        $pitchTotal = 0;
        foreach (Criteria::PITCH as $k => $c) {
            $p = max(0, min(10, (int) input('pts_' . $k, 0)));
            $upsert->execute([$evalId, $k, 'pitch', $p, trim((string) input('note_' . $k)) ?: null]);
            $pitchTotal += $p;
        }
        $pitchSubmitted = 1;
    }

    $grand = Criteria::grandTotal((float) $bpTotal, (float) ($pitchTotal ?? 0));
    Database::run(
        'UPDATE evaluations SET bp_submitted=1, pitch_submitted=?, bp_total=?, pitch_total=?, grand_total=? WHERE id=?',
        [$pitchSubmitted, $bpTotal, $pitchTotal, $grand, $evalId]
    );
    flash('success', 'Bewertung gespeichert (' . $bpTotal . '/50' . ($isPitch ? ', Pitch ' . $pitchTotal . '/40' : '') . ').');
    redirect(url('evaluate', ['team' => $teamId]));
}

$renderCriteria = function (array $group, string $phaseLabel) use ($scores) {
    foreach ($group as $k => $c) {
        $cur = $scores[$k]['points'] ?? '';
        $note = $scores[$k]['notes'] ?? '';
        echo '<div class="crit">';
        echo '<div class="crit__head"><strong>' . e($c['title']) . '</strong>';
        echo '<div class="crit__pts"><input type="number" name="pts_' . e($k) . '" min="0" max="10" step="1" data-score value="' . e((string) $cur) . '"> <span class="muted">/10</span></div></div>';
        echo '<ul class="crit__hints">';
        foreach ($c['points'] as $h) { echo '<li>' . e($h) . '</li>'; }
        echo '</ul>';
        echo '<textarea name="note_' . e($k) . '" rows="2" placeholder="Notizen (optional)">' . e((string) $note) . '</textarea>';
        echo '</div>';
    }
};

ob_start(); ?>
<div class="page-head">
  <h1>Bewerten: <?= e($team['name']) ?></h1>
  <a href="<?= url('ranking') ?>" class="btn btn--ghost">← Ranking</a>
</div>
<p class="muted mb"><?= e($team['school_name']) ?><?= $team['idea_name'] ? ' · ' . e($team['idea_name']) : '' ?>
  <?php if ($plan): ?> · <a href="<?= url('bp_download', ['id' => $plan['id']]) ?>" target="_blank">Businessplan-PDF ↗</a><?php endif; ?>
</p>

<form method="post" action="<?= url('evaluate', ['team' => $teamId]) ?>">
  <?= Csrf::field() ?>
  <div class="card mb">
    <div class="card__head">Businessplan <span class="muted" style="font-weight:400">— Summe <span data-score-total>0</span>/50</span></div>
    <div class="card__body eval-grid"><?php $renderCriteria(Criteria::BUSINESSPLAN, 'businessplan'); ?></div>
  </div>

  <?php if ($isPitch): ?>
    <div class="card mb">
      <div class="card__head">Pitch-Day <span class="muted" style="font-weight:400">(Team pitcht)</span></div>
      <div class="card__body eval-grid"><?php $renderCriteria(Criteria::PITCH, 'pitch'); ?></div>
    </div>
  <?php else: ?>
    <p class="muted mb">Pitch-Kriterien erscheinen, sobald das Team für den Pitch-Day nominiert ist.</p>
  <?php endif; ?>

  <div class="card"><div class="card__body" style="display:flex;justify-content:space-between;align-items:center">
    <div>
      <strong>Punkteskala:</strong>
      <span class="muted" style="font-size:13px">10 herausragend · 8–9 sehr gut · 6–7 gut · 4–5 ausbaufähig · 1–3 schwach · 0 unbewertbar</span>
    </div>
    <button class="btn btn--primary">Bewertung speichern</button>
  </div></div>
</form>

<style>
.eval-grid{display:grid;grid-template-columns:1fr 1fr;gap:16px}
.crit{border:1px solid var(--line);border-radius:10px;padding:12px 14px}
.crit__head{display:flex;justify-content:space-between;align-items:center;gap:10px}
.crit__pts input{width:64px;text-align:center}
.crit__hints{margin:8px 0;padding-left:18px;color:var(--muted);font-size:13px}
.crit textarea{margin-top:6px}
@media(max-width:800px){.eval-grid{grid-template-columns:1fr}}
</style>
<?php
$content = ob_get_clean();
$title = 'Bewerten';
require APP_PATH . '/pages/_layout.php';
