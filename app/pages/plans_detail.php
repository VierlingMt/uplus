<?php
/** Businessplan-Detail eines Teams: Datei + KI-Vorbewertung. Erwartet $tid. */
declare(strict_types=1);

/** @var int $tid */
$team = Database::one(
    'SELECT t.*, s.name AS school_name FROM teams t JOIN schools s ON s.id=t.school_id WHERE t.id = ?',
    [$tid]
);
if (!$team) { http_response_code(404); render('error', ['title' => 'Nicht gefunden', 'message' => 'Team existiert nicht.']); return; }
if ($isTeacher && (int) $team['school_id'] !== $mySchool) {
    http_response_code(403); render('error', ['title' => 'Kein Zugriff', 'message' => 'Nur Teams der eigenen Schule.']); return;
}

$plan = Database::one('SELECT * FROM business_plans WHERE team_id = ? AND is_current = 1', [$tid]);
$ai   = $plan ? AiEval::latest((int) $plan['id']) : null;
$sc   = $plan ? AiEval::latestStructure((int) $plan['id']) : null;
$canUpload = $isAdmin || ($isTeacher && (int) $team['school_id'] === $mySchool);
$members = Database::all('SELECT name FROM students WHERE team_id = ? ORDER BY name', [$tid]);
$fmt = fn($n) => $n === null ? '—' : rtrim(rtrim(number_format((float) $n, 1, ',', ''), '0'), ',');

ob_start(); ?>
<div class="page-head">
  <h1><?= e($team['name']) ?></h1>
  <a href="<?= url('plans') ?>" class="btn btn--ghost">← Übersicht</a>
</div>

<div class="grid cols-2">
  <div class="card">
    <div class="card__head" style="display:flex;align-items:center;justify-content:space-between;gap:10px">
      <span>Team</span>
      <?php $canManageTeam = $isAdmin || ($isTeacher && (int) $team['school_id'] === $mySchool); ?>
      <?php if ($canManageTeam): ?>
        <a href="<?= url('teams', ['edit' => (int) $team['id']]) ?>" class="btn btn--ghost btn--sm" title="Team &amp; Mitglieder verwalten">👥 Team verwalten</a>
      <?php endif; ?>
    </div>
    <div class="card__body">
      <p><strong>Schule:</strong> <?= e($team['school_name']) ?></p>
      <?php if ($team['idea_name']): ?><p><strong>Idee:</strong> <?= e($team['idea_name']) ?></p><?php endif; ?>
      <?php if ($team['idea_pitch']): ?><p class="muted"><?= nl2br(e($team['idea_pitch'])) ?></p><?php endif; ?>
      <?php if ($members): ?><p><strong>Mitglieder (<?= count($members) ?>):</strong> <?= e(implode(', ', array_column($members, 'name'))) ?></p>
      <?php else: ?><p class="muted">Noch keine Teammitglieder hinterlegt.<?= $canManageTeam ? ' <a href="' . url('teams', ['edit' => (int) $team['id']]) . '">Ergänzen →</a>' : '' ?></p><?php endif; ?>
    </div>
  </div>

  <div class="card">
    <div class="card__head">Businessplan (PDF)</div>
    <div class="card__body">
      <?php if ($plan): ?>
        <p><span class="pill teal">Version <?= (int) $plan['version'] ?></span>
           <span class="muted"><?= e($plan['original_name']) ?> · <?= human_size((int) $plan['size_bytes']) ?> · <?= e(date('d.m.Y H:i', strtotime((string) $plan['created_at']))) ?></span></p>
        <a class="btn btn--primary" href="<?= url('bp_download', ['id' => $plan['id']]) ?>"
           data-pdf-url="<?= url('bp_download', ['id' => $plan['id']]) ?>"
           data-pdf-title="<?= e($team['name'] . ($team['idea_name'] ? ' – ' . $team['idea_name'] : '')) ?>">PDF ansehen</a>
        <a class="btn btn--ghost" href="<?= url('bp_download', ['id' => $plan['id']]) ?>" download>Herunterladen</a>
        <?php if ($isAdmin): ?>
          <form method="post" action="<?= url('plans') ?>" style="display:inline" data-confirm="Diese Version löschen?">
            <?= Csrf::field() ?><input type="hidden" name="action" value="delete_plan"><input type="hidden" name="bp_id" value="<?= (int) $plan['id'] ?>">
            <button class="btn btn--danger">Löschen</button>
          </form>
        <?php endif; ?>
      <?php else: ?>
        <p class="muted">Noch kein Businessplan eingereicht.</p>
      <?php endif; ?>

      <?php if ($canUpload): ?>
        <hr style="margin:16px 0;border:none;border-top:1px solid var(--line)">
        <form method="post" action="<?= url('plans') ?>" enctype="multipart/form-data">
          <?= Csrf::field() ?><input type="hidden" name="action" value="upload"><input type="hidden" name="team_id" value="<?= (int) $team['id'] ?>">
          <label><?= $plan ? 'Neue Version hochladen' : 'Businessplan hochladen' ?> (PDF)</label>
          <div style="display:flex;gap:8px;margin-top:6px">
            <input type="file" name="file" accept="application/pdf" required>
            <button class="btn btn--teal">Hochladen</button>
          </div>
        </form>
      <?php endif; ?>
    </div>
  </div>
</div>

<?php if (!$isTeacher): /* Lehrkräfte sehen keine Bewertungen (Jury/KI/Struktur) */ ?>

<?php
// Jury-Bewertung – Kopfzahlen (Karte ganz oben, eingeklappt)
$evals = Database::all(
    'SELECT e.*, u.name AS juror_name FROM evaluations e JOIN users u ON u.id=e.juror_id
     WHERE e.team_id=? AND e.bp_submitted=1 ORDER BY u.name',
    [$tid]
);
$avgBp = $evals ? array_sum(array_map(fn($e) => (float) $e['bp_total'], $evals)) / count($evals) : null;
?>
<details class="card mt collapse">
  <summary class="collapse__head">
    <span class="collapse__title"><span class="collapse__chev" aria-hidden="true">▸</span> Jury-Bewertung
      <span class="collapse__info"><?= count($evals) ?> abgegeben<?= $avgBp !== null ? ' · Ø ' . $fmt($avgBp) . '/50' : '' ?></span></span>
    <a class="btn btn--teal btn--sm" href="<?= url('evaluate', ['team' => $tid]) ?>" onclick="event.stopPropagation()">Selbst bewerten</a>
  </summary>
  <div class="card__body">
    <?php if (!$evals): ?>
      <p class="muted">Noch keine Jury-Bewertung abgegeben.</p>
    <?php else: ?>
      <table class="data data--cards">
        <thead><tr><th>Juror:in</th><th>Businessplan /50</th><th>Pitch /40</th><th>Gesamt /140</th></tr></thead>
        <tbody>
        <?php foreach ($evals as $ev): ?>
          <tr>
            <td data-label="Juror:in"><?= e($ev['juror_name']) ?></td>
            <td data-label="Businessplan /50"><strong><?= $fmt($ev['bp_total']) ?></strong></td>
            <td data-label="Pitch /40"><?= $ev['pitch_submitted'] ? $fmt($ev['pitch_total']) : '–' ?></td>
            <td data-label="Gesamt /140"><strong style="color:var(--wj-blue)"><?= $fmt($ev['grand_total']) ?></strong></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </div>
</details>

<?php
// Effektives Ergebnis: manueller Override der Projektleitung schlägt das automatische Gate.
$scOverride = ($plan && $plan['sc_override'] !== null) ? (int) $plan['sc_override'] : null;
$scAuto     = $sc ? (int) $sc['meets_minimum'] : null;
$scEff      = $scOverride !== null ? $scOverride : $scAuto;
?>
<details class="card mt collapse">
  <summary class="collapse__head">
    <span class="collapse__title"><span class="collapse__chev" aria-hidden="true">▸</span> Struktur-Check
      <span class="collapse__info"><?php
        if (!$plan || !$sc) { echo 'offen · Eigentext-Tiefe gegen die Vorlage';
        } elseif ($sc['status'] === 'error') { echo 'Fehler';
        } elseif ($sc['status'] !== 'done') { echo 'läuft …';
        } else { echo ($sc['completeness_score'] !== null ? (int) $sc['completeness_score'] : '–') . '/10 · '
            . ($scEff === 0 ? '⚠ unter Standard' : '✓ ok')
            . ($scOverride !== null ? ' · ✋ Override' : ''); } ?></span></span>
    <?php if ($plan && $isAdmin): ?>
      <form method="post" action="<?= url('plans') ?>" onclick="event.stopPropagation()">
        <?= Csrf::field() ?><input type="hidden" name="action" value="run_structure"><input type="hidden" name="bp_id" value="<?= (int) $plan['id'] ?>">
        <button class="btn btn--ghost btn--sm" data-loading="Prüfe …"><?= $sc ? 'Neu prüfen' : 'Struktur-Check starten' ?></button>
      </form>
    <?php endif; ?>
  </summary>
  <div class="card__body">
    <?php if (!$plan): ?>
      <p class="muted">Zuerst einen Businessplan hochladen.</p>
    <?php elseif (!$sc): ?>
      <p class="muted">Noch kein Struktur-Check.<?= $isAdmin ? '' : ' Die Projektleitung kann ihn starten.' ?></p>
    <?php elseif ($sc['status'] === 'error'): ?>
      <div class="flash error">Fehler: <?= e($sc['error_message'] ?: 'unbekannt') ?></div>
    <?php elseif ($sc['status'] !== 'done'): ?>
      <p class="muted">Prüfung läuft …</p>
    <?php else:
        $thr = Settings::getInt('ai_min_score', 6);
        $minWords = Settings::getInt('ai_min_words', 200);
        $ownWords = $sc['own_words'] !== null ? (int) $sc['own_words'] : null; ?>
      <p><strong style="font-size:22px;color:var(--wj-blue)"><?= $sc['completeness_score'] !== null ? (int) $sc['completeness_score'] : '–' ?></strong> / 10
         <span class="muted">Substanz-Score (Kernabschnitte) · Schwelle <?= $thr ?></span>
         <?php if ($ownWords !== null): ?>
           &nbsp;·&nbsp; <strong style="font-size:18px"><?= $ownWords ?></strong> <span class="muted">geschätzte Eigentext-Wörter · Mindestwert <?= $minWords ?></span>
         <?php endif; ?></p>

      <?php if ($scEff === 0): ?>
        <div class="flash error"><strong>⚠ Mindeststandard nicht erfüllt</strong> – kann ohne weitere Sichtung aussortiert werden.
          <?php if ($scOverride === null && $sc['reason']): ?><br><span style="font-size:13px"><?= nl2br(e($sc['reason'])) ?></span><?php endif; ?></div>
      <?php else: ?>
        <div class="flash success"><strong>✓ Mindeststandard erfüllt</strong><?= ($scOverride === null && $sc['reason']) ? ' – <span style="font-weight:400">' . e($sc['reason']) . '</span>' : '' ?></div>
      <?php endif; ?>

      <?php if ($scOverride !== null):
          $ovrBy = $plan['sc_override_by'] ? Database::value('SELECT name FROM users WHERE id=?', [(int) $plan['sc_override_by']]) : null; ?>
        <div class="flash" style="background:var(--amber-bg,#fff7e6);border-color:#e0a800">
          <strong>✋ Manueller Override der Projektleitung</strong> – gilt als
          <strong><?= $scOverride === 1 ? 'bestanden' : 'aussortiert' ?></strong>
          <?php if ($sc['completeness_score'] !== null): ?><span class="muted">(automatisch wäre: <?= $scAuto === 0 ? 'unter Standard' : 'ok' ?>)</span><?php endif; ?>
          <?php if ($plan['sc_override_reason']): ?><br><span style="font-size:13px"><?= e($plan['sc_override_reason']) ?></span><?php endif; ?>
          <?php if ($ovrBy || $plan['sc_override_at']): ?><br><span class="muted" style="font-size:12px"><?= $ovrBy ? e((string) $ovrBy) : '' ?><?= $plan['sc_override_at'] ? ' · ' . e(date('d.m.Y H:i', strtotime((string) $plan['sc_override_at']))) : '' ?></span><?php endif; ?>
        </div>
      <?php endif; ?>

      <table class="data mt">
        <thead><tr><th>Abschnitt der Vorlage</th><th style="width:140px">Status</th><th style="width:80px">Eigene Sätze</th><th>Hinweis</th></tr></thead>
        <tbody>
        <?php foreach (($sc['sections'] ?? []) as $sec):
            $st = $sec['status'] ?? 'fehlt';
            $map = ['behandelt' => ['behandelt', 'teal'], 'oberflaechlich' => ['nur oberflächlich', 'amber'], 'fehlt' => ['fehlt', 'red']];
            [$lbl, $cls] = $map[$st] ?? [$st, 'muted']; ?>
          <tr>
            <td><?= e($sec['title'] ?? '') ?><?= empty($sec['required']) ? ' <span class="muted" style="font-size:12px">(optional)</span>' : '' ?></td>
            <td><span class="pill <?= $cls ?>"><?= e($lbl) ?></span></td>
            <td><?= isset($sec['own_sentences']) && $sec['own_sentences'] !== null ? (int) $sec['own_sentences'] : '–' ?></td>
            <td class="muted" style="font-size:13px"><?= e($sec['note'] ?? '') ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      <p class="muted mt" style="font-size:12px">Modell: <?= e((string) $sc['model']) ?> · Überschriften/Leitfragen der Vorlage zählen nicht als Inhalt – gemessen wird der Eigentext der Schüler:innen.</p>

      <?php if ($isAdmin): ?>
        <hr style="margin:14px 0;border:none;border-top:1px solid var(--line)">
        <details>
          <summary style="cursor:pointer;font-weight:600">✋ Ergebnis manuell überschreiben (Projektleitung)</summary>
          <form method="post" action="<?= url('plans') ?>" class="mt">
            <?= Csrf::field() ?><input type="hidden" name="action" value="override_structure"><input type="hidden" name="bp_id" value="<?= (int) $plan['id'] ?>">
            <label>Begründung (optional)</label>
            <input type="text" name="override_reason" maxlength="255" placeholder="z. B. von Hand geprüft – Plan ist ausreichend" value="<?= e((string) ($plan['sc_override_reason'] ?? '')) ?>" style="width:100%;margin:6px 0">
            <div style="display:flex;gap:8px;flex-wrap:wrap">
              <button class="btn btn--teal btn--sm" name="override" value="pass">Als bestanden markieren</button>
              <button class="btn btn--danger btn--sm" name="override" value="fail">Als aussortiert markieren</button>
              <?php if ($scOverride !== null): ?>
                <button class="btn btn--ghost btn--sm" name="override" value="clear">Override aufheben</button>
              <?php endif; ?>
            </div>
          </form>
        </details>
      <?php endif; ?>
    <?php endif; ?>
  </div>
</details>

<?php
// KI-Vorbewertung (inhaltliche Note) nur für Verwaltung – oder für Jury, falls im Admin freigegeben.
$canSeeAiEval = $isAdmin || Settings::getInt('ai_eval_jurors', 0) === 1;
if ($canSeeAiEval): ?>
<details class="card mt collapse">
  <summary class="collapse__head">
    <span class="collapse__title"><span class="collapse__chev" aria-hidden="true">▸</span> KI-Vorbewertung
      <span class="collapse__info"><?php
        if (!$plan || !$ai) { echo 'offen · inhaltliche Note (max 50)';
        } elseif ($ai['status'] === 'error') { echo 'Fehler';
        } elseif ($ai['status'] !== 'done') { echo 'läuft …';
        } else { echo $fmt($ai['total_score']) . '/50'; } ?></span></span>
    <?php if ($plan && $isAdmin): ?>
      <form method="post" action="<?= url('plans') ?>" onclick="event.stopPropagation()">
        <?= Csrf::field() ?><input type="hidden" name="action" value="run_ai"><input type="hidden" name="bp_id" value="<?= (int) $plan['id'] ?>">
        <button class="btn btn--teal btn--sm" data-loading="KI bewertet …"><?= $ai ? 'Neu bewerten' : 'KI-Vorbewertung starten' ?></button>
      </form>
    <?php endif; ?>
  </summary>
  <div class="card__body">
    <?php if (!$plan): ?>
      <p class="muted">Zuerst einen Businessplan hochladen.</p>
    <?php elseif (!$ai): ?>
      <p class="muted">Noch keine KI-Vorbewertung.<?= $isAdmin ? '' : ' Die Projektleitung kann sie starten.' ?></p>
    <?php elseif ($ai['status'] === 'error'): ?>
      <div class="flash error">KI-Fehler: <?= e($ai['error_message'] ?: 'unbekannt') ?></div>
    <?php elseif ($ai['status'] !== 'done'): ?>
      <p class="muted">Bewertung läuft …</p>
    <?php else: ?>
      <p><strong style="font-size:22px;color:var(--wj-blue)"><?= $fmt($ai['total_score']) ?></strong> / 50
         <span class="muted">· Modell <?= e($ai['model']) ?></span></p>
      <?php if ($ai['summary']): ?><p><?= nl2br(e($ai['summary'])) ?></p><?php endif; ?>
      <table class="data mt">
        <thead><tr><th>Kriterium</th><th style="width:70px">Punkte</th><th>Begründung</th></tr></thead>
        <tbody>
        <?php
        $byKey = [];
        foreach ($ai['scores'] as $s) { $byKey[$s['criterion_key']] = $s; }
        foreach (Criteria::BUSINESSPLAN as $k => $c):
            $s = $byKey[$k] ?? null; ?>
          <tr>
            <td><strong><?= e($c['title']) ?></strong></td>
            <td><strong><?= $s ? $fmt($s['score']) : '—' ?></strong>/10</td>
            <td class="muted" style="font-size:13px"><?= $s ? nl2br(e($s['rationale'])) : '' ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      <?php if ($ai['strengths'] || $ai['weaknesses']): ?>
        <div class="grid cols-2 mt">
          <?php if ($ai['strengths']): ?><div><label>Stärken</label><p class="muted" style="font-size:14px"><?= nl2br(e($ai['strengths'])) ?></p></div><?php endif; ?>
          <?php if ($ai['weaknesses']): ?><div><label>Verbesserungspotenzial</label><p class="muted" style="font-size:14px"><?= nl2br(e($ai['weaknesses'])) ?></p></div><?php endif; ?>
        </div>
      <?php endif; ?>
      <p class="muted mt" style="font-size:12px">Hinweis: KI-generierte Vorbewertung als Orientierung – die finale Bewertung erfolgt durch die Jury.</p>
    <?php endif; ?>
  </div>
</details>
<?php endif; /* Ende KI-Vorbewertung */ ?>
<?php endif; /* Ende Bewertungs-Karten (nur für Nicht-Lehrkräfte) */ ?>
<?php
$content = ob_get_clean();
$title = $team['name'];
require APP_PATH . '/pages/_layout.php';
