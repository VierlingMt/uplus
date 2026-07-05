<?php
/**
 * Front-Controller / Router.
 * Routing ueber ?r=route (robust auf Shared-Hosting, unabhaengig von mod_rewrite).
 */

declare(strict_types=1);

require __DIR__ . '/app/bootstrap.php';

$route = (string) ($_GET['r'] ?? 'dashboard');

// Oeffentliche Routen (ohne Login)
$public = ['login'];

try {
    if (!in_array($route, $public, true)) {
        Auth::require();
    }

    switch ($route) {
        case 'login':
            require APP_PATH . '/pages/auth.php';
            break;

        case 'logout':
            Auth::logout();
            redirect(url('login'));
            break;

        case 'dashboard':
            require APP_PATH . '/pages/dashboard_controller.php';
            break;

        case 'profile':
            require APP_PATH . '/pages/profile.php';
            break;

        case 'changelog':
            require APP_PATH . '/pages/changelog.php';
            break;

        case 'admin':
            require APP_PATH . '/pages/admin.php';
            break;

        // --- Module ---
        case 'schools':
        case 'teams':
        case 'jurors':
        case 'materials':
        case 'material_download':
        case 'plans':
        case 'bp_download':
        case 'ranking':
        case 'evaluate':
            require APP_PATH . '/pages/' . $route . '.php';
            break;

        default:
            http_response_code(404);
            render('error', ['title' => 'Seite nicht gefunden', 'message' => 'Diese Route existiert nicht: ' . e($route)]);
    }
} catch (Throwable $ex) {
    http_response_code(500);
    $detail = cfg('app_env') === 'production' ? null : $ex->getMessage() . "\n" . $ex->getTraceAsString();
    render('error', [
        'title'   => 'Ein Fehler ist aufgetreten',
        'message' => 'Bitte versuche es erneut oder kontaktiere die Projektleitung.',
        'detail'  => $detail,
    ]);
}
