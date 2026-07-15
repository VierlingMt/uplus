<?php
/** Jury-Bewertung eines Teams durch die/den angemeldete:n Juror:in (Leitung/Jury). */
declare(strict_types=1);

Auth::require();
// Bewerten setzt Lesezugriff auf Businessplan- (ranking) oder Pitch-Bewertung voraus.
if (!Access::canRead('ranking') && !Access::canRead('pitch')) {
    Access::requireRead('ranking');
}
$jurorId = (int) Auth::id();

$teamId = (int) input('team');
$team = Database::one(
    'SELECT t.*, s.name AS school_name FROM teams t JOIN schools s ON s.id=t.school_id WHERE t.id=?',
    [$teamId]
);
if (!$team) { http_response_code(404); render('error', ['title' => 'Nicht gefunden', 'message' => 'Team existiert nicht.']); return; }

$isPitch = in_array($team['status'], ['nominated', 'fallback'], true);
$plan = Database::one('SELECT id FROM business_plans WHERE team_id=? AND is_current=1', [$teamId]);

// Zwei getrennte Sperren: die Businessplan-Runde (vor dem PitchDay) und das
// Gesamt-/Endergebnis (nach dem Pitch). Die Verwaltung (Admin/Leitung) darf
// jeweils weiter ändern; für die Jury ist die jeweilige Phase schreibgeschützt.
$bpFrozen    = Settings::getInt('bp_frozen', 0) === 1;
$finalFrozen = Settings::getInt('ranking_frozen', 0) === 1;
$isManager   = Auth::isManager();
// Bewerten-Recht laut Zugriffsmatrix: „Schreiben" auf ranking (Businessplan) bzw.
// pitch (Pitch). Ohne Schreibrecht sieht die Rolle die Maske nur lesend.
$canScoreBp    = $isManager || Access::canWrite('ranking');
$canScorePitch = $isManager || Access::canWrite('pitch');
$bpLocked    = (($bpFrozen || $finalFrozen) && !$isManager) || !$canScoreBp;  // Businessplan-Punkte gesperrt
$pitchLocked = ($finalFrozen && !$isManager) || !$canScorePitch;             // Pitch-Punkte gesperrt
$allLocked   = $bpLocked && (!$isPitch || $pitchLocked);                     // gar nichts editierbar

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

    if ($allLocked) {
        $lockMsg = ($canScoreBp || $canScorePitch)
            ? 'Die Bewertung ist eingefroren – Änderungen sind nicht mehr möglich.'
            : 'Für die Bewertung fehlt dir das Schreibrecht – die Ansicht ist schreibgeschützt.';
        if (input('ajax') === '1') {
            http_response_code(423);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok' => false, 'locked' => true, 'error' => $lockMsg]);
            exit;
        }
        flash('error', $lockMsg);
        redirect(url('evaluate', ['team' => $teamId]));
    }

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

    // Gesperrte Phasen NICHT neu schreiben: deren Felder sind im Formular
    // deaktiviert und werden vom Browser gar nicht mitgeschickt – ein Neuberechnen
    // würde die bestehenden Punkte mit 0 überbügeln. Darum bei Sperre die schon
    // gespeicherten Werte beibehalten.
    $bpTotal     = $eval['bp_total'] ?? 0;
    $bpSubmitted = (int) ($eval['bp_submitted'] ?? 0);
    if (!$bpLocked) {
        $bpTotal = 0;
        foreach (Criteria::BUSINESSPLAN as $k => $c) {
            $p = max(0, min(10, (int) input('pts_' . $k, 0)));
            $upsert->execute([$evalId, $k, 'businessplan', $p, trim((string) input('note_' . $k)) ?: null]);
            $bpTotal += $p;
        }
        $bpSubmitted = 1;
    }

    $pitchTotal     = $eval['pitch_total'] ?? null;
    $pitchSubmitted = (int) ($eval['pitch_submitted'] ?? 0);
    if ($isPitch && !$pitchLocked) {
        $pitchTotal  = 0;
        $pitchScored = 0;
        foreach (Criteria::PITCH as $k => $c) {
            $p = max(0, min(10, (int) input('pts_' . $k, 0)));
            $upsert->execute([$evalId, $k, 'pitch', $p, trim((string) input('note_' . $k)) ?: null]);
            $pitchTotal += $p;
            if ($p > 0) { $pitchScored++; }
        }
        // „Pitch bewertet" gilt erst, wenn ALLE Pitch-Kriterien Punkte haben – nicht
        // schon beim bloßen Öffnen/Autosave eines nominierten Teams (sonst stünde im
        // PitchDay „bewertet", obwohl bislang nur der Businessplan ausgefüllt wurde).
        // So bleibt auch der Ø Pitch sauber (keine 0er von noch nicht bewerteten Pitches).
        $pitchSubmitted = $pitchScored === count(Criteria::PITCH) ? 1 : 0;
    }

    // Eigene PitchDay-Fragen der/des Juror:in (nur wenn Pitch-Phase editierbar,
    // sonst bestehenden Text beibehalten – deaktivierte Felder werden nicht gesendet).
    $pitchQuestions = $eval['pitch_questions'] ?? null;
    if ($isPitch && !$pitchLocked) {
        $pitchQuestions = trim((string) input('pitch_questions')) ?: null;
    }

    $grand = Criteria::grandTotal((float) $bpTotal, (float) ($pitchTotal ?? 0));
    Database::run(
        'UPDATE evaluations SET bp_submitted=?, pitch_submitted=?, bp_total=?, pitch_total=?, pitch_questions=?, grand_total=? WHERE id=?',
        [$bpSubmitted, $pitchSubmitted, $bpTotal, $pitchTotal, $pitchQuestions, $grand, $evalId]
    );

    // Autosave: still speichern und als JSON antworten (keine Umleitung / kein Flash).
    if (input('ajax') === '1') {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => true, 'bp_total' => $bpTotal, 'pitch_total' => $pitchTotal, 'grand' => $grand]);
        exit;
    }

    Audit::log('eval.save', 'Bewertung gespeichert: ' . $team['name'] . ' (' . $bpTotal . '/50' . ($isPitch ? ', Pitch ' . $pitchTotal . '/40' : '') . ')', 'team', $teamId);
    flash('success', 'Bewertung gespeichert (' . $bpTotal . '/50' . ($isPitch ? ', Pitch ' . $pitchTotal . '/40' : '') . ').');
    redirect(url('evaluate', ['team' => $teamId]));
}

$renderCriteria = function (array $group, string $phaseLabel, bool $lock) use ($scores) {
    $dis = $lock ? ' disabled' : '';
    foreach ($group as $k => $c) {
        $cur = $scores[$k]['points'] ?? '';
        $note = $scores[$k]['notes'] ?? '';
        echo '<div class="crit">';
        echo '<div class="crit__head">';
        echo '<span style="display:inline-flex;align-items:center;gap:8px;min-width:0"><strong>' . e($c['title']) . '</strong><span class="crit__saved" hidden>✓ gespeichert</span></span>';
        echo '<div class="crit__pts"><input type="number" name="pts_' . e($k) . '" min="0" max="10" step="1" data-score value="' . e((string) $cur) . '"' . $dis . '> <span class="muted">/10</span></div></div>';
        echo '<ul class="crit__hints">';
        foreach ($c['points'] as $h) { echo '<li>' . e($h) . '</li>'; }
        echo '</ul>';
        echo '<textarea name="note_' . e($k) . '" rows="2" placeholder="Notizen (optional)"' . $dis . '>' . e((string) $note) . '</textarea>';
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

<?php if ($finalFrozen || $bpFrozen): ?>
  <div class="card mb" style="border-left:4px solid var(--wj-blue)"><div class="card__body" style="display:flex;align-items:center;gap:10px">
    <span style="font-size:20px">🔒</span>
    <div><?php if ($finalFrozen): ?>
        <strong>Endergebnis eingefroren.</strong>
        <span class="muted"><?= $isManager
          ? 'Das Ranking ist festgeschrieben. Als Verwaltung kannst du hier noch Korrekturen vornehmen.'
          : 'Das Ranking ist festgeschrieben – Änderungen sind nicht mehr möglich (nur lesend).' ?></span>
      <?php else: ?>
        <strong>Businessplan-Bewertung eingefroren.</strong>
        <span class="muted"><?= $isManager
          ? 'Die BP-Punkte sind festgeschrieben (Nominierung steht). Als Verwaltung kannst du noch korrigieren.'
          : 'Die BP-Punkte sind festgeschrieben.' . ($isPitch ? ' Die Pitch-Bewertung am PitchDay ist weiterhin möglich.' : '') ?></span>
      <?php endif; ?></div>
  </div></div>
<?php endif; ?>

<form method="post" action="<?= url('evaluate', ['team' => $teamId]) ?>"<?= $allLocked ? '' : ' data-autosave' ?>>
  <?= Csrf::field() ?>
  <div class="card mb">
    <div class="card__head">Businessplan <span class="muted" style="font-weight:400">— Summe <span data-score-total>0</span>/50<?= $bpLocked ? ' · 🔒 eingefroren' : '' ?></span></div>
    <div class="card__body eval-grid"><?php $renderCriteria(Criteria::BUSINESSPLAN, 'businessplan', $bpLocked); ?></div>
  </div>

  <?php if ($isPitch): ?>
    <div class="card mb">
      <div class="card__head">❓ Meine Fragen für den PitchDay <span class="muted" style="font-weight:400">— nur für dich sichtbar</span></div>
      <div class="card__body">
        <p class="muted" style="font-size:13px;margin:0 0 8px">Notiere hier Fragen, die du dem Team am PitchDay stellen möchtest. Diese Notiz ist privat (nur für dich) und wird automatisch gespeichert.</p>
        <textarea name="pitch_questions" rows="4" data-questions placeholder="z. B. Wie skaliert ihr die Produktion? Wer ist eure Zielgruppe? …"<?= $pitchLocked ? ' disabled' : '' ?>><?= e((string) ($eval['pitch_questions'] ?? '')) ?></textarea>
      </div>
    </div>

    <div class="card mb">
      <div class="card__head">Pitch-Day <span class="muted" style="font-weight:400">(Team pitcht)<?= $pitchLocked ? ' · 🔒 eingefroren' : '' ?></span></div>
      <div class="card__body eval-grid"><?php $renderCriteria(Criteria::PITCH, 'pitch', $pitchLocked); ?></div>
    </div>
  <?php else: ?>
    <p class="muted mb">Pitch-Kriterien erscheinen, sobald das Team für den Pitch-Day nominiert ist.</p>
  <?php endif; ?>

  <div class="card"><div class="card__body" style="display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap">
    <div>
      <strong>Punkteskala:</strong>
      <span class="muted" style="font-size:13px">10 herausragend · 8–9 sehr gut · 6–7 gut · 4–5 ausbaufähig · 1–3 schwach · 0 unbewertbar</span>
    </div>
    <div style="display:flex;align-items:center;gap:12px">
      <?php if ($allLocked): ?>
        <span class="muted">🔒 Eingefroren – schreibgeschützt</span>
      <?php else: ?>
        <span class="autosave-status" data-autosave-status aria-live="polite">✓ Automatisch gespeichert</span>
        <button class="btn btn--primary">Speichern</button>
      <?php endif; ?>
    </div>
  </div></div>
</form>

<style>
.eval-grid{display:grid;grid-template-columns:1fr 1fr;gap:16px}
.crit{border:1px solid var(--line);border-radius:10px;padding:12px 14px;transition:border-color .3s ease,box-shadow .3s ease}
.crit.is-saved{border-color:var(--wj-teal);box-shadow:0 0 0 3px rgba(71,215,172,.30)}
.crit__head{display:flex;justify-content:space-between;align-items:center;gap:10px}
.crit__pts{display:flex;align-items:center;gap:6px}
.crit__pts input{width:64px;text-align:center}
.crit__saved{color:var(--wj-teal-d);font-family:"Chivo",sans-serif;font-weight:700;font-size:12px;white-space:nowrap;opacity:0;transition:opacity .3s ease}
.crit.is-saved .crit__saved{opacity:1}
.crit__hints{margin:8px 0;padding-left:18px;color:var(--muted);font-size:13px}
.crit textarea{margin-top:6px}
.autosave-status{color:var(--wj-teal-d);font-family:"Chivo",sans-serif;font-weight:700;font-size:13px;opacity:0;transition:opacity .3s ease}
.autosave-status.is-visible{opacity:1}
.autosave-status.is-saving{color:var(--muted)}
textarea[data-questions]{width:100%;resize:vertical;min-height:90px}
@media(max-width:800px){.eval-grid{grid-template-columns:1fr}}
</style>
<?php
$content = ob_get_clean();
$title = 'Bewerten';
require APP_PATH . '/pages/_layout.php';
