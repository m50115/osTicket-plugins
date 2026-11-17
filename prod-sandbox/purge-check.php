<?php
// Usage: php purge-check.php <app_dir>   (SANDBOX ONLY)
// Runs the plugin's cron handler (OstWorkflowPlugin::purgeExpired -> Store::purge(true)) once, the way osTicket's cron does,
// so e2e.py can prove the idempotency purge never touches the ScheduledMaintenance table (OW-REQ-70, MUST V1 point 1).
$app = $argv[1] ?? '';
if (!is_dir($app)) { fwrite(STDERR, "usage: php purge-check.php <app_dir>\n"); exit(2); }
chdir($app . '/api');
$_SERVER['REQUEST_METHOD'] = 'GET'; $_SERVER['SERVER_NAME'] = '127.0.0.1'; $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
$_SERVER['SCRIPT_NAME'] = '/api/http.php'; $_SERVER['HTTP_HOST'] = '127.0.0.1:8090';
require_once $app . '/api/api.inc.php';
global $ost;
$ost->plugins->bootstrap();
if (!class_exists('OstWorkflowPlugin')) { echo "plugin class not loaded\n"; exit(1); }
OstWorkflowPlugin::purgeExpired();
echo "purged\n";
