<?php
/**
 * Projektpräsentation „Unternehmen Plus" – Druck-/PDF-Ansicht (DIN A4 quer).
 *
 * Eigenständige, layout-freie HTML-Seite mit Druck-CSS: eine Folie je Seite.
 * Der Browser erzeugt daraus per „Drucken → Als PDF speichern" das fertige
 * PDF – ohne zusätzliche PDF-Bibliothek auf dem schlanken Shared-Hosting
 * (gleiches Vorgehen wie die PitchDay-Handouts, siehe event_print.php).
 *
 * Die Folien teilen sich die Optik mit der Bildschirmansicht über
 * assets/css/presentation.css und Presentation::renderSlide().
 */

declare(strict_types=1);

Access::requireRead('presentation');

$cycles  = Cycle::all();
$cycleId = (int) input('cycle', 0);
if ($cycleId <= 0 || Cycle::find($cycleId) === null) {
    $cycleId = Cycle::activeId();
}
if (!$cycleId) {
    http_response_code(404);
    exit('Noch kein Wettbewerbsjahr angelegt.');
}

$ctx  = Presentation::context($cycleId);
$year = $ctx['year_label'];

$slides = [];
foreach (Presentation::SLIDES as $def) {
    $editable = Presentation::isEditable($def['type']);
    $text = $editable ? Presentation::text($def['key'], $cycleId)
                      : ['title' => $def['title'], 'subtitle' => '', 'body' => ''];
    $slides[] = Presentation::renderSlide($def, $ctx, $text);
}

$pageTitle = 'Präsentation Unternehmen Plus' . ($year !== '' ? ' ' . $year : '');
header('Content-Type: text/html; charset=utf-8');
?>
<!doctype html>
<html lang="de">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($pageTitle) ?></title>
<link href="https://fonts.googleapis.com/css2?family=Chivo:wght@400;700;900&family=Bitter:wght@400;500;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= asset('css/presentation.css') ?>">
<style>
  * { box-sizing: border-box; }
  html, body { margin: 0; padding: 0; }
  body { font-family: "Bitter", Georgia, serif; background: #eef1f6; }

  .toolbar { position: sticky; top: 0; z-index: 10; display: flex; gap: 10px; align-items: center;
    background: #003594; color: #fff; padding: 10px 16px; flex-wrap: wrap; }
  .toolbar b { font-weight: 700; }
  .toolbar .sp { flex: 1 1 auto; }
  .toolbar a, .toolbar button { font: inherit; border: 0; border-radius: 8px; padding: 8px 14px; cursor: pointer;
    text-decoration: none; background: rgba(255,255,255,.15); color: #fff; font-family: system-ui, sans-serif; }
  .toolbar button.primary { background: #fff; color: #003594; font-weight: 700; }
  .toolbar .hint { color: #cdd9f2; font-size: 13px; font-family: system-ui, sans-serif; }

  .ps-deck { max-width: 1000px; margin: 18px auto; padding: 0 16px; }
  /* Auf dem Bildschirm die Folien untereinander mit Abstand zeigen. */
  .ps-deck .ps-slide { margin: 0 auto 18px; }

  @media print {
    body { background: #fff; }
    .toolbar { display: none; }
    .ps-deck { max-width: none; margin: 0; padding: 0; }
    @page { size: A4 landscape; margin: 0; }
    /* Eine Folie exakt auf eine Querseite; Rahmen/Schatten fürs Papier entfernen. */
    .ps-deck .ps-slide { margin: 0; width: 100%; height: 100vh; border: 0; border-radius: 0;
      box-shadow: none; aspect-ratio: auto; page-break-after: always; break-after: page; }
    .ps-deck .ps-slide:last-child { page-break-after: auto; break-after: auto; }
  }
</style>
</head>
<body>
  <div class="toolbar">
    <b><?= e($pageTitle) ?></b>
    <span class="hint">Eine Folie je Seite (A4 quer) · beim Drucken „Als PDF speichern" wählen · Hintergrundgrafiken aktivieren</span>
    <span class="sp"></span>
    <a href="<?= e(url('presentation', ['cycle' => $cycleId])) ?>">← Zurück</a>
    <button type="button" class="primary" onclick="window.print()">🖨 Drucken / Als PDF speichern</button>
  </div>
  <div class="ps-deck">
    <?php foreach ($slides as $html): ?>
      <div class="ps-slide"><?= $html ?></div>
    <?php endforeach; ?>
  </div>
  <script>
    window.addEventListener('load', function () { setTimeout(function () { window.print(); }, 400); });
  </script>
</body>
</html>
<?php
exit;
