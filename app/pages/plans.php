<?php
/** Businesspläne: Upload, Übersicht, KI-Vorbewertung. */
declare(strict_types=1);

Auth::require();
$me = Auth::user();
$isAdmin = Auth::is('admin');
$isTeacher = Auth::is('teacher');
$mySchool = $me['school_id'] ? (int) $me['school_id'] : null;

$canUploadFor = function (array $team) use ($isAdmin, $isTeacher, $mySchool): bool {
    if ($isAdmin) return true;
    if ($isTeacher) return (int) $team['school_id'] === $mySchool;
    return false;
};

if (is_post()) {
    Csrf::check();
    $action = (string) input('action');

    if ($action === 'upload') {
        $tid = (int) input('team_id');
        $team = Database::one('SELECT * FROM teams WHERE id = ?', [$tid]);
        if (!$team || !$canUploadFor($team)) { flash('error', 'Kein Zugriff.'); redirect(url('plans')); }
        $err = $_FILES['file']['error'] ?? UPLOAD_ERR_NO_FILE;
        if ($err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE) {
            flash('error', 'Datei überschreitet das Server-Upload-Limit. Bitte kleinere PDF oder Limit erhöhen.');
            redirect(url('plans', ['team' => $tid]));
        }
        if ($err === UPLOAD_ERR_NO_FILE || empty($_FILES['file']['name'])) {
            flash('error', 'Keine Datei gewählt.'); redirect(url('plans', ['team' => $tid]));
        }
        if ($err !== UPLOAD_ERR_OK || !is_uploaded_file($_FILES['file']['tmp_name'])) {
            flash('error', 'Upload fehlgeschlagen (Fehlercode ' . (int) $err . ').'); redirect(url('plans', ['team' => $tid]));
        }
        $f = $_FILES['file'];
        $ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
        $mime = function_exists('mime_content_type') ? (mime_content_type($f['tmp_name']) ?: '') : '';
        if ($ext !== 'pdf' || ($mime && $mime !== 'application/pdf')) {
            flash('error', 'Bitte eine PDF-Datei hochladen.'); redirect(url('plans', ['team' => $tid]));
        }
        if ($f['size'] > (int) cfg('upload_max_bytes')) {
            flash('error', 'Datei zu groß (max. ' . human_size((int) cfg('upload_max_bytes')) . ').'); redirect(url('plans', ['team' => $tid]));
        }
        $dir = UPLOAD_PATH . '/plans';
        if (!is_dir($dir)) { @mkdir($dir, 0775, true); }
        $stored = bin2hex(random_bytes(12)) . '.pdf';
        if (!move_uploaded_file($f['tmp_name'], $dir . '/' . $stored)) {
            flash('error', 'Upload fehlgeschlagen.'); redirect(url('plans', ['team' => $tid]));
        }
        $ver = (int) Database::value('SELECT COALESCE(MAX(version),0)+1 FROM business_plans WHERE team_id = ?', [$tid]);
        Database::run('UPDATE business_plans SET is_current = 0 WHERE team_id = ?', [$tid]);
        Database::run(
            'INSERT INTO business_plans (team_id, original_name, stored_name, mime, size_bytes, version, is_current, uploaded_by)
             VALUES (?,?,?,?,?,?,1,?)',
            [$tid, $f['name'], $stored, 'application/pdf', $f['size'], $ver, Auth::id()]
        );
        if (in_array($team['status'], ['draft'], true)) {
            Database::run('UPDATE teams SET status = ? WHERE id = ?', ['submitted', $tid]);
        }
        flash('success', 'Businessplan hochgeladen (Version ' . $ver . ').');
        redirect(url('plans', ['team' => $tid]));
    }

    if ($action === 'run_ai') {
        Auth::require('admin');
        $bpId = (int) input('bp_id');
        $bp = Database::one('SELECT * FROM business_plans WHERE id = ?', [$bpId]);
        if ($bp) {
            $res = AiEval::run($bpId);
            flash($res['ok'] ? 'success' : 'error',
                $res['ok'] ? 'KI-Vorbewertung erstellt (' . rtrim(rtrim((string) $res['total'], '0'), '.') . '/50).'
                           : 'KI-Fehler: ' . $res['error']);
            redirect(url('plans', ['team' => (int) $bp['team_id']]));
        }
        redirect(url('plans'));
    }

    if ($action === 'delete_plan') {
        Auth::require('admin');
        $bp = Database::one('SELECT * FROM business_plans WHERE id = ?', [(int) input('bp_id')]);
        if ($bp) {
            @unlink(UPLOAD_PATH . '/plans/' . basename((string) $bp['stored_name']));
            Database::run('DELETE FROM business_plans WHERE id = ?', [(int) $bp['id']]);
            // ggf. vorherige Version wieder aktuell setzen
            $prev = Database::one('SELECT id FROM business_plans WHERE team_id = ? ORDER BY version DESC LIMIT 1', [(int) $bp['team_id']]);
            if ($prev) { Database::run('UPDATE business_plans SET is_current = 1 WHERE id = ?', [(int) $prev['id']]); }
            flash('success', 'Businessplan gelöscht.');
            redirect(url('plans', ['team' => (int) $bp['team_id']]));
        }
        redirect(url('plans'));
    }
}

// ---- Detailansicht ----
if ($tid = (int) input('team', 0)) {
    require APP_PATH . '/pages/plans_detail.php';
    return;
}

// ---- Übersicht ----
$where = $isTeacher ? 'WHERE t.school_id = ' . (int) $mySchool : '';
$teams = Database::all(
    "SELECT t.*, s.name AS school_name, s.short_name,
            bp.id AS bp_id, bp.version, bp.created_at AS uploaded_at,
            ai.total_score AS ai_score, ai.status AS ai_status
     FROM teams t
     JOIN schools s ON s.id = t.school_id
     LEFT JOIN business_plans bp ON bp.team_id = t.id AND bp.is_current = 1
     LEFT JOIN ai_evaluations ai ON ai.id = (
        SELECT id FROM ai_evaluations WHERE business_plan_id = bp.id ORDER BY id DESC LIMIT 1)
     $where
     ORDER BY (bp.id IS NULL), s.name, t.name"
);

$fmt = fn($n) => $n === null ? '—' : rtrim(rtrim(number_format((float) $n, 1, ',', ''), '0'), ',');

ob_start(); ?>
<div class="page-head"><h1>Businesspläne</h1></div>
<div class="card">
  <div class="table-wrap">
    <table class="data">
      <thead><tr><th>Team</th><th>Schule</th><th>Businessplan</th><th>KI-Vorbewertung</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($teams as $t): ?>
        <tr>
          <td><strong><?= e($t['name']) ?></strong><?php if ($t['idea_name']): ?><br><span class="muted" style="font-size:13px"><?= e($t['idea_name']) ?></span><?php endif; ?></td>
          <td><?= e($t['short_name'] ?: $t['school_name']) ?></td>
          <td>
            <?php if ($t['bp_id']): ?>
              <span class="pill teal">v<?= (int) $t['version'] ?></span> <span class="muted" style="font-size:12px"><?= e(date('d.m.Y', strtotime((string) $t['uploaded_at']))) ?></span>
            <?php else: ?><span class="pill muted">nicht eingereicht</span><?php endif; ?>
          </td>
          <td>
            <?php if (!$t['bp_id']): ?>—
            <?php elseif ($t['ai_status'] === 'done'): ?><strong><?= $fmt($t['ai_score']) ?></strong> / 50
            <?php elseif ($t['ai_status'] === 'error'): ?><span class="pill red">Fehler</span>
            <?php else: ?><span class="pill muted">offen</span><?php endif; ?>
          </td>
          <td style="text-align:right"><a href="<?= url('plans', ['team' => $t['id']]) ?>" class="btn btn--ghost btn--sm">Öffnen</a></td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$teams): ?><tr><td colspan="5" class="muted">Noch keine Teams.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</div>
<?php
$content = ob_get_clean();
$title = 'Businesspläne';
require APP_PATH . '/pages/_layout.php';
