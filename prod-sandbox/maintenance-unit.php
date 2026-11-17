<?php
// Serverless unit harness of the native ScheduledMaintenance store (OW-REQ-70, module 8; no plugin table).
//   php maintenance-unit.php [path/to/ost-workflow/lib/OstWorkflow]
// Drives the REAL Handlers\Maintenances + Maintenance engine against an in-memory MaintenanceStore that mimics the
// native one (single-row compare-and-swap, inserts invisible until referenced), and injects concurrent writers between
// a request's read and its swap. No osTicket, no database, no network. Exit 1 on any failure.
namespace {
$lib = $argv[1] ?? (__DIR__ . '/../ost-workflow/lib/OstWorkflow');
$stubDir = sys_get_temp_dir() . '/ow-m8-unit-' . getmypid() . '/';
@mkdir($stubDir);
foreach (['class.list.php', 'class.ticket.php', 'class.dept.php'] as $f) file_put_contents($stubDir . $f, "<?php\n");
define('INCLUDE_DIR', $stubDir);
register_shutdown_function(function () use ($stubDir) { foreach (glob($stubDir . '*') as $f) unlink($f); @rmdir($stubDir); });

// ---------------------------------------------------------------- osTicket stubs
class Staff {
    public $id; public $admin;
    function __construct($id, $admin = false) { $this->id = $id; $this->admin = $admin; }
    function getId() { return $this->id; }
    function isAdmin() { return $this->admin; }
}
class Dept {
    static $managers = [];        // staff id => true
    private $f = [];
    static function objects() { return new self(); }
    function filter(array $f) { $this->f = $f; return $this; }
    function exists() { return !empty(self::$managers[$this->f['manager_id'] ?? 0]); }
}
class DynamicListItem {
    static $items = [];           // id => [list_id, enabled]
    private $r;
    function __construct($r) { $this->r = $r; }
    static function lookup($id) { return isset(self::$items[$id]) ? new self(self::$items[$id]) : null; }
    function get($k) { return $k === 'list_id' ? $this->r[0] : null; }
    function isEnabled() { return $this->r[1]; }
}
class Ticket {
    static $visible = [];         // ticket id => [staff ids] (an existing ticket)
    private $id;
    function __construct($id) { $this->id = $id; }
    static function lookup($id) { return isset(self::$visible[$id]) ? new self($id) : null; }
    function checkStaffPerm($s) { return $s->isAdmin() || in_array($s->getId(), self::$visible[$this->id], true); }
}
class StubCfg { function getTimezone($s = null) { return 'UTC'; } }
$cfg = new StubCfg();
class StubConfig { public $v = []; function get($k) { return $this->v[$k] ?? null; } }

}

namespace OstWorkflow {
final class Documents {
    const UUID = '/^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$/';
    static $docs = [];            // "uuid@v" => ticket id
    static function find(array $d, $version = null) {
        $k = strtolower($d['uuid']) . '@' . (int) $d['version'];
        return isset(self::$docs[$k]) ? ['ticket_id' => self::$docs[$k]] : null;
    }
}
foreach (['ApiError', 'Res', 'Request', 'Runtime', 'Token', 'Ticketing', 'MaintenanceStore', 'Maintenance'] as $c) require_once "$lib/$c.php";
require_once "$lib/Handlers/Maintenances.php";

/** In-memory store with the native store's semantics; `hook` runs once inside the next cas() (a concurrent writer). */
final class MemStore implements MaintenanceStore {
    public $reg = '';
    public $items = [];           // id => ['value', 'raw', 'extra']
    public $next = 1000;
    public $hook = null;
    public $clock = 0;
    public $casCalls = 0;
    function registry() { return $this->reg; }
    function casRegistry($old, $new) { $this->fire(); if ($this->reg !== $old) return false; $this->reg = $new; return true; }
    function items(array $ids) { $o = []; foreach ($ids as $i) if (isset($this->items[$i])) $o[$i] = $this->items[$i]['raw']; return $o; }
    function itemsContaining(array $ids, $n) { return array_filter($this->items($ids), function ($r) use ($n) { return strpos((string) $r, $n) !== false; }); }
    function insert($value, $raw) { $id = $this->next++; $this->items[$id] = ['value' => $value, 'raw' => $raw, 'extra' => null]; return $id; }
    function rewrite($id, $raw) { $this->items[$id]['raw'] = $raw; }
    function cas($id, $old, $new) { $this->casCalls++; $this->fire(); if ($this->items[$id]['raw'] !== $old) return false; $this->items[$id]['raw'] = $new; return true; }
    function orphan($id) { $this->items[$id]['extra'] = 'orphan'; }
    function now() { $this->clock++; return sprintf('2026-11-16 10:%02d:%02d.%06d', intdiv($this->clock, 60) % 60, $this->clock % 60, $this->clock); }
    private function fire() { if ($h = $this->hook) { $this->hook = null; $h(); } }
}

use OstWorkflow\Handlers\Maintenances as H;

$passed = 0; $failed = 0;
function check($name, $ok, $info = '') {
    global $passed, $failed;
    if ($ok) { $passed++; echo "  ok    $name\n"; }
    else { $failed++; echo "  FAIL  $name" . ($info !== '' ? "\n          " . substr(is_string($info) ? $info : json_encode($info), 0, 600) : '') . "\n"; }
}
function req($staff, array $body = [], array $params = [], array $query = []) {
    $r = new Request();
    $r->staff = $staff; $r->params = $params; $r->query = $query; $r->method = $body ? 'POST' : 'GET';
    $p = new \ReflectionProperty(Request::class, 'json'); $p->setAccessible(true); $p->setValue($r, $body);
    return $r;
}
/** @return array [status, body] (ApiError -> its status and error body) */
function run($fn, $req) {
    try { $r = call_user_func(['OstWorkflow\Handlers\Maintenances', $fn], $req); return [$r[0], $r[1]]; }
    catch (ApiError $e) { return [$e->status(), $e->toBody()]; }
}
function pol($name, $req) {
    try { H::policy()[$name]($req); return null; } catch (ApiError $e) { return [$e->status(), $e->toBody()]; }
}
function uid() { $h = bin2hex(random_bytes(16)); return substr($h, 0, 8) . '-' . substr($h, 8, 4) . '-4' . substr($h, 13, 3) . '-a' . substr($h, 17, 3) . '-' . substr($h, 20, 12); }
function err($r) { return $r[1]['error']['code'] ?? null; }
function det($r, $k) { return $r[1]['error']['details'][$k] ?? null; }

// ---------------------------------------------------------------- fixtures
$conf = new \StubConfig();
Runtime::init(null, $conf);
$store = new MemStore();
Maintenance::useStore($store);
const LIST_EQ = 50; const LIST_STORE = 60;
const EA = 101, EB = 102, ER = 103, OTHER = 201;
\DynamicListItem::$items = [EA => [LIST_EQ, true], EB => [LIST_EQ, true], ER => [LIST_EQ, false], OTHER => [77, true]];
$ADMIN = new \Staff(1, true); $AG3 = new \Staff(3); $AG2 = new \Staff(2);
\Ticket::$visible = [11 => [3], 12 => [3], 13 => []];         // 13 = a ticket agent3 cannot see

echo "maintenance-unit: native store engine (" . realpath($lib) . ")\n";

// ---- configuration --------------------------------------------------------------------------------------------
Maintenance::useStore(null);
$r = run('index', req($ADMIN));
check("store list not configured -> 503 not_configured", $r[0] === 503 && err($r) === 'not_configured', $r);
$conf->v = ['installed_equipment_list_id' => (string) LIST_STORE, 'maintenance_list_id' => (string) LIST_STORE];
$r = run('index', req($ADMIN));
check("store list = the Installed Equipment list -> 503 not_configured", $r[0] === 503 && err($r) === 'not_configured', $r);
$conf->v = ['maintenance_list_id' => (string) LIST_STORE];
Maintenance::useStore($store);
$store->reg = 'some other configuration';
$r = run('index', req($ADMIN));
check("a list whose configuration holds other data -> 503 (never adopted)", $r[0] === 503 && err($r) === 'not_configured', $r);
$store->reg = '';
$r = run('batch', req($ADMIN, ['items' => [['id' => uid(), 'equipment_id' => EA, 'scheduled_date' => '2025-01-10']]]));
check("setting 'installed_equipment_list_id' empty -> batch 503 not_configured", $r[0] === 503 && err($r) === 'not_configured', $r);
check("... and nothing was written (no plan, no orphan item, registry untouched)", $store->reg === '' && count($store->items) === 0, $store->items);
$conf->v['installed_equipment_list_id'] = (string) LIST_EQ;

// ---- batch ----------------------------------------------------------------------------------------------------
list($a1, $a2, $b1, $dup, $rt, $oth, $nf, $bd) = [uid(), uid(), uid(), uid(), uid(), uid(), uid(), uid()];
$items = [
    ['id' => $a1, 'equipment_id' => EA, 'scheduled_date' => '2025-03-15'],
    ['id' => $a2, 'equipment_id' => EA, 'scheduled_date' => '2025-06-15'],
    ['id' => strtoupper($b1), 'equipment_id' => EB, 'scheduled_date' => '2025-03-15'],
    ['id' => $dup, 'equipment_id' => EA, 'scheduled_date' => '2025-03-15'],
    ['id' => $rt, 'equipment_id' => ER, 'scheduled_date' => '2025-03-15'],
    ['id' => $oth, 'equipment_id' => OTHER, 'scheduled_date' => '2025-03-15'],
    ['id' => $nf, 'equipment_id' => 99999999, 'scheduled_date' => '2025-03-15'],
    ['id' => $bd, 'equipment_id' => EA, 'scheduled_date' => '2025-02-30'],
    ['id' => 'not-a-uuid', 'equipment_id' => EA, 'scheduled_date' => '2025-09-15'],
    ['id' => $a1, 'equipment_id' => EB, 'scheduled_date' => '2025-10-01'],
    ['id' => uid(), 'equipment_id' => '7', 'scheduled_date' => '2025-10-01'],
];
$r = run('batch', req($ADMIN, ['items' => $items]));
$res = []; foreach ($r[1]['data'] ?? [] as $x) if ($x['id'] && !isset($res[$x['id']])) $res[$x['id']] = $x;
check("batch: 200, one result per item in order, counts in meta", $r[0] === 200 && count($r[1]['data']) === 11 && $r[1]['meta'] === ['created' => 3, 'exists' => 1, 'rejected' => 7], $r[1]['meta'] ?? $r);
check("batch: ids are normalized to lower case", $r[1]['data'][2]['id'] === $b1 && $r[1]['data'][2]['result'] === 'created');
$m = $res[$a1]['maintenance'];
check("batch: created PLANNED with server fields and one `created` audit entry", $res[$a1]['result'] === 'created' && $m['stored_status'] === 'PLANNED' && $m['created_by'] === 1 && $m['updated_by'] === 1 && count($m['changes']) === 1 && $m['changes'][0]['kind'] === 'created', $m);
check("DTO: exact keys, in the retired order", array_keys($m) === ['id', 'equipment_id', 'scheduled_date', 'stored_status', 'ticket_id', 'completed_on', 'completed_by', 'evidence_ref', 'completion_note', 'cancel_reason', 'changes', 'created_at', 'created_by', 'updated_at', 'updated_by'], array_keys($m));
check("DTO: nulls and types (ticket_id/completed_* null, timestamps ISO Z)", $m['ticket_id'] === null && $m['completed_on'] === null && $m['evidence_ref'] === null && $m['completion_note'] === null && $m['cancel_reason'] === null && preg_match('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ$/', $m['created_at']) && $m['equipment_id'] === EA);
$ch = $m['changes'][0];
check("changes[] entry shape", array_keys($ch) === ['kind', 'at', 'by', 'reason', 'from', 'to'] && $ch['to'] === ['equipment_id' => EA, 'scheduled_date' => '2025-03-15'] && $ch['from'] === null && $ch['reason'] === null);
check("batch: duplicate (equipment, date) -> rejected naming the holder", $res[$dup]['code'] === 'duplicate' && $res[$dup]['details']['existing_id'] === $a1);
check("batch: retired / other list / unknown equipment", $res[$rt]['code'] === 'equipment_retired' && $res[$oth]['code'] === 'equipment_not_found' && $res[$nf]['code'] === 'equipment_not_found');
check("batch: invalid date; non-UUID id (id null); non-int equipment", $res[$bd]['code'] === 'invalid_date' && $r[1]['data'][8]['code'] === 'invalid_id' && $r[1]['data'][8]['id'] === 'not-a-uuid' && $r[1]['data'][10]['code'] === 'invalid_equipment');
check("batch: an id repeated in the same request -> exists (the first one's record)", $r[1]['data'][9]['result'] === 'exists' && $r[1]['data'][9]['maintenance']['equipment_id'] === EA);
$reg = json_decode($store->reg, true);
check("store: registry envelope with one plan per equipment", $reg['bcw'] === 'BCW_PMREG|1' && array_keys($reg['plans']) === [EA, EB], $store->reg);
$planA = json_decode($store->items[$reg['plans'][EA]]['raw'], true);
check("store: plan envelope, two records of EA, journals referenced", $planA['bcw'] === 'BCW_PM|1' && $planA['eq'] === EA && count($planA['r']) === 2 && count($planA['r'][$a1]['j']) === 1);
check("store: every item is ASCII JSON (fits the core's utf8 columns)", array_reduce($store->items, function ($ok, $i) { return $ok && !preg_match('/[^\x20-\x7e]/', $i['raw']); }, true));
$r3 = run('batch', req($ADMIN, ['items' => array_slice($items, 0, 3)]));
check("idempotency per item: same client ids in a new request -> exists x3, nothing new", array_column($r3[1]['data'], 'result') === ['exists', 'exists', 'exists'] && count($planA['r']) === 2);
$b2 = uid();
$mixed = run('batch', req($ADMIN, ['items' => [$items[0], ['id' => $b2, 'equipment_id' => EB, 'scheduled_date' => '2025-09-15']]]));
check("per-item results mix", array_column($mixed[1]['data'], 'result') === ['exists', 'created']);
check("batch: bad shapes -> 422", run('batch', req($ADMIN, ['x' => 1]))[0] === 422 && run('batch', req($ADMIN, ['items' => []]))[0] === 422
      && run('batch', req($ADMIN, ['items' => array_fill(0, 201, [])]))[0] === 422 && run('batch', req($ADMIN, ['items' => ['a' => 1]]))[0] === 422);
$ok = run('batch', req($ADMIN, ['items' => array_map(function ($i) { return ['id' => uid(), 'equipment_id' => EB, 'scheduled_date' => sprintf('2030-%02d-%02d', 1 + intdiv($i, 28), 1 + $i % 28)]; }, range(0, 199))]));
check("batch: 200 items for one equipment -> 200 created in one request", $ok[0] === 200 && $ok[1]['meta']['created'] === 200, $ok[1]['meta'] ?? $ok);

// ---- reads ------------------------------------------------------------------------------------------------------
$r = run('show', req($AG2, [], ['uuid' => strtoupper($a1)]));
check("read by id: any agent, exact DTO, id case-insensitive", $r[0] === 200 && $r[1]['data']['id'] === $a1 && count($r[1]['data']) === 15, $r);
check("read by id: unknown -> 404; malformed -> 404", run('show', req($AG2, [], ['uuid' => uid()]))[0] === 404 && run('show', req($AG2, [], ['uuid' => str_repeat('-', 36)]))[0] === 404);
$r = run('index', req($AG2, [], [], ['equipment_id' => (string) EA]));
check("read by equipment_id (all states)", $r[0] === 200 && sorted(array_column($r[1]['data'], 'id')) === sorted([$a1, $a2]), $r);
function sorted($a) { sort($a); return $a; }
check("filters: equipment_id=0 -> 422; cursor=zzzz -> 422; ticket_id=x -> 422", run('index', req($AG2, [], [], ['equipment_id' => '0']))[0] === 422 && run('index', req($AG2, [], [], ['cursor' => 'zzzz']))[0] === 422 && run('index', req($AG2, [], [], ['ticket_id' => 'x']))[0] === 422);
function walk($staff, $cur = null, $q = []) {
    $all = []; $pages = 0; $last = null;
    do {
        $r = run('index', req($staff, [], [], array_merge($q, ['limit' => '7'], $cur ? ['cursor' => $cur] : [])));
        $all = array_merge($all, $r[1]['data']); $pages++; $last = $r;
        $cur = $r[1]['meta']['next_cursor'];
    } while ($r[1]['meta']['has_more'] && $pages < 1000);
    return [$all, $last];
}
list($all, $last) = walk($AG2);
$ids = array_column($all, 'id');
$total = 0; foreach (json_decode($store->reg, true)['plans'] as $pid) $total += count(json_decode($store->items[$pid]['raw'], true)['r']);
check("feed: the cursor walks every record exactly once", count($ids) === count(array_unique($ids)) && count($ids) === $total && $total === 204, count($ids) . " / $total");
$ordered = true; for ($i = 1; $i < count($all); $i++) if ([$all[$i - 1]['updated_at'], $all[$i - 1]['id']] > [$all[$i]['updated_at'], $all[$i]['id']]) $ordered = false;
check("feed: ordered by (updated_at, id)", $ordered);
$wm = $last[1]['meta']['watermark'];
$e = run('index', req($AG2, [], [], ['cursor' => $wm]));
check("feed: from the watermark nothing is returned and the watermark is kept", $e[1]['data'] === [] && $e[1]['meta']['watermark'] === $wm && $e[1]['meta']['has_more'] === false && $e[1]['meta']['next_cursor'] === null);
$p1 = run('index', req($AG2, [], [], ['limit' => '1']));
check("feed: limit pages with has_more and count", $p1[1]['meta']['has_more'] === true && $p1[1]['meta']['count'] === 1 && $p1[1]['meta']['next_cursor'] === $p1[1]['meta']['watermark']);
$legacy = Token::b64(json_encode(['t' => '2000-01-01 00:00:00', 'i' => $a1]));
check("feed: a cursor of the retired table (fewer fractional digits) is accepted", run('index', req($AG2, [], [], ['cursor' => $legacy]))[0] === 200);

// ---- reschedule ---------------------------------------------------------------------------------------------------
$r = run('reschedule', req($AG3, ['base' => '2025-03-15', 'scheduled_date' => '2025-03-28', 'reason' => 'client asked'], ['uuid' => $a1]));
$c = $r[1]['data']['changes'][1] ?? [];
check("reschedule: applied, audit from/to/reason/by, updated_by", $r[0] === 200 && $r[1]['meta']['applied'] === true && $r[1]['data']['scheduled_date'] === '2025-03-28' && $c['kind'] === 'reschedule' && $c['from'] === '2025-03-15' && $c['to'] === '2025-03-28' && $c['reason'] === 'client asked' && $c['by'] === 3 && $r[1]['data']['updated_by'] === 3, $r);
$f = run('index', req($AG2, [], [], ['cursor' => $wm]));
check("feed: after a change the watermark returns exactly that record", array_column($f[1]['data'], 'id') === [$a1] && $f[1]['meta']['watermark'] !== $wm);
$r = run('reschedule', req($AG3, ['base' => '2025-03-28', 'scheduled_date' => '2025-03-28', 'reason' => 'retry'], ['uuid' => $a1]));
check("reschedule to the same date -> applied=false, no audit entry", $r[0] === 200 && $r[1]['meta']['applied'] === false && count($r[1]['data']['changes']) === 2);
$r = run('reschedule', req($ADMIN, ['base' => '2025-03-15', 'scheduled_date' => '2025-04-02', 'reason' => 'stale'], ['uuid' => $a1]));
check("reschedule stale base -> 409 base_mismatch with current/base/last_change/record", $r[0] === 409 && err($r) === 'conflict' && det($r, 'reason') === 'base_mismatch' && det($r, 'current') === '2025-03-28' && det($r, 'base') === '2025-03-15' && det($r, 'last_change')['kind'] === 'reschedule' && det($r, 'record')['id'] === $a1, $r);
check("reschedule: without base / reason / bad date -> 422", run('reschedule', req($ADMIN, ['scheduled_date' => '2025-04-02', 'reason' => 'x'], ['uuid' => $a1]))[1]['error']['field'] === 'base'
      && run('reschedule', req($ADMIN, ['base' => '2025-03-28', 'scheduled_date' => '2025-04-02'], ['uuid' => $a1]))[0] === 422
      && run('reschedule', req($ADMIN, ['base' => '2025-03-28', 'scheduled_date' => '2025-13-02', 'reason' => 'x'], ['uuid' => $a1]))[0] === 422);
$r = run('reschedule', req($ADMIN, ['base' => '2025-03-28', 'scheduled_date' => '2025-06-15', 'reason' => 'clash'], ['uuid' => $a1]));
check("reschedule onto a held slot -> 409 duplicate existing_id", $r[0] === 409 && det($r, 'reason') === 'duplicate' && det($r, 'existing_id') === $a2, $r);
$r = run('reschedule', req($ADMIN, ['base' => '2025-03-28', 'scheduled_date' => '2020-01-05', 'reason' => 'past'], ['uuid' => $a1]));
check("reschedule into the past is allowed", $r[0] === 200 && $r[1]['data']['scheduled_date'] === '2020-01-05');
run('reschedule', req($ADMIN, ['base' => '2020-01-05', 'scheduled_date' => '2025-03-28', 'reason' => 'back'], ['uuid' => $a1]));
check("reschedule: every applied change is one audit entry (no lost update)", count(run('show', req($ADMIN, [], ['uuid' => $a1]))[1]['data']['changes']) === 4);
check("reschedule unknown id -> 404", run('reschedule', req($ADMIN, ['base' => '2025-03-28', 'scheduled_date' => '2025-04-02', 'reason' => 'x'], ['uuid' => uid()]))[0] === 404);
$u1 = run('show', req($ADMIN, [], ['uuid' => $a1]))[1]['data']['updated_at'];

// ---- link / unlink -------------------------------------------------------------------------------------------------
$r = run('link', req($AG3, ['base' => null, 'ticket_id' => 13], ['uuid' => $b1]));
check("link a Ticket the agent cannot see -> 403, untouched", $r[0] === 403 && run('show', req($ADMIN, [], ['uuid' => $b1]))[1]['data']['ticket_id'] === null);
check("link a missing Ticket -> the same 403", run('link', req($AG3, ['base' => null, 'ticket_id' => 99999], ['uuid' => $b1]))[0] === 403);
$r = run('link', req($ADMIN, ['base' => null, 'ticket_id' => 13], ['uuid' => $b1]));
check("link by the administrator; audit link from null to the ticket", $r[0] === 200 && $r[1]['data']['ticket_id'] === 13 && end($r[1]['data']['changes'])['kind'] === 'link' && end($r[1]['data']['changes'])['to'] === 13 && end($r[1]['data']['changes'])['from'] === null);
check("link again -> applied=false", run('link', req($ADMIN, ['base' => null, 'ticket_id' => 13], ['uuid' => $b1]))[1]['meta']['applied'] === false);
check("link another while linked -> 409 ticket_already_linked", det(run('link', req($ADMIN, ['base' => 13, 'ticket_id' => 11], ['uuid' => $b1])), 'reason') === 'ticket_already_linked');
check("unlink without reason -> 422; stale base -> 409 current", run('unlink', req($AG3, ['base' => 13], ['uuid' => $b1]))[0] === 422 && det(run('unlink', req($AG3, ['base' => 11, 'reason' => 'w'], ['uuid' => $b1])), 'current') === 13);
$r = run('unlink', req($AG3, ['base' => 13, 'reason' => 'wrong ticket'], ['uuid' => $b1]));
check("unlink needs no access to the Ticket; audit unlink", $r[0] === 200 && $r[1]['data']['ticket_id'] === null && end($r[1]['data']['changes'])['from'] === 13 && end($r[1]['data']['changes'])['reason'] === 'wrong ticket');
check("unlink with nothing linked -> applied=false", run('unlink', req($AG3, ['base' => 13, 'reason' => 'again'], ['uuid' => $b1]))[1]['meta']['applied'] === false);
$r = run('link', req($AG3, ['base' => null, 'ticket_id' => 11, 'reason' => 'right one'], ['uuid' => $b1]));
check("link the right Ticket -> 200 with reason", $r[0] === 200 && $r[1]['data']['ticket_id'] === 11 && end($r[1]['data']['changes'])['reason'] === 'right one');
check("read by ticket_id", array_column(run('index', req($AG2, [], [], ['ticket_id' => '11']))[1]['data'], 'id') === [$b1]);
check("link: stale base -> 409; ticket_id not int -> 422; no base -> 422", run('link', req($ADMIN, ['base' => null, 'ticket_id' => 12], ['uuid' => $b1]))[0] === 409
      && run('link', req($ADMIN, ['base' => 11, 'ticket_id' => 'x'], ['uuid' => $b1]))[0] === 422 && run('link', req($ADMIN, ['ticket_id' => 12], ['uuid' => $b1]))[0] === 422);

// ---- complete -------------------------------------------------------------------------------------------------------
$DU = uid(); $DX = uid();
Documents::$docs = ["$DU@1" => 11, "$DX@1" => 12];
check("complete: no evidence and no note -> 422 on completion_note", run('complete', req($AG3, ['base' => 'PLANNED', 'completed_on' => '2025-03-16'], ['uuid' => $b1]))[1]['error']['field'] === 'completion_note');
check("complete: future date -> 422", run('complete', req($AG3, ['base' => 'PLANNED', 'completed_on' => '2999-01-01', 'completion_note' => 'x'], ['uuid' => $b1]))[1]['error']['field'] === 'completed_on');
check("complete: foreign evidence -> 422 foreign_evidence; unpublished version too", det(run('complete', req($AG3, ['base' => 'PLANNED', 'completed_on' => '2025-03-16', 'evidence_ref' => "doc:$DX@1"], ['uuid' => $b1])), 'reason') === 'foreign_evidence'
      && det(run('complete', req($AG3, ['base' => 'PLANNED', 'completed_on' => '2025-03-16', 'evidence_ref' => "doc:$DU@7"], ['uuid' => $b1])), 'reason') === 'foreign_evidence');
check("complete: malformed evidence_ref -> 422", run('complete', req($AG3, ['base' => 'PLANNED', 'completed_on' => '2025-03-16', 'evidence_ref' => 'nope'], ['uuid' => $b1]))[1]['error']['field'] === 'evidence_ref');
check("complete: evidence without a Ticket -> 422 no_ticket", det(run('complete', req($AG3, ['base' => 'PLANNED', 'completed_on' => '2025-03-16', 'evidence_ref' => "doc:$DU@1"], ['uuid' => $a2])), 'reason') === 'no_ticket');
check("complete: stale base -> 409 current PLANNED", det(run('complete', req($AG3, ['base' => 'CANCELLED', 'completed_on' => '2025-03-16', 'evidence_ref' => "doc:$DU@1"], ['uuid' => $b1])), 'current') === 'PLANNED');
$r = run('complete', req($AG3, ['base' => 'PLANNED', 'completed_on' => '2025-03-16', 'evidence_ref' => 'doc:' . strtoupper($DU) . '@1'], ['uuid' => $b1]));
check("complete with evidence -> COMPLETED, completed_on/by, normalized ref, audit", $r[0] === 200 && $r[1]['data']['stored_status'] === 'COMPLETED' && $r[1]['data']['completed_on'] === '2025-03-16' && $r[1]['data']['completed_by'] === 3 && $r[1]['data']['evidence_ref'] === "doc:$DU@1" && $r[1]['data']['completion_note'] === null && end($r[1]['data']['changes'])['kind'] === 'complete', $r);
check("complete again same data -> applied=false", run('complete', req($AG3, ['base' => 'PLANNED', 'completed_on' => '2025-03-16', 'evidence_ref' => "doc:$DU@1"], ['uuid' => $b1]))[1]['meta']['applied'] === false);
check("complete again other day -> 409", run('complete', req($AG3, ['base' => 'PLANNED', 'completed_on' => '2025-03-20', 'evidence_ref' => "doc:$DU@1"], ['uuid' => $b1]))[0] === 409);
$okInv = true;
foreach ([['reschedule', ['base' => '2025-03-15', 'scheduled_date' => '2025-05-01', 'reason' => 'x']], ['link', ['base' => 11, 'ticket_id' => 12]], ['unlink', ['base' => 11, 'reason' => 'x']]] as $t)
    $okInv = $okInv && det(run($t[0], req($ADMIN, $t[1], ['uuid' => $b1])), 'reason') === 'invalid_state';
check("COMPLETED: reschedule/link/unlink -> 409 invalid_state; Ticket stays", $okInv && run('show', req($ADMIN, [], ['uuid' => $b1]))[1]['data']['ticket_id'] === 11);
$r = run('complete', req($AG3, ['base' => 'PLANNED', 'completed_on' => '2025-06-16', 'completion_note' => "done, documented elsewhere \u{1F527} ñ"], ['uuid' => $a2]));
check("complete with a note (non-ASCII kept exactly) and no Ticket", $r[0] === 200 && $r[1]['data']['completion_note'] === "done, documented elsewhere \u{1F527} ñ" && $r[1]['data']['evidence_ref'] === null, $r);

// ---- manager actions ------------------------------------------------------------------------------------------------
check("cancel/revert policy: a non-manager agent -> 403 manager_required", pol('maintenance.manager', req($AG3))[1]['error']['details']['reason'] === 'manager_required');
check("policy: the administrator passes", pol('maintenance.manager', req($ADMIN)) === null);
check("cancel without reason -> 422; stale base -> 409", run('cancel', req($ADMIN, ['base' => 'PLANNED'], ['uuid' => $b2]))[0] === 422 && det(run('cancel', req($ADMIN, ['base' => 'COMPLETED', 'reason' => 'x'], ['uuid' => $b2])), 'current') === 'PLANNED');
check("cancel a COMPLETED one -> 409 invalid_state", det(run('cancel', req($ADMIN, ['base' => 'COMPLETED', 'reason' => 'x'], ['uuid' => $a2])), 'reason') === 'invalid_state');
$r = run('cancel', req($ADMIN, ['base' => 'PLANNED', 'reason' => 'no longer needed'], ['uuid' => $b2]));
check("cancel -> CANCELLED, cancel_reason, audit; kept", $r[0] === 200 && $r[1]['data']['stored_status'] === 'CANCELLED' && $r[1]['data']['cancel_reason'] === 'no longer needed' && end($r[1]['data']['changes'])['kind'] === 'cancel' && end($r[1]['data']['changes'])['from'] === 'PLANNED' && end($r[1]['data']['changes'])['to'] === 'CANCELLED');
check("cancel again -> applied=false", run('cancel', req($ADMIN, ['base' => 'PLANNED', 'reason' => 'again'], ['uuid' => $b2]))[1]['meta']['applied'] === false);
check("CANCELLED is read-only (reschedule/complete/revert/link -> 409)", run('reschedule', req($ADMIN, ['base' => '2025-09-15', 'scheduled_date' => '2025-09-16', 'reason' => 'x'], ['uuid' => $b2]))[0] === 409
      && run('complete', req($ADMIN, ['base' => 'CANCELLED', 'completed_on' => '2025-09-16', 'completion_note' => 'x'], ['uuid' => $b2]))[0] === 409
      && run('revert', req($ADMIN, ['base' => 'CANCELLED', 'reason' => 'x'], ['uuid' => $b2]))[0] === 409
      && run('link', req($ADMIN, ['base' => null, 'ticket_id' => 11], ['uuid' => $b2]))[0] === 409);
check("uniqueness ignores CANCELLED: same slot can be created again", run('batch', req($ADMIN, ['items' => [['id' => uid(), 'equipment_id' => EB, 'scheduled_date' => '2025-09-15']]]))[1]['data'][0]['result'] === 'created');
\Dept::$managers = [3 => true];
check("policy: a manager of ANY department passes", pol('maintenance.manager', req($AG3)) === null);
check("revert without reason -> 422; stale base -> 409", run('revert', req($AG3, ['base' => 'COMPLETED'], ['uuid' => $a2]))[0] === 422 && det(run('revert', req($AG3, ['base' => 'PLANNED', 'reason' => 'm'], ['uuid' => $a2])), 'current') === 'COMPLETED');
$r = run('revert', req($AG3, ['base' => 'COMPLETED', 'reason' => 'completed by mistake'], ['uuid' => $a2]));
$c = end($r[1]['data']['changes']);
check("revert -> PLANNED, completion fields cleared, date unchanged", $r[0] === 200 && $r[1]['data']['stored_status'] === 'PLANNED' && $r[1]['data']['scheduled_date'] === '2025-06-15' && $r[1]['data']['completed_on'] === null && $r[1]['data']['completed_by'] === null && $r[1]['data']['completion_note'] === null && $r[1]['data']['evidence_ref'] === null, $r);
check("revert: previous values kept in changes[]", $c['kind'] === 'revert' && $c['from'] === ['stored_status' => 'COMPLETED', 'completed_on' => '2025-06-16', 'completed_by' => 3, 'evidence_ref' => null, 'completion_note' => "done, documented elsewhere \u{1F527} ñ"] && $c['to'] === ['stored_status' => 'PLANNED'] && $c['by'] === 3, $c);
check("revert again -> applied=false", run('revert', req($AG3, ['base' => 'COMPLETED', 'reason' => 'again'], ['uuid' => $a2]))[1]['meta']['applied'] === false);
check("cancel by a manager of any department", run('cancel', req($AG3, ['base' => 'PLANNED', 'reason' => 'manager cancels'], ['uuid' => $a2]))[1]['data']['stored_status'] === 'CANCELLED');
$r = run('revert', req($ADMIN, ['base' => 'COMPLETED', 'reason' => 'evidence replaced'], ['uuid' => $b1]));
check("revert with evidence: ref only in the audit; Ticket kept", $r[0] === 200 && $r[1]['data']['evidence_ref'] === null && end($r[1]['data']['changes'])['from']['evidence_ref'] === "doc:$DU@1" && $r[1]['data']['ticket_id'] === 11);
check("after a revert the slot still holds", run('batch', req($ADMIN, ['items' => [['id' => uid(), 'equipment_id' => EB, 'scheduled_date' => '2025-03-15']]]))[1]['data'][0]['code'] === 'duplicate');
\Dept::$managers = [];

// ---- retired after the schedule ----------------------------------------------------------------------------------------
\DynamicListItem::$items[EB][1] = false;
check("equipment retired afterwards: new -> equipment_retired", run('batch', req($ADMIN, ['items' => [['id' => uid(), 'equipment_id' => EB, 'scheduled_date' => '2025-12-20']]]))[1]['data'][0]['code'] === 'equipment_retired');
check("... existing ones still complete", run('complete', req($ADMIN, ['base' => 'PLANNED', 'completed_on' => '2025-03-17', 'evidence_ref' => "doc:$DU@1"], ['uuid' => $b1]))[1]['data']['stored_status'] === 'COMPLETED');
\DynamicListItem::$items[EB][1] = true;

// ---- concurrency: a concurrent writer lands between this request's read and its swap ----------------------------------
$x1 = uid(); $x2 = uid();
$store->hook = function () use ($ADMIN, $x2) { run('batch', req($ADMIN, ['items' => [['id' => $x2, 'equipment_id' => EA, 'scheduled_date' => '2031-01-01']]])); };
$r = run('batch', req($ADMIN, ['items' => [['id' => $x1, 'equipment_id' => EA, 'scheduled_date' => '2031-01-01']]]));
check("race: two creates of the same (equipment, date) -> exactly one wins; the loser is rejected duplicate naming the winner", $r[1]['data'][0]['code'] === 'duplicate' && $r[1]['data'][0]['details']['existing_id'] === $x2 && run('show', req($ADMIN, [], ['uuid' => $x1]))[0] === 404, $r);
$orph = array_filter($store->items, function ($i) use ($x1) { return $i['value'] === "BCW_PMJ|1 $x1"; });
check("race: the loser's journal is marked orphan and never referenced", count($orph) === 1 && reset($orph)['extra'] === 'orphan');
$y = uid();
$store->hook = function () use ($ADMIN, $y) { run('batch', req($ADMIN, ['items' => [['id' => $y, 'equipment_id' => EA, 'scheduled_date' => '2031-02-02']]])); };
$r = run('reschedule', req($ADMIN, ['base' => '2025-03-28', 'scheduled_date' => '2025-03-29', 'reason' => 'race'], ['uuid' => $a1]));
check("race: another record of the same equipment changed meanwhile -> re-evaluated and applied (no false conflict)", $r[0] === 200 && $r[1]['meta']['applied'] === true && run('show', req($ADMIN, [], ['uuid' => $y]))[0] === 200, $r);
$store->hook = function () use ($ADMIN, $a1) { run('reschedule', req($ADMIN, ['base' => '2025-03-29', 'scheduled_date' => '2025-04-05', 'reason' => 'other device'], ['uuid' => $a1])); };
$r = run('reschedule', req($ADMIN, ['base' => '2025-03-29', 'scheduled_date' => '2025-04-09', 'reason' => 'mine'], ['uuid' => $a1]));
check("race: the same record changed meanwhile -> 409 base_mismatch with the winner's state (no lost update)", $r[0] === 409 && det($r, 'reason') === 'base_mismatch' && det($r, 'current') === '2025-04-05' && det($r, 'record')['scheduled_date'] === '2025-04-05', $r);
$store->hook = function () use ($ADMIN, $a1) { run('reschedule', req($ADMIN, ['base' => '2025-04-05', 'scheduled_date' => '2025-04-09', 'reason' => 'same intent'], ['uuid' => $a1])); };
$r = run('reschedule', req($ADMIN, ['base' => '2025-04-05', 'scheduled_date' => '2025-04-09', 'reason' => 'mine'], ['uuid' => $a1]));
check("race: the concurrent writer did exactly what I asked -> 200 applied=false (one audit entry)", $r[0] === 200 && $r[1]['meta']['applied'] === false && count(array_filter($r[1]['data']['changes'], function ($c) { return $c['to'] === '2025-04-09'; })) === 1);
$z1 = uid(); $z2 = uid(); $EN = 104; \DynamicListItem::$items[$EN] = [LIST_EQ, true];
$store->hook = function () use ($ADMIN, $z2, $EN) { run('batch', req($ADMIN, ['items' => [['id' => $z2, 'equipment_id' => $EN, 'scheduled_date' => '2032-01-01']]])); };
$r = run('batch', req($ADMIN, ['items' => [['id' => $z1, 'equipment_id' => $EN, 'scheduled_date' => '2032-02-02']]]));
$reg = json_decode($store->reg, true);
$planN = json_decode($store->items[$reg['plans'][$EN]]['raw'], true);
check("race: two first creates for a new equipment -> one registered plan holds both records", $r[1]['data'][0]['result'] === 'created' && isset($planN['r'][$z1], $planN['r'][$z2]), $r);
$extraPlans = array_filter($store->items, function ($i) use ($EN) { return $i['value'] === "BCW_PM|1 eq=$EN"; });
check("race: the losing (empty) plan item is orphaned, never registered", count($extraPlans) === 2 && count(array_filter($extraPlans, function ($i) { return $i['extra'] === 'orphan'; })) === 1);
$n = 0;
$hammer = function () use (&$hammer, &$n, $store, $ADMIN) { if (++$n < 5) { run('batch', req($ADMIN, ['items' => [['id' => uid(), 'equipment_id' => EA, 'scheduled_date' => sprintf('2033-01-%02d', $n)]]])); $store->hook = $hammer; } };
$store->hook = $hammer;
$r = run('cancel', req($ADMIN, ['base' => 'PLANNED', 'reason' => 'contended'], ['uuid' => $y]));
check("race: 4 consecutive lost swaps -> still one clean commit", $r[0] === 200 && $r[1]['data']['stored_status'] === 'CANCELLED' && count($r[1]['data']['changes']) === 2, $r);
$store->hook = null;

// ---- import (one-shot migration, verbatim records) ---------------------------------------------------------------------
$conf->v[Maintenance::SETTING_IMPORT] = '';
check("import: disabled -> 403 import_disabled", pol('maintenance.import', req($ADMIN))[1]['error']['details']['reason'] === 'import_disabled');
$conf->v[Maintenance::SETTING_IMPORT] = '1';
check("import: non-administrator -> 403 admin_required", pol('maintenance.import', req($AG3))[1]['error']['details']['reason'] === 'admin_required' && pol('maintenance.import', req($ADMIN)) === null);
$v1 = ['id' => uid(), 'equipment_id' => 777, 'scheduled_date' => '2024-05-01', 'stored_status' => 'COMPLETED', 'ticket_id' => 41, 'completed_on' => '2024-05-02', 'completed_by' => 5,
       'evidence_ref' => 'doc:' . uid() . '@2', 'completion_note' => null, 'cancel_reason' => null,
       'changes' => [['kind' => 'created', 'at' => '2024-04-01T10:00:00Z', 'by' => 5, 'reason' => null, 'from' => null, 'to' => ['equipment_id' => 777, 'scheduled_date' => '2024-05-01']],
                     ['kind' => 'link', 'at' => '2024-04-02T10:00:00Z', 'by' => 5, 'reason' => null, 'from' => null, 'to' => 41],
                     ['kind' => 'complete', 'at' => '2024-05-02T10:00:00Z', 'by' => 5, 'reason' => null, 'from' => 'PLANNED', 'to' => ['stored_status' => 'COMPLETED', 'completed_on' => '2024-05-02', 'evidence_ref' => 'x', 'completion_note' => null]]],
       'created_at' => '2024-04-01T10:00:00Z', 'created_by' => 5, 'updated_at' => '2024-05-02T10:00:00Z', 'updated_by' => 5];
$v2 = array_merge($v1, ['id' => uid(), 'stored_status' => 'CANCELLED', 'ticket_id' => null, 'completed_on' => null, 'completed_by' => null, 'evidence_ref' => null, 'cancel_reason' => "obsoleto \u{00E9}",
       'changes' => [$v1['changes'][0], ['kind' => 'cancel', 'at' => '2024-04-03T00:00:00Z', 'by' => 1, 'reason' => "obsoleto \u{00E9}", 'from' => 'PLANNED', 'to' => 'CANCELLED']]]);
$v3 = array_merge($v1, ['id' => uid(), 'stored_status' => 'PLANNED', 'scheduled_date' => '2024-07-01', 'completed_on' => null, 'completed_by' => null, 'evidence_ref' => null, 'ticket_id' => null, 'changes' => [array_merge($v1['changes'][0], ['to' => ['equipment_id' => 777, 'scheduled_date' => '2024-07-01']])]]);
$bad = $v1; unset($bad['changes']); $bad['id'] = uid();
$clash = array_merge($v3, ['id' => uid()]);
$r = run('import', req($ADMIN, ['items' => [$v1, $v2, $v3, $bad, $clash]]));
check("import: created x3 (equipment not re-validated), invalid_record, duplicate slot", $r[0] === 200 && array_column($r[1]['data'], 'result') === ['created', 'created', 'created', 'rejected', 'rejected'] && $r[1]['data'][3]['code'] === 'invalid_record' && $r[1]['data'][4]['code'] === 'duplicate', $r);
$same = true;
foreach ([$v1, $v2, $v3] as $v) $same = $same && run('show', req($ADMIN, [], ['uuid' => $v['id']]))[1]['data'] === $v;
check("import: GET serves every imported record byte-for-byte as given (DTO parity)", $same, run('show', req($ADMIN, [], ['uuid' => $v1['id']]))[1]['data']);
$r = run('import', req($ADMIN, ['items' => [$v1, array_merge($v2, ['cancel_reason' => 'different'])]]));
check("import: re-import -> exists when identical, rejected mismatch (with current) otherwise", array_column($r[1]['data'], 'result') === ['exists', 'rejected'] && $r[1]['data'][1]['code'] === 'mismatch' && $r[1]['data'][1]['details']['current']['cancel_reason'] === "obsoleto \u{00E9}", $r);
$r = run('reschedule', req($ADMIN, ['base' => '2024-07-01', 'scheduled_date' => '2024-06-01', 'reason' => 'after migration'], ['uuid' => $v3['id']]));
check("import: an imported record keeps living (transition appends to its verbatim audit)", $r[0] === 200 && count($r[1]['data']['changes']) === 2 && $r[1]['data']['changes'][0] === $v3['changes'][0] && strcmp($r[1]['data']['updated_at'], $v3['updated_at']) > 0);

// ---- capacity and damage ----------------------------------------------------------------------------------------------
$EC = 105; \DynamicListItem::$items[$EC] = [LIST_EQ, true];
$made = 0; $full = null;
$before = count($store->items);
for ($k = 0; $k < 40 && !$full; $k++) {
    $before = count($store->items);
    $r = run('batch', req($ADMIN, ['items' => array_map(function ($i) use ($EC, $k) { return ['id' => uid(), 'equipment_id' => $EC, 'scheduled_date' => sprintf('%04d-%02d-%02d', 2100 + $k, 1 + intdiv($i, 28), 1 + $i % 28)]; }, range(0, 24))]));
    if ($r[0] === 200) $made += $r[1]['meta']['created']; else $full = $r;
}
check("capacity: a plan holds hundreds of records, then answers 413 too_large plan_full", $made >= 300 && $full && $full[0] === 413 && det($full, 'reason') === 'plan_full', "made=$made " . json_encode($full));
check("capacity: the refused request wrote nothing (checked before any journal or swap)", count($store->items) === $before);
echo "          (capacity measured: $made non-cancelled records in one equipment plan)\n";
$pid = json_decode($store->reg, true)['plans'][EA];
$saved = $store->items[$pid]['raw'];
$store->items[$pid]['raw'] = '[]';                // an administrator pressed Save on the item in Admin > Lists
$r = run('index', req($ADMIN));
check("damage: a plan rewritten outside the plugin -> 503 not_configured (never served as empty)", $r[0] === 503 && err($r) === 'not_configured', $r);
$store->items[$pid]['raw'] = $saved;
check("no route deletes: the engine never removes an item", count(array_filter($store->items, function ($i) { return $i['raw'] === null; })) === 0);

echo "\nmaintenance-unit: $passed passed, $failed failed\n";
exit($failed ? 1 : 0);
}
