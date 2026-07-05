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

render('dashboard', ['stats' => $stats, 'timeline' => $timeline, 'sponsors' => $sponsors, 'year' => $year]);
