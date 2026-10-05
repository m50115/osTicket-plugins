<?php
namespace OstWorkflow\Handlers;

use OstWorkflow\ApiError;
use OstWorkflow\Documents;
use OstWorkflow\Maintenance;
use OstWorkflow\Request;
use OstWorkflow\Res;
use OstWorkflow\Ticketing;

/**
 * ScheduledMaintenance store (OW-REQ-70, module 8). The state machine, the audit (`changes[]`) and the rules live here and in
 * Maintenance; the client keeps the derived views (month / upcoming / overdue). Records are never deleted: cancel instead.
 *
 * Writes follow the plugin's `base` convention (Tickets): the client sends the value it saw; current == desired -> 200
 * {applied:false}; current == base -> apply; else 409 conflict {reason, current, base, last_change, record}.
 *   reschedule  base = scheduled_date seen        complete / cancel / revert  base = stored_status seen
 *   link / unlink  base = ticket_id seen (null when none)
 * Authorization: reads `auth`; create / reschedule / complete / link / unlink `anydept.ticket.create`; cancel and revert
 * `maintenance.manager` (administrator, or manager of ANY department); link also needs `ticket.view` on the target Ticket.
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
        $items = array_map(['OstWorkflow\Maintenance', 'dto'], $rows);
        $wm = $rows ? Maintenance::cursor(end($rows)) : $req->q('cursor');
        return Res::page($items, $more ? $wm : null, ['watermark' => $wm]);
    }

    /** GET /maintenances/{id} */
    static function show(Request $req) {
        Maintenance::ready();
        return Res::ok(Maintenance::dto(self::load($req)));
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
        $b = $req->json();
        $items = $b['items'] ?? null;
        if (!is_array($items) || !$items || array_keys($items) !== range(0, count($items) - 1))
            throw ApiError::validation("'items' must be a non-empty list", 'items');
        if (count($items) > self::BATCH_MAX)
            throw ApiError::validation("'items' has more than " . self::BATCH_MAX . ' entries', 'items');
        $staffId = (int) $req->staff->getId();
        $out = []; $n = ['created' => 0, 'exists' => 0, 'rejected' => 0];
        $equipment = [];
        foreach ($items as $it) {
            $res = self::createOne($it, $staffId, $equipment);
            $n[$res['result']]++;
            $out[] = $res;
        }
        return Res::ok($out, $n);
    }

    private static function createOne($it, $staffId, array &$equipment) {
        $id = is_array($it) && isset($it['id']) && is_string($it['id']) ? strtolower($it['id']) : '';
        $rej = function ($code, $msg, array $extra = []) use ($id) {
            return array_merge(['id' => $id !== '' ? $id : null, 'result' => 'rejected', 'code' => $code, 'message' => $msg], $extra);
        };
        if (!preg_match(Documents::UUID, $id))
            return $rej('invalid_id', "'id' must be a client-generated UUID");
        if ($row = Maintenance::find($id))
            return ['id' => $id, 'result' => 'exists', 'maintenance' => Maintenance::dto($row)];
        $eid = $it['equipment_id'] ?? null;
        if (!is_int($eid) || $eid < 1)
            return $rej('invalid_equipment', "'equipment_id' must be a positive integer");
        $date = self::dateOrNull($it['scheduled_date'] ?? null);
        if ($date === null)
            return $rej('invalid_date', "'scheduled_date' must be a valid date YYYY-MM-DD");
        if (!array_key_exists($eid, $equipment))
            $equipment[$eid] = Maintenance::equipment($eid);
        if ($equipment[$eid] === null)
            return $rej('equipment_not_found', 'The equipment is not an item of the Installed Equipment list');
        if ($equipment[$eid] !== 'ACTIVE')
            return $rej('equipment_retired', 'The equipment is retired: no new maintenance can be scheduled');
        if (Maintenance::insert($id, $eid, $date, $staffId))
            return ['id' => $id, 'result' => 'created', 'maintenance' => Maintenance::dto(Maintenance::find($id))];
        // Not inserted: a concurrent request took the id (adopt it) or the (equipment, date) slot.
        if ($row = Maintenance::find($id))
            return ['id' => $id, 'result' => 'exists', 'maintenance' => Maintenance::dto($row)];
        $h = Maintenance::holder($eid, $date);
        return $rej('duplicate', 'The equipment already has a non-cancelled maintenance on that date', ['details' => ['existing_id' => $h ? $h['id'] : null]]);
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
        $r = self::load($req);
        self::mustBe($r, Maintenance::PLANNED);
        if ($r['scheduled_date'] === $new)
            return self::noop($r);
        if ($r['scheduled_date'] !== $base)
            self::conflict('base_mismatch', $r, $r['scheduled_date'], $base);
        if (($h = Maintenance::holder($r['equipment_id'], $new)) && $h['id'] !== $r['id'])
            self::conflict('duplicate', $r, $r['scheduled_date'], $base, ['existing_id' => $h['id']]);
        $staffId = (int) $req->staff->getId();
        return self::commit($r, ['scheduled_date' => self::q($new), 'active_date' => self::q($new)],
            Maintenance::change('reschedule', $staffId, $reason, $r['scheduled_date'], $new), $staffId, true);
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
        $r = self::load($req);
        if ($r['stored_status'] === Maintenance::COMPLETED) {
            if ($r['completed_on'] === $on && $r['evidence_ref'] === $ref && $r['completion_note'] === $note)
                return self::noop($r);
            self::conflict('base_mismatch', $r, $r['stored_status'], $base);
        }
        if ($r['stored_status'] !== $base)
            self::conflict('base_mismatch', $r, $r['stored_status'], $base);
        self::mustBe($r, Maintenance::PLANNED);
        if ($ref !== null) {
            if ($r['ticket_id'] === null)
                throw ApiError::validation("'evidence_ref' needs a linked Ticket", 'evidence_ref', ['reason' => 'no_ticket']);
            $doc = Documents::find(['uuid' => substr($ref, 4, 36), 'version' => (int) substr($ref, 41)]);
            if (!$doc || $doc['ticket_id'] !== (int) $r['ticket_id'])
                throw ApiError::validation("'evidence_ref' is not a published document of the linked Ticket", 'evidence_ref', ['reason' => 'foreign_evidence']);
        }
        $staffId = (int) $req->staff->getId();
        return self::commit($r, ['stored_status' => "'COMPLETED'", 'completed_on' => self::q($on), 'completed_by' => $staffId,
                'evidence_ref' => self::q($ref), 'completion_note' => self::q($note)],
            Maintenance::change('complete', $staffId, $note, 'PLANNED', ['stored_status' => 'COMPLETED', 'completed_on' => $on, 'evidence_ref' => $ref, 'completion_note' => $note]),
            $staffId);
    }

    /** POST /maintenances/{id}/cancel {base:<status seen>, reason} — manager; PLANNED only; the row stays. */
    static function cancel(Request $req) {
        Maintenance::ready();
        $reason = self::text($req->input('reason'), 'reason', true);
        $base = self::baseStatus($req);
        $r = self::load($req);
        if ($r['stored_status'] === Maintenance::CANCELLED)
            return self::noop($r);
        if ($r['stored_status'] !== $base)
            self::conflict('base_mismatch', $r, $r['stored_status'], $base);
        self::mustBe($r, Maintenance::PLANNED);
        $staffId = (int) $req->staff->getId();
        return self::commit($r, ['stored_status' => "'CANCELLED'", 'active_date' => 'NULL', 'cancel_reason' => self::q($reason)],
            Maintenance::change('cancel', $staffId, $reason, 'PLANNED', 'CANCELLED'), $staffId);
    }

    /** POST /maintenances/{id}/revert {base:<status seen>, reason} — manager; COMPLETED -> PLANNED; completion fields kept in changes[]. */
    static function revert(Request $req) {
        Maintenance::ready();
        $reason = self::text($req->input('reason'), 'reason', true);
        $base = self::baseStatus($req);
        $r = self::load($req);
        if ($r['stored_status'] === Maintenance::PLANNED)
            return self::noop($r);
        if ($r['stored_status'] !== $base)
            self::conflict('base_mismatch', $r, $r['stored_status'], $base);
        self::mustBe($r, Maintenance::COMPLETED);
        $staffId = (int) $req->staff->getId();
        $from = ['stored_status' => 'COMPLETED', 'completed_on' => $r['completed_on'], 'completed_by' => $r['completed_by'] === null ? null : (int) $r['completed_by'],
                 'evidence_ref' => $r['evidence_ref'], 'completion_note' => $r['completion_note']];
        return self::commit($r, ['stored_status' => "'PLANNED'", 'completed_on' => 'NULL', 'completed_by' => 'NULL', 'evidence_ref' => 'NULL', 'completion_note' => 'NULL'],
            Maintenance::change('revert', $staffId, $reason, $from, ['stored_status' => 'PLANNED']), $staffId);
    }

    /** POST /maintenances/{id}/link {base:null, ticket_id, reason?} — PLANNED without Ticket; needs ticket.view on the target. */
    static function link(Request $req) {
        Maintenance::ready();
        $tid = $req->input('ticket_id');
        if (!is_int($tid) || $tid < 1)
            throw ApiError::validation("'ticket_id' must be a positive integer", 'ticket_id');
        $reason = self::text($req->input('reason'), 'reason', false);
        $base = self::baseTicket($req);
        $r = self::load($req);
        // Same answer for "does not exist" and "not yours": linking cannot probe Tickets the agent may not see.
        require_once(INCLUDE_DIR . 'class.ticket.php');
        $t = \Ticket::lookup($tid);
        if (!$t || !$t->checkStaffPerm($req->staff))
            throw new ApiError('forbidden', 'You cannot access this ticket', 'ticket_id');
        self::mustBe($r, Maintenance::PLANNED);
        $cur = $r['ticket_id'] === null ? null : (int) $r['ticket_id'];
        if ($cur === $tid)
            return self::noop($r);
        if ($cur !== $base)
            self::conflict('base_mismatch', $r, $cur, $base);
        if ($cur !== null)
            self::conflict('ticket_already_linked', $r, $cur, $base);
        $staffId = (int) $req->staff->getId();
        return self::commit($r, ['ticket_id' => (string) $tid], Maintenance::change('link', $staffId, $reason, null, $tid), $staffId);
    }

    /** POST /maintenances/{id}/unlink {base:<ticket_id seen>, reason} — PLANNED; needs no access to the Ticket. */
    static function unlink(Request $req) {
        Maintenance::ready();
        $reason = self::text($req->input('reason'), 'reason', true);
        $base = self::baseTicket($req);
        $r = self::load($req);
        $cur = $r['ticket_id'] === null ? null : (int) $r['ticket_id'];
        self::mustBe($r, Maintenance::PLANNED);
        if ($cur === null)
            return self::noop($r);
        if ($cur !== $base)
            self::conflict('base_mismatch', $r, $cur, $base);
        $staffId = (int) $req->staff->getId();
        return self::commit($r, ['ticket_id' => 'NULL'], Maintenance::change('unlink', $staffId, $reason, $cur, null), $staffId);
    }

    // ==================================================================
    // helpers
    // ==================================================================

    private static function load(Request $req) {
        $r = Maintenance::find(strtolower($req->param('uuid')));
        if (!$r) throw ApiError::notFound('maintenance');
        return $r;
    }

    private static function noop(array $r) { return Res::ok(Maintenance::dto($r), ['applied' => false]); }

    /** Apply with compare-and-swap; a lost race answers the same typed conflict as a stale base. */
    private static function commit(array $r, array $set, array $change, $staffId, $uniqueSlot = false) {
        if (!Maintenance::update($r, $set, $change, $staffId)) {
            $now = Maintenance::find($r['id']);
            if ($now && $now['rev'] === $r['rev'] && $uniqueSlot)
                self::conflict('duplicate', $now, $now['scheduled_date'], $r['scheduled_date']);
            self::conflict('base_mismatch', $now ?: $r, null, null);
        }
        return Res::ok(Maintenance::dto(Maintenance::find($r['id'])), ['applied' => true]);
    }

    private static function conflict($reason, array $r, $current, $base, array $extra = []) {
        throw ApiError::conflict('The maintenance changed on the server (' . $reason . ')', array_merge([
            'reason' => $reason, 'current' => $current, 'base' => $base,
            'last_change' => Maintenance::lastChange($r), 'record' => Maintenance::dto($r),
        ], $extra));
    }

    private static function mustBe(array $r, $status) {
        if ($r['stored_status'] !== $status)
            self::conflict('invalid_state', $r, $r['stored_status'], null, ['required' => $status]);
    }

    private static function q($v) { return $v === null ? 'NULL' : \OstWorkflow\Store::esc($v); }

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
}
