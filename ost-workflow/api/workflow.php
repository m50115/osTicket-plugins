<?php
/**
 * Front-controller shim for ost-workflow. Deploy to <osticket>/api/workflow.php.
 *
 * Works without nginx rewrites (nginx already serves .php); if you route
 * /api/workflow/... to http.php with PATH_INFO instead, this file is not needed.
 *
 *   GET  /api/workflow.php?route=/v1/ping
 *   POST /api/workflow.php?route=/v1/auth/login
 *   GET  /api/workflow.php?route=/v1/tickets&limit=25
 *
 * The plugin owns everything under /workflow/v1 (single catch-all matcher).
 */
$route = isset($_GET['route']) ? (string) $_GET['route'] : '';
if ($route !== '' && $route[0] !== '/')
    $route = '/' . $route;

$_SERVER['PATH_INFO']   = '/workflow' . $route;
$_SERVER['SCRIPT_NAME'] = '/api/http.php';

// Don't leak the routing arg into handlers.
unset($_GET['route']);

require __DIR__ . '/http.php';
