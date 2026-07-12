<?php
/**
 * PitchDay-Eventplanung: Aufgaben-Checkliste (mit aus dem Event-Datum
 * berechneten Fälligkeiten), Gäste/VIPs, Ablaufplan und Budget – je
 * Wettbewerbsjahr. Nur für die Verwaltung (Admin/Projektleitung).
 */

declare(strict_types=1);

Auth::requireManager();

$cycles        = Cycle::all();
$activeCycleId = Cycle::activeId();

$cycleId = (int) input('cycle', $activeCycleId);
if ($cycleId <= 0 || Cycle::find($cycleId) === null) {
    $cycleId = $activeCycleId;
}
$cycle = $cycleId ? Cycle::find($cycleId) : null;
$event = $cycleId ? PitchDay::eventForCycle($cycleId) : null;

$allowedTabs = ['tasks', 'guests', 'agenda', 'budget'];
$tab = (string) input('tab', 'tasks');
if (!in_array($tab, $allowedTabs, true)) {
    $tab = 'tasks';
}

$back = fn() => redirect(url('event', ['cycle' => $cycleId, 'tab' => $tab]));

if (is_post()) {
    Csrf::check();
    $action = (string) input('action');
    $tab = in_array((string) input('tab', $tab), $allowedTabs, true) ? (string) input('tab', $tab) : 'tasks';

    // --- Event anlegen (für ein Jahr ohne PitchDay) ---
    if ($action === 'create_event') {
        if ($cycleId && !$event) {
            $eid = Database::insert(
                "INSERT INTO events (cycle_id, type, title, venue) VALUES (?, 'pitchday', ?, ?)",
                [$cycleId, 'PitchDay', 'Stadthalle Ebermannstadt']
            );
            Audit::log('event.create', 'PitchDay angelegt', 'event', $eid);
            flash('success', 'PitchDay angelegt. Jetzt Datum eintragen und Aufgaben aus der Vorlage einfügen.');
        }
        $back();
    }

    // Alle weiteren Aktionen brauchen ein Event.
    if (!$event) {
        flash('error', 'Für dieses Wettbewerbsjahr ist noch kein PitchDay angelegt.');
        $back();
    }
    $eventId = (int) $event['id'];

    if ($action === 'save_event') {
        $title   = trim((string) input('title')) ?: 'PitchDay';
        $date    = trim((string) input('event_date')) ?: null;
        $timeF   = trim((string) input('time_from')) ?: null;
        $venue   = trim((string) input('venue')) ?: null;
        $address = trim((string) input('venue_address')) ?: null;
        $notes   = trim((string) input('notes')) ?: null;
        Database::run(
            'UPDATE events SET title=?, event_date=?, time_from=?, venue=?, venue_address=?, notes=? WHERE id=?',
            [$title, $date, $timeF, $venue, $address, $notes, $eventId]
        );
        // Fälligkeiten der Vorlagen-Aufgaben aus dem Datum neu berechnen.
        if ($date) {
            Database::run(
                'UPDATE event_tasks SET due_date = DATE_ADD(?, INTERVAL offset_days DAY)
                 WHERE event_id=? AND offset_days IS NOT NULL',
                [$date, $eventId]
            );
        } else {
            Database::run(
                'UPDATE event_tasks SET due_date = NULL WHERE event_id=? AND offset_days IS NOT NULL',
                [$eventId]
            );
        }
        Audit::log('event.update', 'PitchDay bearbeitet: ' . $title, 'event', $eventId);
        flash('success', 'Veranstaltung gespeichert.' . ($date ? ' Fälligkeiten wurden aus dem Datum berechnet.' : ''));
        $back();
    }

    // --- Aufgaben ---
    if ($action === 'seed_tasks') {
        $n = PitchDay::seedTasks($eventId, $event['event_date'] ?? null);
        Audit::log('event.seed_tasks', "Standard-Aufgaben eingefügt ($n)", 'event', $eventId);
        flash('success', "$n Standard-Aufgaben aus der Vorlage eingefügt.");
        $back();
    }
    if ($action === 'save_task') {
        $id     = (int) input('id');
        $cat    = array_key_exists((string) input('category'), PitchDay::TASK_CATEGORIES) ? (string) input('category') : 'general';
        $title  = trim((string) input('title'));
        $resp   = trim((string) input('responsible')) ?: null;
        $status = array_key_exists((string) input('status'), PitchDay::TASK_STATUS) ? (string) input('status') : 'open';
        $due    = trim((string) input('due_date')) ?: null;
        $note   = trim((string) input('comment')) ?: null;
        if ($title !== '') {
            if ($id) {
                Database::run(
                    'UPDATE event_tasks SET category=?, title=?, responsible=?, status=?, due_date=?, comment=? WHERE id=? AND event_id=?',
                    [$cat, $title, $resp, $status, $due, $note, $id, $eventId]
                );
            } else {
                Database::insert(
                    'INSERT INTO event_tasks (event_id, category, title, responsible, status, due_date, comment, sort_order)
                     VALUES (?,?,?,?,?,?,?, 9999)',
                    [$eventId, $cat, $title, $resp, $status, $due, $note]
                );
            }
            Audit::log('event.task_save', 'Aufgabe gespeichert: ' . $title, 'event', $eventId);
        }
        $back();
    }
    if ($action === 'task_status') {
        $id     = (int) input('id');
        $status = array_key_exists((string) input('status'), PitchDay::TASK_STATUS) ? (string) input('status') : 'open';
        Database::run('UPDATE event_tasks SET status=? WHERE id=? AND event_id=?', [$status, $id, $eventId]);
        $back();
    }
    if ($action === 'delete_task') {
        Database::run('DELETE FROM event_tasks WHERE id=? AND event_id=?', [(int) input('id'), $eventId]);
        $back();
    }

    // --- Gäste ---
    if ($action === 'import_jury') {
        // „Jury & Nutzer übernehmen": alle am Wettbewerbsjahr Beteiligten aus
        // „Jury & Nutzer" als Gäste übernehmen – Jury, Projektleitung und
        // Lehrkräfte, aber NIE Admin-Konten (technische Servicerolle). Idempotent:
        // neue anlegen, vorhandene mit Organisation/Position/E-Mail auffrischen;
        // Status/Sitzplatz/Bemerkung bleiben erhalten.
        // Kategorie nach Rolle: Jury → Jury, Projektleitung → VIP/Gastgeber,
        // Lehrkraft → Lehrkraft.
        $people = Database::all(
            "SELECT u.id, u.name, u.email, u.org, u.position, u.specialty, s.name AS school,
                    (SELECT GROUP_CONCAT(ur.role) FROM user_roles ur WHERE ur.user_id = u.id) AS roles
             FROM users u
             LEFT JOIN schools s ON s.id = u.school_id
             WHERE EXISTS (SELECT 1 FROM user_roles ur WHERE ur.user_id = u.id AND ur.role IN ('juror','lead','teacher'))
               AND (
                 u.id IN (SELECT user_id FROM cycle_members WHERE cycle_id = ?)
                 OR (EXISTS (SELECT 1 FROM user_roles ur WHERE ur.user_id = u.id AND ur.role = 'teacher')
                     AND u.school_id IN (SELECT school_id FROM cycle_schools WHERE cycle_id = ?))
               )
             ORDER BY u.name",
            [$cycleId, $cycleId]
        );
        $added = $updated = 0;
        foreach ($people as $p) {
            $roles = explode(',', (string) ($p['roles'] ?? ''));
            if (in_array('juror', $roles, true)) {
                $cat = 'jury';
            } elseif (in_array('lead', $roles, true)) {
                $cat = 'vip';
            } elseif (in_array('teacher', $roles, true)) {
                $cat = 'teacher';
            } else {
                continue;
            }
            $isTeacher = $cat === 'teacher';
            $org = ($p['org'] ?? '') !== '' ? $p['org']
                 : ($isTeacher ? ($p['school'] ?: null) : ($p['specialty'] ?: null));
            $pos = ($p['position'] ?? '') !== '' ? $p['position']
                 : ($isTeacher ? 'Projektlehrkraft' : null);
            $uid = (int) $p['id'];
            // Zuerst per Verknüpfung finden; sonst einen vorhandenen, noch nicht
            // verknüpften Gast gleichen Namens „adoptieren".
            $existingId = (int) Database::value(
                "SELECT id FROM event_guests WHERE event_id=? AND user_id=? LIMIT 1",
                [$eventId, $uid]
            );
            if ($existingId === 0) {
                $existingId = (int) Database::value(
                    "SELECT id FROM event_guests WHERE event_id=? AND user_id IS NULL AND name=? LIMIT 1",
                    [$eventId, $p['name']]
                );
            }
            if ($existingId === 0) {
                Database::insert(
                    "INSERT INTO event_guests (event_id, user_id, category, name, org, position, email, status)
                     VALUES (?, ?, ?, ?, ?, ?, ?, 'confirmed')",
                    [$eventId, $uid, $cat, $p['name'], $org, $pos, $p['email'] ?: null]
                );
                $added++;
            } else {
                // Verknüpfung + Fallback-Kopie auffrischen (Anzeige zieht live).
                Database::run(
                    "UPDATE event_guests SET user_id=?, category=?, name=?, org=?, position=?, email=? WHERE id=?",
                    [$uid, $cat, $p['name'], $org, $pos, $p['email'] ?: null, $existingId]
                );
                $updated++;
            }
        }
        Audit::log('event.import_jury', "Jury & Nutzer übernommen (neu $added, aktualisiert $updated)", 'event', $eventId);
        $parts = [];
        if ($added)   { $parts[] = "$added neu"; }
        if ($updated) { $parts[] = "$updated aktualisiert"; }
        flash($parts ? 'success' : 'error',
            $parts ? 'Jury & Nutzer übernommen (' . implode(', ', $parts) . ').' : 'Keine passenden Personen im Wettbewerbsjahr gefunden.');
        $back();
    }
    // Reihenfolge der Grußworte/Keynote fürs Handout ändern (↑/↓).
    if ($action === 'speaker_move') {
        $id  = (int) input('id');
        $dir = input('dir') === 'up' ? 'up' : 'down';
        $ids = array_map(
            static fn($r) => (int) $r['id'],
            Database::all(
                "SELECT g.id FROM event_guests g LEFT JOIN users u ON u.id = g.user_id
                 WHERE g.event_id=? AND (g.greeting=1 OR g.keynote=1)
                 ORDER BY g.sort_order, g.keynote,
                          SUBSTRING_INDEX(COALESCE(NULLIF(u.name,''), g.name),' ',-1),
                          COALESCE(NULLIF(u.name,''), g.name)",
                [$eventId]
            )
        );
        $pos = array_search($id, $ids, true);
        if ($pos !== false) {
            $swap = $dir === 'up' ? $pos - 1 : $pos + 1;
            if ($swap >= 0 && $swap < count($ids)) {
                [$ids[$pos], $ids[$swap]] = [$ids[$swap], $ids[$pos]];
            }
        }
        // Reihenfolge als fortlaufende sort_order festschreiben.
        foreach ($ids as $i => $gid) {
            Database::run('UPDATE event_guests SET sort_order=? WHERE id=? AND event_id=?', [($i + 1) * 10, $gid, $eventId]);
        }
        $back();
    }
    if ($action === 'save_guest') {
        $id      = (int) input('id');
        $cat     = array_key_exists((string) input('category'), PitchDay::GUEST_CATEGORIES) ? (string) input('category') : 'vip';
        $name    = trim((string) input('name'));
        $org     = trim((string) input('org')) ?: null;
        $pos     = trim((string) input('position')) ?: null;
        $email   = trim((string) input('email')) ?: null;
        $channel = trim((string) input('invite_channel')) ?: null;
        $status  = array_key_exists((string) input('status'), PitchDay::GUEST_STATUS) ? (string) input('status') : 'open';
        $greet   = input('greeting') ? 1 : 0;
        $greetM  = trim((string) input('greeting_minutes'));
        $greetM  = $greetM === '' ? null : (int) $greetM;
        $keynote = input('keynote') ? 1 : 0;
        $seat    = input('seat_reserved') ? 1 : 0;
        $notes   = trim((string) input('notes')) ?: null;
        // Vertretung: nur speichern, wenn der Status „Vertretung" ist – so bleiben
        // die Felder sauber, wenn jemand den Status wieder zurückstellt.
        $subName = trim((string) input('sub_name'));
        $subPos  = trim((string) input('sub_position'));
        $subOrg  = trim((string) input('sub_org'));
        if ($status !== 'substitute') {
            $subName = $subPos = $subOrg = '';
        }
        $subName = $subName !== '' ? $subName : null;
        $subPos  = $subPos  !== '' ? $subPos  : null;
        $subOrg  = $subOrg  !== '' ? $subOrg  : null;
        if ($name !== '') {
            if ($id) {
                Database::run(
                    'UPDATE event_guests SET category=?, name=?, org=?, position=?, email=?, invite_channel=?, status=?,
                        sub_name=?, sub_position=?, sub_org=?,
                        greeting=?, greeting_minutes=?, keynote=?, seat_reserved=?, notes=? WHERE id=? AND event_id=?',
                    [$cat, $name, $org, $pos, $email, $channel, $status, $subName, $subPos, $subOrg,
                     $greet, $greetM, $keynote, $seat, $notes, $id, $eventId]
                );
            } else {
                Database::insert(
                    'INSERT INTO event_guests (event_id, category, name, org, position, email, invite_channel, status,
                        sub_name, sub_position, sub_org,
                        greeting, greeting_minutes, keynote, seat_reserved, notes)
                     VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
                    [$eventId, $cat, $name, $org, $pos, $email, $channel, $status, $subName, $subPos, $subOrg,
                     $greet, $greetM, $keynote, $seat, $notes]
                );
            }
            Audit::log('event.guest_save', 'Gast gespeichert: ' . $name, 'event', $eventId);
        }
        $back();
    }
    if ($action === 'delete_guest') {
        Database::run('DELETE FROM event_guests WHERE id=? AND event_id=?', [(int) input('id'), $eventId]);
        $back();
    }

    // --- Agenda ---
    if ($action === 'seed_agenda') {
        $n = PitchDay::seedAgenda($eventId);
        flash('success', "$n Agenda-Punkte aus der Vorlage eingefügt.");
        $back();
    }
    if ($action === 'save_agenda') {
        $id    = (int) input('id');
        $from  = trim((string) input('time_from')) ?: null;
        $to    = trim((string) input('time_to')) ?: null;
        $title = trim((string) input('title'));
        $note  = trim((string) input('note')) ?: null;
        if ($title !== '') {
            if ($id) {
                Database::run('UPDATE event_agenda SET time_from=?, time_to=?, title=?, note=? WHERE id=? AND event_id=?',
                    [$from, $to, $title, $note, $id, $eventId]);
            } else {
                Database::insert('INSERT INTO event_agenda (event_id, time_from, time_to, title, note, sort_order) VALUES (?,?,?,?,?, 9999)',
                    [$eventId, $from, $to, $title, $note]);
            }
        }
        $back();
    }
    if ($action === 'delete_agenda') {
        Database::run('DELETE FROM event_agenda WHERE id=? AND event_id=?', [(int) input('id'), $eventId]);
        $back();
    }

    // --- Budget ---
    if ($action === 'save_budget') {
        $id     = (int) input('id');
        $kind   = input('kind') === 'prize' ? 'prize' : 'cost';
        $label  = trim((string) input('label'));
        $amtRaw = str_replace(['€', ' '], '', str_replace(',', '.', (string) input('amount')));
        $amount = $amtRaw === '' ? null : (float) $amtRaw;
        $place  = trim((string) input('place'));
        $place  = $place === '' ? null : (int) $place;
        $note   = trim((string) input('note')) ?: null;
        if ($label !== '') {
            if ($id) {
                Database::run('UPDATE event_budget_items SET kind=?, label=?, amount=?, place=?, note=? WHERE id=? AND event_id=?',
                    [$kind, $label, $amount, $place, $note, $id, $eventId]);
            } else {
                Database::insert('INSERT INTO event_budget_items (event_id, kind, label, amount, place, note, sort_order) VALUES (?,?,?,?,?,?, 9999)',
                    [$eventId, $kind, $label, $amount, $place, $note]);
            }
        }
        $back();
    }
    if ($action === 'delete_budget') {
        Database::run('DELETE FROM event_budget_items WHERE id=? AND event_id=?', [(int) input('id'), $eventId]);
        $back();
    }

    $back();
}

// ------------------------------------------------------------------ Ansicht
$money    = fn($a) => number_format((float) $a, 2, ',', '.') . ' €';
$today    = date('Y-m-d');
$dateFmt  = fn(?string $d) => $d ? date('d.m.Y', strtotime($d)) : null;
$timeFmt  = fn(?string $t) => $t ? substr($t, 0, 5) : null;
$eventId  = $event ? (int) $event['id'] : 0;

$cycleSwitcher = function () use ($cycles, $cycleId, $tab) {
    if (count($cycles) < 2) {
        return '';
    }
    ob_start(); ?>
    <form method="get" action="<?= url('event') ?>" style="display:inline">
      <input type="hidden" name="r" value="event"><input type="hidden" name="tab" value="<?= e($tab) ?>">
      <select name="cycle" onchange="this.form.submit()" style="min-width:130px">
        <?php foreach ($cycles as $cy): ?>
          <option value="<?= (int) $cy['id'] ?>" <?= (int) $cy['id'] === $cycleId ? 'selected' : '' ?>>
            <?= e($cy['year_label']) ?><?= $cy['is_active'] ? ' •' : '' ?></option>
        <?php endforeach; ?>
      </select>
    </form>
    <?php return (string) ob_get_clean();
};

ob_start(); ?>
<div id="event-page" data-event-ajax>
<div class="page-head">
  <h1>🎤 PitchDay<?= $cycle ? ' <span class="muted" style="font-weight:400;font-size:.7em">' . e($cycle['year_label']) . '</span>' : '' ?></h1>
  <?= $cycleSwitcher() ?>
</div>

<?php if (!$cycleId): ?>
  <div class="card"><div class="card__body">
    <p class="muted">Zuerst unter <a href="<?= url('cycles') ?>">Wettbewerbsjahre</a> ein Jahr anlegen und aktiv setzen.</p>
  </div></div>
<?php elseif (!$event): ?>
  <div class="card"><div class="card__body">
    <p>Für <strong><?= e($cycle['year_label']) ?></strong> ist noch kein PitchDay angelegt.</p>
    <form method="post" action="<?= url('event') ?>" style="margin-top:12px">
      <?= Csrf::field() ?><input type="hidden" name="action" value="create_event"><input type="hidden" name="cycle" value="<?= $cycleId ?>">
      <button class="btn btn--teal">+ PitchDay anlegen</button>
    </form>
  </div></div>
<?php else: ?>

  <?php
    // Kennzahlen für die Aufgaben-Übersicht.
    $tCnt = Database::one(
      "SELECT COUNT(*) total,
              SUM(status='done') done,
              SUM(status<>'done' AND due_date IS NOT NULL AND due_date < ?) overdue,
              SUM(status<>'done' AND due_date IS NOT NULL AND due_date >= ? AND due_date <= DATE_ADD(?, INTERVAL 14 DAY)) soon
       FROM event_tasks WHERE event_id=?",
      [$today, $today, $today, $eventId]
    );
    $gCnt = Database::one(
      "SELECT COUNT(*) total, SUM(status='confirmed') zusage, SUM(status='requested') angefragt,
              SUM(status='declined') absage, SUM(greeting=1) gruss, SUM(keynote=1) keynote
       FROM event_guests WHERE event_id=?", [$eventId]
    );
  ?>

  <!-- Kopf: Eckdaten der Veranstaltung -->
  <div class="card mb">
    <div class="card__head" style="display:flex;align-items:center;justify-content:space-between;gap:10px">
      <span>Veranstaltung</span>
      <button type="button" class="btn btn--ghost btn--sm" data-modal-open="eventModal"
        data-fill='<?= e(json_encode([
          'title' => $event['title'], 'event_date' => $event['event_date'], 'time_from' => $timeFmt($event['time_from']),
          'venue' => $event['venue'], 'venue_address' => $event['venue_address'], 'notes' => $event['notes'],
        ], JSON_UNESCAPED_UNICODE)) ?>'>Bearbeiten</button>
    </div>
    <div class="card__body">
      <div class="grid cols-4">
        <div class="field" style="margin:0"><label>Datum</label>
          <div><?= $event['event_date'] ? '<strong>' . e($dateFmt($event['event_date'])) . '</strong>' : '<span class="pill amber">Datum fehlt</span>' ?>
            <?= $event['time_from'] ? ' · ' . e($timeFmt($event['time_from'])) . ' Uhr' : '' ?></div>
        </div>
        <div class="field" style="margin:0"><label>Ort</label><div><?= e($event['venue'] ?? '—') ?></div></div>
        <div class="field" style="margin:0"><label>Adresse</label><div class="muted"><?= e($event['venue_address'] ?? '—') ?></div></div>
        <div class="field" style="margin:0"><label>Countdown</label>
          <div><?php
            if ($event['event_date']) {
              $days = (int) floor((strtotime($event['event_date']) - strtotime($today)) / 86400);
              echo $days > 0 ? '<strong>in ' . $days . ' Tagen</strong>' : ($days === 0 ? '<strong>heute!</strong>' : '<span class="muted">vorbei</span>');
            } else { echo '<span class="muted">—</span>'; }
          ?></div>
        </div>
      </div>
      <?php if ($event['notes']): ?><p class="muted" style="margin:12px 0 0"><?= nl2br(e($event['notes'])) ?></p><?php endif; ?>
      <?php if (!$event['event_date']): ?>
        <p class="muted" style="margin:12px 0 0;font-size:13px">💡 Sobald ein Datum eingetragen ist, werden die Fälligkeiten der Aufgaben automatisch berechnet („X Tage vorher").</p>
      <?php endif; ?>
    </div>
  </div>

  <!-- Tabs -->
  <?php
    $tabs = [
      'tasks'  => 'Aufgaben' . ((int) $tCnt['overdue'] > 0 ? ' (' . (int) $tCnt['overdue'] . ' überfällig)' : ''),
      'guests' => 'Gäste & VIPs',
      'agenda' => 'Ablaufplan',
      'budget' => 'Budget',
    ];
  ?>
  <div class="table-toolbar" style="gap:8px;padding:0 0 14px;flex-wrap:wrap">
    <?php foreach ($tabs as $key => $label): ?>
      <a href="<?= url('event', ['cycle' => $cycleId, 'tab' => $key]) ?>"
         class="btn btn--sm <?= $tab === $key ? 'btn--primary' : 'btn--ghost' ?>"><?= e($label) ?></a>
    <?php endforeach; ?>
  </div>

  <?php if ($tab === 'tasks'): ?>
    <div class="grid cols-4 mb">
      <?php foreach ([
        ['Offen gesamt', (int) $tCnt['total'] - (int) $tCnt['done']],
        ['Erledigt', (int) $tCnt['done'] . ' / ' . (int) $tCnt['total']],
        ['Überfällig', (int) $tCnt['overdue']],
        ['Fällig in ≤14 Tagen', (int) $tCnt['soon']],
      ] as [$l, $n]): ?>
        <div class="card stat"><div class="bar"></div><div class="card__body"><div class="n"><?= is_int($n) ? $n : e((string) $n) ?></div><div class="l"><?= e($l) ?></div></div></div>
      <?php endforeach; ?>
    </div>

    <div style="display:flex;gap:8px;margin-bottom:14px;flex-wrap:wrap">
      <button type="button" class="btn btn--teal btn--sm" data-modal-open="taskModal">+ Aufgabe</button>
      <?php if ((int) $tCnt['total'] === 0): ?>
        <form method="post" action="<?= url('event') ?>" data-confirm="Standard-Aufgaben aus der Vorlage einfügen?">
          <?= Csrf::field() ?><input type="hidden" name="action" value="seed_tasks"><input type="hidden" name="cycle" value="<?= $cycleId ?>"><input type="hidden" name="tab" value="tasks">
          <button class="btn btn--ghost btn--sm">📋 Aufgaben aus Vorlage einfügen</button>
        </form>
      <?php endif; ?>
    </div>

    <?php
      $tasks = Database::all(
        'SELECT * FROM event_tasks WHERE event_id=? ORDER BY sort_order, id', [$eventId]
      );
      $byCat = [];
      foreach ($tasks as $tk) { $byCat[$tk['category']][] = $tk; }
    ?>
    <?php if (!$tasks): ?>
      <div class="card"><div class="card__body"><p class="muted">Noch keine Aufgaben. Über „Aufgaben aus Vorlage einfügen“ das komplette Playbook laden.</p></div></div>
    <?php endif; ?>

    <?php foreach (PitchDay::TASK_CATEGORIES as $catKey => $catLabel): if (empty($byCat[$catKey])) continue; ?>
      <div class="card mb">
        <div class="card__head"><?= e($catLabel) ?></div>
        <div class="table-wrap">
          <table class="data data--cards">
            <thead><tr><th>Aufgabe</th><th>Verantwortlich</th><th>Fällig</th><th>Status</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($byCat[$catKey] as $tk):
              [$stLabel, $stCls] = PitchDay::taskStatus($tk['status']);
              $overdue = $tk['status'] !== 'done' && $tk['due_date'] && $tk['due_date'] < $today;
              $fill = e(json_encode([
                'id' => (int) $tk['id'], 'category' => $tk['category'], 'title' => $tk['title'],
                'responsible' => $tk['responsible'], 'status' => $tk['status'],
                'due_date' => $tk['due_date'], 'comment' => $tk['comment'],
              ], JSON_UNESCAPED_UNICODE));
            ?>
              <tr>
                <td data-label="Aufgabe"><strong><?= e($tk['title']) ?></strong><?= $tk['comment'] ? '<div class="muted" style="font-size:13px">' . e($tk['comment']) . '</div>' : '' ?></td>
                <td data-label="Verantwortlich"><?= e($tk['responsible'] ?? '—') ?></td>
                <td data-label="Fällig"><?= $tk['due_date'] ? ($overdue ? '<span class="pill red">' . e($dateFmt($tk['due_date'])) . '</span>' : e($dateFmt($tk['due_date']))) : '<span class="muted">–</span>' ?></td>
                <td data-label="Status">
                  <form method="post" action="<?= url('event') ?>" style="display:inline">
                    <?= Csrf::field() ?><input type="hidden" name="action" value="task_status"><input type="hidden" name="cycle" value="<?= $cycleId ?>"><input type="hidden" name="tab" value="tasks"><input type="hidden" name="id" value="<?= (int) $tk['id'] ?>">
                    <select name="status" onchange="if(this.form.requestSubmit){this.form.requestSubmit()}else{this.form.submit()}" class="pill-select">
                      <?php foreach (PitchDay::TASK_STATUS as $sk => [$sl]): ?>
                        <option value="<?= e($sk) ?>" <?= $tk['status'] === $sk ? 'selected' : '' ?>><?= e($sl) ?></option>
                      <?php endforeach; ?>
                    </select>
                  </form>
                </td>
                <td class="row-actions" style="white-space:nowrap;text-align:right">
                  <button type="button" class="btn btn--ghost btn--sm" data-modal-open="taskModal" data-fill="<?= $fill ?>">Bearbeiten</button>
                  <form method="post" action="<?= url('event') ?>" style="display:inline" data-confirm="Aufgabe löschen?">
                    <?= Csrf::field() ?><input type="hidden" name="action" value="delete_task"><input type="hidden" name="cycle" value="<?= $cycleId ?>"><input type="hidden" name="tab" value="tasks"><input type="hidden" name="id" value="<?= (int) $tk['id'] ?>">
                    <button class="btn btn--danger btn--sm">×</button>
                  </form>
                </td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
    <?php endforeach; ?>

  <?php elseif ($tab === 'guests'): ?>
    <div class="grid cols-4 mb">
      <?php foreach ([
        ['Gäste gesamt', (int) $gCnt['total']],
        ['Zusagen', (int) $gCnt['zusage']],
        ['Angefragt', (int) $gCnt['angefragt']],
        ['Absagen', (int) $gCnt['absage']],
      ] as [$l, $n]): ?>
        <div class="card stat"><div class="bar"></div><div class="card__body"><div class="n"><?= (int) $n ?></div><div class="l"><?= e($l) ?></div></div></div>
      <?php endforeach; ?>
    </div>

    <div style="display:flex;gap:8px;margin-bottom:14px;flex-wrap:wrap">
      <button type="button" class="btn btn--teal btn--sm" data-modal-open="guestModal">+ Gast / VIP</button>
      <form method="post" action="<?= url('event') ?>">
        <?= Csrf::field() ?><input type="hidden" name="action" value="import_jury"><input type="hidden" name="cycle" value="<?= $cycleId ?>"><input type="hidden" name="tab" value="guests">
        <button class="btn btn--ghost btn--sm" title="Alle Beteiligten des Wettbewerbsjahres aus „Jury & Nutzer" übernehmen (Jury, Projektleitung, Lehrkräfte; reine Admin-Konten ausgenommen); idempotent">⚖ Jury &amp; Nutzer übernehmen</button>
      </form>
      <a class="btn btn--ghost btn--sm" target="_blank" rel="noopener"
         href="<?= e(url('event_print', ['cycle' => $cycleId, 'kind' => 'signs'])) ?>"
         data-signs-open="<?= e(url('event_print', ['cycle' => $cycleId, 'kind' => 'signs'])) ?>">🪧 Reserviert-Schilder (PDF)</a>
    </div>
    <p class="muted" style="font-size:13px;margin:-6px 0 14px">
      🪧 Die angehakten Gäste kommen aufs Reserviert-Schild – standardmäßig <strong>alle außer Absagen</strong>, einzeln abwählbar. Bei einer <strong>Vertretung</strong> erscheint automatisch die vertretende Person – mit Hinweis, wen sie vertritt.
    </p>

    <?php
      // Gäste-Übersicht: nach Nachname; Lehrkräfte je Schule (Organisation)
      // gruppiert. Verknüpfte Gäste ziehen ihre Stammdaten live aus dem Konto.
      $guests = Database::all(
        PitchDay::GUEST_SELECT . " WHERE g.event_id=?
         ORDER BY FIELD(g.category,'speaker','vip','jury','teacher','sponsor','press'),
                  CASE WHEN g.category='teacher' THEN org END,
                  SUBSTRING_INDEX(name,' ',-1), name",
        [$eventId]
      );
      // Grußworte & Keynote in der manuell festgelegten Reihenfolge (sort_order),
      // sonst Grußworte vor Keynote, dann Nachname.
      $speakers = Database::all(
        PitchDay::GUEST_SELECT . " WHERE g.event_id=? AND (g.greeting=1 OR g.keynote=1)
         ORDER BY g.sort_order, g.keynote, SUBSTRING_INDEX(name,' ',-1), name",
        [$eventId]
      );
    ?>
    <?php if ($speakers): ?>
      <div class="card mb">
        <div class="card__head">Grußworte &amp; Keynote <span class="muted" style="font-weight:400;font-size:13px">· Reihenfolge fürs Handout (↑/↓)</span></div>
        <div class="card__body">
          <?php foreach ($speakers as $i => $g): $gd = PitchDay::guestDisplay($g); ?>
            <div style="display:flex;gap:8px;align-items:center;margin-bottom:6px;flex-wrap:wrap">
              <span style="display:inline-flex;gap:2px">
                <form method="post" action="<?= url('event') ?>" style="display:inline">
                  <?= Csrf::field() ?><input type="hidden" name="action" value="speaker_move"><input type="hidden" name="cycle" value="<?= $cycleId ?>"><input type="hidden" name="tab" value="guests"><input type="hidden" name="id" value="<?= (int) $g['id'] ?>"><input type="hidden" name="dir" value="up">
                  <button class="btn btn--ghost btn--sm" title="nach oben" <?= $i === 0 ? 'disabled' : '' ?>>↑</button>
                </form>
                <form method="post" action="<?= url('event') ?>" style="display:inline">
                  <?= Csrf::field() ?><input type="hidden" name="action" value="speaker_move"><input type="hidden" name="cycle" value="<?= $cycleId ?>"><input type="hidden" name="tab" value="guests"><input type="hidden" name="id" value="<?= (int) $g['id'] ?>"><input type="hidden" name="dir" value="down">
                  <button class="btn btn--ghost btn--sm" title="nach unten" <?= $i === count($speakers) - 1 ? 'disabled' : '' ?>>↓</button>
                </form>
              </span>
              <?= (int) $g['keynote'] === 1 ? '<span class="pill blue">Keynote</span>' : '<span class="pill teal">Grußwort</span>' ?>
              <strong><?= e($gd['name']) ?></strong>
              <span class="muted"><?= e($gd['org'] ?? $gd['position'] ?? '') ?></span>
              <?php if ($gd['subline']): ?><span class="pill blue" title="Vertretung">↷ <?= e($gd['subline']) ?></span><?php endif; ?>
              <?php if ($g['greeting_minutes']): ?><span class="muted">· ca. <?= (int) $g['greeting_minutes'] ?> Min</span><?php endif; ?>
            </div>
          <?php endforeach; ?>
        </div>
      </div>
    <?php endif; ?>

    <div class="card">
      <div class="table-wrap">
        <table class="data data--cards">
          <thead><tr><th title="Für Reserviert-Schild auswählen">🪧</th><th>Name</th><th>Kategorie</th><th>Organisation / Position</th><th>Einladung</th><th>Status</th><th>Rede</th><th></th></tr></thead>
          <tbody>
          <?php foreach ($guests as $g):
            [$stLabel, $stCls] = PitchDay::guestStatus($g['status']);
            $gd = PitchDay::guestDisplay($g);
            $fill = e(json_encode([
              'id' => (int) $g['id'], 'category' => $g['category'], 'name' => $g['name'], 'org' => $g['org'],
              'position' => $g['position'], 'email' => $g['email'], 'invite_channel' => $g['invite_channel'],
              'status' => $g['status'], 'sub_name' => $g['sub_name'], 'sub_position' => $g['sub_position'],
              'sub_org' => $g['sub_org'], 'greeting' => (int) $g['greeting'], 'greeting_minutes' => $g['greeting_minutes'],
              'keynote' => (int) $g['keynote'], 'seat_reserved' => (int) $g['seat_reserved'], 'notes' => $g['notes'],
            ], JSON_UNESCAPED_UNICODE));
          ?>
            <tr>
              <td data-label="Schild"><input type="checkbox" class="js-sign-pick" value="<?= (int) $g['id'] ?>" <?= $g['status'] !== 'declined' ? 'checked' : '' ?> title="Reserviert-Schild für diesen Gast drucken"></td>
              <td data-label="Name"><strong><?= e($gd['name']) ?></strong>
                <?= !empty($g['user_id']) ? ' <span class="pill blue" style="font-size:11px" title="Stammdaten kommen live aus „Jury & Nutzer“">🔗 verknüpft</span>' : '' ?>
                <?= $gd['subline'] ? '<div class="muted" style="font-size:13px">↷ ' . e($gd['subline']) . '</div>' : '' ?>
                <?= $g['notes'] ? '<div class="muted" style="font-size:13px">' . e($g['notes']) . '</div>' : '' ?></td>
              <td data-label="Kategorie"><?= e(PitchDay::guestCategory($g['category'])) ?></td>
              <td data-label="Organisation / Position"><?= e($gd['org'] ?? '') ?><?= $gd['position'] ? '<div class="muted" style="font-size:13px">' . e($gd['position']) . '</div>' : '' ?></td>
              <td data-label="Einladung"><?= e($g['invite_channel'] ?? '—') ?></td>
              <td data-label="Status"><span class="pill <?= $stCls ?>"><?= e($stLabel) ?></span></td>
              <td data-label="Rede"><?= (int) $g['keynote'] === 1 ? '<span class="pill blue">Keynote</span>' : ((int) $g['greeting'] === 1 ? '<span class="pill teal">Grußwort</span>' : '<span class="muted">–</span>') ?></td>
              <td class="row-actions" style="white-space:nowrap;text-align:right">
                <button type="button" class="btn btn--ghost btn--sm" data-modal-open="guestModal" data-fill="<?= $fill ?>">Bearbeiten</button>
                <form method="post" action="<?= url('event') ?>" style="display:inline" data-confirm="Gast „<?= e($g['name']) ?>“ löschen?">
                  <?= Csrf::field() ?><input type="hidden" name="action" value="delete_guest"><input type="hidden" name="cycle" value="<?= $cycleId ?>"><input type="hidden" name="tab" value="guests"><input type="hidden" name="id" value="<?= (int) $g['id'] ?>">
                  <button class="btn btn--danger btn--sm">×</button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
          <?php if (!$guests): ?><tr><td colspan="8" class="muted">Noch keine Gäste. Über „Jury übernehmen“ oder „+ Gast / VIP“ starten.</td></tr><?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>

  <?php elseif ($tab === 'agenda'): ?>
    <div style="display:flex;gap:8px;margin-bottom:14px;flex-wrap:wrap">
      <button type="button" class="btn btn--teal btn--sm" data-modal-open="agendaModal">+ Programmpunkt</button>
      <?php
        $agenda = Database::all('SELECT * FROM event_agenda WHERE event_id=? ORDER BY sort_order, time_from, id', [$eventId]);
        if (!$agenda): ?>
        <form method="post" action="<?= url('event') ?>" data-confirm="Standard-Agenda einfügen?">
          <?= Csrf::field() ?><input type="hidden" name="action" value="seed_agenda"><input type="hidden" name="cycle" value="<?= $cycleId ?>"><input type="hidden" name="tab" value="agenda">
          <button class="btn btn--ghost btn--sm">🕒 Standard-Agenda einfügen</button>
        </form>
      <?php endif; ?>
      <a class="btn btn--ghost btn--sm" target="_blank" rel="noopener"
         href="<?= e(url('event_print', ['cycle' => $cycleId, 'kind' => 'handout'])) ?>">📄 Ablaufplan / Handout (PDF)</a>
    </div>
    <div class="card">
      <div class="table-wrap">
        <table class="data data--cards">
          <thead><tr><th>Zeit</th><th>Programmpunkt</th><th>Notiz</th><th></th></tr></thead>
          <tbody>
          <?php foreach ($agenda as $a):
            $fill = e(json_encode(['id' => (int) $a['id'], 'time_from' => $timeFmt($a['time_from']), 'time_to' => $timeFmt($a['time_to']), 'title' => $a['title'], 'note' => $a['note']], JSON_UNESCAPED_UNICODE));
          ?>
            <tr>
              <td data-label="Zeit" style="white-space:nowrap"><strong><?= e($timeFmt($a['time_from']) ?? '') ?><?= $a['time_to'] ? '–' . e($timeFmt($a['time_to'])) : '' ?></strong></td>
              <td data-label="Programmpunkt"><?= e($a['title']) ?></td>
              <td data-label="Notiz" class="muted"><?= e($a['note'] ?? '') ?></td>
              <td class="row-actions" style="white-space:nowrap;text-align:right">
                <button type="button" class="btn btn--ghost btn--sm" data-modal-open="agendaModal" data-fill="<?= $fill ?>">Bearbeiten</button>
                <form method="post" action="<?= url('event') ?>" style="display:inline" data-confirm="Programmpunkt löschen?">
                  <?= Csrf::field() ?><input type="hidden" name="action" value="delete_agenda"><input type="hidden" name="cycle" value="<?= $cycleId ?>"><input type="hidden" name="tab" value="agenda"><input type="hidden" name="id" value="<?= (int) $a['id'] ?>">
                  <button class="btn btn--danger btn--sm">×</button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
          <?php if (!$agenda): ?><tr><td colspan="4" class="muted">Noch kein Ablaufplan.</td></tr><?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>

  <?php elseif ($tab === 'budget'): ?>
    <?php
      $income  = (float) Database::value('SELECT COALESCE(SUM(amount),0) FROM sponsor_contributions WHERE cycle_id=?', [$cycleId]);
      $costs   = (float) Database::value("SELECT COALESCE(SUM(amount),0) FROM event_budget_items WHERE event_id=? AND kind='cost'", [$eventId]);
      $prizes  = (float) Database::value("SELECT COALESCE(SUM(amount),0) FROM event_budget_items WHERE event_id=? AND kind='prize'", [$eventId]);
      $balance = $income - $costs - $prizes;
      $items   = Database::all('SELECT * FROM event_budget_items WHERE event_id=? ORDER BY kind DESC, place, sort_order, id', [$eventId]);
    ?>
    <div class="grid cols-4 mb">
      <?php foreach ([
        ['Einnahmen (Sponsoren)', $money($income), 'teal'],
        ['Kosten', $money($costs), 'amber'],
        ['Preisgelder', $money($prizes), 'blue'],
        ['Saldo', $money($balance), $balance >= 0 ? 'teal' : 'red'],
      ] as [$l, $v, $c]): ?>
        <div class="card stat"><div class="bar"></div><div class="card__body"><div class="n" style="font-size:22px"><span class="pill <?= $c ?>"><?= e($v) ?></span></div><div class="l" style="margin-top:8px"><?= e($l) ?></div></div></div>
      <?php endforeach; ?>
    </div>
    <p class="muted" style="font-size:13px;margin-bottom:14px">Einnahmen werden aus den Sponsoren-Beiträgen dieses Wettbewerbsjahres übernommen (Modul <a href="<?= url('sponsors') ?>">Sponsoren</a>).</p>

    <div style="margin-bottom:14px"><button type="button" class="btn btn--teal btn--sm" data-modal-open="budgetModal">+ Kosten / Preis</button></div>
    <div class="card">
      <div class="table-wrap">
        <table class="data data--cards">
          <thead><tr><th>Art</th><th>Bezeichnung</th><th>Platz</th><th>Betrag</th><th>Notiz</th><th></th></tr></thead>
          <tbody>
          <?php foreach ($items as $it):
            $fill = e(json_encode(['id' => (int) $it['id'], 'kind' => $it['kind'], 'label' => $it['label'], 'amount' => $it['amount'], 'place' => $it['place'], 'note' => $it['note']], JSON_UNESCAPED_UNICODE));
          ?>
            <tr>
              <td data-label="Art"><?= $it['kind'] === 'prize' ? '<span class="pill blue">Preis</span>' : '<span class="pill amber">Kosten</span>' ?></td>
              <td data-label="Bezeichnung"><?= e($it['label']) ?></td>
              <td data-label="Platz"><?= $it['place'] ? (int) $it['place'] . '.' : '<span class="muted">–</span>' ?></td>
              <td data-label="Betrag"><?= $it['amount'] !== null ? $money($it['amount']) : '<span class="muted">–</span>' ?></td>
              <td data-label="Notiz" class="muted"><?= e($it['note'] ?? '') ?></td>
              <td class="row-actions" style="white-space:nowrap;text-align:right">
                <button type="button" class="btn btn--ghost btn--sm" data-modal-open="budgetModal" data-fill="<?= $fill ?>">Bearbeiten</button>
                <form method="post" action="<?= url('event') ?>" style="display:inline" data-confirm="Position löschen?">
                  <?= Csrf::field() ?><input type="hidden" name="action" value="delete_budget"><input type="hidden" name="cycle" value="<?= $cycleId ?>"><input type="hidden" name="tab" value="budget"><input type="hidden" name="id" value="<?= (int) $it['id'] ?>">
                  <button class="btn btn--danger btn--sm">×</button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
          <?php if (!$items): ?><tr><td colspan="6" class="muted">Noch keine Kosten oder Preisgelder erfasst.</td></tr><?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  <?php endif; ?>

  <!-- ===================== Modals ===================== -->
  <div class="modal-overlay" id="eventModal" data-modal-static hidden>
    <div class="modal modal--form" role="dialog" aria-modal="true" aria-labelledby="eventModalTitle">
      <div class="modal__head"><h3 id="eventModalTitle">Veranstaltung bearbeiten</h3><button type="button" class="modal__close" data-modal-close>&times;</button></div>
      <form method="post" action="<?= url('event') ?>" class="modal__body" data-modal-form>
        <?= Csrf::field() ?><input type="hidden" name="action" value="save_event"><input type="hidden" name="cycle" value="<?= $cycleId ?>"><input type="hidden" name="tab" value="<?= e($tab) ?>">
        <div class="field"><label>Titel</label><input type="text" name="title" value="PitchDay"></div>
        <div class="grid cols-2">
          <div class="field"><label>Datum</label><input type="date" name="event_date"></div>
          <div class="field"><label>Beginn (Uhrzeit)</label><input type="time" name="time_from"></div>
        </div>
        <div class="field"><label>Ort</label><input type="text" name="venue" placeholder="z. B. Stadthalle Ebermannstadt"></div>
        <div class="field"><label>Adresse</label><input type="text" name="venue_address"></div>
        <div class="field"><label>Notizen</label><textarea name="notes" rows="2"></textarea></div>
        <div class="modal__foot"><button type="button" class="btn btn--ghost" data-modal-close>Abbrechen</button><button class="btn btn--primary">Speichern</button></div>
      </form>
    </div>
  </div>

  <div class="modal-overlay" id="taskModal" data-modal-static hidden>
    <div class="modal modal--form" role="dialog" aria-modal="true" aria-labelledby="taskModalTitle">
      <div class="modal__head"><h3 id="taskModalTitle" data-modal-title data-title-new="Neue Aufgabe" data-title-edit="Aufgabe bearbeiten">Neue Aufgabe</h3><button type="button" class="modal__close" data-modal-close>&times;</button></div>
      <form method="post" action="<?= url('event') ?>" class="modal__body" data-modal-form>
        <?= Csrf::field() ?><input type="hidden" name="action" value="save_task"><input type="hidden" name="cycle" value="<?= $cycleId ?>"><input type="hidden" name="tab" value="tasks"><input type="hidden" name="id" value="0">
        <div class="field"><label>Aufgabe *</label><input type="text" name="title" required></div>
        <div class="grid cols-2">
          <div class="field"><label>Kategorie</label><select name="category">
            <?php foreach (PitchDay::TASK_CATEGORIES as $k => $l): ?><option value="<?= e($k) ?>"><?= e($l) ?></option><?php endforeach; ?>
          </select></div>
          <div class="field"><label>Status</label><select name="status">
            <?php foreach (PitchDay::TASK_STATUS as $k => [$l]): ?><option value="<?= e($k) ?>"><?= e($l) ?></option><?php endforeach; ?>
          </select></div>
        </div>
        <div class="grid cols-2">
          <div class="field"><label>Verantwortlich</label><input type="text" name="responsible"></div>
          <div class="field"><label>Fällig am</label><input type="date" name="due_date"></div>
        </div>
        <div class="field"><label>Kommentar</label><input type="text" name="comment"></div>
        <div class="modal__foot"><button type="button" class="btn btn--ghost" data-modal-close>Abbrechen</button><button class="btn btn--primary" data-label-new="Anlegen" data-label-edit="Speichern">Anlegen</button></div>
      </form>
    </div>
  </div>

  <div class="modal-overlay" id="guestModal" data-modal-static hidden>
    <div class="modal modal--form" role="dialog" aria-modal="true" aria-labelledby="guestModalTitle">
      <div class="modal__head"><h3 id="guestModalTitle" data-modal-title data-title-new="Neuer Gast" data-title-edit="Gast bearbeiten">Neuer Gast</h3><button type="button" class="modal__close" data-modal-close>&times;</button></div>
      <form method="post" action="<?= url('event') ?>" class="modal__body" data-modal-form>
        <?= Csrf::field() ?><input type="hidden" name="action" value="save_guest"><input type="hidden" name="cycle" value="<?= $cycleId ?>"><input type="hidden" name="tab" value="guests"><input type="hidden" name="id" value="0">
        <div class="field"><label>Name *</label><input type="text" name="name" required></div>
        <div class="grid cols-2">
          <div class="field"><label>Kategorie</label><select name="category">
            <?php foreach (PitchDay::GUEST_CATEGORIES as $k => $l): ?><option value="<?= e($k) ?>"><?= e($l) ?></option><?php endforeach; ?>
          </select></div>
          <div class="field"><label>Status</label><select name="status">
            <?php foreach (PitchDay::GUEST_STATUS as $k => [$l]): ?><option value="<?= e($k) ?>"><?= e($l) ?></option><?php endforeach; ?>
          </select></div>
        </div>
        <div class="grid cols-2">
          <div class="field"><label>Organisation</label><input type="text" name="org"></div>
          <div class="field"><label>Position</label><input type="text" name="position"></div>
        </div>
        <!-- Vertretung: nur bei Status „Vertretung" sichtbar (Toggle via app.js) -->
        <div data-substitute-block hidden style="border:1px solid var(--line,#e4e7ee);border-radius:10px;padding:12px;margin:0 0 14px;background:rgba(0,53,148,.03)">
          <div class="muted" style="font-size:13px;margin-bottom:8px">↷ <strong>Vertretung</strong> – wer springt für die/den oben genannten Gast ein? Name & Position erscheinen auf dem Reserviert-Schild und in der VIP-Übersicht.</div>
          <div class="field" style="margin-bottom:10px"><label>Name der Vertretung</label><input type="text" name="sub_name" placeholder="z. B. Max Mustermann"></div>
          <div class="grid cols-2" style="margin:0">
            <div class="field" style="margin:0"><label>Position</label><input type="text" name="sub_position" placeholder="z. B. stv. Bürgermeister"></div>
            <div class="field" style="margin:0"><label>Organisation</label><input type="text" name="sub_org"></div>
          </div>
        </div>
        <div class="grid cols-2">
          <div class="field"><label>E-Mail</label><input type="email" name="email"></div>
          <div class="field"><label>Einladung per</label><input type="text" name="invite_channel" placeholder="z. B. Mail"></div>
        </div>
        <div class="grid cols-2">
          <div class="field"><label>Grußwort / Keynote</label>
            <label style="display:flex;gap:6px;align-items:center;font-weight:400"><input type="checkbox" name="greeting" value="1"> Grußwort (ca. 3 Min)</label>
            <label style="display:flex;gap:6px;align-items:center;font-weight:400;margin-top:4px"><input type="checkbox" name="keynote" value="1"> Keynote (ca. 15 Min)</label>
          </div>
          <div class="field"><label>Redezeit (Min)</label><input type="number" name="greeting_minutes" min="0" step="1">
            <label style="display:flex;gap:6px;align-items:center;font-weight:400;margin-top:8px"><input type="checkbox" name="seat_reserved" value="1"> Sitzplatz reserviert</label>
          </div>
        </div>
        <div class="field"><label>Bemerkung</label><input type="text" name="notes"></div>
        <div class="modal__foot"><button type="button" class="btn btn--ghost" data-modal-close>Abbrechen</button><button class="btn btn--primary" data-label-new="Anlegen" data-label-edit="Speichern">Anlegen</button></div>
      </form>
    </div>
  </div>

  <div class="modal-overlay" id="agendaModal" data-modal-static hidden>
    <div class="modal modal--form" role="dialog" aria-modal="true" aria-labelledby="agendaModalTitle">
      <div class="modal__head"><h3 id="agendaModalTitle" data-modal-title data-title-new="Neuer Programmpunkt" data-title-edit="Programmpunkt bearbeiten">Neuer Programmpunkt</h3><button type="button" class="modal__close" data-modal-close>&times;</button></div>
      <form method="post" action="<?= url('event') ?>" class="modal__body" data-modal-form>
        <?= Csrf::field() ?><input type="hidden" name="action" value="save_agenda"><input type="hidden" name="cycle" value="<?= $cycleId ?>"><input type="hidden" name="tab" value="agenda"><input type="hidden" name="id" value="0">
        <div class="grid cols-2">
          <div class="field"><label>Von</label><input type="time" name="time_from"></div>
          <div class="field"><label>Bis</label><input type="time" name="time_to"></div>
        </div>
        <div class="field"><label>Programmpunkt *</label><input type="text" name="title" required></div>
        <div class="field"><label>Notiz</label><input type="text" name="note"></div>
        <div class="modal__foot"><button type="button" class="btn btn--ghost" data-modal-close>Abbrechen</button><button class="btn btn--primary" data-label-new="Anlegen" data-label-edit="Speichern">Anlegen</button></div>
      </form>
    </div>
  </div>

  <div class="modal-overlay" id="budgetModal" data-modal-static hidden>
    <div class="modal modal--form" role="dialog" aria-modal="true" aria-labelledby="budgetModalTitle">
      <div class="modal__head"><h3 id="budgetModalTitle" data-modal-title data-title-new="Neue Position" data-title-edit="Position bearbeiten">Neue Position</h3><button type="button" class="modal__close" data-modal-close>&times;</button></div>
      <form method="post" action="<?= url('event') ?>" class="modal__body" data-modal-form>
        <?= Csrf::field() ?><input type="hidden" name="action" value="save_budget"><input type="hidden" name="cycle" value="<?= $cycleId ?>"><input type="hidden" name="tab" value="budget"><input type="hidden" name="id" value="0">
        <div class="grid cols-2">
          <div class="field"><label>Art</label><select name="kind"><option value="cost">Kosten</option><option value="prize">Preisgeld</option></select></div>
          <div class="field"><label>Platz (bei Preis)</label><input type="number" name="place" min="1" step="1"></div>
        </div>
        <div class="field"><label>Bezeichnung *</label><input type="text" name="label" required></div>
        <div class="field"><label>Betrag (€)</label><input type="text" name="amount" placeholder="z. B. 500"></div>
        <div class="field"><label>Notiz</label><input type="text" name="note"></div>
        <div class="modal__foot"><button type="button" class="btn btn--ghost" data-modal-close>Abbrechen</button><button class="btn btn--primary" data-label-new="Anlegen" data-label-edit="Speichern">Anlegen</button></div>
      </form>
    </div>
  </div>

<?php endif; ?>
</div><!-- /#event-page -->
<?php
$content = ob_get_clean();
$title = 'PitchDay';
require APP_PATH . '/pages/_layout.php';
