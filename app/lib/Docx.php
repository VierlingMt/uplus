<?php
/**
 * Minimaler DOCX-Generator (ohne externe Bibliotheken).
 *
 * Ein .docx ist ein ZIP aus XML-Teilen (Open XML). Diese Klasse erzeugt ein
 * schlankes, gut lesbares Dokument aus Überschrift, Fließtext-Absätzen und
 * eingebetteten Bildern (je mit Bildunterschrift und Fotograf-Angabe) – gedacht
 * für die Pressemitteilung samt Bildanhang.
 *
 * Bilder werden über GD nach JPEG konvertiert (maximale Kompatibilität, auch aus
 * WEBP/PNG) und als Inline-Grafik eingebettet.
 */

declare(strict_types=1);

final class Docx
{
    /** EMU je Pixel bei 96 dpi (Open-XML-Maßeinheit). */
    private const EMU_PER_PX = 9525;
    /** Maximale Bildbreite im Dokument (~15,5 cm Textbreite). */
    private const MAX_IMG_EMU = 5580000;

    /**
     * DOCX erzeugen.
     *
     * @param string $path     Zielpfad (.docx)
     * @param string $title    Dokument-Überschrift
     * @param string $body     Fließtext (Absätze durch Leerzeile getrennt)
     * @param array  $images   Liste [['path'=>.., 'caption'=>.., 'photographer'=>..], …]
     * @param string $appendixHeading Überschrift des Bildanhangs (leer = kein Titel)
     */
    public static function create(
        string $path,
        string $title,
        string $body,
        array $images = [],
        string $appendixHeading = 'Bildanhang'
    ): bool {
        if (!class_exists('ZipArchive')) {
            return false;
        }

        // 1) Bilder vorbereiten (nach JPEG konvertieren, Maße bestimmen).
        $media = [];   // ['data'=>jpegBytes, 'cx'=>emu, 'cy'=>emu, 'caption'=>, 'photographer'=>]
        foreach ($images as $img) {
            $prep = self::prepareImage((string) ($img['path'] ?? ''));
            if ($prep === null) {
                // Bild nicht verwertbar – Unterschrift trotzdem als Text behalten.
                $media[] = ['data' => null,
                            'caption' => trim((string) ($img['caption'] ?? '')),
                            'photographer' => trim((string) ($img['photographer'] ?? ''))];
                continue;
            }
            $prep['caption']      = trim((string) ($img['caption'] ?? ''));
            $prep['photographer'] = trim((string) ($img['photographer'] ?? ''));
            $media[] = $prep;
        }

        // 2) document.xml zusammenbauen.
        $bodyXml = '';

        // Überschrift.
        if (trim($title) !== '') {
            $bodyXml .= self::para(self::run(self::esc($title), ['b' => true, 'sz' => 32]));
        }

        // Fließtext: Absätze an Leerzeilen, Zeilenumbrüche innerhalb erhalten.
        foreach (preg_split('/\R{2,}/', trim($body)) ?: [] as $paragraph) {
            $paragraph = rtrim($paragraph);
            if ($paragraph === '') {
                continue;
            }
            $lines = preg_split('/\R/', $paragraph) ?: [$paragraph];
            $runs = '';
            foreach ($lines as $i => $line) {
                if ($i > 0) {
                    $runs .= '<w:r><w:br/></w:r>';
                }
                $runs .= self::run(self::esc($line));
            }
            $bodyXml .= self::para($runs);
        }

        // 3) Bildanhang.
        $rels  = '';
        $imgNo = 0;
        $hasRealImg = (bool) array_filter($media, static fn($m) => $m['data'] !== null);
        if ($media && ($hasRealImg || array_filter($media, static fn($m) => $m['caption'] !== '' || $m['photographer'] !== ''))) {
            $bodyXml .= self::para(''); // Abstand
            if (trim($appendixHeading) !== '') {
                $bodyXml .= self::para(self::run(self::esc($appendixHeading), ['b' => true, 'sz' => 26]));
            }
            foreach ($media as $m) {
                if ($m['data'] !== null) {
                    $imgNo++;
                    $rid = 'rIdImg' . $imgNo;
                    $bodyXml .= self::imageParagraph($rid, $imgNo, $m['cx'], $m['cy']);
                    $rels .= '<Relationship Id="' . $rid . '" '
                           . 'Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/image" '
                           . 'Target="media/image' . $imgNo . '.jpeg"/>';
                }
                if ($m['caption'] !== '') {
                    $bodyXml .= self::para(self::run(self::esc($m['caption']), ['i' => true, 'sz' => 18]));
                }
                if ($m['photographer'] !== '') {
                    $bodyXml .= self::para(self::run(self::esc('Foto: ' . $m['photographer']), ['i' => true, 'sz' => 16, 'color' => '666666']));
                }
            }
        }

        $documentXml = self::documentShell($bodyXml);

        // 4) ZIP schreiben.
        $zip = new ZipArchive();
        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            return false;
        }
        $zip->addFromString('[Content_Types].xml', self::contentTypes());
        $zip->addFromString('_rels/.rels', self::rootRels());
        $zip->addFromString('word/document.xml', $documentXml);
        $zip->addFromString('word/_rels/document.xml.rels', self::documentRels($rels));
        $n = 0;
        foreach ($media as $m) {
            if ($m['data'] !== null) {
                $n++;
                $zip->addFromString('word/media/image' . $n . '.jpeg', $m['data']);
            }
        }
        return $zip->close();
    }

    /** Bild laden, nach JPEG konvertieren, skalieren und Maße (EMU) liefern. */
    private static function prepareImage(string $srcPath): ?array
    {
        if ($srcPath === '' || !is_file($srcPath) || !extension_loaded('gd')) {
            return null;
        }
        $info = @getimagesize($srcPath);
        if ($info === false) {
            return null;
        }
        [$w, $h] = $info;
        if ($w < 1 || $h < 1) {
            return null;
        }
        $img = match ($info[2]) {
            IMAGETYPE_JPEG => @imagecreatefromjpeg($srcPath),
            IMAGETYPE_PNG  => @imagecreatefrompng($srcPath),
            IMAGETYPE_GIF  => @imagecreatefromgif($srcPath),
            IMAGETYPE_WEBP => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($srcPath) : false,
            default        => false,
        };
        if (!$img) {
            return null;
        }
        // EXIF-Ausrichtung (nur JPEG) korrigieren.
        if ($info[2] === IMAGETYPE_JPEG && function_exists('exif_read_data')) {
            $ex = @exif_read_data($srcPath);
            $angle = match ((int) ($ex['Orientation'] ?? 0)) { 3 => 180, 6 => -90, 8 => 90, default => 0 };
            if ($angle !== 0 && function_exists('imagerotate')) {
                $rot = imagerotate($img, $angle, 0);
                if ($rot !== false) { imagedestroy($img); $img = $rot; }
            }
        }
        // Weißer Hintergrund (Transparenz plätten) + moderate Größe.
        $rw = imagesx($img);
        $rh = imagesy($img);
        $canvas = imagecreatetruecolor($rw, $rh);
        imagefill($canvas, 0, 0, imagecolorallocate($canvas, 255, 255, 255));
        imagecopy($canvas, $img, 0, 0, 0, 0, $rw, $rh);
        imagedestroy($img);

        ob_start();
        $ok = imagejpeg($canvas, null, 85);
        $data = (string) ob_get_clean();
        imagedestroy($canvas);
        if (!$ok || $data === '') {
            return null;
        }

        // Maße in EMU, Breite auf MAX begrenzen.
        $cx = $rw * self::EMU_PER_PX;
        $cy = $rh * self::EMU_PER_PX;
        if ($cx > self::MAX_IMG_EMU) {
            $cy = (int) round($cy * (self::MAX_IMG_EMU / $cx));
            $cx = self::MAX_IMG_EMU;
        }
        return ['data' => $data, 'cx' => (int) $cx, 'cy' => (int) $cy];
    }

    // --- XML-Bausteine ---------------------------------------------------

    private static function esc(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }

    /** Ein Text-Run mit optionaler Formatierung. */
    private static function run(string $escapedText, array $opts = []): string
    {
        $rpr = '';
        if (!empty($opts['b']))     { $rpr .= '<w:b/>'; }
        if (!empty($opts['i']))     { $rpr .= '<w:i/>'; }
        if (!empty($opts['color'])) { $rpr .= '<w:color w:val="' . $opts['color'] . '"/>'; }
        if (!empty($opts['sz']))    { $rpr .= '<w:sz w:val="' . (int) $opts['sz'] . '"/>'; }
        $rprXml = $rpr !== '' ? '<w:rPr>' . $rpr . '</w:rPr>' : '';
        return '<w:r>' . $rprXml . '<w:t xml:space="preserve">' . $escapedText . '</w:t></w:r>';
    }

    private static function para(string $innerRuns): string
    {
        return '<w:p>' . $innerRuns . '</w:p>';
    }

    /** Absatz mit einer eingebetteten Inline-Grafik. */
    private static function imageParagraph(string $rid, int $id, int $cx, int $cy): string
    {
        return '<w:p><w:r><w:drawing>'
            . '<wp:inline distT="0" distB="0" distL="0" distR="0">'
            . '<wp:extent cx="' . $cx . '" cy="' . $cy . '"/>'
            . '<wp:effectExtent l="0" t="0" r="0" b="0"/>'
            . '<wp:docPr id="' . $id . '" name="Bild' . $id . '"/>'
            . '<wp:cNvGraphicFramePr><a:graphicFrameLocks xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main" noChangeAspect="1"/></wp:cNvGraphicFramePr>'
            . '<a:graphic xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main">'
            . '<a:graphicData uri="http://schemas.openxmlformats.org/drawingml/2006/picture">'
            . '<pic:pic xmlns:pic="http://schemas.openxmlformats.org/drawingml/2006/picture">'
            . '<pic:nvPicPr><pic:cNvPr id="' . $id . '" name="Bild' . $id . '"/><pic:cNvPicPr/></pic:nvPicPr>'
            . '<pic:blipFill><a:blip r:embed="' . $rid . '"/><a:stretch><a:fillRect/></a:stretch></pic:blipFill>'
            . '<pic:spPr><a:xfrm><a:off x="0" y="0"/><a:ext cx="' . $cx . '" cy="' . $cy . '"/></a:xfrm>'
            . '<a:prstGeom prst="rect"><a:avLst/></a:prstGeom></pic:spPr>'
            . '</pic:pic></a:graphicData></a:graphic></wp:inline>'
            . '</w:drawing></w:r></w:p>';
    }

    private static function documentShell(string $bodyXml): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<w:document '
            . 'xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main" '
            . 'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships" '
            . 'xmlns:wp="http://schemas.openxmlformats.org/drawingml/2006/wordprocessingDrawing" '
            . 'xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main" '
            . 'xmlns:pic="http://schemas.openxmlformats.org/drawingml/2006/picture">'
            . '<w:body>' . $bodyXml
            . '<w:sectPr><w:pgSz w:w="11906" w:h="16838"/>'
            . '<w:pgMar w:top="1417" w:right="1417" w:bottom="1417" w:left="1417" w:header="708" w:footer="708" w:gutter="0"/>'
            . '</w:sectPr></w:body></w:document>';
    }

    private static function contentTypes(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Default Extension="jpeg" ContentType="image/jpeg"/>'
            . '<Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/>'
            . '</Types>';
    }

    private static function rootRels(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/>'
            . '</Relationships>';
    }

    private static function documentRels(string $inner): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . $inner . '</Relationships>';
    }
}
