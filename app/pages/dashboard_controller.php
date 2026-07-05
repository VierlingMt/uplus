<?php
/** Dashboard-Controller: sammelt Kennzahlen und rendert die Uebersicht. */

declare(strict_types=1);

$stats = [
    'schools' => (int) Database::value('SELECT COUNT(*) FROM schools'),
    'teams'   => (int) Database::value('SELECT COUNT(*) FROM teams'),
    'plans'   => (int) Database::value('SELECT COUNT(DISTINCT team_id) FROM business_plans WHERE is_current = 1'),
    'jurors'  => (int) Database::value("SELECT COUNT(*) FROM users WHERE role = 'juror'"),
];

$timeline = [
    ['Kick-Off', 'Ende Feb', 'done'],
    ['Teambuilding', 'Ende Mrz', 'done'],
    ['Ideenfindung', 'ab April', 'done'],
    ['Juryfeedback', 'KW21/Mai', 'done'],
    ['Businessplan-Erstellung', '8 Wochen', 'done'],
    ['Einsendeschluss', '01.07', 'active'],
    ['Jury-Bewertung', 'Jul', 'active'],
    ['Pitch Day', '15.07', 'upcoming'],
    ['Project Closing', '22.07', 'upcoming'],
];

render('dashboard', ['stats' => $stats, 'timeline' => $timeline]);
