<?php
/**
 * Globale Hilfsfunktionen: Escaping, URLs, Redirects, Views, Flash, CSRF.
 */

declare(strict_types=1);

/** HTML-Escape. */
function e(?string $s): string
{
    return htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** Interne URL zu einer Route bauen: url('teams', ['id'=>5]) -> index.php?r=teams&id=5 */
function url(string $route = 'dashboard', array $params = []): string
{
    $base = rtrim(cfg('base_path', ''), '/');
    $qs = http_build_query(['r' => $route] + $params);
    return ($base ?: '') . '/index.php?' . $qs;
}

/**
 * Basis-URL (Schema + Host) fuer absolute Links, z. B. in E-Mails.
 * Bevorzugt cfg('app_url'); faellt sonst auf den aktuellen Request zurueck.
 * Der Pfad (base_path) wird von url() ergaenzt – app_url daher nur Schema+Host.
 */
function base_url(): string
{
    $configured = trim((string) cfg('app_url', ''));
    if ($configured !== '') {
        return rtrim($configured, '/');
    }
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    $host = (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');
    return ($https ? 'https' : 'http') . '://' . $host;
}

/** Absolute URL zu einer Route (fuer E-Mails o. Ae.). */
function abs_url(string $route = 'dashboard', array $params = []): string
{
    return base_url() . url($route, $params);
}

/** Asset-URL (CSS/JS/Bilder) mit Versions-Cache-Busting. */
function asset(string $path): string
{
    $base = rtrim(cfg('base_path', ''), '/');
    $url = ($base ?: '') . '/assets/' . ltrim($path, '/');
    $ver = defined('APP_VERSION') ? APP_VERSION : '';
    if ($ver !== '') {
        $url .= (strpos($url, '?') === false ? '?' : '&') . 'v=' . rawurlencode($ver);
    }
    return $url;
}

function redirect(string $to): void
{
    header('Location: ' . $to);
    exit;
}

/** View rendern (in Layout eingebettet, sofern nicht $bare). */
function render(string $view, array $data = [], bool $bare = false): void
{
    extract($data, EXTR_SKIP);
    $__viewFile = APP_PATH . '/pages/' . $view . '.php';
    if (!is_file($__viewFile)) {
        http_response_code(500);
        echo 'View nicht gefunden: ' . e($view);
        return;
    }
    ob_start();
    require $__viewFile;
    $content = ob_get_clean();
    if ($bare) {
        echo $content;
        return;
    }
    require APP_PATH . '/pages/_layout.php';
}

/** Flash-Nachricht setzen. */
function flash(string $type, string $message): void
{
    $_SESSION['_flash'][] = ['type' => $type, 'message' => $message];
}

/** Flash-Nachrichten abrufen und leeren. */
function flashes(): array
{
    $f = $_SESSION['_flash'] ?? [];
    unset($_SESSION['_flash']);
    return $f;
}

/** Request-Wert (GET/POST). */
function input(string $key, $default = null)
{
    return $_POST[$key] ?? $_GET[$key] ?? $default;
}

function is_post(): bool
{
    return ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
}

/** Sehr schlanker, sicherer Markdown-Renderer (Überschriften, Listen, Fett, Links). */
function render_markdown(string $md): string
{
    $lines = preg_split('/\R/', $md);
    $html = '';
    $inList = false;
    $inline = static function (string $t): string {
        $t = e($t);
        $t = preg_replace('/\*\*(.+?)\*\*/', '<strong>$1</strong>', $t);
        $t = preg_replace_callback('/\[(.+?)\]\((https?:\/\/[^\s)]+)\)/', static fn($m) =>
            '<a href="' . $m[2] . '" target="_blank" rel="noopener">' . $m[1] . '</a>', $t);
        return $t;
    };
    foreach ($lines as $line) {
        $t = rtrim($line);
        if (preg_match('/^(#{1,4})\s+(.*)$/', $t, $m)) {
            if ($inList) { $html .= "</ul>\n"; $inList = false; }
            $lvl = min(strlen($m[1]) + 1, 4);
            $html .= "<h{$lvl}>" . $inline($m[2]) . "</h{$lvl}>\n";
        } elseif (preg_match('/^\s*[-*]\s+(.*)$/', $t, $m)) {
            if (!$inList) { $html .= "<ul>\n"; $inList = true; }
            $html .= '<li>' . $inline($m[1]) . "</li>\n";
        } elseif (trim($t) === '') {
            if ($inList) { $html .= "</ul>\n"; $inList = false; }
        } else {
            if ($inList) { $html .= "</ul>\n"; $inList = false; }
            // Referenz-Link-Definitionen ([x]: url) ausblenden
            if (preg_match('/^\[[^\]]+\]:\s/', $t)) { continue; }
            $html .= '<p>' . $inline($t) . "</p>\n";
        }
    }
    if ($inList) { $html .= "</ul>\n"; }
    return $html;
}

/** Minimale, layout-freie Fehlerseite fuer Infrastrukturprobleme (DB/Config). */
function fail_page(string $title, string $message): void
{
    http_response_code(503);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><html lang="de"><head><meta charset="utf-8">'
       . '<meta name="viewport" content="width=device-width,initial-scale=1"><title>' . e($title) . '</title>'
       . '<style>body{font-family:system-ui,Segoe UI,sans-serif;background:#003594;color:#fff;display:flex;'
       . 'min-height:100vh;align-items:center;justify-content:center;margin:0}.box{max-width:520px;padding:34px;'
       . 'background:rgba(255,255,255,.06);border-radius:14px}.box h1{margin:0 0 10px;font-size:22px}'
       . '.box p{color:#cbd8f0;line-height:1.5}</style></head><body><div class="box">'
       . '<h1>' . e($title) . '</h1><p>' . nl2br(e($message)) . '</p></div></body></html>';
    exit;
}

/** Menschliche Dateigroesse. */
function human_size(int $bytes): string
{
    $u = ['B', 'KB', 'MB', 'GB'];
    $i = 0;
    $n = (float) $bytes;
    while ($n >= 1024 && $i < count($u) - 1) { $n /= 1024; $i++; }
    return round($n, $i ? 1 : 0) . ' ' . $u[$i];
}

/**
 * Bild aus einem Formular speichern. Bevorzugt das im Browser zugeschnittene
 * Ergebnis (Cropper) als Daten-URL im Feld „{field}_cropped"; fällt sonst auf
 * den klassischen Datei-Upload $_FILES[field] zurück (z. B. ohne JavaScript
 * oder bei Vektor-Logos/SVG, die nicht zugeschnitten werden).
 *
 * @param string $field  Formularfeld-Basisname (muss zu image_field() passen)
 * @param string $prefix Dateinamen-Präfix (z. B. "sp", "sch", "usr")
 * @param string $subdir Zielunterordner unter assets/uploads (z. B. "logos")
 * @return string|null   Pfad relativ zu assets/ (z. B. "uploads/logos/x.png") oder null
 */
function save_image(string $field, string $prefix, string $subdir): ?string
{
    $maxBytes = (int) cfg('upload_max_bytes', 25 * 1024 * 1024);
    $dir = ROOT_PATH . '/assets/uploads/' . $subdir;
    $ensureDir = static function () use ($dir): bool {
        if (!is_dir($dir)) { @mkdir($dir, 0775, true); }
        return is_dir($dir);
    };

    // 1) Im Browser zugeschnittenes Bild (Daten-URL aus dem Cropper).
    $cropped = (string) ($_POST[$field . '_cropped'] ?? '');
    if ($cropped !== '' && preg_match('#^data:image/(png|jpeg|webp);base64,#', $cropped, $m)) {
        $data = base64_decode(substr($cropped, strpos($cropped, ',') + 1), true);
        if ($data === false || $data === '') {
            flash('error', 'Bild konnte nicht verarbeitet werden.');
            return null;
        }
        if (strlen($data) > $maxBytes) {
            flash('error', 'Bild ist zu groß.');
            return null;
        }
        if (!$ensureDir()) { flash('error', 'Upload-Ordner fehlt.'); return null; }
        $ext = $m[1] === 'jpeg' ? 'jpg' : $m[1];
        $stored = $prefix . '_' . bin2hex(random_bytes(8)) . '.' . $ext;
        if (@file_put_contents($dir . '/' . $stored, $data) === false) {
            flash('error', 'Bild konnte nicht gespeichert werden.');
            return null;
        }
        return 'uploads/' . $subdir . '/' . $stored;
    }

    // 2) Klassischer Datei-Upload (Fallback ohne JS bzw. SVG/Vektor).
    if (empty($_FILES[$field]['name']) || !is_uploaded_file($_FILES[$field]['tmp_name'] ?? '')) {
        return null;
    }
    $f = $_FILES[$field];
    if (($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || $f['size'] > $maxBytes) {
        flash('error', 'Bild konnte nicht hochgeladen werden (zu groß?).');
        return null;
    }
    $ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
    $allowed = ['png', 'jpg', 'jpeg', 'gif', 'webp', 'svg'];
    if (!in_array($ext, $allowed, true)) {
        flash('error', 'Nur Bilddateien (PNG, JPG, WEBP, GIF, SVG).');
        return null;
    }
    if (!$ensureDir()) { flash('error', 'Upload-Ordner fehlt.'); return null; }
    $ext = preg_replace('/[^a-z0-9]/', '', $ext);
    $stored = $prefix . '_' . bin2hex(random_bytes(8)) . '.' . $ext;
    if (!move_uploaded_file($f['tmp_name'], $dir . '/' . $stored)) {
        flash('error', 'Bild-Upload fehlgeschlagen.');
        return null;
    }
    return 'uploads/' . $subdir . '/' . $stored;
}

/**
 * Drag-&-Drop-Bildfeld mit Zuschnitt (Zoom/Drehen/Crop). Das Verhalten liefert
 * das JS in app.js über [data-imgdrop] und die data-Attribute. Die Formulare
 * müssen enctype="multipart/form-data" tragen und über save_image() speichern.
 *
 * @param string      $field   Feld-Basisname (muss zu save_image() passen)
 * @param string|null $current Aktueller Pfad relativ zu assets/ oder null
 * @param array{aspect?:float|null,shape?:string,format?:string,label?:string,hint?:string} $opts
 */
function image_field(string $field, ?string $current = null, array $opts = []): string
{
    $aspect = array_key_exists('aspect', $opts) ? $opts['aspect'] : null; // null = frei
    $shape  = ($opts['shape'] ?? 'rect') === 'round' ? 'round' : 'rect';
    $format = ($opts['format'] ?? 'png') === 'jpeg' ? 'jpeg' : 'png';
    $label  = (string) ($opts['label'] ?? 'Bild');
    $hint   = (string) ($opts['hint']  ?? 'Bild hierher ziehen oder klicken – dann zuschneiden, zoomen, drehen.');
    $curUrl = $current ? asset($current) : '';
    $aspAttr = $aspect !== null ? (string) $aspect : '';

    ob_start(); ?>
    <div class="field">
      <label><?= e($label) ?></label>
      <div class="imgdrop imgdrop--<?= $shape ?>" data-imgdrop
           data-field="<?= e($field) ?>" data-aspect="<?= e($aspAttr) ?>" data-format="<?= e($format) ?>"
           tabindex="0" role="button" aria-label="<?= e($label) ?> hochladen">
        <img class="imgdrop__img" src="<?= e($curUrl) ?>" alt=""<?= $curUrl ? '' : ' hidden' ?>>
        <div class="imgdrop__placeholder"<?= $curUrl ? ' hidden' : '' ?>>
          <span class="imgdrop__icon" aria-hidden="true">🖼️</span>
          <span class="imgdrop__hint"><?= e($hint) ?></span>
        </div>
        <button type="button" class="imgdrop__clear" data-imgdrop-clear title="Auswahl verwerfen" aria-label="Auswahl verwerfen"<?= $curUrl ? '' : ' hidden' ?>>×</button>
        <input type="file" class="imgdrop__file" name="<?= e($field) ?>" accept="image/*" hidden>
        <input type="hidden" class="imgdrop__data" name="<?= e($field) ?>_cropped" value="">
      </div>
    </div>
    <?php
    return (string) ob_get_clean();
}

/** Label + CSS-Klasse fuer Team-Status. */
function status_label(string $status): array
{
    return [
        'draft'      => ['In Arbeit', 'muted'],
        'submitted'  => ['Eingereicht', 'blue'],
        'nominated'  => ['Pitch nominiert', 'teal'],
        'fallback'   => ['Nachrücker', 'amber'],
        'eliminated' => ['Ausgeschieden', 'muted'],
    ][$status] ?? [$status, 'muted'];
}
