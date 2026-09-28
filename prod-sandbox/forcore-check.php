<?php
// Usage: php forcore-check.php <app_dir> [plugin_lib_dir]
// Read-only check of OstWorkflow\Time::forCore against the sandbox MySQL clock (SELECT only, no writes).
//  (a) core zone right  (a fixed zone equal to MySQL's): forCore must be the identity (same string as a plain conversion).
//  (b) core zone wrong  (America/Chicago over a fixed CST MySQL, RC-14): the core's formula (UTC + offset of ITS zone, what
//      Misc::dbtime stores) must land on Time::toDb() (the wall clock MySQL must hold), in DST and non-DST months.
$app = $argv[1] ?? '';
$lib = $argv[2] ?? (__DIR__ . '/../ost-workflow/lib/OstWorkflow');
if (!is_dir($app)) { fwrite(STDERR, "usage: php forcore-check.php <app_dir> [plugin_lib_dir]\n"); exit(2); }
chdir($app . '/api');
$_SERVER['REQUEST_METHOD'] = 'GET'; $_SERVER['SERVER_NAME'] = '127.0.0.1'; $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
$_SERVER['SCRIPT_NAME'] = '/api/http.php'; $_SERVER['HTTP_HOST'] = '127.0.0.1:8090';
require_once $app . '/api/api.inc.php';
require_once $lib . '/Store.php';
require_once $lib . '/Time.php';

class StubCfg {
    public $db; public $tz;
    function __construct($db, $tz) { $this->db = $db; $this->tz = $tz; }
    function getDbTimezone() { return $this->db; }
    function getTimezone($u = false) { return $this->tz; }
}
function resetTime() {
    $r = new ReflectionClass('OstWorkflow\Time');
    foreach (['calibrated' => false, 'fixedOffset' => null] as $p => $v) { $q = $r->getProperty($p); $q->setAccessible(true); $q->setValue(null, $v); }
}
function coreStores($string, $staffTz, $coreDbTz) {      // Misc::dbtime + date('Y-m-d G:i') in UTC
    $utc = (new DateTime($string, new DateTimeZone($staffTz)))->getTimestamp();
    return gmdate('Y-m-d H:i:s', $utc + (new DateTimeZone($coreDbTz))->getOffset((new DateTime())->setTimestamp($utc)));
}
$instants = ['2027-01-15T18:00:00Z', '2027-03-20T18:00:00Z', '2027-07-15T18:00:00Z', '2027-10-15T18:00:00Z', '2027-12-15T18:00:00Z',
             '2027-03-14T07:30:00Z', '2027-03-14T09:30:00Z', '2027-11-07T05:30:00Z', '2027-11-07T09:30:00Z'];   // around the US switch days
$staffZones = ['UTC', 'America/Mexico_City', 'Europe/Madrid'];
$fail = 0; $n = 0;
function check($ok, $msg) { global $fail, $n; $n++; if (!$ok) { $fail++; echo "  FAIL  $msg\n"; } }

global $cfg; $real = $cfg;
$mysql = db_fetch_array(db_query("SELECT UNIX_TIMESTAMP() AS u, NOW() AS n"));
$measured = strtotime($mysql['n'] . ' UTC') - (int) $mysql['u'];
echo sprintf("MySQL measured offset: %+d h | zone the core believes: %s\n", $measured / 3600, $real->getDbTimezone());
$rightZone = sprintf('Etc/GMT%+d', -$measured / 3600);        // e.g. -6h -> Etc/GMT+6 (POSIX sign is inverted)

foreach ($staffZones as $sz) foreach ($instants as $iso) {
    // (a) the core is right -> identity
    $GLOBALS['cfg'] = new StubCfg($rightZone, $sz); resetTime();
    $plain = (new DateTime($iso))->setTimezone(new DateTimeZone($sz))->format('Y-m-d H:i:s');
    check(OstWorkflow\Time::forCore($iso, $sz) === $plain, "identity [$rightZone/$sz] $iso");
    check(coreStores(OstWorkflow\Time::forCore($iso, $sz), $sz, $rightZone) === OstWorkflow\Time::toDb($iso), "right zone lands on toDb [$sz] $iso");
    // (b) the core is wrong -> compensated
    $GLOBALS['cfg'] = new StubCfg('America/Chicago', $sz); resetTime();
    $want = OstWorkflow\Time::toDb($iso);
    $got = coreStores(OstWorkflow\Time::forCore($iso, $sz), $sz, 'America/Chicago');
    check($got === $want, "compensated [wrong Chicago/$sz] $iso: core stores $got, MySQL must hold $want");
}
// The real, configured core: Misc::dbtime itself (its zone is cached from the first call).
$GLOBALS['cfg'] = $real; resetTime();
foreach ($instants as $iso) {
    $s = OstWorkflow\Time::forCore($iso, $real->getTimezone());
    $stored = gmdate('Y-m-d H:i:s', Misc::dbtime($s));
    check($stored === OstWorkflow\Time::toDb($iso), "real Misc::dbtime lands on toDb: $iso stores $stored vs " . OstWorkflow\Time::toDb($iso));
}
echo $fail ? "forcore-check: $fail of $n FAILED\n" : "forcore-check: OK ($n checks)\n";
exit($fail ? 1 : 0);
