<?php
/**
 * PitchDay-Druckansichten (DIN A4) für die Projektleitung:
 *
 *   ?kind=signs    – „Reserviert"-Schilder, ein Schild je A4-Seite, für alle
 *                    ausgewählten Gäste (Parameter ids=…, sonst alle mit
 *                    reserviertem Sitzplatz). Bei einer Vertretung steht die
 *                    vertretende Person auf dem Schild – mit Hinweis, wen sie
 *                    vertritt.
 *   ?kind=handout  – kompletter Ablauf-/Infoplan zum PitchDay als Handout,
 *                    zusammengesetzt aus den in der App gepflegten Daten
 *                    (Eckdaten, Ehrengäste, Jury, Ablauf, Grußworte, Preise,
 *                    Sponsoren) plus den wiederkehrenden Infotexten.
 *
 * Bewusst eine eigenständige, layout-freie HTML-Seite mit Druck-CSS: der
 * Browser erzeugt daraus per „Drucken → Als PDF speichern" das fertige PDF –
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
$kind    = input('kind') === 'handout' ? 'handout' : 'signs';

$dateFmt = fn(?string $d) => $d ? date('d.m.Y', strtotime($d)) : null;
$timeFmt = fn(?string $t) => $t ? substr($t, 0, 5) : null;
$money   = fn($a) => number_format((float) $a, 2, ',', '.') . ' €';
$wdays   = ['Sonntag', 'Montag', 'Dienstag', 'Mittwoch', 'Donnerstag', 'Freitag', 'Samstag'];
$weekday = fn(?string $d) => $d ? $wdays[(int) date('w', strtotime($d))] : null;

$guests = Database::all(
    "SELECT * FROM event_guests WHERE event_id=? ORDER BY FIELD(category,'speaker','vip','jury','teacher','sponsor','press'), name",
    [$eventId]
);

/** Standard-Rollenzeile eines Gasts (Kategorie + Organisation/Position). */
$roleLine = function (array $gd, string $category): string {
    $bits = array_filter([$gd['position'], $gd['org']]);
    $txt  = $bits ? implode(' · ', $bits) : PitchDay::guestCategory($category);
    return $txt;
};

$logo = asset('img/logo.svg');
$yearLabel = $cycle['year_label'] ?? '';

ob_start();

if ($kind === 'signs'):
    // -------- Auswahl der Gäste fürs Reserviert-Schild --------
    $idsRaw  = (string) input('ids', '');
    $pickIds = array_values(array_filter(array_map('intval', array_filter(explode(',', $idsRaw), 'strlen'))));
    if ($pickIds) {
        $signs = array_values(array_filter($guests, fn($g) => in_array((int) $g['id'], $pickIds, true)));
    } else {
        // Standard: alle Gäste/VIPs bis auf Absagen.
        $signs = array_values(array_filter($guests, fn($g) => $g['status'] !== 'declined'));
    }
    // Innerhalb der Kategorie klassisch nach Nachname sortieren.
    $catOrder = array_flip(array_keys(PitchDay::GUEST_CATEGORIES));
    usort($signs, fn($a, $b) =>
        [$catOrder[$a['category']] ?? 99, PitchDay::surname(PitchDay::guestDisplay($a)['name'])]
        <=> [$catOrder[$b['category']] ?? 99, PitchDay::surname(PitchDay::guestDisplay($b)['name'])]);
    $eventLine = trim(implode(' · ', array_filter([
        $weekday($event['event_date']) . ($event['event_date'] ? ', ' . $dateFmt($event['event_date']) : ''),
        $event['time_from'] ? $timeFmt($event['time_from']) . ' Uhr' : '',
        $event['venue'] ?? '',
    ])));
?>
  <?php if (!$signs): ?>
    <div class="empty">
      <h1>Keine Gäste ausgewählt</h1>
      <p>Für ein Reserviert-Schild bitte im PitchDay unter „Gäste &amp; VIPs" die gewünschten Gäste anhaken
         und erneut auf „Reserviert-Schilder" klicken. Ohne Auswahl werden alle Gäste außer Absagen gedruckt.</p>
    </div>
  <?php else: ?>
    <?php foreach ($signs as $g): $gd = PitchDay::guestDisplay($g);
      $cat = PitchDay::guestCategory($g['category']);
      $sub = trim(implode(' · ', array_filter([$gd['position'], $gd['org']])));
    ?>
      <section class="sign">
        <div class="sign__top">
          <img class="sign__logo" src="<?= e($logo) ?>" alt="">
          <div class="sign__event">Unternehmen&nbsp;Plus · PitchDay<?= $eventLine ? '<br><span>' . e($eventLine) . '</span>' : '' ?></div>
        </div>
        <div class="sign__mid">
          <div class="sign__word">RESERVIERT</div>
          <div class="sign__cat"><?= e($cat) ?></div>
          <div class="sign__name"><?= e($gd['name']) ?></div>
          <?php if ($sub !== ''): ?><div class="sign__sub"><?= e($sub) ?></div><?php endif; ?>
          <?php if ($gd['subline']): ?><div class="sign__vertritt">↷ <?= e($gd['subline']) ?></div><?php endif; ?>
        </div>
        <div class="sign__foot">Bitte diesen Platz freihalten · Wirtschaftsjunioren Forchheim</div>
      </section>
    <?php endforeach; ?>
  <?php endif; ?>
<?php
else:
    // ======================= HANDOUT =======================
    // Nur tatsächlich Teilnehmende – Absagen tauchen im Handout nicht auf
    // (weder in den Listen noch in den Zahlen).
    $attending = array_values(array_filter($guests, fn($g) => $g['status'] !== 'declined'));
    // Personenlisten klassisch nach Nachname sortieren.
    $vips     = PitchDay::sortBySurname(array_filter($attending, fn($g) => $g['category'] === 'vip'));
    $jury     = PitchDay::sortBySurname(array_filter($attending, fn($g) => $g['category'] === 'jury'));
    $teachers = PitchDay::sortBySurname(array_filter($attending, fn($g) => $g['category'] === 'teacher'));
    $press    = PitchDay::sortBySurname(array_filter($attending, fn($g) => $g['category'] === 'press'));
    // Lehrkräfte zusätzlich je Schule (Organisation) gruppieren.
    $teachersBySchool = [];
    foreach ($teachers as $g) {
        $teachersBySchool[trim((string) ($g['org'] ?? '')) ?: 'Weitere'][] = $g;
    }
    ksort($teachersBySchool, SORT_NATURAL | SORT_FLAG_CASE);
    // Grußworte & Keynote: manuelle Reihenfolge aus der Übersicht (sort_order),
    // sonst Grußworte vor Keynote, dann Nachname.
    $speakers = array_values(array_filter($attending, fn($g) => (int) $g['greeting'] === 1 || (int) $g['keynote'] === 1));
    usort($speakers, fn($a, $b) =>
        [(int) $a['sort_order'], (int) $a['keynote'], PitchDay::surname(PitchDay::guestDisplay($a)['name'])]
        <=> [(int) $b['sort_order'], (int) $b['keynote'], PitchDay::surname(PitchDay::guestDisplay($b)['name'])]);

    $agenda = Database::all('SELECT * FROM event_agenda WHERE event_id=? ORDER BY sort_order, time_from, id', [$eventId]);
    $prizes = Database::all("SELECT * FROM event_budget_items WHERE event_id=? AND kind='prize' ORDER BY place IS NULL, place, sort_order, id", [$eventId]);
    $sponsors = Database::all(
        'SELECT s.name, s.logo_path FROM sponsor_contributions c JOIN sponsors s ON s.id = c.sponsor_id
         WHERE c.cycle_id = ? GROUP BY s.id, s.name, s.logo_path ORDER BY s.name',
        [$cycleId]
    );
    // Ansprechpartner = Projektleitung (Rolle „lead"), exakt wie der Menüpunkt
    // „Kontakt" (contact.php). Das Admin/Super-Admin-Konto ist eine technische
    // Rolle und erscheint hier bewusst nicht.
    $leads = Database::all(
        'SELECT name, email, phone, specialty FROM users u
         WHERE u.is_active = 1
           AND EXISTS (SELECT 1 FROM user_roles ur WHERE ur.user_id = u.id AND ur.role = "lead")
         ORDER BY name'
    );

    $nStudents = (int) Database::value('SELECT COUNT(*) FROM students');
    $nTeams    = (int) Database::value('SELECT COUNT(*) FROM teams');

    $parts = array_filter([
        $nStudents ? $nStudents . ' Schüler:innen' : '',
        $nTeams ? $nTeams . ' Teams' : '',
        $jury ? count($jury) . (count($jury) === 1 ? ' Jurymitglied' : ' Jurymitglieder') : '',
        $vips ? count($vips) . (count($vips) === 1 ? ' Ehrengast' : ' Ehrengäste') : '',
        $teachers ? count($teachers) . (count($teachers) === 1 ? ' Lehrkraft' : ' Lehrkräfte') : '',
    ]);

    $eventDateLine = trim(implode(', ', array_filter([$weekday($event['event_date']), $dateFmt($event['event_date'])])));
    $timeLine = $event['time_from'] ? $timeFmt($event['time_from']) . ' Uhr' : '';
?>
  <article class="doc">
    <header class="doc__head">
      <img class="doc__logo" src="<?= e($logo) ?>" alt="">
      <div>
        <div class="doc__kicker">Wirtschaftsjunioren Forchheim</div>
        <h1>Unternehmen&nbsp;Plus – PitchDay<?= $yearLabel ? ' <span>' . e($yearLabel) . '</span>' : '' ?></h1>
        <div class="doc__sub">Ablauf &amp; Infos für Projektbeteiligte<?= $eventDateLine ? ' · ' . e($eventDateLine) : '' ?></div>
      </div>
    </header>

    <section class="block">
      <h2>Veranstaltungsinfos</h2>
      <table class="kv">
        <tr><th>Veranstaltung</th><td><?= e($event['title'] ?: 'PitchDay Unternehmen Plus') ?></td></tr>
        <tr><th>Veranstalter</th><td>Wirtschaftsjunioren Forchheim</td></tr>
        <?php if ($event['venue'] || $event['venue_address']): ?>
          <tr><th>Ort</th><td><?= e(trim(implode(', ', array_filter([$event['venue'], $event['venue_address']])))) ?></td></tr>
        <?php endif; ?>
        <?php if ($eventDateLine): ?><tr><th>Datum</th><td><?= e($eventDateLine) ?></td></tr><?php endif; ?>
        <?php if ($timeLine): ?><tr><th>Uhrzeit</th><td><?= e($timeLine) ?></td></tr><?php endif; ?>
        <?php if ($parts): ?><tr><th>Teilnehmende</th><td><?= e(implode(' · ', $parts)) ?></td></tr><?php endif; ?>
      </table>
    </section>

    <?php if ($vips): ?>
    <section class="block">
      <h2>Ehrengäste</h2>
      <ol class="people">
        <?php foreach ($vips as $g): $gd = PitchDay::guestDisplay($g); ?>
          <li>
            <strong><?= e($gd['name']) ?></strong>
            <?php $r = $roleLine($gd, $g['category']); if ($r): ?><span class="role"><?= e($r) ?></span><?php endif; ?>
            <?php if ($gd['subline']): ?><span class="vertritt">↷ <?= e($gd['subline']) ?></span><?php endif; ?>
          </li>
        <?php endforeach; ?>
      </ol>
    </section>
    <?php endif; ?>

    <?php if ($jury): ?>
    <section class="block">
      <h2>Jury</h2>
      <ol class="people">
        <?php foreach ($jury as $g): $gd = PitchDay::guestDisplay($g); ?>
          <li>
            <strong><?= e($gd['name']) ?></strong>
            <?php $r = $roleLine($gd, $g['category']); if ($r && $r !== PitchDay::guestCategory('jury')): ?><span class="role"><?= e($r) ?></span><?php endif; ?>
            <?php if ($gd['subline']): ?><span class="vertritt">↷ <?= e($gd['subline']) ?></span><?php endif; ?>
          </li>
        <?php endforeach; ?>
      </ol>
    </section>
    <?php endif; ?>

    <?php if ($teachers): ?>
    <section class="block">
      <h2>Lehrkräfte / Projektbetreuung</h2>
      <?php foreach ($teachersBySchool as $school => $group): ?>
        <div class="subgroup"><?= e($school) ?></div>
        <ol class="people">
          <?php foreach ($group as $g): $gd = PitchDay::guestDisplay($g); ?>
            <li>
              <strong><?= e($gd['name']) ?></strong>
              <?php if ($gd['position']): ?><span class="role"><?= e($gd['position']) ?></span><?php endif; ?>
              <?php if ($gd['subline']): ?><span class="vertritt">↷ <?= e($gd['subline']) ?></span><?php endif; ?>
            </li>
          <?php endforeach; ?>
        </ol>
      <?php endforeach; ?>
    </section>
    <?php endif; ?>

    <?php if ($agenda): ?>
    <section class="block">
      <h2>Ablauf</h2>
      <table class="agenda">
        <?php foreach ($agenda as $a): ?>
          <tr>
            <td class="agenda__time"><?php
              $tf = $timeFmt($a['time_from']); $tt = $timeFmt($a['time_to']);
              echo e($tf ? ($tt ? $tf . ' – ' . $tt : $tf) : '');
            ?></td>
            <td class="agenda__title"><?= e($a['title']) ?><?= $a['note'] ? ' <span class="note">(' . e($a['note']) . ')</span>' : '' ?></td>
          </tr>
        <?php endforeach; ?>
      </table>
    </section>
    <?php endif; ?>

    <?php if ($speakers): ?>
    <section class="block">
      <h2>Grußworte &amp; Keynote</h2>
      <ol class="people">
        <?php foreach ($speakers as $g): $gd = PitchDay::guestDisplay($g);
          $tag = (int) $g['keynote'] === 1 ? 'Keynote' : 'Grußwort'; ?>
          <li>
            <span class="tag"><?= e($tag) ?></span>
            <strong><?= e($gd['name']) ?></strong>
            <?php $r = $roleLine($gd, $g['category']); if ($r): ?><span class="role"><?= e($r) ?></span><?php endif; ?>
            <?php if ($g['greeting_minutes']): ?><span class="role">ca. <?= (int) $g['greeting_minutes'] ?> Min</span><?php endif; ?>
            <?php if ($gd['subline']): ?><span class="vertritt">↷ <?= e($gd['subline']) ?></span><?php endif; ?>
          </li>
        <?php endforeach; ?>
      </ol>
    </section>
    <?php endif; ?>

    <section class="block">
      <h2>Infos zum Projekt</h2>
      <p>Die Wirtschaftsjunioren Forchheim – ein Netzwerk junger Unternehmerinnen, Unternehmer und
        Führungskräfte bis 45 Jahre – engagieren sich für wirtschaftliche Bildung, gesellschaftliche
        Verantwortung und nachhaltige Entwicklung in der Region.</p>
      <p>Mit „Unternehmen&nbsp;Plus" fördern wir gezielt unternehmerisches Denken bei Schülerinnen und
        Schülern an den regionalen Gymnasien und unterstützen den bayerischen LehrplanPLUS mit
        Praxisbezug. In Anlehnung an „Die Höhle der Löwen" entwickeln die Jugendlichen in Teams eigene
        Geschäftsideen, die sie beim großen Pitch&nbsp;Day einer Jury aus Unternehmerinnen, Unternehmern
        und Führungskräften präsentieren. Die besten Teams erfahren erst am Veranstaltungstag, ob sie
        live auf die Bühne dürfen.</p>
    </section>

    <section class="block">
      <h2>Aufgaben der Jury</h2>
      <ol class="steps">
        <li>Ihr sitzt zu Beginn vorne in den ersten Sitzreihen.</li>
        <li>Vor den Pitches werdet ihr aufgerufen und vorgestellt und kommt einzeln auf die Bühne zu den Jurystühlen.</li>
        <li>Zu jedem Pitch werden Fragen gestellt und Anmerkungen gemacht – immer konstruktiv und positiv, mit echtem Mehrwert für die Teams.</li>
        <li>Ablauf je Team: 3&nbsp;Min. Pitch, 5&nbsp;Min. Jury-Feedback, 2&nbsp;Min. Puffer.</li>
        <li>Nach den Pitches zieht ihr euch in einen separaten Raum zurück und kürt den 1., 2. und 3.&nbsp;Platz. Die Nominierung anschließend an die Moderation geben (Urkunden mit Businessplan-Titel, Namen der Teammitglieder &amp; Schulname).</li>
        <li>Bitte haltet euch streng an den Zeitplan. Danke für euren Einsatz!</li>
      </ol>
    </section>

    <?php if ($prizes): ?>
    <section class="block">
      <h2>Preise</h2>
      <ul class="prizes">
        <?php foreach ($prizes as $p): ?>
          <li>
            <strong><?= $p['place'] ? (int) $p['place'] . '. Platz' : e($p['label']) ?></strong>
            <?= $p['place'] ? ': ' . e($p['label']) : '' ?>
            <?php if ($p['amount'] !== null): ?><span class="amount"><?= e($money($p['amount'])) ?></span><?php endif; ?>
            <?php if ($p['note']): ?><span class="note"><?= e($p['note']) ?></span><?php endif; ?>
          </li>
        <?php endforeach; ?>
      </ul>
    </section>
    <?php endif; ?>

    <?php if ($press): ?>
    <section class="block">
      <h2>Presse</h2>
      <ul class="plain">
        <?php foreach ($press as $g): $gd = PitchDay::guestDisplay($g); ?>
          <li><strong><?= e($gd['name']) ?></strong><?php $r = $roleLine($gd, $g['category']); if ($r && $r !== PitchDay::guestCategory('press')): ?> · <?= e($r) ?><?php endif; ?></li>
        <?php endforeach; ?>
      </ul>
    </section>
    <?php endif; ?>

    <?php if ($sponsors): ?>
    <section class="block">
      <h2>Sponsoren</h2>
      <div class="sponsors">
        <?php foreach ($sponsors as $s): ?>
          <div class="sponsor">
            <?php if (!empty($s['logo_path'])): ?><img src="<?= e(asset($s['logo_path'])) ?>" alt="<?= e($s['name']) ?>"><?php else: ?><span><?= e($s['name']) ?></span><?php endif; ?>
          </div>
        <?php endforeach; ?>
      </div>
    </section>
    <?php endif; ?>

    <?php if ($leads): ?>
    <section class="block">
      <h2>Fragen &amp; Kontakt</h2>
      <p>Bei Fragen rund um den Wettbewerb hilft dir die Projektleitung gerne weiter:</p>
      <ul class="plain">
        <?php foreach ($leads as $l): ?>
          <li><strong><?= e($l['name']) ?></strong><?= $l['specialty'] ? ' · ' . e($l['specialty']) : '' ?><?= $l['email'] ? ' · ' . e($l['email']) : '' ?><?= $l['phone'] ? ' · ' . e($l['phone']) : '' ?></li>
        <?php endforeach; ?>
      </ul>
    </section>
    <?php endif; ?>

    <footer class="doc__foot">Unternehmen&nbsp;Plus – Businessplanwettbewerb der Wirtschaftsjunioren Forchheim<?= $yearLabel ? ' · ' . e($yearLabel) : '' ?></footer>
  </article>
<?php
endif;
$body = ob_get_clean();

$pageTitle = $kind === 'signs' ? 'Reserviert-Schilder' : 'PitchDay – Ablauf & Handout';

// Kurze Überschrift für die laufende Fußzeile (links). CSS-escapen, da als
// content-String in einer @page-Margin-Box (im <style>) ausgegeben.
$footTitle    = 'Unternehmen Plus · PitchDay' . ($yearLabel !== '' ? ' ' . $yearLabel : '');
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
  :root { --blue:#003594; --ink:#1c2430; --muted:#5b6472; --line:#d8dee9; }
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

  /* ---------- Reserviert-Schilder ---------- */
  .sign { background: #fff; width: 100%; max-width: 820px; margin: 0 auto 18px; aspect-ratio: 1 / 1.414;
          border: 1px solid var(--line); border-radius: 6px; padding: 22mm 18mm; display: flex;
          flex-direction: column; text-align: center; }
  .sign__top { display: flex; flex-direction: column; align-items: center; gap: 10px; color: var(--muted); }
  .sign__logo { height: 68px; }
  .sign__event { font-size: 15px; line-height: 1.3; }
  .sign__event span { color: var(--muted); font-size: 13px; }
  .sign__mid { flex: 1 1 auto; display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 8px; }
  .sign__word { font-size: 58px; font-weight: 900; letter-spacing: .12em; color: var(--blue); }
  .sign__cat { text-transform: uppercase; letter-spacing: .18em; font-size: 15px; color: var(--muted); margin-top: 6px; }
  .sign__name { font-size: 42px; font-weight: 800; line-height: 1.1; }
  .sign__sub { font-size: 20px; color: var(--muted); }
  .sign__vertritt { margin-top: 6px; font-size: 17px; color: var(--blue); font-weight: 600; }
  .sign__foot { color: var(--muted); font-size: 13px; border-top: 1px solid var(--line); padding-top: 10px; }

  .empty { background:#fff; max-width: 820px; margin: 40px auto; padding: 40px; border-radius: 8px; text-align: center; }
  .empty h1 { color: var(--blue); }

  /* ---------- Handout ---------- */
  .doc { background: #fff; max-width: 820px; margin: 0 auto; padding: 26mm 22mm; border: 1px solid var(--line); border-radius: 6px; }
  .doc__head { display: flex; gap: 16px; align-items: center; border-bottom: 3px solid var(--blue); padding-bottom: 14px; margin-bottom: 20px; }
  .doc__logo { height: 58px; }
  .doc__kicker { text-transform: uppercase; letter-spacing: .14em; font-size: 12px; color: var(--muted); }
  .doc h1 { font-size: 26px; margin: 2px 0 4px; color: var(--blue); }
  .doc h1 span { font-weight: 400; color: var(--muted); font-size: .7em; }
  .doc__sub { color: var(--muted); font-size: 14px; }
  .block { margin: 0 0 18px; }
  .block h2 { font-size: 16px; color: var(--blue); border-bottom: 1px solid var(--line); padding-bottom: 5px; margin: 0 0 10px; }
  .subgroup { font-weight: 700; color: var(--ink); font-size: 13px; margin: 10px 0 3px; }
  .subgroup + ol.people { margin-top: 0; }
  .kv { width: 100%; border-collapse: collapse; }
  .kv th { text-align: left; width: 150px; vertical-align: top; color: var(--muted); font-weight: 600; padding: 3px 10px 3px 0; }
  .kv td { padding: 3px 0; }
  ol.people, ol.steps { margin: 0; padding-left: 22px; }
  ol.people li, ol.steps li { margin-bottom: 5px; }
  ol.people .role, ol.people .tag, ol.people .vertritt { font-size: 13px; }
  ol.people .role { color: var(--muted); }
  ol.people .role::before { content: " – "; }
  ol.people .tag { display: inline-block; background: var(--blue); color: #fff; border-radius: 4px; padding: 0 6px; margin-right: 6px; font-size: 11px; vertical-align: middle; }
  .vertritt { color: var(--blue); font-weight: 600; }
  ol.people .vertritt { display: block; }
  table.agenda { width: 100%; border-collapse: collapse; }
  table.agenda td { padding: 4px 0; border-bottom: 1px solid #eef1f6; vertical-align: top; }
  .agenda__time { white-space: nowrap; font-weight: 700; color: var(--blue); width: 120px; padding-right: 12px; }
  .agenda .note, .prizes .note { color: var(--muted); font-weight: 400; }
  ul.prizes, ul.plain { margin: 0; padding-left: 20px; }
  ul.prizes li, ul.plain li { margin-bottom: 5px; }
  .prizes .amount { color: var(--blue); font-weight: 700; margin-left: 4px; }
  .prizes .note { display: block; font-size: 13px; }
  .sponsors { display: flex; flex-wrap: wrap; gap: 16px 22px; align-items: center; }
  .sponsor img { height: 42px; max-width: 150px; object-fit: contain; filter: grayscale(.15); }
  .sponsor span { font-weight: 600; }
  .doc__foot { margin-top: 22px; border-top: 1px solid var(--line); padding-top: 10px; color: var(--muted); font-size: 12px; text-align: center; }

  @media print {
    body { background: #fff; }
    .toolbar { display: none; }
    .sheet { margin: 0; max-width: none; }
    .sign { border: 0; border-radius: 0; margin: 0; max-width: none; width: 100%; height: 100vh;
            aspect-ratio: auto; page-break-after: always; }
    .sign:last-child { page-break-after: auto; }
    .doc { border: 0; border-radius: 0; max-width: none; margin: 0; padding: 0; }
    .block, ol.people li, table.agenda tr, .sign { break-inside: avoid; }
  }
  <?php if ($kind === 'handout'): ?>
  /* Laufende Fußzeile: links kurz die Überschrift, rechts „Seite X / Y" –
     klein und anthrazit. Echte Seitenzähler über CSS Paged Media. */
  @page {
    size: A4;
    margin: 13mm 12mm 15mm;
    @bottom-left  { content: "<?= $cssFootTitle ?>"; font-size: 8.5pt; color: #3a3f47; }
    @bottom-right { content: "Seite " counter(page) " / " counter(pages); font-size: 8.5pt; color: #3a3f47; }
  }
  <?php else: ?>
  @page { size: A4; margin: 12mm; }
  <?php endif; ?>
</style>
</head>
<body>
  <div class="toolbar">
    <b><?= e($pageTitle) ?></b>
    <span class="hint"><?= $kind === 'signs'
      ? 'Ein Schild je Seite · beim Drucken „Als PDF speichern" wählen'
      : 'Beim Drucken „Als PDF speichern" wählen' ?></span>
    <span class="sp"></span>
    <a href="<?= e(url('event', ['cycle' => $cycleId, 'tab' => 'guests'])) ?>">← Zurück</a>
    <button type="button" class="primary" onclick="window.print()">🖨 Drucken / Als PDF speichern</button>
  </div>
  <div class="sheet"><?= $body ?></div>
  <script>
    // Direkt den Druck-/PDF-Dialog anbieten (nach dem Laden der Grafiken).
    window.addEventListener('load', function () { setTimeout(function () { window.print(); }, 350); });
  </script>
</body>
</html>
<?php
exit;
