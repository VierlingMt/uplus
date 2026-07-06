<?php
/** Dashboard-Controller: sammelt Kennzahlen und rendert die Uebersicht. */

declare(strict_types=1);

$stats = [
    'schools' => (int) Database::value('SELECT COUNT(*) FROM schools'),
    'teams'   => (int) Database::value('SELECT COUNT(*) FROM teams'),
    'plans'   => (int) Database::value('SELECT COUNT(DISTINCT team_id) FROM business_plans WHERE is_current = 1'),
    'jurors'  => (int) Database::value("SELECT COUNT(*) FROM users WHERE role = 'juror'"),
];

// Projektablauf: konfigurierbare Meilensteine des aktiven Wettbewerbsjahres.
$activeCycle = Cycle::active();
$year = (string) ($activeCycle['year_label'] ?? '');
$timeline = $activeCycle ? Cycle::milestoneTimeline((int) $activeCycle['id']) : [];
$sponsors = $activeCycle ? Database::all(
    'SELECT DISTINCT s.name, s.logo_path
     FROM sponsors s JOIN sponsor_contributions c ON c.sponsor_id = s.id
     WHERE c.cycle_id = ? AND s.logo_path IS NOT NULL AND s.logo_path <> ""
     ORDER BY s.name',
    [(int) $activeCycle['id']]
) : [];

// PitchDay-Kurzüberblick (nur Verwaltung): Countdown + offene/überfällige Aufgaben.
$pitchday = null;
if (Auth::isManager() && $activeCycle) {
    $ev = PitchDay::eventForCycle((int) $activeCycle['id']);
    if ($ev) {
        $today = date('Y-m-d');
        $cnt = Database::one(
            "SELECT COUNT(*) total, SUM(status='done') done,
                    SUM(status<>'done' AND due_date IS NOT NULL AND due_date < ?) overdue,
                    SUM(status<>'done' AND due_date IS NOT NULL AND due_date >= ? AND due_date <= DATE_ADD(?, INTERVAL 14 DAY)) soon
             FROM event_tasks WHERE event_id = ?",
            [$today, $today, $today, (int) $ev['id']]
        );
        $days = $ev['event_date'] ? (int) floor((strtotime($ev['event_date']) - strtotime($today)) / 86400) : null;
        $pitchday = [
            'title'   => $ev['title'],
            'date'    => $ev['event_date'],
            'days'    => $days,
            'open'    => (int) $cnt['total'] - (int) $cnt['done'],
            'overdue' => (int) $cnt['overdue'],
            'soon'    => (int) $cnt['soon'],
        ];
    }
}

render('dashboard', ['stats' => $stats, 'timeline' => $timeline, 'sponsors' => $sponsors, 'year' => $year, 'pitchday' => $pitchday]);
