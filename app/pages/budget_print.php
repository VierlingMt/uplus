<?php
/**
 * PitchDay-Budget-Druckansichten (DIN A4) für die Projektleitung – gedacht als
 * Nachweis gegenüber Zuwendungsgebern (Kommunen, Stiftungen, Sponsoren):
 *
 *   ?kind=expenses – reine Ausgabenübersicht (Kosten + Preisgelder) mit Summe.
 *   ?kind=full     – Einnahmen- und Ausgabenübersicht: Einnahmen (Sponsoren-
 *                    beiträge), Ausgaben (Kosten + Preisgelder) und Saldo.
 *
 * Bewusst – wie event_print.php – eine eigenständige HTML-Seite mit Druck-CSS:
 * der Browser erzeugt daraus per „Drucken → Als PDF speichern" das fertige PDF,
 * ohne zusätzliche PDF-Bibliothek auf dem schlanken Shared-Hosting.
 */

declare(strict_types=1);

Auth::requireManager();

$cycleId = (int) input('cycle', Cycle::activeId());
if ($cycleId <= 0 || Cycle::find($cycleId) === null) {
    $cycleId = Cycle::activeId();
}
$cycle = $cycleId ? Cycle::find($cycleId) : null;
$event = $cycleId ? PitchDay::eventForCycle($cycleId) : null;
if (!$event) {
    http_response_code(404);
    exit('Für dieses Wettbewerbsjahr ist noch kein PitchDay angelegt.');
}
$eventId = (int) $event['id'];
$kind    = input('kind') === 'full' ? 'full' : 'expenses';

$dateFmt = fn(?string $d) => $d ? date('d.m.Y', strtotime($d)) : null;
$timeFmt = fn(?string $t) => $t ? substr($t, 0, 5) : null;
$money   = fn($a) => number_format((float) $a, 2, ',', '.') . ' €';
$wdays   = ['Sonntag', 'Montag', 'Dienstag', 'Mittwoch', 'Donnerstag', 'Freitag', 'Samstag'];
$weekday = fn(?string $d) => $d ? $wdays[(int) date('w', strtotime($d))] : null;

$logo      = asset('img/logo.svg');
$yearLabel = $cycle['year_label'] ?? '';

// --- Daten -----------------------------------------------------------------
// Ausgaben: Kosten und Preisgelder getrennt, damit die Übersicht die beiden
// Blöcke sauber ausweist. Preisgelder nach Platz sortiert.
$costs = Database::all(
    "SELECT * FROM event_budget_items WHERE event_id=? AND kind='cost' ORDER BY sort_order, id",
    [$eventId]
);
$prizes = Database::all(
    "SELECT * FROM event_budget_items WHERE event_id=? AND kind='prize' ORDER BY place IS NULL, place, sort_order, id",
    [$eventId]
);
$sumCosts  = (float) Database::value("SELECT COALESCE(SUM(amount),0) FROM event_budget_items WHERE event_id=? AND kind='cost'", [$eventId]);
$sumPrizes = (float) Database::value("SELECT COALESCE(SUM(amount),0) FROM event_budget_items WHERE event_id=? AND kind='prize'", [$eventId]);
$sumExpenses = $sumCosts + $sumPrizes;

// Einnahmen (nur für die vollständige Übersicht): Sponsorenbeiträge dieses Jahres.
$income = [];
$sumIncome = 0.0;
if ($kind === 'full') {
    $income = Database::all(
        'SELECT s.name, c.amount, c.description
           FROM sponsor_contributions c JOIN sponsors s ON s.id = c.sponsor_id
          WHERE c.cycle_id = ? ORDER BY s.name, c.id',
        [$cycleId]
    );
    $sumIncome = (float) Database::value('SELECT COALESCE(SUM(amount),0) FROM sponsor_contributions WHERE cycle_id=?', [$cycleId]);
}
$balance = $sumIncome - $sumExpenses;

// Teilnehmende Schulen dieses Wettbewerbsjahres mit Anzahl Teams/Schüler:innen –
// Schulen aus der Zyklus-Zuordnung (cycle_schools), Zahlen live aus teams/students.
// Fallback für Altbestände ohne Zyklus-Zuordnung: alle Schulen mit erfassten Teams.
$schoolCountSelect =
    'SELECT s.id, s.name, s.short_name,
            COUNT(DISTINCT t.id)  AS n_teams,
            COUNT(DISTINCT st.id) AS n_students';
$schools = Database::all(
    $schoolCountSelect . '
       FROM cycle_schools cs
       JOIN schools s   ON s.id = cs.school_id
       LEFT JOIN teams t    ON t.school_id = s.id
       LEFT JOIN students st ON st.team_id = t.id
      WHERE cs.cycle_id = ?
      GROUP BY s.id, s.name, s.short_name
      ORDER BY s.name',
    [$cycleId]
);
if (!$schools) {
    $schools = Database::all(
        $schoolCountSelect . '
           FROM schools s
           JOIN teams t     ON t.school_id = s.id
           LEFT JOIN students st ON st.team_id = t.id
          GROUP BY s.id, s.name, s.short_name
          HAVING n_teams > 0 OR n_students > 0
          ORDER BY s.name'
    );
}
$sumTeams    = array_sum(array_map(static fn($r) => (int) $r['n_teams'], $schools));
$sumStudents = array_sum(array_map(static fn($r) => (int) $r['n_students'], $schools));

// Aktuelle Projektleitung (Rolle „lead") – für die digitalen Pseudo-Unterschriften.
// Gleiche Quelle wie „Kontakt"/Handout: aktive Konten mit Rolle „lead".
$leads = Database::all(
    'SELECT name, specialty FROM users u
      WHERE u.is_active = 1
        AND EXISTS (SELECT 1 FROM user_roles ur WHERE ur.user_id = u.id AND ur.role = "lead")
      ORDER BY name'
);

$eventDateLine = trim(implode(', ', array_filter([$weekday($event['event_date']), $dateFmt($event['event_date'])])));

$pageTitle = $kind === 'full' ? 'Einnahmen- und Ausgabenübersicht' : 'Ausgabenübersicht';
$subtitle  = $kind === 'full'
    ? 'Aufstellung der Einnahmen und Ausgaben zum Nachweis von Zuwendungen'
    : 'Aufstellung der Ausgaben zum Nachweis von Zuwendungen';

// Kurze Überschrift für die laufende Fußzeile (CSS-escapen, da als content-String
// in einer @page-Margin-Box im <style> ausgegeben).
$footTitle    = 'Unternehmen Plus · PitchDay' . ($yearLabel !== '' ? ' ' . $yearLabel : '') . ' · ' . $pageTitle;
$cssFootTitle = str_replace(['\\', '"'], ['\\\\', '\\"'], $footTitle);

header('Content-Type: text/html; charset=utf-8');
?>
<!doctype html>
<html lang="de">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($pageTitle) ?> – Unternehmen Plus</title>
<style>
  :root { --blue:#003594; --ink:#1c2430; --muted:#5b6472; --line:#d8dee9; --red:#b3261e; --teal:#0f766e; }
  * { box-sizing: border-box; }
  html, body { margin: 0; padding: 0; }
  body { font-family: "Segoe UI", system-ui, -apple-system, Roboto, Helvetica, Arial, sans-serif;
         color: var(--ink); background: #eef1f6; line-height: 1.5; }

  /* Bildschirm: Werkzeugleiste + Papier-Optik */
  .toolbar { position: sticky; top: 0; z-index: 10; display: flex; gap: 10px; align-items: center;
             background: var(--blue); color: #fff; padding: 10px 16px; flex-wrap: wrap; }
  .toolbar b { font-weight: 700; }
  .toolbar .sp { flex: 1 1 auto; }
  .toolbar button, .toolbar a { font: inherit; border: 0; border-radius: 8px; padding: 8px 14px; cursor: pointer;
             text-decoration: none; background: rgba(255,255,255,.15); color: #fff; }
  .toolbar button.primary { background: #fff; color: var(--blue); font-weight: 700; }
  .hint { color: #cdd9f2; font-size: 13px; }

  .sheet { max-width: 820px; margin: 18px auto; }

  .doc { background: #fff; max-width: 820px; margin: 0 auto; padding: 26mm 22mm; border: 1px solid var(--line); border-radius: 6px; }
  .doc__head { display: flex; gap: 16px; align-items: center; border-bottom: 3px solid var(--blue); padding-bottom: 14px; margin-bottom: 20px; }
  .doc__logo { height: 58px; }
  .doc__kicker { text-transform: uppercase; letter-spacing: .14em; font-size: 12px; color: var(--muted); }
  .doc h1 { font-size: 24px; margin: 2px 0 4px; color: var(--blue); }
  .doc h1 span { font-weight: 400; color: var(--muted); font-size: .7em; }
  .doc__sub { color: var(--muted); font-size: 14px; }

  .meta { width: 100%; border-collapse: collapse; margin: 0 0 20px; }
  .meta th { text-align: left; width: 160px; vertical-align: top; color: var(--muted); font-weight: 600; padding: 3px 10px 3px 0; font-size: 13.5px; }
  .meta td { padding: 3px 0; font-size: 13.5px; }

  .block { margin: 0 0 20px; }
  .block h2 { font-size: 16px; color: var(--blue); border-bottom: 1px solid var(--line); padding-bottom: 5px; margin: 0 0 8px; }

  .lead { font-size: 13.5px; margin: 0 0 10px; line-height: 1.55; }
  .impact { border: 1px solid var(--line); border-left: 4px solid var(--blue); border-radius: 6px;
            background: rgba(0,53,148,.03); padding: 12px 16px; margin-top: 12px; }
  .impact__title { font-weight: 700; color: var(--blue); font-size: 13px; text-transform: uppercase;
                   letter-spacing: .06em; margin-bottom: 6px; }
  .impact ul { margin: 0; padding-left: 20px; }
  .impact li { font-size: 13px; line-height: 1.5; margin-bottom: 6px; }
  .impact li:last-child { margin-bottom: 0; }

  table.fin { width: 100%; border-collapse: collapse; }
  table.fin th, table.fin td { padding: 6px 8px; text-align: left; vertical-align: top; }
  table.fin thead th { font-size: 11.5px; text-transform: uppercase; letter-spacing: .05em; color: var(--muted);
                       border-bottom: 1.5px solid var(--line); }
  table.fin tbody td { border-bottom: 1px solid #eef1f6; font-size: 13.5px; }
  table.fin .num { text-align: right; white-space: nowrap; font-variant-numeric: tabular-nums; }
  table.fin .place { width: 60px; color: var(--muted); white-space: nowrap; }
  table.fin .note { color: var(--muted); font-size: 12.5px; }
  table.fin tfoot td { border-top: 1.5px solid var(--line); font-weight: 700; font-size: 14px; padding-top: 8px; }
  table.fin tfoot .num { color: var(--blue); }
  .empty-row td { color: var(--muted); font-style: italic; }

  /* Zusammenfassung (Saldo) */
  .summary { margin: 6px 0 0; border-collapse: collapse; width: 100%; }
  .summary td { padding: 7px 8px; font-size: 14px; border-bottom: 1px solid #eef1f6; }
  .summary td.num { text-align: right; white-space: nowrap; font-variant-numeric: tabular-nums; font-weight: 700; }
  .summary tr.total td { border-top: 2px solid var(--blue); border-bottom: 0; font-size: 16px; font-weight: 800; padding-top: 10px; }
  .summary tr.total td.num.pos { color: var(--teal); }
  .summary tr.total td.num.neg { color: var(--red); }

  .attest { margin-top: 26px; font-size: 12.5px; color: var(--muted); line-height: 1.5; }

  /* Digitale „Pseudo-Unterschriften" der Projektleitung: Name in Schreibschrift,
     darunter Trennlinie mit gedrucktem Namen und Position. */
  .signs { display: flex; flex-wrap: wrap; gap: 26px 44px; margin-top: 30px; }
  .sig { min-width: 210px; }
  .sig__hand { font-family: "Segoe Script", "Bradley Hand", "Brush Script MT", "Snell Roundhand", "Comic Sans MS", cursive;
               font-size: 30px; line-height: 1; color: #16255c; padding: 0 6px 3px; white-space: nowrap;
               transform: rotate(-3deg); transform-origin: left bottom; }
  .sig__line { border-top: 1px solid var(--ink); padding-top: 5px; font-size: 12.5px; color: var(--muted); }
  .sig__line strong { display: block; color: var(--ink); font-weight: 700; }
  .auto-note { margin-top: 24px; font-size: 11.5px; color: var(--muted); font-style: italic; line-height: 1.5; }

  .doc__foot { margin-top: 22px; border-top: 1px solid var(--line); padding-top: 10px; color: var(--muted); font-size: 12px; text-align: center; }

  @media print {
    body { background: #fff; }
    .toolbar { display: none; }
    .sheet { margin: 0; max-width: none; }
    .doc { border: 0; border-radius: 0; max-width: none; margin: 0; padding: 0; }
    .block, table.fin tr, .sig { break-inside: avoid; }
  }
  @page {
    size: A4;
    margin: 14mm 12mm 15mm;
    /* Links unten: erst die Seitenzahl („Seite 1 von 3"), auf gleicher Höhe der
       Dokumenttitel – klein und anthrazit. Echte Seitenzähler über CSS Paged Media. */
    @bottom-left {
      content: "Seite " counter(page) " von " counter(pages) "   ·   <?= $cssFootTitle ?>";
      font-size: 8.5pt; color: #3a3f47;
    }
  }
</style>
</head>
<body>
  <div class="toolbar">
    <b><?= e($pageTitle) ?></b>
    <span class="hint">Beim Drucken „Als PDF speichern" wählen</span>
    <span class="sp"></span>
    <a href="<?= e(url('event', ['cycle' => $cycleId, 'tab' => 'budget'])) ?>">← Zurück</a>
    <button type="button" class="primary" onclick="window.print()">🖨 Drucken / Als PDF speichern</button>
  </div>
  <div class="sheet">
    <article class="doc">
      <header class="doc__head">
        <img class="doc__logo" src="<?= e($logo) ?>" alt="">
        <div>
          <div class="doc__kicker">Wirtschaftsjunioren Forchheim</div>
          <h1><?= e($pageTitle) ?><?= $yearLabel ? ' <span>' . e($yearLabel) . '</span>' : '' ?></h1>
          <div class="doc__sub"><?= e($subtitle) ?></div>
        </div>
      </header>

      <table class="meta">
        <tr><th>Veranstaltung</th><td><?= e($event['title'] ?: 'PitchDay Unternehmen Plus') ?></td></tr>
        <tr><th>Veranstalter</th><td>Wirtschaftsjunioren Forchheim</td></tr>
        <?php if ($yearLabel): ?><tr><th>Wettbewerbsjahr</th><td><?= e($yearLabel) ?></td></tr><?php endif; ?>
        <?php if ($event['venue']): ?><tr><th>Ort</th><td><?= e($event['venue']) ?><?= $event['venue_address'] ? ', ' . e($event['venue_address']) : '' ?></td></tr><?php endif; ?>
        <?php if ($eventDateLine): ?><tr><th>Veranstaltungsdatum</th><td><?= e($eventDateLine) ?></td></tr><?php endif; ?>
        <tr><th>Erstellt am</th><td><?= e(date('d.m.Y')) ?></td></tr>
      </table>

      <!-- ============ Über das Projekt / Wirkung ============ -->
      <section class="block">
        <h2>Über das Projekt</h2>
        <p class="lead">„Unternehmen&nbsp;Plus" ist der Businessplanwettbewerb der Wirtschaftsjunioren
          Forchheim für Schülerinnen und Schüler der regionalen Gymnasien. In Anlehnung an „Die Höhle
          der Löwen" entwickeln die Jugendlichen in Teams eigene Geschäftsideen, erstellen einen
          Businessplan und präsentieren ihn beim großen <strong>Pitch&nbsp;Day</strong> vor einer Jury
          aus Unternehmerinnen, Unternehmern und Führungskräften. Ehrenamtlich getragen, mit
          Praxisbezug zum bayerischen LehrplanPLUS und offen für alle weiterführenden Schulen der Region.</p>
        <p class="lead">Die hier aufgeführten Mittel fließen unmittelbar in die Durchführung – von der
          Veranstaltung über die Betreuung der Teams bis zu den Preisgeldern.</p>
        <div class="impact">
          <div class="impact__title">Wirkung &amp; Mehrwert</div>
          <ul>
            <li><strong>Für die Schülerinnen und Schüler:</strong> unternehmerisches Denken, wirtschaftliche
              Bildung und Selbstvertrauen – praxisnah, im Team und mit echtem Bühnen-Pitch statt trockener
              Theorie. Wertvolle Erfahrungen für Ausbildung, Studium und Berufsorientierung.</li>
            <li><strong>Für die Region:</strong> Stärkung von Fachkräftenachwuchs und Gründergeist vor Ort,
              enge Verzahnung von Schulen und regionaler Wirtschaft sowie Sichtbarkeit für den Standort
              Forchheim als Ort, der junge Talente fördert.</li>
            <li><strong>Nachhaltig &amp; ehrenamtlich:</strong> getragen vom bürgerschaftlichen Engagement
              der Wirtschaftsjunioren – jede Zuwendung kommt direkt den teilnehmenden Jugendlichen zugute.</li>
          </ul>
        </div>
      </section>

      <?php if ($schools): ?>
      <!-- ============ Teilnehmende Schulen (live) ============ -->
      <section class="block">
        <h2>Teilnehmende Schulen<?= $yearLabel ? ' – ' . e($yearLabel) : '' ?></h2>
        <table class="fin">
          <thead><tr><th>Schule</th><th class="num">Teams</th><th class="num">Schüler:innen</th></tr></thead>
          <tbody>
            <?php foreach ($schools as $r): ?>
              <tr>
                <td><?= e($r['name']) ?><?= $r['short_name'] ? ' <span class="note">(' . e($r['short_name']) . ')</span>' : '' ?></td>
                <td class="num"><?= (int) $r['n_teams'] ?></td>
                <td class="num"><?= (int) $r['n_students'] ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
          <tfoot><tr><td><?= count($schools) ?> <?= count($schools) === 1 ? 'Schule' : 'Schulen' ?></td><td class="num"><?= $sumTeams ?></td><td class="num"><?= $sumStudents ?></td></tr></tfoot>
        </table>
      </section>
      <?php endif; ?>

      <?php if ($kind === 'full'): ?>
      <!-- ============ Einnahmen ============ -->
      <section class="block">
        <h2>Einnahmen</h2>
        <table class="fin">
          <thead><tr><th>Sponsor / Zuwendungsgeber</th><th>Verwendung / Beschreibung</th><th class="num">Betrag</th></tr></thead>
          <tbody>
            <?php foreach ($income as $c): ?>
              <tr>
                <td><?= e($c['name']) ?></td>
                <td class="note"><?= e($c['description'] ?? '') ?></td>
                <td class="num"><?= $c['amount'] !== null ? e($money($c['amount'])) : '–' ?></td>
              </tr>
            <?php endforeach; ?>
            <?php if (!$income): ?><tr class="empty-row"><td colspan="3">Noch keine Sponsorenbeiträge erfasst.</td></tr><?php endif; ?>
          </tbody>
          <tfoot><tr><td colspan="2">Summe Einnahmen</td><td class="num"><?= e($money($sumIncome)) ?></td></tr></tfoot>
        </table>
      </section>
      <?php endif; ?>

      <!-- ============ Ausgaben: Kosten ============ -->
      <section class="block">
        <h2>Ausgaben – Kosten</h2>
        <table class="fin">
          <thead><tr><th>Bezeichnung</th><th>Notiz</th><th class="num">Betrag</th></tr></thead>
          <tbody>
            <?php foreach ($costs as $it): ?>
              <tr>
                <td><?= e($it['label']) ?></td>
                <td class="note"><?= e($it['note'] ?? '') ?></td>
                <td class="num"><?= $it['amount'] !== null ? e($money($it['amount'])) : '–' ?></td>
              </tr>
            <?php endforeach; ?>
            <?php if (!$costs): ?><tr class="empty-row"><td colspan="3">Keine Kosten erfasst.</td></tr><?php endif; ?>
          </tbody>
          <tfoot><tr><td colspan="2">Summe Kosten</td><td class="num"><?= e($money($sumCosts)) ?></td></tr></tfoot>
        </table>
      </section>

      <!-- ============ Ausgaben: Preisgelder ============ -->
      <section class="block">
        <h2>Ausgaben – Preisgelder</h2>
        <table class="fin">
          <thead><tr><th class="place">Platz</th><th>Bezeichnung</th><th>Notiz</th><th class="num">Betrag</th></tr></thead>
          <tbody>
            <?php foreach ($prizes as $it): ?>
              <tr>
                <td class="place"><?= $it['place'] ? (int) $it['place'] . '.' : '–' ?></td>
                <td><?= e($it['label']) ?></td>
                <td class="note"><?= e($it['note'] ?? '') ?></td>
                <td class="num"><?= $it['amount'] !== null ? e($money($it['amount'])) : '–' ?></td>
              </tr>
            <?php endforeach; ?>
            <?php if (!$prizes): ?><tr class="empty-row"><td colspan="4">Keine Preisgelder erfasst.</td></tr><?php endif; ?>
          </tbody>
          <tfoot><tr><td colspan="3">Summe Preisgelder</td><td class="num"><?= e($money($sumPrizes)) ?></td></tr></tfoot>
        </table>
      </section>

      <!-- ============ Zusammenfassung ============ -->
      <section class="block">
        <h2>Zusammenfassung</h2>
        <table class="summary">
          <?php if ($kind === 'full'): ?>
            <tr><td>Summe Einnahmen</td><td class="num"><?= e($money($sumIncome)) ?></td></tr>
          <?php endif; ?>
          <tr><td>Summe Kosten</td><td class="num"><?= e($money($sumCosts)) ?></td></tr>
          <tr><td>Summe Preisgelder</td><td class="num"><?= e($money($sumPrizes)) ?></td></tr>
          <tr><td><strong>Summe Ausgaben gesamt</strong></td><td class="num"><?= e($money($sumExpenses)) ?></td></tr>
          <?php if ($kind === 'full'): ?>
            <tr class="total"><td>Saldo (Einnahmen − Ausgaben)</td><td class="num <?= $balance >= 0 ? 'pos' : 'neg' ?>"><?= e($money($balance)) ?></td></tr>
          <?php endif; ?>
        </table>
      </section>

      <div class="attest">
        Die vorstehenden Angaben entsprechen den in „Unternehmen&nbsp;Plus" erfassten Positionen und wurden
        nach bestem Wissen und Gewissen zusammengestellt. Alle Beträge in Euro (€), inkl. ggf. anfallender USt.
      </div>

      <?php if ($leads): ?>
      <div class="signs">
        <?php foreach ($leads as $l): ?>
          <div class="sig">
            <div class="sig__hand"><?= e($l['name']) ?></div>
            <div class="sig__line">
              <strong><?= e($l['name']) ?></strong>
              <?= $l['specialty'] ? e($l['specialty']) . ' · ' : '' ?>Projektleitung „Unternehmen&nbsp;Plus"
            </div>
          </div>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>

      <div class="auto-note">
        Hinweis: Dieses Dokument wurde am <?= e(date('d.m.Y')) ?> automatisch aus den in „Unternehmen&nbsp;Plus"
        erfassten Daten erzeugt. Die Unterschriften der Projektleitung sind digital eingesetzt; das Dokument ist
        auch ohne handschriftliche Unterschrift gültig.
      </div>

      <footer class="doc__foot">Unternehmen&nbsp;Plus – Businessplanwettbewerb der Wirtschaftsjunioren Forchheim<?= $yearLabel ? ' · ' . e($yearLabel) : '' ?></footer>
    </article>
  </div>
</body>
</html>
