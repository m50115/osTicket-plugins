<?php
namespace OstWorkflow;

/**
 * Ticket DTOs, list cursors and the base-value precondition helpers shared by
 * Handlers\Tickets (Architecture §J, §K).
 */
final class Ticketing {
    const EVENT_NAMES = [
        'status'   => ['closed', 'reopened', 'edited', 'created'],
        'assign'   => ['assigned', 'released', 'transferred', 'created'],
        'dept'     => ['transferred', 'created'],
        'field'    => ['edited'],
        'owner'    => ['edited'],
        'answered' => ['edited'],
        'task_state' => ['closed', 'reopened', 'created'],
    ];

    // ------------------------------------------------------------------
    // Raw columns (normalized to UTC) — one query for a whole page
    // ------------------------------------------------------------------

    /** @return array<int,array> ticket_id => row */
    static function rows(array $ids) {
        $ids = array_values(array_filter(array_map('intval', $ids)));
        if (!$ids) return [];
        $r = Store::q('SELECT ticket_id, created, updated, closed, lastupdate, reopened, duedate, est_duedate, source_extra,'
            . ' isoverdue, isanswered FROM ' . TICKET_TABLE . ' WHERE ticket_id IN (' . implode(',', $ids) . ')');
        $out = [];
        while ($r && ($row = db_fetch_array($r)))
            $out[(int) $row['ticket_id']] = $row;
        return $out;
    }

    // ------------------------------------------------------------------
    // DTOs
    // ------------------------------------------------------------------

    /** Compact representation for lists, lookups and write responses. */
    static function summary(\Ticket $t, array $row = null) {
        $row = $row ?: (self::rows([$t->getId()])[(int) $t->getId()] ?? []);
        $status = $t->getStatus();
        $dept = $t->getDept();
        $topic = $t->getTopic();
        $sla = $t->getSLA();
        $owner = $t->getOwner();
        $dueManual = Time::iso($row['duedate'] ?? null);
        $dueSla = Time::iso($row['est_duedate'] ?? null);
        return [
            'id'        => (int) $t->getId(),
            'number'    => (string) $t->getNumber(),
            'subject'   => (string) $t->getSubject(),
            'status'    => $status ? ['id' => (int) $status->getId(), 'name' => $status->getName(), 'state' => $status->getState()] : null,
            'dept'      => $dept ? ['id' => (int) $dept->getId(), 'name' => $dept->getName()] : null,
            'topic'     => $topic ? ['id' => (int) $topic->getId(), 'name' => $topic->getName()] : null,
            'priority'  => self::priority($t),
            'sla'       => $sla ? ['id' => (int) $sla->getId(), 'name' => $sla->getName()] : null,
            'owner'     => $owner ? ['id' => (int) $owner->getId(), 'name' => (string) $owner->getName(), 'email' => (string) $owner->getEmail()] : null,
            'assignee'  => self::assignee($t),
            'source'    => (string) ($t->ht['source'] ?? ''),
            'is_overdue'  => !empty($row['isoverdue']),
            'is_answered' => !empty($row['isanswered']),
            'created'   => Time::iso($row['created'] ?? null),
            'updated'   => Time::iso($row['updated'] ?? null),
            'last_activity' => Time::iso($row['lastupdate'] ?? null),
            'closed'    => Time::iso($row['closed'] ?? null),
            'due'       => ['manual' => $dueManual, 'sla' => $dueSla, 'effective' => $dueManual ?: $dueSla],
        ];
    }

    /** Header for GET /tickets/{id}: summary + owner contact, counts, lock, marker. */
    static function detail(\Ticket $t) {
        $row = self::rows([$t->getId()])[(int) $t->getId()] ?? [];
        $d = self::summary($t, $row);
        $owner = $t->getOwner();
        if ($owner && $d['owner']) {
            $org = method_exists($owner, 'getOrganization') ? $owner->getOrganization() : null;
            $d['owner']['phone'] = (string) ($owner->getPhoneNumber() ?: '');
            $d['owner']['org'] = $org ? ['id' => (int) $org->getId(), 'name' => (string) $org->getName()] : null;
        }
        $d['reopened'] = Time::iso($row['reopened'] ?? null);
        $d['ip_address'] = $t->getIP() ?: null;
        $d['collaborators_count'] = (int) $t->getNumCollaborators();
        $d['tasks_count'] = (int) $t->getNumTasks();
        $d['open_tasks_count'] = (int) $t->getNumOpenTasks();
        $d['messages_count'] = (int) $t->getNumMessages();
        $d['is_closeable'] = $t->isCloseable() === true;
        $d['source_extra'] = $row['source_extra'] ?? null;
        $d['lock'] = self::lock($t);
        return $d;
    }

    static function priority(\Ticket $t) {
        $id = (int) $t->getPriorityId();
        $p = \Priority::lookup($id);
        return $p ? ['id' => $id, 'name' => $p->getDesc(), 'urgency' => (int) $p->getUrgency()] : ($id ? ['id' => $id, 'name' => null, 'urgency' => null] : null);
    }

    /** {type:'staff'|'team', id, name, token:'s12'|'t3'} or null */
    static function assignee(\Ticket $t) {
        if ($t->getStaffId() && ($s = $t->getStaff()) && !$t->isClosed())
            return ['type' => 'staff', 'id' => (int) $s->getId(), 'name' => $s->getName()->getOriginal(), 'token' => 's' . $s->getId()];
        if ($t->getTeamId() && ($tm = $t->getTeam()))
            return ['type' => 'team', 'id' => (int) $tm->getId(), 'name' => $tm->getName(), 'token' => 't' . $tm->getId()];
        return null;
    }

    /** Assignee token as used for `base` comparisons: 's12' | 't3' | null. */
    static function assigneeToken(\Ticket $t) {
        $a = self::assignee($t);
        return $a ? $a['token'] : null;
    }

    /** Read-only view of the desktop lock (P-16: the plugin never acquires it). */
    static function lock(\Ticket $t) {
        $lock = $t->getLock();
        if (!$lock) return ['locked' => false];
        $st = \Staff::lookup($lock->getStaffId());
        return ['locked' => true, 'staff_id' => (int) $lock->getStaffId(),
                'staff_name' => $st ? $st->getName()->getOriginal() : null,
                'expires_at' => Time::iso($lock->getExpireTime())];
    }

    // ------------------------------------------------------------------
    // Cursor pagination on (sort value, id)
    // ------------------------------------------------------------------

    static function encodeCursor(array $c) { return Token::b64(json_encode($c)); }

    static function decodeCursor($raw) {
        $c = json_decode(Token::unb64($raw), true);
        if (!is_array($c) || !isset($c['v'], $c['i']) || !is_string($c['v']))
            throw ApiError::validation('Invalid cursor', 'cursor');
        return ['v' => $c['v'], 'i' => (int) $c['i']];
    }

    /**
     * Tickets the agent may see. The visibility filter joins referral/assignment tables, so a ticket can match
     * through several rows: DISTINCT keeps each ticket once (osTicket's own queues do the same).
     */
    static function visible(\Staff $staff) {
        require_once(INCLUDE_DIR . 'class.ticket.php');
        return \Ticket::objects()->filter($staff->getTicketsVisibility())->distinct('ticket_id');
    }

    /** Newest-first page of an already visibility-filtered query, cursor on ticket_id. */
    static function pageById($qs, Request $req) {
        $limit = $req->intQuery('limit', 25, 1, 100);
        if (($c = $req->q('cursor')) !== null) {
            $d = json_decode(Token::unb64($c), true);
            if (!is_array($d) || !isset($d['i']) || !is_int($d['i'])) throw ApiError::validation('Invalid cursor', 'cursor');
            $qs = $qs->filter(['ticket_id__lt' => $d['i']]);
        }
        $rows = [];
        foreach ($qs->order_by('-ticket_id')->limit($limit + 1) as $t) $rows[] = $t;
        $more = count($rows) > $limit;
        $rows = array_slice($rows, 0, $limit);
        $raw = self::rows(array_map(function ($t) { return $t->getId(); }, $rows));
        $items = [];
        foreach ($rows as $t) $items[] = self::summary($t, $raw[(int) $t->getId()] ?? []);
        $next = ($more && $rows) ? Token::b64(json_encode(['i' => (int) end($rows)->getId()])) : null;
        return Res::page($items, $next);
    }

    // ------------------------------------------------------------------
    // Base-value preconditions (Architecture §K)
    // ------------------------------------------------------------------

    /** `base` must be present (null = "none"); never last-write-wins. */
    static function requireBase(Request $req) {
        $b = $req->json();
        if (!array_key_exists('base', $b))
            throw ApiError::validation("'base' is required: send the value you saw (null when it was empty)", 'base');
        return $b['base'];
    }

    /**
     * @param mixed $current  value now on the server
     * @param mixed $base     value the device saw
     * @param mixed $desired  value requested
     * @return string 'apply' | 'noop'; throws 409 conflict otherwise
     */
    static function precondition($t, $current, $base, $desired, $eventGroup) {
        if (self::same($current, $desired))
            return 'noop';
        if (!self::same($current, $base)) {
            throw new ApiError('conflict', 'The value changed on the server since you read it', null,
                ['current' => $current, 'base' => $base, 'last_change' => self::lastChange($t, $eventGroup)]);
        }
        return 'apply';
    }

    static function same($a, $b) {
        if ($a === null || $a === '' || $a === 0 || $a === '0') $a = null;
        if ($b === null || $b === '' || $b === 0 || $b === '0') $b = null;
        return $a === null || $b === null ? $a === $b : (string) $a === (string) $b;
    }

    /** Author and time of the latest relevant thread event. */
    static function lastChange($t, $group) {
        $names = self::EVENT_NAMES[$group] ?? ['edited'];
        $in = implode(',', array_map(function ($n) { return Store::esc($n); }, $names));
        $r = Store::row('SELECT e.timestamp, e.username, e.staff_id, ev.name FROM ' . THREAD_EVENT_TABLE . ' e JOIN '
            . TABLE_PREFIX . 'event ev ON ev.id=e.event_id WHERE e.thread_id=' . (int) $t->getThreadId()
            . ' AND ev.name IN (' . $in . ') ORDER BY e.id DESC LIMIT 1');
        if (!$r) return null;
        return ['event' => $r['name'], 'at' => Time::iso($r['timestamp']), 'actor' => $r['username'] ?: null,
                'staff_id' => $r['staff_id'] ? (int) $r['staff_id'] : null];
    }

    /** Map a failed core call to a typed error (403 vs validation vs conflict). */
    static function fail(array $errors, $fallback) {
        if (!$errors)
            throw new ApiError('forbidden', $fallback);
        throw ApiError::fromErrors($errors, $fallback);
    }

    /** Collect errors from a osTicket Form object. */
    static function formErrors(\Form $form) {
        $out = [];
        foreach ($form->errors() as $k => $v)
            $out[is_string($k) ? $k : 'err'] = is_array($v) ? implode('; ', $v) : (string) $v;
        return $out;
    }
}
