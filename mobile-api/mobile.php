<?php
/**
 * Standalone front controller for the Mobile API plugin.
 *
 * Deploy this file to <osticket>/api/mobile.php
 * Works WITHOUT any nginx rewrite rules — nginx already serves .php files.
 *
 * URL pattern:
 *   GET  /api/mobile.php?route=/ping
 *   POST /api/mobile.php?route=/auth/login
 *   GET  /api/mobile.php?route=/tickets&status=open&page=1
 *   POST /api/mobile.php?route=/tickets/5/reply
 */

$route = isset($_GET['route']) ? $_GET['route'] : '';
if ($route !== '' && $route[0] !== '/') {
    $route = '/' . $route;
}

// The plugin registers routes under /mobile/*, so prepend /mobile.
$_SERVER['PATH_INFO']   = '/mobile' . $route;
$_SERVER['SCRIPT_NAME'] = '/api/http.php';

// Remove 'route' from GET so it doesn't leak into endpoint handlers.
unset($_GET['route']);

require __DIR__ . '/http.php';
