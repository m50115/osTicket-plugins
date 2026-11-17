<?php
namespace OstWorkflow;

/**
 * ScheduledMaintenance store (OW-REQ-70, Preventive Maintenance module 8) on NATIVE osTicket storage: no plugin table.
 * Storage is one dedicated Custom List (MaintenanceListStore); the rules, the encodings and the compare-and-swap engine
 * live here; Handlers/Maintenances.php keeps the routes and the state machine (unchanged API, unchanged DTO).
 *
 * Layout (all JSON, ASCII-only: json_encode escapes non-ASCII, so the core's utf8 columns hold any text):
 *   registry  (list.configuration)  {"bcw":"BCW_PMREG|1","rev":n,"plans":{"<equipment_id>":<plan item id>}}
 *   plan      (one item / equipment){"bcw":"BCW_PM|1","eq":<equipment_id>,"rev":n,"r":{"<uuid>":core}}
 *   journal   (one item / write)    {"bcw":"BCW_PMJ|1","id":"<uuid>","eq":<equipment_id>,"c":[change...],"x":{...}}
 *   core = {d: scheduled_date, s: stored_status, t?: ticket_id, ca/ua: created/updated 'Y-m-d H:i:s.uuuuuu' UTC,
 *           cb/ub: staff, co?/cy?/e?: completed_on/completed_by/evidence_ref, nn?: journal holding completion_note,
 *           xr?: journal holding cancel_reason, j: [journal ids, in order]}
 *
 * Guarantees (same as the retired table, now enforced by single-row compare-and-swap):
 *   - uniqueness (equipment_id, scheduled_date) among non-cancelled records: the whole equipment lives in ONE plan row and
 *     every write to it is a swap of that row -> two writers can never both take a slot;
 *   - no lost update: a write re-reads and re-evaluates its rules (incl. the client's `base`) until its swap lands, so the
 *     outcome is the one of some sequential order (the retired `rev` CAS answered a lost race with 409; a retry that
 *     finds the record unchanged now simply applies);
 *   - a write is visible only once its plan swap lands; a journal is written before and is unreferenced (ignored) until then;
 *   - nothing is ever deleted; `updated_at` comes from the database clock (as before) and is strictly increasing per record.
 */
final class Maintenance {
    const SETTING_LIST   = 'installed_equipment_list_id';
    const SETTING_STORE  = 'maintenance_list_id';
    const SETTING_IMPORT = 'maintenance_import_enabled';
    const PLANNED   = 'PLANNED';
    const COMPLETED = 'COMPLETED';
    const CANCELLED = 'CANCELLED';
    const REG     = 'BCW_PMREG|1';
    const PLAN    = 'BCW_PM|1';
    const JOURNAL = 'BCW_PMJ|1';
    /** MySQL TEXT holds 65535 bytes; keep a margin. */
    const ITEM_MAX = 65000;
    const ATTEMPTS = 16;

    /** @var MaintenanceStore|null */
    private static $store;

    // ------------------------------------------------------------------ configuration

    /** Tests inject an in-memory store; null restores the native one. */
    static function useStore(MaintenanceStore $s = null) { self::$store = $s; }

    static function listId() {
        $raw = trim((string) Runtime::setting(self::SETTING_STORE, ''));
        if ($raw === '' || !ctype_digit($raw) || (int) $raw < 1)
            throw new ApiError('not_configured', "The plugin setting 'maintenance_list_id' (maintenance store list) is not set");
        if ((int) $raw === Runtime::intSetting(self::SETTING_LIST, 0))
            throw new ApiError('not_configured', "The plugin setting 'maintenance_list_id' must be a dedicated list, not the Installed Equipment list");
        return (int) $raw;
    }

    /** @return MaintenanceStore */
    static function store() {
        if (!self::$store) {
            $id = self::listId();
            self::$store = new MaintenanceListStore($id);
        }
        return self::$store;
    }

    /** 503 when the store list is not configured or not usable (the analog of the retired "table not installed"). */
    static function ready() {
        self::registry();
    }

    static function importEnabled() {
        return in_array(strtolower(trim((string) Runtime::setting(self::SETTING_IMPORT, ''))), ['1', 'yes', 'true', 'on'], true);
    }

    // ------------------------------------------------------------------ equipment

    /** @return string|null 'ACTIVE' | 'RETIRED' | null (not an item of the configured Installed Equipment list) */
    static function equipment($equipmentId) {
        $listId = Runtime::intSetting(self::SETTING_LIST, 0);
        if ($listId < 1)
            throw new ApiError('not_configured', "The plugin setting 'installed_equipment_list_id' (Installed Equipment list) is not set");
        require_once(INCLUDE_DIR . 'class.list.php');
        $it = \DynamicListItem::lookup((int) $equipmentId);
        if (!$it || (int) $it->get('list_id') !== $listId)
            return null;
        return $it->isEnabled() ? 'ACTIVE' : 'RETIRED';
    }

    /** Is the agent an administrator or the manager of ANY department (decision B of the design)? */
    static function isManagerOfAny(\Staff $s) {
        if ($s->isAdmin()) return true;
        require_once(INCLUDE_DIR . 'class.dept.php');
        return (bool) \Dept::objects()->filter(['manager_id' => (int) $s->getId()])->exists();
    }

    // ------------------------------------------------------------------ encodings

    private static function enc(array $v) {
        $s = json_encode($v);
        if (!is_string($s)) throw new ApiError('internal_error', 'Unable to encode the maintenance store');
        return $s;
    }

    private static function damaged($what) {
        return new ApiError('not_configured', "The maintenance store is damaged ($what was changed outside the plugin); restore it from the database backup");
    }

    /** @return array ['raw' => string, 'rev' => int, 'plans' => [eq => item id]] */
    static function registry() {
        $raw = self::store()->registry();
        $t = trim($raw);
        if ($t === '' || $t === '[]' || $t === '{}' || $t === 'null') {
            // An empty registry is adopted only on a list that holds no store item and feeds no form field: a setting
            // pointed at another list (or a reset registry) is refused instead of being served as an empty store.
            if (!self::store()->adoptable())
                throw new ApiError('not_configured', "The list named by 'maintenance_list_id' is not an empty dedicated list (it has store items or is used by a form field)");
            return ['raw' => $raw, 'rev' => 0, 'plans' => []];
        }
        $d = json_decode($raw, true);
        if (!is_array($d) || ($d['bcw'] ?? null) !== self::REG || !isset($d['rev'], $d['plans']) || !is_array($d['plans'])
            || (isset($d['list']) && (int) $d['list'] !== self::listIdOrZero()))
            throw new ApiError('not_configured', "The list named by 'maintenance_list_id' is not this maintenance store (its configuration holds other data)");
        $plans = [];
        foreach ($d['plans'] as $eq => $id) $plans[(int) $eq] = (int) $id;
        return ['raw' => $raw, 'rev' => (int) $d['rev'], 'plans' => $plans];
    }

    private static function encodeRegistry($rev, array $plans) {
        ksort($plans);
        $p = [];
        foreach ($plans as $eq => $id) $p[(string) $eq] = (int) $id;
        $s = self::enc(['bcw' => self::REG, 'list' => self::listIdOrZero(), 'rev' => (int) $rev, 'plans' => (object) $p]);
        if (strlen($s) > self::ITEM_MAX)
            throw new ApiError('too_large', 'The maintenance registry is full (' . count($plans) . ' equipments)', null, ['reason' => 'registry_full']);
        return $s;
    }

    /** The configured store list id (0 in tests without the setting). */
    private static function listIdOrZero() {
        $raw = trim((string) Runtime::setting(self::SETTING_STORE, ''));
        return ctype_digit($raw) ? (int) $raw : 0;
    }

    static function emptyPlan($eq) { return ['eq' => (int) $eq, 'rev' => 0, 'r' => []]; }

    static function decodePlan($raw, $eq) {
        $d = is_string($raw) ? json_decode($raw, true) : null;
        if (!is_array($d) || ($d['bcw'] ?? null) !== self::PLAN || (int) ($d['eq'] ?? 0) !== (int) $eq || !isset($d['rev']) || !is_array($d['r'] ?? null))
            throw self::damaged('the plan of equipment ' . (int) $eq);
        return ['eq' => (int) $eq, 'rev' => (int) $d['rev'], 'r' => $d['r']];
    }

    static function encodePlan(array $p) {
        $s = self::enc(['bcw' => self::PLAN, 'eq' => (int) $p['eq'], 'rev' => (int) $p['rev'], 'r' => (object) $p['r']]);
        if (strlen($s) > self::ITEM_MAX)
            throw new ApiError('too_large', 'The maintenance plan of this equipment is full (' . strlen($s) . ' bytes)', null,
                ['reason' => 'plan_full', 'equipment_id' => (int) $p['eq']]);
        return $s;
    }

    /** Throws too_large unless $p still fits once each of $uuids gets one more journal reference (checked before writing). */
    static function assertFits(array $p, array $ops) {
        foreach ($ops as $u => $op) {
            self::encodeJournal($u, $p['eq'], $op['c'], $op['x'] ?? []);
            $p['r'][$u]['j'][] = 2147483647;
            if (array_key_exists('completion_note', $op['x'] ?? [])) $p['r'][$u]['nn'] = 2147483647;
            if (array_key_exists('cancel_reason', $op['x'] ?? [])) $p['r'][$u]['xr'] = 2147483647;
        }
        $p['rev'] = (int) $p['rev'] + 1;
        self::encodePlan($p);
    }

    /** Size of one record's journal (import rejects oversized records per item). */
    static function journalFits($uuid, $eq, array $changes, array $x) {
        try { self::encodeJournal($uuid, $eq, $changes, $x); return true; } catch (ApiError $e) { return false; }
    }

    private static function encodeJournal($uuid, $eq, array $changes, array $x) {
        $s = self::enc(['bcw' => self::JOURNAL, 'id' => $uuid, 'eq' => (int) $eq, 'c' => $changes, 'x' => (object) $x]);
        if (strlen($s) > self::ITEM_MAX)
            throw new ApiError('too_large', 'The audit entry is too large to store', null, ['reason' => 'journal_too_large']);
        return $s;
    }

    private static function decodeJournal($raw, $jid) {
        $d = is_string($raw) ? json_decode($raw, true) : null;
        if (!is_array($d) || ($d['bcw'] ?? null) !== self::JOURNAL || !is_array($d['c'] ?? null))
            throw self::damaged('audit entry ' . (int) $jid);
        return $d;
    }

    // ------------------------------------------------------------------ reads

    /**
     * Every plan, freshly read. @return array [registry, [eq => ['id' => item id, 'raw' => raw, 'state' => plan]]]
     */
    static function loadAll() {
        $reg = self::registry();
        $raws = $reg['plans'] ? self::store()->items(array_values($reg['plans'])) : [];
        $out = [];
        foreach ($reg['plans'] as $eq => $id) {
            if (!array_key_exists($id, $raws)) throw self::damaged('plan item ' . $id);
            $out[$eq] = ['id' => $id, 'raw' => $raws[$id], 'state' => self::decodePlan($raws[$id], $eq)];
        }
        return [$reg, $out];
    }

    /** The plan of one equipment, or null when it has none yet. */
    static function loadPlan($eq) {
        $reg = self::registry();
        if (!isset($reg['plans'][(int) $eq])) return null;
        $id = $reg['plans'][(int) $eq];
        $raws = self::store()->items([$id]);
        if (!array_key_exists($id, $raws)) throw self::damaged('plan item ' . $id);
        return ['id' => $id, 'raw' => $raws[$id], 'state' => self::decodePlan($raws[$id], $eq)];
    }

    /** @return array|null [eq, plan row] of the record with this id */
    static function locate($uuid) {
        $reg = self::registry();
        if (!$reg['plans']) return null;
        $byId = array_flip($reg['plans']);
        $hit = null;
        foreach (self::store()->itemsContaining(array_values($reg['plans']), '"' . $uuid . '"') as $id => $raw) {
            $eq = $byId[$id];
            $st = self::decodePlan($raw, $eq);
            if (!isset($st['r'][$uuid])) continue;
            // An id stored under two equipments can only come from two concurrent creates of the same client id with
            // different equipment (never the app): the earliest creation wins, consistently for every reader.
            $key = [$st['r'][$uuid]['ca'], $eq];
            if ($hit === null || $key < $hit[0]) $hit = [$key, $eq, ['id' => $id, 'raw' => $raw, 'state' => $st]];
        }
        return $hit ? [$hit[1], $hit[2]] : null;
    }

    /** Loads the journals referenced by these cores into $cache (jid => decoded). */
    static function preload(array $cores, array &$cache) {
        $need = [];
        foreach ($cores as $c)
            foreach (array_merge($c['j'] ?? [], isset($c['nn']) ? [$c['nn']] : [], isset($c['xr']) ? [$c['xr']] : []) as $jid)
                if (!isset($cache[(int) $jid])) $need[(int) $jid] = true;
        if (!$need) return;
        $raws = self::store()->items(array_keys($need));
        foreach (array_keys($need) as $jid) {
            if (!array_key_exists($jid, $raws)) throw self::damaged('audit entry ' . $jid);
            $cache[$jid] = self::decodeJournal($raws[$jid], $jid);
        }
    }

    // ------------------------------------------------------------------ DTO

    private static function utc($v) { return $v ? str_replace(' ', 'T', substr($v, 0, 19)) . 'Z' : null; }

    static function changesOf(array $core, array $journals) {
        $out = [];
        foreach ($core['j'] ?? [] as $jid) foreach ($journals[(int) $jid]['c'] as $c) $out[] = $c;
        return $out;
    }

    /** The exact record of the API (keys and types of the retired table's DTO). */
    static function dto($uuid, $eq, array $core, array &$journals) {
        self::preload([$core], $journals);
        $note = isset($core['nn']) ? ($journals[(int) $core['nn']]['x']['completion_note'] ?? null) : null;
        $cancel = isset($core['xr']) ? ($journals[(int) $core['xr']]['x']['cancel_reason'] ?? null) : null;
        return [
            'id'              => (string) $uuid,
            'equipment_id'    => (int) $eq,
            'scheduled_date'  => $core['d'],
            'stored_status'   => $core['s'],
            'ticket_id'       => isset($core['t']) ? (int) $core['t'] : null,
            'completed_on'    => $core['co'] ?? null,
            'completed_by'    => isset($core['cy']) ? (int) $core['cy'] : null,
            'evidence_ref'    => $core['e'] ?? null,
            'completion_note' => $note,
            'cancel_reason'   => $cancel,
            'changes'         => self::changesOf($core, $journals),
            'created_at'      => self::utc($core['ca']),
            'created_by'      => (int) $core['cb'],
            'updated_at'      => self::utc($core['ua']),
            'updated_by'      => (int) $core['ub'],
        ];
    }

    static function lastChange(array $core, array &$journals) {
        self::preload([$core], $journals);
        $c = self::changesOf($core, $journals);
        return $c ? end($c) : null;
    }

    // ------------------------------------------------------------------ feed

    /**
     * One page of the feed ordered by (updated_at, id). @return array [[[uuid, eq, core], ...], more]
     */
    static function page($after, $limit, $equipmentId, $ticketId) {
        if ($equipmentId) {
            $p = self::loadPlan($equipmentId);
            $plans = $p ? [$equipmentId => $p] : [];
        } else {
            list(, $plans) = self::loadAll();
        }
        // One row per id: a copy left under another equipment by a cross-equipment id clash is never served twice.
        $canon = [];
        foreach ($plans as $eq => $p)
            foreach ($p['state']['r'] as $uuid => $core) {
                $k = [$core['ca'], (int) $eq];
                if (!isset($canon[$uuid]) || $k < $canon[$uuid]) $canon[$uuid] = $k;
            }
        $rows = [];
        foreach ($plans as $eq => $p)
            foreach ($p['state']['r'] as $uuid => $core) {
                if ($canon[$uuid][1] !== (int) $eq) continue;
                if ($ticketId && (int) ($core['t'] ?? 0) !== (int) $ticketId) continue;
                if ($after && ($core['ua'] < $after['t'] || ($core['ua'] === $after['t'] && strcmp((string) $uuid, $after['i']) <= 0))) continue;
                $rows[] = [(string) $uuid, (int) $eq, $core];
            }
        usort($rows, function ($a, $b) { return strcmp($a[2]['ua'], $b[2]['ua']) ?: strcmp($a[0], $b[0]); });
        $more = count($rows) > $limit;
        return [array_slice($rows, 0, $limit), $more];
    }

    static function cursor($uuid, array $core) {
        return Token::b64(json_encode(['t' => $core['ua'], 'i' => (string) $uuid]));
    }

    static function decodeCursor($c) {
        if ($c === null) return null;
        $d = json_decode(Token::unb64($c), true);
        if (!is_array($d) || !isset($d['t'], $d['i']) || !is_string($d['t']) || !is_string($d['i'])
            || !preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}(\.\d{1,6})?$/', $d['t']) || !preg_match(Documents::UUID, $d['i']))
            throw ApiError::validation('Invalid cursor', 'cursor');
        // Cursors of the retired table may carry fewer fractional digits: compare on the stored 6-digit form.
        $d['t'] = substr($d['t'] . (strpos($d['t'], '.') === false ? '.' : '') . '000000', 0, 26);
        return $d;
    }

    // ------------------------------------------------------------------ writes

    static function now() { return self::store()->now(); }

    /** Strictly increasing per record (the feed never sees an update "in the past" of the same record). */
    static function stamp($now, $prev = null) {
        if ($prev === null || strcmp($now, $prev) > 0) return $now;
        $t = \DateTime::createFromFormat('Y-m-d H:i:s.u', $prev, new \DateTimeZone('UTC'));
        $t->modify('+1 microsecond');
        return $t->format('Y-m-d H:i:s.u');
    }

    static function change($kind, $staffId, $reason, $from, $to, $now) {
        return ['kind' => $kind, 'at' => str_replace(' ', 'T', substr($now, 0, 19)) . 'Z', 'by' => (int) $staffId, 'reason' => $reason, 'from' => $from, 'to' => $to];
    }

    /**
     * Commits $after over $row (null: the equipment has no plan yet) with ONE swap of the plan row. $ops = [uuid =>
     * ['c' => [changes], 'x' => [completion_note?, cancel_reason?], 'restamp' => ['created' => bool, 'prev' => ua|null]?]].
     * The plan is registered first; then the database clock is read again and live writes are re-stamped with it right
     * before their journals and the swap (so a record never lands in the feed behind a watermark already served, beyond
     * the few milliseconds of one insert); a fresh journal is written per attempt (an earlier attempt's is orphaned).
     * @return array|false the committed plan state, or false when someone else changed the plan (re-read, re-evaluate).
     */
    static function commit($eq, $row, array $after, array $ops, array &$jids, array &$journals) {
        $s = self::store();
        if ($row === null) {
            $row = self::register($eq);
            if ($row === null) return false;
        }
        $now = self::now();
        foreach ($ops as $uuid => $op) {
            $x = $op['x'] ?? [];
            $core = $after['r'][$uuid];
            if (isset($op['restamp'])) {
                $core['ua'] = self::stamp($now, $op['restamp']['prev']);
                if ($op['restamp']['created']) $core['ca'] = $core['ua'];
                $at = str_replace(' ', 'T', substr($core['ua'], 0, 19)) . 'Z';
                foreach ($op['c'] as $k => $c) $op['c'][$k]['at'] = $at;
            }
            $raw = self::encodeJournal($uuid, $eq, $op['c'], $x);
            if (isset($jids[$uuid])) $s->orphan($jids[$uuid]);
            $jids[$uuid] = $jid = $s->insert(self::JOURNAL . ' ' . $uuid, $raw);
            $journals[$jid] = self::decodeJournal($raw, $jid);
            $core['j'] = array_merge($core['j'] ?? [], [$jid]);
            if (array_key_exists('completion_note', $x)) $core['nn'] = $jid;
            if (array_key_exists('cancel_reason', $x)) $core['xr'] = $jid;
            $after['r'][$uuid] = $core;
        }
        $after['eq'] = (int) $eq;
        $after['rev'] = (int) $row['state']['rev'] + 1;
        if (!$s->cas($row['id'], $row['raw'], self::encodePlan($after))) return false;
        return $after;
    }

    /**
     * Copies of $uuid stored under OTHER equipments: [eq => core]. Only two concurrent creates of the same client id with
     * different equipment (never the app) can produce one.
     */
    static function copiesElsewhere($uuid, $eq) {
        $reg = self::registry();
        $ids = $reg['plans']; unset($ids[(int) $eq]);
        if (!$ids) return [];
        $byId = array_flip($ids); $out = [];
        foreach (self::store()->itemsContaining(array_values($ids), '"' . $uuid . '"') as $id => $raw) {
            $st = self::decodePlan($raw, $byId[$id]);
            if (isset($st['r'][$uuid])) $out[$byId[$id]] = $st['r'][$uuid];
        }
        return $out;
    }

    /** Removes a record this request just created (compensation of a cross-equipment id clash), with its own CAS loop. */
    static function retract($eq, $uuid) {
        for ($i = 0; $i < self::ATTEMPTS; $i++) {
            self::backoff($i);
            $row = self::loadPlan($eq);
            if (!$row || !isset($row['state']['r'][$uuid])) return;
            $after = $row['state']; unset($after['r'][$uuid]); $after['rev']++;
            if (self::store()->cas($row['id'], $row['raw'], self::encodePlan($after))) {
                foreach ($row['state']['r'][$uuid]['j'] ?? [] as $jid) self::store()->orphan($jid);
                return;
            }
        }
        throw self::busy();
    }

    /** Gives the equipment its (empty) plan row. @return array|null the row, or null when another writer registered one. */
    private static function register($eq) {
        $s = self::store();
        $empty = self::emptyPlan($eq);
        $raw = self::encodePlan($empty);
        $id = null;
        for ($i = 0; $i < self::ATTEMPTS; $i++) {
            $reg = self::registry();
            if (isset($reg['plans'][(int) $eq])) {
                if ($id !== null) $s->orphan($id);
                return null;
            }
            if ($id === null) $id = $s->insert(self::PLAN . ' eq=' . (int) $eq, $raw);
            $plans = $reg['plans'];
            $plans[(int) $eq] = $id;
            if ($s->casRegistry($reg['raw'], self::encodeRegistry($reg['rev'] + 1, $plans)))
                return ['id' => $id, 'raw' => $raw, 'state' => $empty];
            self::backoff($i);
        }
        if ($id !== null) $s->orphan($id);
        throw self::busy();
    }

    /** Journals written by attempts that did not end referenced. */
    static function orphanUnreferenced(array $jids, array $referenced) {
        foreach ($jids as $uuid => $jid)
            if (empty($referenced[$jid])) self::store()->orphan($jid);
    }

    static function backoff($attempt) {
        if ($attempt > 0) usleep(min(200000, 2000 * (1 << min($attempt, 7))) + mt_rand(0, 3000));
    }

    static function busy() {
        // 429 (not a final 409): a retryable answer; the client treats it as server pacing (Retry-After, no budget).
        return new ApiError('rate_limited', 'The maintenance plan is being changed by other requests; retry', null,
            ['reason' => 'busy', 'retry_after' => 2], ['Retry-After' => '2']);
    }
}
