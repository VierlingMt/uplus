<?php
/**
 * Front-Controller / Router.
 * Routing ueber ?r=route (robust auf Shared-Hosting, unabhaengig von mod_rewrite).
 */

declare(strict_types=1);

require __DIR__ . '/app/bootstrap.php';

$route = (string) ($_GET['r'] ?? 'dashboard');

// Oeffentliche Routen (ohne Login). Passkey-Endpunkte prüfen die Anmeldung
// je Aktion selbst (login/login_options öffentlich, register* nur angemeldet).
$public = ['login', 'confirm_email', 'passkey'];

try {
    if (!in_array($route, $public, true)) {
        Auth::require();
    }

    // „Ansehen als" ist eine reine Nur-Lese-Sicht: schreibende Aktionen sperren
    // (Ausnahme: die Sicht selbst beenden).
    if (Auth::isImpersonating() && is_post() && $route !== 'viewstop') {
        flash('error', 'Nur-Lese-Ansicht („Ansehen als"). Bitte zuerst die Sicht beenden.');
        redirect(url($route === 'login' ? 'dashboard' : $route));
    }

    switch ($route) {
        case 'login':
            require APP_PATH . '/pages/auth.php';
            break;

        case 'confirm_email':
            require APP_PATH . '/pages/confirm_email.php';
            break;

        case 'passkey':
            require APP_PATH . '/pages/passkey.php';
            break;

        case 'logout':
            Auth::logout();
            redirect(url('login'));
            break;

        // --- „Ansehen als" (nur echter Admin) ---
        case 'viewas':
            if (Auth::isImpersonating() || !Auth::isAdmin()) {
                http_response_code(403);
                render('error', ['title' => 'Kein Zugriff', 'message' => 'Nur ein Admin kann eine Nutzersicht starten.']);
                break;
            }
            $target = Database::one('SELECT * FROM users WHERE id = ? AND is_active = 1', [(int) ($_GET['user'] ?? 0)]);
            if (!$target || (int) $target['id'] === Auth::id()) {
                flash('error', 'Nutzer für die Ansicht nicht gefunden.');
                redirect(url('jurors'));
            }
            Audit::log('impersonate.start', 'Ansehen als „' . $target['name'] . '" gestartet', 'user', (int) $target['id']);
            Auth::startImpersonation($target);
            flash('success', 'Ansicht als „' . $target['name'] . '" – Nur-Lese-Modus.');
            redirect(url('dashboard'));
            break;

        case 'viewstop':
            Audit::log('impersonate.stop', 'Ansehen-als beendet');
            Auth::stopImpersonation();
            redirect(url('jurors'));
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

        case 'contact':
            require APP_PATH . '/pages/contact.php';
            break;

        // --- Module ---
        case 'schools':
        case 'school_teachers':
        case 'teams':
        case 'cycles':
        case 'jurors':
        case 'materials':
        case 'material_download':
        case 'sponsors':
        case 'event':
        case 'event_print':
        case 'audit':
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
