<?php
/** Bewertungsübersicht, Ranking und Nominierung (Admin + Jury). */
declare(strict_types=1);

Auth::require('admin', 'lead', 'juror');
$isAdmin = Auth::isManager(); // Admin oder Projektleitung = volle Verwaltung
$jurorId = (int) Auth::id();

$pitchSlots = Settings::getInt('pitch_slots', 7);
$fallbackSlots = Settings::getInt('fallback_slots', 2);
// Faire Verteilung je Schule (damit keine Schule leer ausgeht).
$fairPerSchool     = Settings::getInt('pitch_fair', 1) === 1;
$fallbackPerSchool = Settings::getInt('fallback_per_school', 2);
// KI-Vorbewertung nur für Verwaltung – oder für Jury, falls im Admin freigegeben.
$showAiEval = $isAdmin || Settings::getInt('ai_eval_jurors', 0) === 1;
$cols = $showAiEval ? 10 : 9;

/** Ranking-Daten laden (inkl. Mittelwerte je Team). */
$loadRows = function () use ($jurorId): array {
    $rows = Database::all(
        "SELECT t.id, t.name, t.idea_name, t.status, t.pitch_order, t.school_id, s.short_name, s.name AS school_name,
                (SELECT AVG(e.bp_total)   FROM evaluations e JOIN users ju ON ju.id=e.juror_id WHERE e.team_id=t.id AND e.bp_submitted=1    AND EXISTS (SELECT 1 FROM user_roles ur WHERE ur.user_id = ju.id AND ur.role IN ('lead','juror'))) AS avg_bp,
                (SELECT COUNT(*)          FROM evaluations e JOIN users ju ON ju.id=e.juror_id WHERE e.team_id=t.id AND e.bp_submitted=1    AND EXISTS (SELECT 1 FROM user_roles ur WHERE ur.user_id = ju.id AND ur.role IN ('lead','juror'))) AS n_bp,
                (SELECT AVG(e.pitch_total)FROM evaluations e JOIN users ju ON ju.id=e.juror_id WHERE e.team_id=t.id AND e.pitch_submitted=1 AND EXISTS (SELECT 1 FROM user_roles ur WHERE ur.user_id = ju.id AND ur.role IN ('lead','juror'))) AS avg_pitch,
                (SELECT COUNT(*)          FROM evaluations e JOIN users ju ON ju.id=e.juror_id WHERE e.team_id=t.id AND e.pitch_submitted=1 AND EXISTS (SELECT 1 FROM user_roles ur WHERE ur.user_id = ju.id AND ur.role IN ('lead','juror'))) AS n_pitch,
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
        // Bewertete, nicht ausgeschiedene Teams – bereits nach Gesamt absteigend.
        $rows = array_values(array_filter($loadRows(), fn($r) => $r['status'] !== 'eliminated' && $r['n_bp'] > 0));
        Database::run("UPDATE teams SET status='submitted', pitch_order=NULL WHERE status IN ('nominated','fallback')");

        $nomIds = [];   // nominierte Team-IDs (Auswahl nach Leistung)
        $fbIds  = [];   // Nachrücker-IDs
        $msg    = '';

        if (!$fairPerSchool) {
            // Klassisch: globale Top-Liste (kann eine Schule ganz auslassen).
            $i = 0;
            foreach ($rows as $r) {
                if ($i < $pitchSlots) { $nomIds[] = (int) $r['id']; }
                elseif ($i < $pitchSlots + $fallbackSlots) { $fbIds[] = (int) $r['id']; }
                else { break; }
                $i++;
            }
            $msg = "Top {$pitchSlots} nominiert, {$fallbackSlots} Nachrücker gesetzt.";
        } else {
            // Faire Verteilung je Schule: jede Schule bekommt einen Grundstock an
            // Plätzen (⌊Plätze/Schulen⌋); die Restplätze gehen an die Schule(n) mit
            // den besten Businessplänen. So kommt keine Schule zu kurz.
            $bySchool = [];
            foreach ($rows as $r) { $bySchool[(int) $r['school_id']][] = $r; } // je Schule nach Gesamt absteigend
            $S = count($bySchool);
            if ($S === 0) {
                flash('error', 'Keine bewerteten Teams gefunden.');
                redirect(url('ranking'));
            }
            $perBase   = intdiv($pitchSlots, $S);
            $remainder = $pitchSlots % $S;
            // Schul-Stärke = Ø-Gesamt der besten (perBase+1) Teams der Schule.
            $strength = [];
            foreach ($bySchool as $sid => $teams) {
                $topK = array_slice($teams, 0, max(1, $perBase + 1));
                $strength[$sid] = array_sum(array_map(fn($t) => (float) $t['grand'], $topK)) / count($topK);
            }
            arsort($strength); // stärkste Schule zuerst
            $byStrength = array_keys($strength);
            // Platz-Kontingent je Schule (Restplätze an die stärksten Schulen).
            $quota = [];
            foreach (array_keys($bySchool) as $sid) { $quota[$sid] = $perBase; }
            for ($k = 0; $k < $remainder; $k++) { $quota[$byStrength[$k % $S]]++; }

            $used = [];
            foreach ($bySchool as $sid => $teams) {
                $take = min($quota[$sid], count($teams));
                for ($j = 0; $j < $take; $j++) { $nomIds[] = (int) $teams[$j]['id']; $used[(int) $teams[$j]['id']] = true; }
            }
            // Falls eine Schule zu wenige Teams hatte: mit den global Besten auffüllen.
            if (count($nomIds) < $pitchSlots) {
                foreach ($rows as $r) {
                    if (count($nomIds) >= $pitchSlots) { break; }
                    if (empty($used[(int) $r['id']])) { $nomIds[] = (int) $r['id']; $used[(int) $r['id']] = true; }
                }
            }
            // Nachrücker: je Schule die nächsten fallback_per_school Teams.
            foreach ($bySchool as $sid => $teams) {
                $c = 0;
                foreach ($teams as $t) {
                    if (!empty($used[(int) $t['id']])) { continue; }
                    if ($c >= $fallbackPerSchool) { break; }
                    $fbIds[] = (int) $t['id']; $used[(int) $t['id']] = true; $c++;
                }
            }
            $msg = "Fair nominiert: {$pitchSlots} Plätze auf {$S} Schulen verteilt, je Schule {$fallbackPerSchool} Nachrücker.";
        }

        // Bühnen-Reihenfolge bewusst ZUFÄLLIG (nicht die Punktereihenfolge), damit
        // aus der Aufruf-Reihenfolge nicht auf die Platzierung geschlossen werden kann.
        shuffle($nomIds);
        $ord = 1;
        foreach ($nomIds as $id) {
            Database::run('UPDATE teams SET status=?, pitch_order=? WHERE id=?', ['nominated', $ord++, $id]);
        }
        foreach ($fbIds as $id) {
            Database::run("UPDATE teams SET status='fallback', pitch_order=NULL WHERE id=?", [$id]);
        }
        flash('success', $msg . ' Bühnen-Reihenfolge zufällig gesetzt.');
    } elseif ($action === 'set_status') {
        $allowed = ['submitted', 'nominated', 'fallback', 'eliminated'];
        $st = (string) input('status');
        if (in_array($st, $allowed, true)) {
            $ord = $st === 'nominated' ? ((int) input('pitch_order') ?: null) : null;
            Database::run('UPDATE teams SET status=?, pitch_order=? WHERE id=?', [$st, $ord, (int) input('team_id')]);
            flash('success', 'Status aktualisiert.');
        }
    } elseif ($action === 'bp_freeze') {
        Settings::set('bp_frozen', '1');
        Audit::log('bp.freeze', 'Businessplan-Bewertung eingefroren.');
        flash('success', 'Businessplan-Bewertung eingefroren – die Jury kann die BP-Punkte nicht mehr ändern. Jederzeit wieder freizugeben.');
    } elseif ($action === 'bp_unfreeze') {
        Settings::set('bp_frozen', '0');
        Audit::log('bp.unfreeze', 'Businessplan-Bewertung wieder freigegeben.');
        flash('success', 'Businessplan-Bewertung wieder freigegeben – die Jury kann die BP-Punkte wieder ändern.');
    }
    // Das „Endergebnis einfrieren" (ranking_frozen) wird bewusst NUR im Menüpunkt
    // PitchDay bedient (pitch.php) – hier geht es um die Businessplan-Runde.
    redirect(url('ranking'));
}

$rows = $loadRows();
$bpFrozen = Settings::getInt('bp_frozen', 0) === 1;
$frozen = Settings::getInt('ranking_frozen', 0) === 1;
$fmt = fn($n) => $n === null ? '–' : rtrim(rtrim(number_format((float) $n, 1, ',', ''), '0'), ',');
// Admin ist eine reine Servicerolle und zählt NICHT als Jurymitglied.
$totalJurors = (int) Database::value("SELECT COUNT(*) FROM users u WHERE u.is_active=1 AND EXISTS (SELECT 1 FROM user_roles ur WHERE ur.user_id = u.id AND ur.role IN ('lead','juror'))");
$phaseLabels = ['submitted' => ['eingereicht', 'muted'], 'nominated' => ['Pitch', 'teal'], 'fallback' => ['Nachrücker', 'amber'], 'eliminated' => ['raus', 'muted'], 'draft' => ['Entwurf', 'muted']];
$roleLabels  = ['admin' => 'Admin', 'lead' => 'Projektleitung', 'juror' => 'Jury'];

// Bewertungsstand (Businessplan): wer hat welchen Plan noch nicht bewertet? (nur Verwaltung)
$coverage = [];
$coverageDone = 0;
if ($isAdmin) {
    $evaluators = Database::all(
        "SELECT u.id, u.name, u.role FROM users u WHERE u.is_active=1 AND EXISTS (SELECT 1 FROM user_roles ur WHERE ur.user_id = u.id AND ur.role IN ('lead','juror'))
         ORDER BY FIELD(u.role,'juror','lead'), u.name"
    );
    $planTeams = array_values(array_filter($rows, fn($r) => $r['bp_id'])); // nur Teams mit aktuellem Businessplan
    $doneSet = [];
    foreach (Database::all("SELECT juror_id, team_id FROM evaluations WHERE bp_submitted=1") as $d) {
        $doneSet[(int) $d['juror_id'] . '-' . (int) $d['team_id']] = true;
    }
    foreach ($evaluators as $ev) {
        $open = [];
        foreach ($planTeams as $pt) {
            if (empty($doneSet[(int) $ev['id'] . '-' . (int) $pt['id']])) {
                $open[] = $pt['name'];
            }
        }
        if (!$open) { $coverageDone++; }
        $coverage[] = ['name' => $ev['name'], 'role' => $ev['role'], 'open' => $open, 'total' => count($planTeams)];
    }
    // Wer am meisten offen hat, steht oben (zum „Nachfassen").
    usort($coverage, fn($a, $b) => count($b['open']) <=> count($a['open']));
}

ob_start(); ?>
<div class="page-head">
  <h1>Bewertung &amp; Ranking</h1>
  <?php if ($isAdmin): ?>
    <div style="display:flex;gap:8px;flex-wrap:wrap">
      <form method="post" action="<?= url('ranking') ?>" data-confirm="<?= $fairPerSchool
          ? e($pitchSlots . ' Plätze fair je Schule verteilen (Restplätze an die beste Schule) und je Schule ' . $fallbackPerSchool . ' Nachrücker setzen? Bestehende Nominierungen werden überschrieben.')
          : e('Automatisch Top ' . $pitchSlots . ' nominieren und ' . $fallbackSlots . ' Nachrücker setzen? Bestehende Nominierungen werden überschrieben.') ?>">
        <?= Csrf::field() ?><input type="hidden" name="action" value="auto_nominate">
        <button class="btn btn--teal"<?= $frozen ? ' disabled title="Erst freigeben, um neu zu nominieren."' : '' ?>>★ <?= $fairPerSchool
          ? 'Fair nominieren (' . (int) $pitchSlots . ' Plätze je Schule)'
          : 'Top ' . (int) $pitchSlots . ' (+' . (int) $fallbackSlots . ') nominieren' ?></button>
      </form>
      <?php if (!$frozen): ?>
        <?php if ($bpFrozen): ?>
          <form method="post" action="<?= url('ranking') ?>" data-confirm="Businessplan-Bewertung wieder freigeben? Die Jury kann die BP-Punkte danach wieder ändern.">
            <?= Csrf::field() ?><input type="hidden" name="action" value="bp_unfreeze">
            <button class="btn btn--ghost">🔓 BP-Runde freigeben</button>
          </form>
        <?php else: ?>
          <form method="post" action="<?= url('ranking') ?>" data-confirm="Businessplan-Bewertung jetzt einfrieren? Die Jury kann die BP-Punkte dann nicht mehr ändern (die Pitch-Bewertung am PitchDay bleibt möglich).">
            <?= Csrf::field() ?><input type="hidden" name="action" value="bp_freeze">
            <button class="btn btn--ghost">🔒 BP-Runde einfrieren</button>
          </form>
        <?php endif; ?>
      <?php endif; ?>
    </div>
  <?php endif; ?>
</div>

<?php if ($frozen): ?>
<div class="card" style="border-left:4px solid var(--wj-blue)"><div class="card__body" style="display:flex;align-items:center;gap:10px">
  <span style="font-size:20px">🔒</span>
  <div><strong>Endergebnis eingefroren – das Ranking ist festgeschrieben.</strong>
    <span class="muted"><?= $isAdmin
      ? 'Freigeben (falls nötig) im Menüpunkt PitchDay.'
      : 'Deine Bewertungen sind gespeichert und können nicht mehr geändert werden.' ?></span></div>
</div></div>
<?php elseif ($bpFrozen): ?>
<div class="card" style="border-left:4px solid var(--wj-blue)"><div class="card__body" style="display:flex;align-items:center;gap:10px">
  <span style="font-size:20px">🔒</span>
  <div><strong>Businessplan-Bewertung eingefroren.</strong>
    <span class="muted"><?= $isAdmin
      ? 'Die BP-Punkte sind festgeschrieben – die Jury kann sie nicht mehr ändern. Die Pitch-Bewertung am PitchDay bleibt möglich. Oben jederzeit wieder freizugeben.'
      : 'Die Businessplan-Punkte sind gespeichert und können nicht mehr geändert werden.' ?></span></div>
</div></div>
<?php endif; ?>

<div class="card">
  <div class="card__head" style="display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap">
    <span>Ranking <span class="muted" style="font-weight:400;font-size:13px">· Gesamt = 2 × Businessplan + 1 × Pitch (max 140) · <?= $totalJurors ?> Bewertende</span></span>
    <span class="ranking-filters">
      <label class="toggle-eval" style="font-weight:400"><input type="checkbox" id="hideCompleteToggle"> Nur unvollständig bewertete</label>
    </span>
  </div>
  <div class="table-wrap">
    <table class="data data--cards data--tight" id="rankingTable">
      <thead><tr>
        <th style="width:40px">#</th><th>Team</th><th>Schule</th><th>Jury</th>
        <th>Ø BP<br>/50</th><th>Ø Pitch<br>/40</th><th>Gesamt<br>/140</th><?php if ($showAiEval): ?><th>KI</th><?php endif; ?><th>Status</th><th></th>
      </tr></thead>
      <tbody>
      <?php foreach ($rows as $i => $r): [$sl, $sc] = $phaseLabels[$r['status']] ?? [$r['status'], 'muted'];
          $complete = $totalJurors > 0 && (int) $r['n_bp'] >= $totalJurors ? 1 : 0;
          $isPitchRow = $r['status'] === 'nominated' ? 1 : 0; ?>
        <tr data-complete="<?= $complete ?>" data-pitch="<?= $isPitchRow ?>">
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
        <tr class="admin-row" data-complete="<?= $complete ?>" data-pitch="<?= $isPitchRow ?>"><td colspan="<?= $cols ?>" style="padding-top:0">
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

<?php if ($isAdmin && $coverage): ?>
<details class="card mt collapse">
  <summary class="collapse__head">
    <span class="collapse__title"><span class="collapse__chev" aria-hidden="true">▸</span> Bewertungsstand (Businessplan)
      <span class="collapse__info"><?= $coverageDone ?> von <?= count($coverage) ?> Bewertenden fertig</span></span>
  </summary>
  <div class="card__body">
    <p class="muted" style="margin-top:0;font-size:13px">Wer hat welche eingereichten Businesspläne noch nicht bewertet? (Sortiert nach den meisten offenen zuerst.)</p>
    <table class="data data--cards">
      <thead><tr><th>Bewertende:r</th><th>Fortschritt</th><th>Noch offen</th></tr></thead>
      <tbody>
      <?php if (!$coverage[0]['total']): ?>
        <tr><td colspan="3" class="muted">Noch keine eingereichten Businesspläne.</td></tr>
      <?php else: foreach ($coverage as $c): $done = $c['total'] - count($c['open']); ?>
        <tr>
          <td data-label="Bewertende:r"><strong><?= e($c['name']) ?></strong>
            <span class="pill <?= ['admin'=>'blue','lead'=>'blue','juror'=>'teal'][$c['role']] ?? 'muted' ?>"><?= e($roleLabels[$c['role']] ?? $c['role']) ?></span></td>
          <td data-label="Fortschritt"><strong><?= $done ?></strong>/<?= $c['total'] ?></td>
          <td data-label="Noch offen"><?php if ($c['open']): ?><span style="color:#b32a22"><?= e(implode(', ', $c['open'])) ?></span><?php else: ?><span class="pill teal">✓ alle bewertet</span><?php endif; ?></td>
        </tr>
      <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</details>
<?php endif; ?>

<style>
.admin-row td{border-bottom:2px solid var(--line)}.admin-row form{opacity:.75}.admin-row:hover form{opacity:1}
table.hide-complete tr[data-complete="1"]{display:none}
.ranking-filters{display:flex;gap:16px;flex-wrap:wrap;align-items:center}
</style>
<script>
(function(){
  var tbl=document.getElementById('rankingTable');
  if(!tbl) return;
  function wire(id,cls,key){
    var t=document.getElementById(id); if(!t) return;
    var saved=null; try{saved=localStorage.getItem(key);}catch(e){}
    var on=saved==='1';
    t.checked=on; tbl.classList.toggle(cls,on);
    t.addEventListener('change',function(){
      try{localStorage.setItem(key,t.checked?'1':'0');}catch(e){}
      tbl.classList.toggle(cls,t.checked);
    });
  }
  wire('hideCompleteToggle','hide-complete','uplus_ranking_hide_complete');
})();
</script>
<?php
$content = ob_get_clean();
$title = 'Bewertung & Ranking';
require APP_PATH . '/pages/_layout.php';
