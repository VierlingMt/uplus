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
