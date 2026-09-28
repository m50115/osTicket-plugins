<?php
namespace OstWorkflow;

/**
 * Write deduplication (Architecture §I).
 *
 *   begin()  -> INSERT in_progress (lock with lease) | replay | 409 | 422
 *   finish() -> store the response (2xx and deterministic 4xx are replayed)
 *   abort()  -> 5xx: delete the row unless a resource was already created
 *
 * `model.created` records the first business resource created by the request,
 * so a retry after a crash can adopt it instead of duplicating (needs_review
 * when in doubt). Tickets also carry the marker source_extra = 'wf:<key>'.
 */
final class Idempotency {
    const LEASE_SECONDS = 90;
    const KEY_PATTERN = '/^[A-Za-z0-9-]{8,64}$/';
    const RESOURCE_CLASSES = [
        'Ticket' => 'ticket', 'Task' => 'task', 'ThreadEntry' => 'entry',
        'User' => 'user', 'Organization' => 'organization',
    ];

    private static $rowId;
    private static $hooked = false;

    static function marker($key) { return 'wf:' . substr($key, 0, 37); }

    /**
     * @return array|null null = proceed; [status, body, headers] = replay/answer immediately
     */
    static function begin(Request $req) {
        $key = $req->idemKey;
        $staffId = (int) $req->staff->getId();
        $route = substr($req->method . ' ' . $req->route['path'], 0, 190);
        $hash = $req->bodyHash();
        $t = Store::table();

        Store::q('INSERT IGNORE INTO ' . $t . ' SET kind=\'idem\', staff_id=' . $staffId
            . ', idem_key=' . Store::esc($key) . ', method=' . Store::esc($req->method)
            . ', route=' . Store::esc($route) . ', body_hash=' . Store::esc($hash)
            . ', status=\'in_progress\', created=NOW(), lease_until=DATE_ADD(NOW(), INTERVAL ' . self::LEASE_SECONDS . ' SECOND)'
            . ', expires=DATE_ADD(NOW(), INTERVAL 30 DAY)');

        if (Store::affected() === 1) {
            self::$rowId = (int) db_insert_id();
            self::hook();
            return null;
        }

        $row = Store::row('SELECT id, method, route, body_hash, status, http_code, response, resource_type, resource_id,'
            . ' (lease_until > NOW()) AS leased FROM ' . $t . ' WHERE staff_id=' . $staffId
            . ' AND idem_key=' . Store::esc($key) . ' AND kind=\'idem\'');
        if (!$row)
            throw new \RuntimeException('idempotency row vanished');

        if ($row['body_hash'] !== $hash || $row['route'] !== $route)
            throw new ApiError('idempotency_key_reused', 'This Idempotency-Key was already used for a different request');

        if ($row['status'] === 'done' || $row['status'] === 'failed_final') {
            $body = json_decode((string) $row['response'], true);
            return [(int) $row['http_code'], $body, ['Idempotent-Replayed' => 'true']];
        }

        // in_progress
        if ((int) $row['leased'] === 1)
            throw new ApiError('in_progress', 'The same operation is still running; retry shortly', null, [], ['Retry-After' => '2']);

        // Lease expired: the earlier attempt died mid-flight. Adopt if we can prove what it created.
        $type = $row['resource_type']; $id = $row['resource_id'];
        // A ticket created for a new contact records the contact first: the marker is the proof for the ticket.
        if ((!$type || $type === 'user') && $req->method === 'POST' && preg_match('#^/tickets$#', $req->route['path'])) {
            $tid = Store::row('SELECT ticket_id FROM ' . TICKET_TABLE . ' WHERE source_extra=' . Store::esc(self::marker($key)));
            if ($tid) { $type = 'ticket'; $id = $tid['ticket_id']; }
            else $type = $id = null;   // only the contact exists: the ticket is unproven, never adopt the contact as the answer
        }
        if ($type && $id) {
            $body = ['data' => ['adopted' => true, 'resource_type' => $type, 'resource_id' => (string) $id]];
            Store::q('UPDATE ' . $t . ' SET status=\'done\', http_code=200, response=' . Store::esc(json_encode($body))
                . ', resource_type=' . Store::esc($type) . ', resource_id=' . Store::esc($id) . ' WHERE id=' . (int) $row['id']);
            return [200, $body, ['Idempotent-Replayed' => 'true']];
        }
        throw new ApiError('needs_review', 'A previous attempt did not finish and its outcome cannot be proven; verify on the server before retrying');
    }

    static function finish($status, $body) {
        if (!self::$rowId) return;
        // 5xx and 429 are not answers to replay: the key must stay usable once the fault or the window passes.
        $final = $status < 500 && $status !== 429;
        if ($final) {
            Store::q('UPDATE ' . Store::table() . ' SET status=' . ($status < 400 ? '\'done\'' : '\'failed_final\'')
                . ', http_code=' . (int) $status . ', response=' . Store::esc(json_encode($body, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE))
                . ' WHERE id=' . self::$rowId);
        } else {
            self::abort();
        }
        self::$rowId = null;
    }

    /** 5xx: forget the key unless a resource was created (then keep in_progress for adoption). */
    static function abort() {
        if (!self::$rowId) return;
        try {
            Store::q('DELETE FROM ' . Store::table() . ' WHERE id=' . self::$rowId . ' AND resource_id IS NULL');
        } catch (\Throwable $t) { /* lease will expire */ }
        self::$rowId = null;
    }

    /** Record a resource (e.g. an uploaded file) explicitly. */
    static function record($type, $id) {
        if (!self::$rowId) return;
        Store::q('UPDATE ' . Store::table() . ' SET resource_type=' . Store::esc($type) . ', resource_id=' . Store::esc((string) $id)
            . ' WHERE id=' . self::$rowId . ' AND resource_id IS NULL');
    }

    private static function hook() {
        if (self::$hooked) return;
        self::$hooked = true;
        \Signal::connect('model.created', function ($model, &$data) {
            if (!self::$rowId) return;
            $type = self::RESOURCE_CLASSES[get_class($model)] ?? null;
            if ($type && ($id = $model->getId()))
                self::record($type, $id);
        });
    }

    /** Files uploaded by this agent (ownership proof for POST /notes file_ids). */
    static function ownsFile($staffId, $fileId) {
        return self::owns($staffId, 'file', $fileId);
    }

    /** Did this agent create the resource (file, user, organization, ticket, ...)? Proven by the idempotency ledger (30 days). */
    static function owns($staffId, $type, $id) {
        return (bool) Store::row('SELECT id FROM ' . Store::table() . ' WHERE staff_id=' . (int) $staffId
            . ' AND resource_type=' . Store::esc($type) . ' AND resource_id=' . Store::esc((string) $id) . ' LIMIT 1');
    }
}
