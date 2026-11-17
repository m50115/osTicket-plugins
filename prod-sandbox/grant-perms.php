<?php
/** Sandbox only: php grant-perms.php <username> perm1,perm2 — grants staff-level permissions (user.*, org.*) through Staff::updatePerms. */
$app = getenv('HOME') . '/development/ost-sandbox/app'; chdir($app . '/api');
$_SERVER['REQUEST_METHOD'] = 'GET'; $_SERVER['SERVER_NAME'] = '127.0.0.1'; $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
$_SERVER['SCRIPT_NAME'] = '/api/http.php'; $_SERVER['HTTP_HOST'] = '127.0.0.1:8090';
require_once $app . '/api/api.inc.php'; require_once INCLUDE_DIR . 'class.staff.php';
$id = Staff::getIdByUsername($argv[1]); $s = $id ? Staff::lookup($id) : null;
if (!$s) { fwrite(STDERR, "no such staff\n"); exit(1); }
$p = array_map('trim', explode(',', $argv[2]));
$s->updatePerms($p); $s->save(true);
echo "perms set for {$argv[1]}: " . json_encode($p) . "\n";
