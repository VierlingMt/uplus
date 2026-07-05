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
    <div class="card__head">Team</div>
    <div class="card__body">
      <p><strong>Schule:</strong> <?= e($team['school_name']) ?></p>
      <?php if ($team['idea_name']): ?><p><strong>Idee:</strong> <?= e($team['idea_name']) ?></p><?php endif; ?>
      <?php if ($team['idea_pitch']): ?><p class="muted"><?= nl2br(e($team['idea_pitch'])) ?></p><?php endif; ?>
      <?php if ($members): ?><p><strong>Mitglieder:</strong> <?= e(implode(', ', array_column($members, 'name'))) ?></p><?php endif; ?>
    </div>
  </div>

  <div class="card">
    <div class="card__head">Businessplan (PDF)</div>
    <div class="card__body">
      <?php if ($plan): ?>
        <p><span class="pill teal">Version <?= (int) $plan['version'] ?></span>
           <span class="muted"><?= e($plan['original_name']) ?> · <?= human_size((int) $plan['size_bytes']) ?> · <?= e(date('d.m.Y H:i', strtotime((string) $plan['created_at']))) ?></span></p>
        <a class="btn btn--primary" href="<?= url('bp_download', ['id' => $plan['id']]) ?>">PDF herunterladen</a>
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

<div class="card mt">
  <div class="card__head" style="display:flex;justify-content:space-between;align-items:center">
    <span>KI-Vorbewertung <span class="muted" style="font-weight:400;font-size:13px">(Businessplan, max 50)</span></span>
    <?php if ($plan && $isAdmin): ?>
      <form method="post" action="<?= url('plans') ?>">
        <?= Csrf::field() ?><input type="hidden" name="action" value="run_ai"><input type="hidden" name="bp_id" value="<?= (int) $plan['id'] ?>">
        <button class="btn btn--teal btn--sm"><?= $ai ? 'Neu bewerten' : 'KI-Vorbewertung starten' ?></button>
      </form>
    <?php endif; ?>
  </div>
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
</div>
<?php
$content = ob_get_clean();
$title = $team['name'];
require APP_PATH . '/pages/_layout.php';
