<?php
/**
 * Gemeinsame PWA-Meta-Tags für <head> (Manifest, Theme-Color, Apple-Touch-Icon).
 * Wird von _layout.php und auth.php eingebunden.
 */
declare(strict_types=1);

$pwaBase = rtrim(cfg('base_path', ''), '/');
?>
<meta name="theme-color" content="#003594">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<meta name="apple-mobile-web-app-title" content="Unternehmen Plus">
<link rel="manifest" href="<?= e($pwaBase) ?>/manifest.webmanifest">
<link rel="apple-touch-icon" href="<?= asset('img/icons/apple-touch-icon.png') ?>">
