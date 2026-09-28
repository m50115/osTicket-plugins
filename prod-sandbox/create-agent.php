<?php
/**
 * Creates (or re-keys) a SANDBOX agent for the ost-workflow e2e suite and writes its credentials to a local env file.
 *
 *   php create-agent.php <username> <role_id> [--dept=1] [--app=<osticket dir>] [--out=<file.env>]
 *                        [--reset-password] [--2fa=email]
 *
 *   e2e fixtures (see prod-sandbox/E2E-Fixtures.md):  agent2 = role 3 (Limited Access), agent3 = role 2 (Expanded Access), dept 1
 *
 * - The password is generated here (random, never printed, never stored in Git). It goes ONLY to --out (mode 0600):
 *       <USERNAME>_USER=<username>
 *       <USERNAME>_PASS=<password>        e.g. AGENT2_USER / AGENT2_PASS, the names e2e.py reads
 * - An existing agent is left alone unless --reset-password is given (then only the password changes).
 * - --2fa=email switches on osTicket's built-in e-mail second factor for the agent (the same rows the profile page writes:
 *   namespace 'staff.<id>' keys 'default_2fa' and '2fa-email'). Used only by the 2FA verification of the requalification.
 * - Sandbox only: it refuses to run unless the osTicket dir is under ~/development/ost-sandbox (override: --i-know-this-is-a-sandbox).
 */
if (PHP_SAPI !== 'cli') { fwrite(STDERR, "cli only\n"); exit(1); }

$pos = []; $opt = [];
foreach (array_slice($argv, 1) as $a) {
    if (preg_match('/^--([a-z0-9-]+)(?:=(.*))?$/', $a, $m)) $opt[$m[1]] = isset($m[2]) ? $m[2] : true;
    else $pos[] = $a;
}
if (count($pos) < 2) { fwrite(STDERR, "usage: php create-agent.php <username> <role_id> [--dept=1] [--app=DIR] [--out=FILE] [--reset-password] [--2fa=email]\n"); exit(1); }
list($username, $roleId) = $pos;
$deptId = (int) ($opt['dept'] ?? 1);
$app = rtrim($opt['app'] ?? (getenv('HOME') . '/development/ost-sandbox/app'), '/');
if (!preg_match('/^[a-z][a-z0-9_]{1,30}$/', $username) || !ctype_digit((string) $roleId)) { fwrite(STDERR, "bad username or role_id\n"); exit(1); }
if (strpos(realpath($app) ?: '', '/ost-sandbox/') === false && !isset($opt['i-know-this-is-a-sandbox'])) {
    fwrite(STDERR, "refusing: $app is not an ost-sandbox directory\n"); exit(1);
}

chdir($app . '/api');
$_SERVER['REQUEST_METHOD'] = 'GET'; $_SERVER['SERVER_NAME'] = '127.0.0.1'; $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
$_SERVER['SCRIPT_NAME'] = '/api/http.php'; $_SERVER['HTTP_HOST'] = '127.0.0.1:8080';
require_once $app . '/api/api.inc.php';
require_once INCLUDE_DIR . 'class.staff.php';

$pass = bin2hex(random_bytes(9)) . 'aA1!';
$id = Staff::getIdByUsername($username);
if ($id && !isset($opt['reset-password'])) { echo "exists (id $id); use --reset-password to re-key it\n"; exit(2); }

$errors = [];
if (!$id) {
    $vars = ['id' => null, 'username' => $username, 'firstname' => 'Agent', 'lastname' => ucfirst($username),
        'email' => $username . '@example.com', 'dept_id' => $deptId, 'role_id' => (int) $roleId,
        'islocked' => '1', 'isvisible' => '1', 'passwd1' => $pass, 'passwd2' => $pass, 'perms' => []];
    $s = Staff::create();   // no vars: the model would take passwd1/islocked… as columns (DB error 1054); update() maps them

    if (!$s->update($vars, $errors)) {
        fwrite(STDERR, "FAILED " . json_encode($errors) . " db: " . (function_exists('db_error') ? db_error() : '-') . "\n");
        exit(1);
    }
    $id = $s->getId();
    echo "created id $id\n";
} else {
    $s = Staff::lookup($id);
    $s->passwd = Passwd::hash($pass);   // same hash the native backend verifies
    $s->save(true);
    echo "password reset for id $id\n";
}

if (($opt['2fa'] ?? null) === 'email') {
    $c = new Config('staff.' . $id);
    $c->set('default_2fa', Email2FABackend::$id);
    $c->set(Email2FABackend::$id, JsonDataEncoder::encode(['config' => ['email' => $username . '@example.com']]));
    echo "2FA (" . Email2FABackend::$id . ") enabled for id $id\n";
}

if (!empty($opt['out'])) {
    $var = strtoupper($username);
    $old = umask(0177);
    file_put_contents($opt['out'], "{$var}_USER=$username\n{$var}_PASS=$pass\n");
    umask($old);
    echo "credentials written to {$opt['out']} (0600)\n";
}
