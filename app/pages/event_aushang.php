<?php
/**
 * PitchDay-Aushänge & Urkunden (automatisch aus den App-Daten erzeugt).
 *
 * Bewusst – wie event_print.php – eine eigenständige HTML-Seite mit Druck-CSS:
 * der Browser erzeugt daraus per „Drucken → Als PDF speichern“ das fertige PDF,
 * ohne zusätzliche PDF-Bibliothek auf dem schlanken Shared-Hosting.
 *
 * Alle Farben stammen aus der offiziellen WJ-CI (siehe assets/css/app.css):
 *   Blau #003594 · Gelb #FFB81C · Türkis #47D7AC/#2fb992 · Rot #F9423A · Grau #A2AAAD.
 *
 *   ?kind=poster    – DIN-A3-Aushang „Pitch-Day“ (Ort aus den Event-Daten)
 *   ?kind=agenda    – DIN-A3-Aushang mit Agenda (aus dem gepflegten Ablaufplan)
 *   ?kind=wegpfeil  – DIN-A4-Wegpfeil (Richtung ?dir=up|down|left|right, ?label=…)
 *   ?kind=urkunden  – DIN-A4-Urkunden für ALLE nominierten Teams + Nachrücker.
 *                     Aus den Infos zusammengesetzt (Geschäftsidee, Teammitglieder,
 *                     Schule + Logo, Sponsoren, Pseudo-Unterschrift der
 *                     Projektleitung, PitchDay-Datum). Die Platzierung wird am
 *                     Veranstaltungstag per Hand ergänzt.
 */

declare(strict_types=1);

// Aushänge & Urkunden sind Orga-Material: nur die Verwaltung darf drucken.
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

$validKinds = ['poster', 'agenda', 'wegpfeil', 'urkunden'];
$kind = in_array((string) input('kind'), $validKinds, true) ? (string) input('kind') : 'poster';

// -------------------------------------------------------------------------
// Gemeinsame Daten & Helfer
// -------------------------------------------------------------------------
$dateFmt = fn(?string $d) => $d ? date('d.m.Y', strtotime($d)) : null;
$timeFmt = fn(?string $t) => $t ? substr($t, 0, 5) : null;
$wdays   = ['Sonntag', 'Montag', 'Dienstag', 'Mittwoch', 'Donnerstag', 'Freitag', 'Samstag'];
$weekday = fn(?string $d) => $d ? $wdays[(int) date('w', strtotime($d))] : null;

$yearLabel = (string) ($cycle['year_label'] ?? '');
$venue     = trim((string) ($event['venue'] ?? ''));
$eventDate = $event['event_date'] ?? null;
$timeFrom  = $event['time_from'] ?? null;

$dateLine = trim(implode(', ', array_filter([$weekday($eventDate), $dateFmt($eventDate)])));
$timeLine = $timeFrom ? 'ab ' . $timeFmt($timeFrom) . ' Uhr' : '';

$wjLogo = asset('img/wj/wj-forchheim.png'); // Wirtschaftsjunioren Forchheim
$upLogo = asset('img/logo.svg');            // Unternehmen-Plus-Bildmarke

// Sponsoren des Wettbewerbsjahres (wie im Handout: pro Zyklus, nicht pro Team).
$sponsors = Database::all(
    'SELECT s.name, s.logo_path FROM sponsor_contributions c JOIN sponsors s ON s.id = c.sponsor_id
     WHERE c.cycle_id = ? GROUP BY s.id, s.name, s.logo_path ORDER BY s.name',
    [$cycleId]
);

// Projektleitung (Rolle „lead“) – für die Pseudo-Unterschrift auf der Urkunde.
$leads = Database::all(
    'SELECT name, specialty FROM users u
     WHERE u.is_active = 1
       AND EXISTS (SELECT 1 FROM user_roles ur WHERE ur.user_id = u.id AND ur.role = "lead")
     ORDER BY name'
);

/** Sponsor-Logostreifen (grau, wie auf den Vorlagen). */
$sponsorStrip = function (array $sponsors): string {
    if (!$sponsors) { return ''; }
    ob_start(); ?>
    <div class="sponsors">
      <?php foreach ($sponsors as $s): ?>
        <?php if (!empty($s['logo_path'])): ?>
          <img src="<?= e(asset($s['logo_path'])) ?>" alt="<?= e($s['name']) ?>">
        <?php else: ?>
          <span class="sponsors__name"><?= e($s['name']) ?></span>
        <?php endif; ?>
      <?php endforeach; ?>
    </div>
    <?php
    return (string) ob_get_clean();
};

ob_start();

// =========================================================================
// 1) + 2)  A3-AUSHANG  (poster / agenda)
// =========================================================================
if ($kind === 'poster' || $kind === 'agenda'):
    $agenda = $kind === 'agenda'
        ? Database::all('SELECT * FROM event_agenda WHERE event_id=? ORDER BY sort_order, time_from, id', [$eventId])
        : [];
?>
  <section class="poster">
    <header class="poster__head">
      <img class="poster__wj" src="<?= e($wjLogo) ?>" alt="Wirtschaftsjunioren Forchheim">
      <img class="poster__up" src="<?= e($upLogo) ?>" alt="Unternehmen Plus">
    </header>

    <div class="poster__blue">
      <div class="poster__title">Pitch-Day</div>
      <?php if ($kind === 'agenda'): ?>
        <div class="poster__sub">Agenda</div>
      <?php else: ?>
        <div class="poster__sub">Großes Finale des<br>Businessplanwettbewerbs<br>UnternehmenPlus</div>
      <?php endif; ?>
    </div>

    <?php if ($kind === 'agenda'): ?>
      <div class="poster__yellow poster__yellow--agenda">
        <ul class="poster__agenda">
          <?php foreach ($agenda as $a):
            $tf = $timeFmt($a['time_from']); $tt = $timeFmt($a['time_to']);
            $time = $tf ? ($tt ? $tf . ' – ' . $tt : $tf) : '';
          ?>
            <li><?php if ($time !== ''): ?><span class="poster__agenda-time"><?= e($time) ?>:</span> <?php endif; ?><span class="poster__agenda-title"><?= e($a['title']) ?></span></li>
          <?php endforeach; ?>
          <?php if (!$agenda): ?><li>Noch kein Ablaufplan hinterlegt.</li><?php endif; ?>
        </ul>
      </div>
    <?php else: ?>
      <div class="poster__yellow">
        <div class="poster__venue"><?= $venue !== '' ? 'Veranstaltung in der ' . e($venue) : 'Veranstaltung im großen Finale' ?></div>
        <?php if ($dateLine !== '' || $timeLine !== ''): ?>
          <div class="poster__when"><?= e(trim($dateLine . ($timeLine ? ' · ' . $timeLine : ''))) ?></div>
        <?php endif; ?>
      </div>
    <?php endif; ?>

    <footer class="poster__foot">
      <span class="ribbon ribbon--l">SPONSOR</span>
      <?= $sponsorStrip($sponsors) ?>
      <span class="ribbon ribbon--r">SPONSOR</span>
    </footer>
  </section>
<?php

// =========================================================================
// 3)  A4-WEGPFEIL
// =========================================================================
elseif ($kind === 'wegpfeil'):
    $dir   = in_array((string) input('dir'), ['up', 'down', 'left', 'right'], true) ? (string) input('dir') : 'up';
    $label = trim((string) input('label', 'Pitch-Day'));
    $rot   = ['up' => 0, 'right' => 90, 'down' => 180, 'left' => 270][$dir];
?>
  <section class="pfeil">
    <div class="pfeil__circle">
      <svg viewBox="0 0 100 100" class="pfeil__arrow" style="transform:rotate(<?= $rot ?>deg)" aria-hidden="true">
        <path d="M50 12 L82 46 A5 5 0 0 1 74.5 53 L61 39 L61 84 A5 5 0 0 1 56 89 L44 89 A5 5 0 0 1 39 84 L39 39 L25.5 53 A5 5 0 0 1 18 46 Z"
              fill="#fff" stroke="#fff" stroke-width="1" stroke-linejoin="round"/>
      </svg>
    </div>
    <?php if ($label !== ''): ?><div class="pfeil__label"><?= e($label) ?></div><?php endif; ?>
  </section>
<?php

// =========================================================================
// 4)  A4-URKUNDEN  (alle nominierten Teams + Nachrücker)
// =========================================================================
else:
    $teams = Database::all(
        "SELECT t.id, t.name, t.idea_name, t.status, t.pitch_order,
                s.name AS school_name, s.short_name AS school_short, s.logo_path AS school_logo
         FROM teams t JOIN schools s ON s.id = t.school_id
         WHERE t.status IN ('nominated','fallback')
         ORDER BY FIELD(t.status,'nominated','fallback'), t.pitch_order IS NULL, t.pitch_order, s.short_name, t.name"
    );
    // Teammitglieder je Team.
    $members = [];
    if ($teams) {
        $ids = array_map(static fn($t) => (int) $t['id'], $teams);
        $in  = implode(',', array_fill(0, count($ids), '?'));
        foreach (Database::all("SELECT team_id, name FROM students WHERE team_id IN ($in) ORDER BY name", $ids) as $st) {
            $members[(int) $st['team_id']][] = $st['name'];
        }
    }

    $venueLine = trim(implode(' · ', array_filter([
        $dateLine !== '' ? $dateLine : null,
        $venue !== '' ? $venue : null,
    ])));
?>
  <?php if (!$teams): ?>
    <div class="empty">
      <h1>Noch keine Teams für Urkunden</h1>
      <p>Urkunden werden für alle <strong>nominierten</strong> Teams und die <strong>Nachrücker</strong> erzeugt.
         Bitte zuerst im Bereich „Teams“ bzw. „PitchDay-Nominierung“ die Teams auf den Status
         <em>Pitch nominiert</em> bzw. <em>Nachrücker</em> setzen.</p>
    </div>
  <?php else: ?>
    <?php foreach ($teams as $t):
      $idea    = trim((string) ($t['idea_name'] ?? ''));
      $teamNm  = trim((string) ($t['name'] ?? ''));
      $mem     = $members[(int) $t['id']] ?? [];
      $isSub   = $t['status'] === 'fallback';
    ?>
      <article class="urk">
        <div class="urk__frame urk__frame--tr"></div>
        <div class="urk__frame urk__frame--bl"></div>

        <div class="urk__inner">
          <div class="urk__brand">
            <div class="w3">
              <span class="w3__mark"><span class="w3__sq">w<sup>3</sup></span></span>
            </div>
            <div class="w3__wordmark">
              <div class="w3__line1">Wirtschaftswissen</div>
              <div class="w3__line2">im Wettbewerb</div>
            </div>
          </div>

          <h1 class="urk__title">Urkunde</h1>
          <div class="urk__event">Businessplanwettbewerb Unternehmen&nbsp;Plus<?= $yearLabel !== '' ? '<br><span>Schuljahr ' . e($yearLabel) . '</span>' : '' ?></div>

          <div class="urk__rank">
            <span class="urk__rank-blank"></span><span class="urk__rank-word">. Platz</span>
            <?php if ($isSub): ?><div class="urk__rank-note">Nachrücker</div><?php endif; ?>
          </div>

          <div class="urk__awardline">verliehen an das Team</div>
          <div class="urk__team"><?= e($idea !== '' ? $idea : $teamNm) ?></div>
          <?php if ($idea !== '' && $teamNm !== '' && $idea !== $teamNm): ?>
            <div class="urk__teamname"><?= e($teamNm) ?></div>
          <?php endif; ?>
          <?php if ($mem): ?><div class="urk__members"><?= e(implode(' · ', $mem)) ?></div><?php endif; ?>

          <div class="urk__school">
            <?php if (!empty($t['school_logo'])): ?>
              <img src="<?= e(asset($t['school_logo'])) ?>" alt="<?= e((string) $t['school_name']) ?>">
            <?php endif; ?>
            <span><?= e((string) ($t['school_name'] ?: $t['school_short'])) ?></span>
          </div>

          <?php if ($venueLine !== ''): ?>
            <div class="urk__date">PitchDay am <?= e($venueLine) ?></div>
          <?php endif; ?>

          <?php if ($leads): ?>
            <div class="urk__sigs">
              <?php foreach ($leads as $l): ?>
                <div class="urk__sig">
                  <div class="urk__sig-name"><?= e((string) $l['name']) ?></div>
                  <div class="urk__sig-line"></div>
                  <div class="urk__sig-role"><?= e((string) $l['name']) ?><br>Projektleitung Unternehmen&nbsp;Plus</div>
                </div>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>

          <?php if ($sponsors): ?>
            <div class="urk__sponsors">
              <div class="urk__sponsors-cap">Mit freundlicher Unterstützung</div>
              <?= $sponsorStrip($sponsors) ?>
            </div>
          <?php endif; ?>
        </div>
      </article>
    <?php endforeach; ?>
  <?php endif; ?>
<?php
endif;
$body = ob_get_clean();

$titles = [
    'poster'   => 'PitchDay-Aushang (A3)',
    'agenda'   => 'PitchDay-Aushang mit Agenda (A3)',
    'wegpfeil' => 'PitchDay-Wegpfeil (A4)',
    'urkunden' => 'PitchDay-Urkunden (A4)',
];
$pageTitle = $titles[$kind];
$pageSize  = ($kind === 'poster' || $kind === 'agenda') ? 'A3 portrait' : 'A4 portrait';

header('Content-Type: text/html; charset=utf-8');
?>
<!doctype html>
<html lang="de">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($pageTitle) ?> – Unternehmen Plus</title>
<style>
  :root {
    --blue:#003594; --blue-d:#012a76; --yellow:#FFB81C; --teal:#47D7AC; --teal-d:#2fb992;
    --red:#F9423A; --grey:#A2AAAD; --ink:#1c2430; --muted:#5b6472; --line:#d8dee9;
  }
  * { box-sizing: border-box; }
  html, body { margin: 0; padding: 0; }
  body { font-family: "Segoe UI", system-ui, -apple-system, Roboto, Helvetica, Arial, sans-serif;
         color: var(--ink); background: #eef1f6; line-height: 1.4; }
  /* Farben zuverlässig mitdrucken. */
  .poster, .poster *, .pfeil, .pfeil *, .urk, .urk * {
    -webkit-print-color-adjust: exact; print-color-adjust: exact;
  }

  /* Bildschirm: Werkzeugleiste + Papier-Optik */
  .toolbar { position: sticky; top: 0; z-index: 10; display: flex; gap: 10px; align-items: center;
             background: var(--blue); color: #fff; padding: 10px 16px; flex-wrap: wrap; }
  .toolbar b { font-weight: 700; }
  .toolbar .sp { flex: 1 1 auto; }
  .toolbar button, .toolbar a, .toolbar select, .toolbar input {
             font: inherit; border: 0; border-radius: 8px; padding: 8px 14px; cursor: pointer;
             text-decoration: none; background: rgba(255,255,255,.15); color: #fff; }
  .toolbar select option { color: #111; }
  .toolbar input { cursor: text; background: rgba(255,255,255,.9); color: #111; }
  .toolbar button.primary { background: #fff; color: var(--blue); font-weight: 700; }
  .toolbar .grp { display: flex; gap: 6px; align-items: center; }
  .hint { color: #cdd9f2; font-size: 13px; }

  .sheet { max-width: 900px; margin: 18px auto; }

  /* ===================== A3-Aushang (poster / agenda) ===================== */
  .poster { position: relative; container-type: size; width: 100%; max-width: 720px; margin: 0 auto; background: #fff;
            aspect-ratio: 297 / 420; border: 1px solid var(--line);
            display: flex; flex-direction: column; overflow: hidden; }
  .poster__head { display: flex; align-items: center; justify-content: space-between;
                  padding: 4.5% 6% 3%; gap: 4%; }
  .poster__wj { height: 6cqh; width: auto; }
  .poster__up { height: 8cqh; width: auto; }
  .poster__blue { background: var(--blue); color: #fff; text-align: center; flex: 1 1 auto;
                  display: flex; flex-direction: column; align-items: center; justify-content: center;
                  gap: 4%; padding: 5% 7%; }
  .poster__title { font-weight: 900; line-height: .95; letter-spacing: -.02em; font-size: 14cqw; white-space: nowrap; }
  .poster__sub { font-weight: 600; font-size: 4.6cqw; line-height: 1.25; }
  .poster__yellow { background: var(--yellow); color: var(--blue); text-align: center;
                    display: flex; flex-direction: column; align-items: center; justify-content: center;
                    gap: 1.5%; padding: 4% 7%; min-height: 22%; }
  .poster__venue { font-weight: 900; font-size: 6.4cqw; line-height: 1.05; }
  .poster__when { font-weight: 700; font-size: 3.1cqw; color: var(--blue-d); }
  .poster__yellow--agenda { text-align: left; align-items: stretch; min-height: 30%; }
  .poster__agenda { list-style: none; margin: 0; padding: 0; width: 100%; }
  .poster__agenda li { display: flex; gap: .5em; align-items: baseline; padding: 1.15% 0;
                       font-size: 2.7cqw; line-height: 1.25; border-bottom: 1px solid rgba(0,53,148,.18); }
  .poster__agenda li:last-child { border-bottom: 0; }
  .poster__agenda-time { font-weight: 900; white-space: nowrap; }
  .poster__agenda-title { font-weight: 600; }
  .poster__foot { position: relative; display: flex; align-items: center; justify-content: center;
                  padding: 3% 9%; min-height: 12%; }
  .sponsors { display: flex; flex-wrap: wrap; align-items: center; justify-content: center;
              gap: 3% 4%; width: 100%; }
  .sponsors img { height: 4.4cqw; max-height: 40px; max-width: 20%; object-fit: contain; filter: grayscale(1); opacity: .78; }
  .sponsors__name { font-weight: 700; color: var(--muted); font-size: 2.4cqw; }
  .ribbon { position: absolute; top: 0; bottom: 0; width: 6%; background: var(--red); color: #fff;
            font-weight: 900; letter-spacing: .08em; display: flex; align-items: center; justify-content: center;
            writing-mode: vertical-rl; text-orientation: mixed; transform: rotate(180deg); font-size: 2.2cqw;
            clip-path: polygon(0 0, 100% 0, 100% 100%, 50% 88%, 0 100%); }
  .ribbon--l { left: 0; }
  .ribbon--r { right: 0; }

  /* ===================== A4-Wegpfeil ===================== */
  .pfeil { container-type: size; width: 100%; max-width: 620px; margin: 0 auto; background: #fff; aspect-ratio: 210 / 297;
           border: 1px solid var(--line); display: flex; flex-direction: column;
           align-items: center; justify-content: center; gap: 4%; padding: 8%; }
  .pfeil__circle { width: 74%; aspect-ratio: 1; border-radius: 50%; background: var(--yellow);
                   display: flex; align-items: center; justify-content: center; }
  .pfeil__arrow { width: 66%; height: 66%; display: block; }
  .pfeil__label { font-weight: 900; color: var(--blue); font-size: 8cqw; line-height: 1; text-align: center; }

  /* ===================== A4-Urkunde ===================== */
  .urk { position: relative; container-type: size; width: 100%; max-width: 620px; margin: 0 auto 18px; background: #fff;
         aspect-ratio: 210 / 297; border: 1px solid var(--line); overflow: hidden; }
  .urk__frame { position: absolute; pointer-events: none; }
  .urk__frame--tr { top: 3.2%; right: 3.2%; width: 82%; height: 40%;
                    border-top: 10px solid var(--teal); border-right: 10px solid var(--teal); }
  .urk__frame--bl { bottom: 3.2%; left: 3.2%; width: 82%; height: 62%;
                    border-bottom: 10px solid var(--teal); border-left: 10px solid var(--teal); }
  .urk__inner { position: relative; z-index: 1; height: 100%; padding: 8% 10%;
                display: flex; flex-direction: column; align-items: center; text-align: center; }
  .urk__brand { display: flex; align-items: center; gap: 3%; margin-bottom: 1.5%; }
  .w3__mark { position: relative; display: inline-block; width: 12cqw; max-width: 62px; aspect-ratio: 1; }
  .w3__mark::before { content: ""; position: absolute; inset: 0; background: var(--teal-d);
                      transform: translate(11%, 11%); }
  .w3__sq { position: absolute; inset: 0; background: var(--blue); color: var(--yellow);
            display: flex; align-items: center; justify-content: center; font-weight: 900;
            font-size: 6.6cqw; line-height: 1; }
  .w3__sq sup { font-size: .55em; }
  .w3__wordmark { text-align: left; color: var(--blue); }
  .w3__line1 { font-weight: 800; letter-spacing: .06em; text-transform: uppercase; font-size: 3.1cqw; }
  .w3__line2 { font-weight: 500; letter-spacing: .34em; text-transform: uppercase; font-size: 2cqw; }
  .urk__title { color: var(--blue); font-weight: 900; text-transform: uppercase; letter-spacing: .04em;
                font-size: 12cqw; line-height: 1; margin: 2% 0 1%; }
  .urk__event { color: var(--blue); font-weight: 600; font-size: 3cqw; line-height: 1.35; }
  .urk__event span { color: var(--muted); font-weight: 500; }
  .urk__rank { margin: 4% 0 2%; color: var(--ink); font-weight: 900; font-size: 8.5cqw; line-height: 1;
               display: flex; align-items: flex-end; justify-content: center; gap: .12em; }
  .urk__rank-blank { display: inline-block; width: 1.4em; border-bottom: 4px solid var(--grey); }
  .urk__rank-note { position: absolute; }
  .urk__rank { position: relative; flex-wrap: wrap; }
  .urk__rank-note { position: static; width: 100%; font-size: .34em; font-weight: 700; color: var(--teal-d);
                    letter-spacing: .12em; text-transform: uppercase; margin-top: .3em; }
  .urk__awardline { color: var(--muted); font-size: 2.8cqw; }
  .urk__team { color: var(--blue); font-weight: 800; font-size: 6cqw; line-height: 1.1; margin: 1% 0; }
  .urk__teamname { color: var(--muted); font-weight: 600; font-size: 3cqw; }
  .urk__members { color: var(--ink); font-size: 2.9cqw; margin-top: 1.5%; line-height: 1.4; }
  .urk__school { display: flex; align-items: center; justify-content: center; gap: 2.5%; margin: 3% 0 1%; }
  .urk__school img { height: 9cqw; max-height: 54px; max-width: 40%; object-fit: contain; }
  .urk__school span { color: var(--ink); font-weight: 700; font-size: 3cqw; }
  .urk__date { color: var(--muted); font-size: 2.7cqw; margin-top: 1%; }
  .urk__sigs { display: flex; justify-content: center; gap: 8%; margin-top: auto; padding-top: 4%; width: 100%; }
  .urk__sig { flex: 0 1 46%; }
  .urk__sig-name { font-family: "Segoe Script", "Bradley Hand", "Brush Script MT", "Snell Roundhand", cursive;
                   color: var(--blue-d); font-size: 5cqw; line-height: 1; padding-bottom: 2%; }
  .urk__sig-line { border-top: 1.5px solid var(--ink); }
  .urk__sig-role { color: var(--muted); font-size: 2.2cqw; line-height: 1.35; padding-top: 2%; }
  .urk__sponsors { width: 100%; margin-top: 4%; }
  .urk__sponsors-cap { color: var(--grey); font-size: 2.1cqw; letter-spacing: .1em; text-transform: uppercase;
                       margin-bottom: 1.5%; }
  .urk__sponsors .sponsors img { height: 4.6cqw; max-height: 30px; max-width: 15%; }

  .empty { background:#fff; max-width: 720px; margin: 40px auto; padding: 40px; border-radius: 8px; text-align: center; }
  .empty h1 { color: var(--blue); }

  /* ===================== Druck ===================== */
  @media print {
    body { background: #fff; }
    .toolbar { display: none; }
    .sheet { margin: 0; max-width: none; }
    .poster, .pfeil, .urk { border: 0; margin: 0; max-width: none; width: 100%; height: 100vh;
                            aspect-ratio: auto; page-break-after: always; break-inside: avoid; }
    .urk:last-child, .poster:last-child, .pfeil:last-child { page-break-after: auto; }
    /* Beim Druck skalieren die vw-Größen auf die A3-Breite (297 mm ≈ 1122 px)
       bzw. A4-Breite – die Seiten füllen die volle Papierbreite, daher passt vw. */
  }
  @page { size: <?= $pageSize ?>; margin: 0; }
</style>
</head>
<body>
  <div class="toolbar">
    <b><?= e($pageTitle) ?></b>
    <?php if ($kind === 'wegpfeil'): ?>
      <?php $curDir = in_array((string) input('dir'), ['up','down','left','right'], true) ? (string) input('dir') : 'up';
            $curLabel = trim((string) input('label', 'Pitch-Day')); ?>
      <form class="grp" method="get" action="<?= url('event_aushang') ?>">
        <input type="hidden" name="r" value="event_aushang">
        <input type="hidden" name="cycle" value="<?= $cycleId ?>">
        <input type="hidden" name="kind" value="wegpfeil">
        <select name="dir" onchange="this.form.submit()">
          <?php foreach (['up'=>'↑ nach oben','right'=>'→ nach rechts','down'=>'↓ nach unten','left'=>'← nach links'] as $k=>$v): ?>
            <option value="<?= $k ?>"<?= $curDir === $k ? ' selected' : '' ?>><?= e($v) ?></option>
          <?php endforeach; ?>
        </select>
        <input type="text" name="label" value="<?= e($curLabel) ?>" placeholder="Beschriftung" size="12">
        <button type="submit">Übernehmen</button>
      </form>
    <?php endif; ?>
    <span class="hint"><?php
      echo $kind === 'urkunden'
        ? 'Je Team eine Seite · beim Drucken „Als PDF speichern“ · A4 wählen'
        : ($kind === 'wegpfeil'
          ? 'Richtung wählen, dann drucken · A4'
          : 'Beim Drucken „Als PDF speichern“ · Papierformat A3 wählen'); ?></span>
    <span class="sp"></span>
    <a href="<?= e(url('event', ['cycle' => $cycleId, 'tab' => 'aushang'])) ?>">← Zurück</a>
    <button type="button" class="primary" onclick="window.print()">🖨 Drucken / Als PDF speichern</button>
  </div>
  <div class="sheet"><?= $body ?></div>
  <script>
    // Direkt den Druck-/PDF-Dialog anbieten (nach dem Laden der Grafiken).
    window.addEventListener('load', function () { setTimeout(function () { window.print(); }, 400); });
  </script>
</body>
</html>
<?php
exit;
