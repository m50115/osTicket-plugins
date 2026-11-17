<?php
namespace OstWorkflow\Handlers;

use OstWorkflow\ApiError;
use OstWorkflow\Documents;
use OstWorkflow\Maintenance;
use OstWorkflow\Request;
use OstWorkflow\Res;
use OstWorkflow\Ticketing;

/**
 * ScheduledMaintenance store (OW-REQ-70, module 8) on native osTicket storage (Maintenance / MaintenanceListStore: one
 * dedicated Custom List, no plugin table). The state machine, the audit (`changes[]`) and the rules live here; the client
 * keeps the derived views (month / upcoming / overdue). Records are never deleted: cancel instead.
 *
 * Writes follow the plugin's `base` convention (Tickets): the client sends the value it saw; current == desired -> 200
 * {applied:false}; current == base -> apply; else 409 conflict {reason, current, base, last_change, record}.
 *   reschedule  base = scheduled_date seen        complete / cancel / revert  base = stored_status seen
 *   link / unlink  base = ticket_id seen (null when none)
 * Every write is evaluated against the freshest plan of the equipment and committed with one compare-and-swap of that
 * plan; a lost swap re-reads and re-evaluates the same request (Maintenance::commit), so concurrent writers serialize.
 * Authorization: reads `auth`; create / reschedule / complete / link / unlink `anydept.ticket.create`; cancel and revert
 * `maintenance.manager` (administrator, or manager of ANY department); link also needs `ticket.view` on the target Ticket.
 * `POST /maintenances/import` (one-shot migration of the retired table, verbatim records) is administrator-only and
 * answers 403 unless the plugin setting `maintenance_import_enabled` is on.
 */
final class Maintenances {
    const BATCH_MAX = 200;
    const LIMIT_DEFAULT = 100;
    const LIMIT_MAX = 200;
    const TEXT_MAX = 2000;
    const ID = '(?P<uuid>[0-9a-fA-F-]{36})';

    static function routes() {
        $id = '/maintenances/' . self::ID;
        return [
            ['GET',  '/maintenances',            'index',      ['policy' => 'auth']],
            ['POST', '/maintenances/batch',      'batch',      ['policy' => 'anydept.ticket.create']],
            ['POST', '/maintenances/import',     'import',     ['policy' => 'maintenance.import', 'max_body' => 4194304]],
            ['GET',  $id,                        'show',       ['policy' => 'auth']],
            ['POST', "$id/reschedule",           'reschedule', ['policy' => 'anydept.ticket.create']],
            ['POST', "$id/complete",             'complete',   ['policy' => 'anydept.ticket.create']],
            ['POST', "$id/cancel",               'cancel',     ['policy' => 'maintenance.manager']],
            ['POST', "$id/revert",               'revert',     ['policy' => 'maintenance.manager']],
            ['POST', "$id/link",                 'link',       ['policy' => 'anydept.ticket.create']],
            ['POST', "$id/unlink",               'unlink',     ['policy' => 'anydept.ticket.create']],
        ];
    }

    static function policy() {
        return [
            // Decision B (design §13): no Ticket, so no natural department -> administrator or manager of any department.
            'maintenance.manager' => function (Request $req) {
                if (!Maintenance::isManagerOfAny($req->staff))
                    throw new ApiError('forbidden', 'Only an administrator or a department manager can do this', null, ['reason' => 'manager_required']);
            },
            'maintenance.import' => function (Request $req) {
                if (!$req->staff->isAdmin())
                    throw new ApiError('forbidden', 'Only an administrator can import maintenances', null, ['reason' => 'admin_required']);
                if (!Maintenance::importEnabled())
                    throw new ApiError('forbidden', "The import is disabled (plugin setting 'maintenance_import_enabled')", null, ['reason' => 'import_disabled']);
            },
        ];
    }

    // ==================================================================
    // READ
    // ==================================================================

    /**
     * GET /maintenances?cursor=&limit=&equipment_id=&ticket_id=
     * Every state (cancelled included), ordered by (updated_at, id) ascending: this IS the incremental feed. `meta.watermark`
     * is the cursor to send next time to receive only what changed since (also when this page is empty or last).
     */
    static function index(Request $req) {
        Maintenance::ready();
        $limit = $req->intQuery('limit', self::LIMIT_DEFAULT, 1, self::LIMIT_MAX);
        $after = Maintenance::decodeCursor($req->q('cursor'));
        $eq = self::idQuery($req, 'equipment_id');
        $tk = self::idQuery($req, 'ticket_id');
        list($rows, $more) = Maintenance::page($after, $limit, $eq, $tk);
        $journals = [];
        Maintenance::preload(array_column($rows, 2), $journals);
        $items = [];
        foreach ($rows as $r) $items[] = Maintenance::dto($r[0], $r[1], $r[2], $journals);
        $last = $rows ? end($rows) : null;
        $wm = $last ? Maintenance::cursor($last[0], $last[2]) : $req->q('cursor');
        return Res::page($items, $more ? $wm : null, ['watermark' => $wm]);
    }

    /** GET /maintenances/{id} */
    static function show(Request $req) {
        Maintenance::ready();
        list($eq, $row, $uuid) = self::load($req);
        $j = [];
        return Res::ok(Maintenance::dto($uuid, $eq, $row['state']['r'][$uuid], $j));
    }

    // ==================================================================
    // CREATE (batch)
    // ==================================================================

    /**
     * POST /maintenances/batch {items:[{id, equipment_id, scheduled_date}]} (max 200). Idempotent per request (Idempotency-Key)
     * AND per item (client uuid: inserts when absent). 200 with one result per item, in order:
     *   {id, result:'created'|'exists'|'rejected', code?, message?, maintenance?}
     * rejected codes: invalid_id, invalid_equipment, invalid_date, equipment_not_found (not in Installed Equipment),
     * equipment_retired, duplicate (details.existing_id holds the (equipment, date) slot among non-cancelled).
     */
    static function batch(Request $req) {
        Maintenance::ready();
        $items = self::items($req);
        $staffId = (int) $req->staff->getId();
        $equipment = [];
        return self::engine($items, function ($it, $now) use ($staffId, &$equipment) {
            $eid = $it['equipment_id'] ?? null;
            if (!is_int($eid) || $eid < 1)
                return ['reject' => ['invalid_equipment', "'equipment_id' must be a positive integer"]];
            $date = self::dateOrNull($it['scheduled_date'] ?? null);
            if ($date === null)
                return ['reject' => ['invalid_date', "'scheduled_date' must be a valid date YYYY-MM-DD"]];
            if (!array_key_exists($eid, $equipment))
                $equipment[$eid] = Maintenance::equipment($eid);
            if ($equipment[$eid] === null)
                return ['reject' => ['equipment_not_found', 'The equipment is not an item of the Installed Equipment list']];
            if ($equipment[$eid] !== 'ACTIVE')
                return ['reject' => ['equipment_retired', 'The equipment is retired: no new maintenance can be scheduled']];
            return ['eq' => $eid, 'core' => ['d' => $date, 's' => Maintenance::PLANNED, 'ca' => $now, 'cb' => $staffId, 'ua' => $now, 'ub' => $staffId, 'j' => []],
                    'c' => [Maintenance::change('created', $staffId, null, null, ['equipment_id' => $eid, 'scheduled_date' => $date], $now)], 'x' => []];
        }, false);
    }

    /**
     * POST /maintenances/import {items:[record...]} (max 200) — one-shot migration of the retired table: each item is a
     * record exactly as `GET /maintenances` of the retired store served it, stored verbatim (ids, dates, staff, audit).
     * Per item: created | exists (the stored record is identical) | rejected (invalid_record, duplicate, mismatch with
     * details.current). Equipment membership and evidence are NOT re-validated (they were when the record was written).
     */
    static function import(Request $req) {
        Maintenance::ready();
        $items = self::items($req);
        return self::engine($items, function ($it) {
            $bad = self::verbatimError($it);
            if ($bad !== null) return ['reject' => ['invalid_record', $bad]];
            $ts = function ($z) { return substr($z, 0, 10) . ' ' . substr($z, 11, 8) . '.000000'; };
            $core = ['d' => $it['scheduled_date'], 's' => $it['stored_status'], 'ca' => $ts($it['created_at']), 'cb' => $it['created_by'],
                     'ua' => $ts($it['updated_at']), 'ub' => $it['updated_by'], 'j' => []];
            if ($it['ticket_id'] !== null) $core['t'] = $it['ticket_id'];
            if ($it['completed_on'] !== null) $core['co'] = $it['completed_on'];
            if ($it['completed_by'] !== null) $core['cy'] = $it['completed_by'];
            if ($it['evidence_ref'] !== null) $core['e'] = $it['evidence_ref'];
            $x = [];
            if ($it['completion_note'] !== null) $x['completion_note'] = $it['completion_note'];
            if ($it['cancel_reason'] !== null) $x['cancel_reason'] = $it['cancel_reason'];
            return ['eq' => $it['equipment_id'], 'core' => $core, 'c' => array_values($it['changes']), 'x' => $x, 'verbatim' => $it];
        }, true);
    }

    private static function items(Request $req) {
        $b = $req->json();
        $items = $b['items'] ?? null;
        if (!is_array($items) || !$items || array_keys($items) !== range(0, count($items) - 1))
            throw ApiError::validation("'items' must be a non-empty list", 'items');
        if (count($items) > self::BATCH_MAX)
            throw ApiError::validation("'items' has more than " . self::BATCH_MAX . ' entries', 'items');
        return $items;
    }

    /**
     * Shared engine of batch and import: evaluates every item in request order against working copies of the plans
     * (exactly the sequential semantics of the retired store), then commits each touched equipment with one swap. A lost
     * swap re-reads everything and re-evaluates the items whose equipment is not committed yet; ids this request created
     * keep answering `created`.
     */
    private static function engine(array $items, callable $build, $verbatim) {
        $createdByUs = [];      // uuid => true (committed by this request)
        $jids = [];             // uuid => journal id (stable across attempts)
        $journals = [];
        for ($attempt = 0; $attempt < Maintenance::ATTEMPTS; $attempt++) {
            Maintenance::backoff($attempt);
            list(, $plans) = Maintenance::loadAll();
            $work = [];         // eq => plan state
            $index = [];        // uuid => eq
            foreach ($plans as $eq => $p) {
                $work[$eq] = $p['state'];
                foreach ($p['state']['r'] as $u => $c) $index[(string) $u] = $eq;
            }
            $now = Maintenance::now();
            $out = []; $ops = []; $answered = [];
            try {
                foreach ($items as $it) {
                    $id = is_array($it) && isset($it['id']) && is_string($it['id']) ? strtolower($it['id']) : '';
                    if (!preg_match(Documents::UUID, $id)) {
                        $out[] = self::rejected($id, 'invalid_id', "'id' must be a client-generated UUID");
                        continue;
                    }
                    if (isset($index[$id])) {
                        // The first occurrence of an id an earlier attempt of THIS request committed is still `created`;
                        // a repeated id inside the request is `exists` (sequential semantics of the retired store).
                        $mine = isset($createdByUs[$id]) && !isset($answered[$id]);
                        $answered[$id] = true;
                        $out[] = ['id' => $id, 'result' => $mine ? 'created' : 'exists', '_eq' => $index[$id], '_verbatim' => $verbatim ? $it : null];
                        continue;
                    }
                    $b = $build($it, $now);
                    if (isset($b['reject'])) {
                        $out[] = self::rejected($id, $b['reject'][0], $b['reject'][1]);
                        continue;
                    }
                    $eq = $b['eq'];
                    if (!isset($work[$eq])) $work[$eq] = Maintenance::emptyPlan($eq);
                    if ($b['core']['s'] !== Maintenance::CANCELLED && ($h = self::holder($work[$eq], $b['core']['d'])) !== null) {
                        $out[] = self::rejected($id, 'duplicate', 'The equipment already has a non-cancelled maintenance on that date', ['details' => ['existing_id' => $h]]);
                        continue;
                    }
                    $work[$eq]['r'][$id] = $b['core'];
                    $ops[$eq][$id] = ['c' => $b['c'], 'x' => $b['x']];
                    $index[$id] = $eq;
                    $answered[$id] = true;
                    $out[] = ['id' => $id, 'result' => 'created', '_eq' => $eq];
                }
                ksort($ops);
                // Capacity is checked for every touched plan before anything is written: the request fails whole.
                foreach ($ops as $eq => $o) Maintenance::assertFits($work[$eq], array_keys($o));
                $lost = false;
                foreach ($ops as $eq => $o) {
                    $after = Maintenance::commit($eq, $plans[$eq] ?? null, $work[$eq], $o, $jids, $journals);
                    if ($after === false) { $lost = true; break; }
                    $work[$eq] = $after;
                    foreach ($o as $u => $_) $createdByUs[$u] = true;
                }
            } catch (ApiError $e) {
                Maintenance::orphanUnreferenced($jids, self::referenced($jids, $createdByUs));
                throw $e;
            }
            if ($lost) continue;
            Maintenance::orphanUnreferenced($jids, self::referenced($jids, $createdByUs));
            return self::answer($out, $work, $journals, $verbatim);
        }
        Maintenance::orphanUnreferenced($jids, self::referenced($jids, $createdByUs));
        throw Maintenance::busy();
    }

    private static function referenced(array $jids, array $createdByUs) {
        $r = [];
        foreach ($jids as $u => $jid) if (isset($createdByUs[$u])) $r[$jid] = true;
        return $r;
    }

    private static function answer(array $out, array $work, array &$journals, $verbatim) {
        $cores = [];
        foreach ($out as $o) if (isset($o['_eq'])) $cores[] = $work[$o['_eq']]['r'][$o['id']];
        Maintenance::preload($cores, $journals);
        $n = ['created' => 0, 'exists' => 0, 'rejected' => 0];
        foreach ($out as $k => $o) {
            if (isset($o['_eq'])) {
                $dto = Maintenance::dto($o['id'], $o['_eq'], $work[$o['_eq']]['r'][$o['id']], $journals);
                if ($verbatim && $o['result'] === 'exists' && !self::sameRecord($dto, $o['_verbatim']))
                    $o = self::rejected($o['id'], 'mismatch', 'A different record with this id is already stored', ['details' => ['current' => $dto]]);
                else
                    $o = ['id' => $o['id'], 'result' => $o['result'], 'maintenance' => $dto];
            }
            unset($o['_eq'], $o['_verbatim']);
            $n[$o['result']]++;
            $out[$k] = $o;
        }
        return Res::ok($out, $n);
    }

    private static function rejected($id, $code, $msg, array $extra = []) {
        return array_merge(['id' => $id !== '' ? $id : null, 'result' => 'rejected', 'code' => $code, 'message' => $msg], $extra);
    }

    /** The non-cancelled record of this plan on this date, if any. */
    private static function holder(array $plan, $date, $except = null) {
        foreach ($plan['r'] as $u => $c)
            if ($c['d'] === $date && $c['s'] !== Maintenance::CANCELLED && (string) $u !== $except) return (string) $u;
        return null;
    }

    // ==================================================================
    // TRANSITIONS
    // ==================================================================

    /** POST /maintenances/{id}/reschedule {base:<date seen>, scheduled_date, reason} — PLANNED only. */
    static function reschedule(Request $req) {
        Maintenance::ready();
        $new = self::dateOrNull($req->input('scheduled_date')) ?: self::bad("'scheduled_date' must be a valid date YYYY-MM-DD", 'scheduled_date');
        $reason = self::text($req->input('reason'), 'reason', true);
        $base = self::baseDate($req);
        $staffId = (int) $req->staff->getId();
        return self::transition($req, function ($uuid, $r, array $plan, $now, &$j) use ($new, $reason, $base, $staffId) {
            self::mustBe($plan['eq'], $uuid, $r, Maintenance::PLANNED, $j);
            if ($r['d'] === $new)
                return null;
            if ($r['d'] !== $base)
                self::conflict('base_mismatch', $plan['eq'], $uuid, $r, $r['d'], $base, $j);
            if (($h = self::holder($plan, $new, $uuid)) !== null)
                self::conflict('duplicate', $plan['eq'], $uuid, $r, $r['d'], $base, $j, ['existing_id' => $h]);
            $c = Maintenance::change('reschedule', $staffId, $reason, $r['d'], $new, $now);
            $r['d'] = $new;
            return [$r, $c, []];
        });
    }

    /** POST /maintenances/{id}/complete {base:<status seen>, completed_on, evidence_ref?, completion_note?} — PLANNED only. */
    static function complete(Request $req) {
        Maintenance::ready();
        $on = self::dateOrNull($req->input('completed_on')) ?: self::bad("'completed_on' must be a valid date YYYY-MM-DD", 'completed_on');
        global $cfg;
        $today = (new \DateTime('now', new \DateTimeZone($cfg->getTimezone($req->staff))))->format('Y-m-d');
        if ($on > $today)
            throw ApiError::validation("'completed_on' cannot be in the future (staff time zone: today is $today)", 'completed_on');
        $ref = $req->input('evidence_ref');
        if ($ref !== null && (!is_string($ref) || !preg_match('/^doc:([0-9a-fA-F-]{36})@(\d{1,4})$/', $ref, $m) || !preg_match(Documents::UUID, $m[1])))
            throw ApiError::validation("'evidence_ref' must be doc:<uuid>@<version>", 'evidence_ref');
        $note = self::text($req->input('completion_note'), 'completion_note', false);
        if ($ref === null && $note === null)
            throw ApiError::validation("'completion_note' is required when there is no 'evidence_ref'", 'completion_note');
        if ($ref !== null) $ref = 'doc:' . strtolower($m[1]) . '@' . (int) $m[2];
        $base = self::baseStatus($req);
        $staffId = (int) $req->staff->getId();
        return self::transition($req, function ($uuid, $r, array $plan, $now, &$j) use ($on, $ref, $note, $base, $staffId) {
            if ($r['s'] === Maintenance::COMPLETED) {
                $dto = Maintenance::dto($uuid, $plan['eq'], $r, $j);
                if ($dto['completed_on'] === $on && $dto['evidence_ref'] === $ref && $dto['completion_note'] === $note)
                    return null;
                self::conflict('base_mismatch', $plan['eq'], $uuid, $r, $r['s'], $base, $j);
            }
            if ($r['s'] !== $base)
                self::conflict('base_mismatch', $plan['eq'], $uuid, $r, $r['s'], $base, $j);
            self::mustBe($plan['eq'], $uuid, $r, Maintenance::PLANNED, $j);
            if ($ref !== null) {
                if (!isset($r['t']))
                    throw ApiError::validation("'evidence_ref' needs a linked Ticket", 'evidence_ref', ['reason' => 'no_ticket']);
                $doc = Documents::find(['uuid' => substr($ref, 4, 36), 'version' => (int) substr($ref, 41)]);
                if (!$doc || $doc['ticket_id'] !== (int) $r['t'])
                    throw ApiError::validation("'evidence_ref' is not a published document of the linked Ticket", 'evidence_ref', ['reason' => 'foreign_evidence']);
            }
            $c = Maintenance::change('complete', $staffId, $note, 'PLANNED', ['stored_status' => 'COMPLETED', 'completed_on' => $on, 'evidence_ref' => $ref, 'completion_note' => $note], $now);
            $r['s'] = Maintenance::COMPLETED;
            $r['co'] = $on;
            $r['cy'] = $staffId;
            if ($ref !== null) $r['e'] = $ref; else unset($r['e']);
            unset($r['nn']);
            return [$r, $c, $note !== null ? ['completion_note' => $note] : []];
        });
    }

    /** POST /maintenances/{id}/cancel {base:<status seen>, reason} — manager; PLANNED only; the record stays. */
    static function cancel(Request $req) {
        Maintenance::ready();
        $reason = self::text($req->input('reason'), 'reason', true);
        $base = self::baseStatus($req);
        $staffId = (int) $req->staff->getId();
        return self::transition($req, function ($uuid, $r, array $plan, $now, &$j) use ($reason, $base, $staffId) {
            if ($r['s'] === Maintenance::CANCELLED)
                return null;
            if ($r['s'] !== $base)
                self::conflict('base_mismatch', $plan['eq'], $uuid, $r, $r['s'], $base, $j);
            self::mustBe($plan['eq'], $uuid, $r, Maintenance::PLANNED, $j);
            $c = Maintenance::change('cancel', $staffId, $reason, 'PLANNED', 'CANCELLED', $now);
            $r['s'] = Maintenance::CANCELLED;
            return [$r, $c, ['cancel_reason' => $reason]];
        });
    }

    /** POST /maintenances/{id}/revert {base:<status seen>, reason} — manager; COMPLETED -> PLANNED; completion fields kept in changes[]. */
    static function revert(Request $req) {
        Maintenance::ready();
        $reason = self::text($req->input('reason'), 'reason', true);
        $base = self::baseStatus($req);
        $staffId = (int) $req->staff->getId();
        return self::transition($req, function ($uuid, $r, array $plan, $now, &$j) use ($reason, $base, $staffId) {
            if ($r['s'] === Maintenance::PLANNED)
                return null;
            if ($r['s'] !== $base)
                self::conflict('base_mismatch', $plan['eq'], $uuid, $r, $r['s'], $base, $j);
            self::mustBe($plan['eq'], $uuid, $r, Maintenance::COMPLETED, $j);
            $dto = Maintenance::dto($uuid, $plan['eq'], $r, $j);
            $from = ['stored_status' => 'COMPLETED', 'completed_on' => $dto['completed_on'], 'completed_by' => $dto['completed_by'],
                     'evidence_ref' => $dto['evidence_ref'], 'completion_note' => $dto['completion_note']];
            $c = Maintenance::change('revert', $staffId, $reason, $from, ['stored_status' => 'PLANNED'], $now);
            $r['s'] = Maintenance::PLANNED;
            unset($r['co'], $r['cy'], $r['e'], $r['nn']);
            return [$r, $c, []];
        });
    }

    /** POST /maintenances/{id}/link {base:null, ticket_id, reason?} — PLANNED without Ticket; needs ticket.view on the target. */
    static function link(Request $req) {
        Maintenance::ready();
        $tid = $req->input('ticket_id');
        if (!is_int($tid) || $tid < 1)
            throw ApiError::validation("'ticket_id' must be a positive integer", 'ticket_id');
        $reason = self::text($req->input('reason'), 'reason', false);
        $base = self::baseTicket($req);
        $staffId = (int) $req->staff->getId();
        $staff = $req->staff;
        $seen = null;
        return self::transition($req, function ($uuid, $r, array $plan, $now, &$j) use ($tid, $reason, $base, $staffId, $staff, &$seen) {
            // Same answer for "does not exist" and "not yours": linking cannot probe Tickets the agent may not see.
            if ($seen === null) {
                require_once(INCLUDE_DIR . 'class.ticket.php');
                $t = \Ticket::lookup($tid);
                $seen = $t && $t->checkStaffPerm($staff);
            }
            if (!$seen)
                throw new ApiError('forbidden', 'You cannot access this ticket', 'ticket_id');
            self::mustBe($plan['eq'], $uuid, $r, Maintenance::PLANNED, $j);
            $cur = isset($r['t']) ? (int) $r['t'] : null;
            if ($cur === $tid)
                return null;
            if ($cur !== $base)
                self::conflict('base_mismatch', $plan['eq'], $uuid, $r, $cur, $base, $j);
            if ($cur !== null)
                self::conflict('ticket_already_linked', $plan['eq'], $uuid, $r, $cur, $base, $j);
            $c = Maintenance::change('link', $staffId, $reason, null, $tid, $now);
            $r['t'] = $tid;
            return [$r, $c, []];
        });
    }

    /** POST /maintenances/{id}/unlink {base:<ticket_id seen>, reason} — PLANNED; needs no access to the Ticket. */
    static function unlink(Request $req) {
        Maintenance::ready();
        $reason = self::text($req->input('reason'), 'reason', true);
        $base = self::baseTicket($req);
        $staffId = (int) $req->staff->getId();
        return self::transition($req, function ($uuid, $r, array $plan, $now, &$j) use ($reason, $base, $staffId) {
            $cur = isset($r['t']) ? (int) $r['t'] : null;
            self::mustBe($plan['eq'], $uuid, $r, Maintenance::PLANNED, $j);
            if ($cur === null)
                return null;
            if ($cur !== $base)
                self::conflict('base_mismatch', $plan['eq'], $uuid, $r, $cur, $base, $j);
            $c = Maintenance::change('unlink', $staffId, $reason, $cur, null, $now);
            unset($r['t']);
            return [$r, $c, []];
        });
    }

    /**
     * Runs one transition: $eval($uuid, $core, $plan, $now, &$journals) returns null (no-op: 200 applied=false), throws
     * (validation / conflict, nothing written) or returns [new core, change, x]; the engine stamps updated_at/by, writes
     * the journal and swaps the plan, re-evaluating on a lost swap.
     */
    private static function transition(Request $req, callable $eval) {
        $uuid = strtolower((string) $req->param('uuid'));
        $staffId = (int) $req->staff->getId();
        $jids = []; $journals = [];
        try {
            for ($attempt = 0; $attempt < Maintenance::ATTEMPTS; $attempt++) {
                Maintenance::backoff($attempt);
                list($eq, $row) = self::load($req);
                $core = $row['state']['r'][$uuid];
                $now = Maintenance::now();
                $res = $eval($uuid, $core, $row['state'], $now, $journals);
                if ($res === null) {
                    Maintenance::orphanUnreferenced($jids, []);
                    return Res::ok(Maintenance::dto($uuid, $eq, $core, $journals), ['applied' => false]);
                }
                list($new, $change, $x) = $res;
                $new['ua'] = Maintenance::stamp($now, $core['ua']);
                $new['ub'] = $staffId;
                $after = $row['state'];
                $after['r'][$uuid] = $new;
                $done = Maintenance::commit($eq, $row, $after, [$uuid => ['c' => [$change], 'x' => $x]], $jids, $journals);
                if ($done !== false)
                    return Res::ok(Maintenance::dto($uuid, $eq, $done['r'][$uuid], $journals), ['applied' => true]);
            }
        } catch (ApiError $e) {
            Maintenance::orphanUnreferenced($jids, []);
            throw $e;
        }
        Maintenance::orphanUnreferenced($jids, []);
        throw Maintenance::busy();
    }

    // ==================================================================
    // helpers
    // ==================================================================

    /** @return array [eq, plan row, uuid] */
    private static function load(Request $req) {
        $uuid = strtolower((string) $req->param('uuid'));
        $loc = preg_match(Documents::UUID, $uuid) ? Maintenance::locate($uuid) : null;
        if (!$loc) throw ApiError::notFound('maintenance');
        return [$loc[0], $loc[1], $uuid];
    }

    private static function conflict($reason, $eq, $uuid, array $r, $current, $base, array &$j, array $extra = []) {
        throw ApiError::conflict('The maintenance changed on the server (' . $reason . ')', array_merge([
            'reason' => $reason, 'current' => $current, 'base' => $base,
            'last_change' => Maintenance::lastChange($r, $j), 'record' => Maintenance::dto($uuid, $eq, $r, $j),
        ], $extra));
    }

    private static function mustBe($eq, $uuid, array $r, $status, array &$j) {
        if ($r['s'] !== $status)
            self::conflict('invalid_state', $eq, $uuid, $r, $r['s'], null, $j, ['required' => $status]);
    }

    private static function bad($msg, $field) { throw ApiError::validation($msg, $field); }

    private static function dateOrNull($v) {
        if (!is_string($v) || !preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $v, $m) || !checkdate((int) $m[2], (int) $m[3], (int) $m[1]) || (int) $m[1] < 1000)
            return null;
        return $v;
    }

    private static function text($v, $field, $required) {
        if ($v === null || (is_string($v) && trim($v) === '')) {
            if ($required) throw ApiError::validation("'$field' is required", $field);
            return null;
        }
        if (!is_string($v) || strlen($v) > self::TEXT_MAX)
            throw ApiError::validation("'$field' must be text of at most " . self::TEXT_MAX . ' bytes', $field);
        return trim($v);
    }

    private static function baseDate(Request $req) {
        $b = Ticketing::requireBase($req);
        return self::dateOrNull($b) ?: self::bad("'base' must be the scheduled_date you saw (YYYY-MM-DD)", 'base');
    }

    private static function baseStatus(Request $req) {
        $b = Ticketing::requireBase($req);
        if (!in_array($b, [Maintenance::PLANNED, Maintenance::COMPLETED, Maintenance::CANCELLED], true))
            throw ApiError::validation("'base' must be the stored_status you saw", 'base');
        return $b;
    }

    private static function baseTicket(Request $req) {
        $b = Ticketing::requireBase($req);
        if ($b !== null && (!is_int($b) || $b < 1))
            throw ApiError::validation("'base' must be the ticket_id you saw (null when none)", 'base');
        return $b;
    }

    private static function idQuery(Request $req, $key) {
        $v = $req->q($key);
        if ($v === null) return 0;
        if (!ctype_digit((string) $v) || (int) $v < 1)
            throw ApiError::validation("'$key' must be a positive integer", $key);
        return (int) $v;
    }

    // ------------------------------------------------------------------ import validation

    /** @return string|null why this is not a record of the retired store's DTO */
    private static function verbatimError($it) {
        $keys = ['id', 'equipment_id', 'scheduled_date', 'stored_status', 'ticket_id', 'completed_on', 'completed_by', 'evidence_ref',
                 'completion_note', 'cancel_reason', 'changes', 'created_at', 'created_by', 'updated_at', 'updated_by'];
        if (!is_array($it)) return 'not an object';
        $got = array_keys($it); sort($got); $want = $keys; sort($want);
        if ($got !== $want) return 'keys must be exactly: ' . implode(', ', $keys);
        $z = '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/';
        $posOrNull = function ($v) { return $v === null || (is_int($v) && $v >= 1); };
        $strOrNull = function ($v) { return $v === null || is_string($v); };
        if (!is_int($it['equipment_id']) || $it['equipment_id'] < 1) return 'equipment_id';
        if (self::dateOrNull($it['scheduled_date']) === null) return 'scheduled_date';
        if (!in_array($it['stored_status'], [Maintenance::PLANNED, Maintenance::COMPLETED, Maintenance::CANCELLED], true)) return 'stored_status';
        if (!$posOrNull($it['ticket_id']) || !$posOrNull($it['completed_by'])) return 'ticket_id / completed_by';
        if ($it['completed_on'] !== null && self::dateOrNull($it['completed_on']) === null) return 'completed_on';
        if ($it['evidence_ref'] !== null && (!is_string($it['evidence_ref']) || !preg_match('/^doc:[0-9a-f-]{36}@\d{1,4}$/', $it['evidence_ref']))) return 'evidence_ref';
        if (!$strOrNull($it['completion_note']) || !$strOrNull($it['cancel_reason'])) return 'completion_note / cancel_reason';
        if (!is_array($it['changes']) || array_keys($it['changes']) !== range(0, count($it['changes']) - 1) || !$it['changes']) return 'changes';
        foreach ($it['changes'] as $c)
            if (!is_array($c) || array_diff(['kind', 'at', 'by', 'reason', 'from', 'to'], array_keys($c)) || count($c) !== 6) return 'changes[] entry';
        if (!is_string($it['created_at']) || !preg_match($z, $it['created_at']) || !is_string($it['updated_at']) || !preg_match($z, $it['updated_at'])) return 'created_at / updated_at';
        if (!is_int($it['created_by']) || $it['created_by'] < 0 || !is_int($it['updated_by']) || $it['updated_by'] < 0) return 'created_by / updated_by';
        if (($it['stored_status'] === Maintenance::COMPLETED) !== ($it['completed_on'] !== null)) return 'completed_on must be set exactly when COMPLETED';
        return null;
    }

    private static function sameRecord(array $stored, $given) {
        if (!is_array($given)) return false;
        $given['id'] = strtolower((string) ($given['id'] ?? ''));
        return json_encode(self::sortKeys($stored)) === json_encode(self::sortKeys($given));
    }

    private static function sortKeys($v) {
        if (!is_array($v)) return $v;
        if (array_keys($v) !== range(0, count($v) - 1)) ksort($v);
        foreach ($v as $k => $x) $v[$k] = self::sortKeys($x);
        return $v;
    }
}
