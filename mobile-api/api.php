<?php
/**
 * Mobile API Plugin
 *
 * Endpoints:
 *   GET  /api/mobile/ping            — health check (no auth)
 *   POST /api/mobile/auth/login      — staff login, returns token
 *   POST /api/mobile/auth/logout     — invalidate token
 */

require_once(INCLUDE_DIR . 'class.plugin.php');
require_once(__DIR__ . '/auth.php');
require_once(__DIR__ . '/tickets.php');
require_once(__DIR__ . '/topics.php');
require_once(__DIR__ . '/departments.php');
require_once(__DIR__ . '/users.php');
require_once(__DIR__ . '/files.php');
require_once(__DIR__ . '/staff.php');
require_once(__DIR__ . '/notifications.php');
class MobileApiPlugin extends Plugin {

    function init() {
        Signal::connect('api', array($this, 'registerRoutes'));
    }

    function registerRoutes($dispatcher) {

        // Health check — no auth required
        // S7 — version removed; /ping only signals liveness, not metadata
        $dispatcher->append(
            url_get('^/mobile/ping$', function() {
                header('Content-Type: application/json');
                header('X-Content-Type-Options: nosniff');
                echo json_encode(array('status' => 'ok'));
                exit;
            })
        );

        // Token verification — called by app on startup to validate stored token
        $dispatcher->append(
            url_get('^/mobile/auth/verify$', function() {
                MobileAuth::handleVerify();
            })
        );

        // Staff login
        $dispatcher->append(
            url_post('^/mobile/auth/login$', function() {
                MobileAuth::handleLogin();
            })
        );

        // Staff logout
        $dispatcher->append(
            url_post('^/mobile/auth/logout$', function() {
                MobileAuth::handleLogout();
            })
        );

        // Help topics (needed by app for ticket creation)
        $dispatcher->append(
            url_get('^/mobile/topics$', function() {
                MobileTopics::handleList();
            })
        );

        // Departments (needed by app for ticket creation)
        $dispatcher->append(
            url_get('^/mobile/departments$', function() {
                MobileDepartments::handleList();
            })
        );

        // Staff list (for ticket assignment picker)
        $dispatcher->append(
            url_get('^/mobile/staff$', function() {
                MobileStaff::handleList();
            })
        );

        // User search (for new-ticket autocomplete)
        $dispatcher->append(
            url_get('^/mobile/users/search$', function() {
                MobileUsers::handleSearch();
            })
        );

        // Ticket search
        $dispatcher->append(
            url_get('^/mobile/tickets/search$', function() {
                MobileTickets::handleSearch();
            })
        );

        // Ticket list
        $dispatcher->append(
            url_get('^/mobile/tickets$', function() {
                MobileTickets::handleList();
            })
        );

        // Create ticket
        $dispatcher->append(
            url_post('^/mobile/tickets$', function() {
                MobileTickets::handleCreate();
            })
        );

        // Ticket detail
        $dispatcher->append(
            url_get('^/mobile/tickets/(\d+)$', function($id) {
                MobileTickets::handleDetail($id);
            })
        );

        // Reply to ticket
        $dispatcher->append(
            url_post('^/mobile/tickets/(\d+)/reply$', function($id) {
                MobileTickets::handleReply($id);
            })
        );

        // File download (authenticated via Bearer token)
        $dispatcher->append(
            url_get('^/mobile/files/([A-Za-z0-9_-]+)$', function($hash) {
                MobileFiles::handleDownload($hash);
            })
        );

        // Register FCM device token for push notifications
        $dispatcher->append(
            url_post('^/mobile/device/token$', function() {
                MobileNotifications::handleRegisterToken();
            })
        );

    }

}
