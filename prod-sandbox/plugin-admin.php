<?php
// Usage: php plugin-admin.php <app_dir> <install|uninstall> [install_path]
// Installs/uninstalls ost-workflow through osTicket's own PluginManager (same as the admin UI), CLI only.
$app = $argv[1]; $action = $argv[2] ?? ''; $path = $argv[3] ?? 'plugins/ost-workflow';
chdir($app . '/api');
$_SERVER['REQUEST_METHOD'] = 'GET'; $_SERVER['SERVER_NAME'] = '127.0.0.1'; $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
$_SERVER['SCRIPT_NAME'] = '/api/http.php'; $_SERVER['HTTP_HOST'] = '127.0.0.1:8090';
require_once $app . '/api/api.inc.php';
global $ost;
$p = Plugin::objects()->filter(['name' => 'Workflow API'])->first();
if ($action === 'uninstall') {
    if (!$p) { echo "not installed\n"; exit(0); }
    $errors = [];
    echo $p->uninstall($errors) ? "uninstalled\n" : "uninstall failed: " . json_encode($errors) . "\n";
    exit(0);
}
if ($action === 'install') {
    if ($p) { echo "already installed at " . $p->getInstallPath() . "\n"; exit(1); }
    $p = $ost->plugins->install($path);
    if (!$p) { echo "INSTALL FAILED\n"; exit(1); }
    $p->isactive = 1; $p->save();
    $impl = $p->getImpl();
    $errors = [];
    $vars = ['name' => 'Workflow', 'isactive' => 1, 'signing_secret' => 'sandbox-secret-0123456789abcdef0123456789abcdef',
             'token_ttl_days' => '30', 'max_files_per_note' => '5', 'max_file_bytes' => '1048576',
             'modules' => 'tickets,contacts,support,configuration', 'trusted_proxies' => '127.0.0.1'];
    $i = $impl->addInstance($vars, $errors);
    echo $i ? "installed $path + instance\n" : "instance FAILED: " . json_encode($errors) . "\n";
}
