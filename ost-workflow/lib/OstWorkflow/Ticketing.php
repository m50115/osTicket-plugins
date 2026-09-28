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
        'task_field' => ['edited', 'created'],
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
    // Real activity of tickets (never ticket.updated, Architecture §J)
    // ------------------------------------------------------------------

    private static $activity = [];

    /**
     * Loads, for a whole page at once, the activity block of each ticket: last activity (max of the visible
     * entries and the non-`viewed` events), last message/response times, max entry/event ids (sync components)
     * and a summary of the last visible entry. Results are kept for summary().
     */
    static function prime(array $ids) {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        $todo = array_values(array_filter($ids, function ($i) { return !isset(self::$activity[$i]); }));
        if (!$todo) return;
        $in = implode(',', $todo);
        $threads = [];
        $q = Store::q('SELECT id, object_id, lastmessage, lastresponse FROM ' . THREAD_TABLE . ' WHERE object_type=\'T\' AND object_id IN (' . $in . ')');
        while ($q && ($r = db_fetch_array($q))) $threads[(int) $r['id']] = $r;
        $out = [];
        foreach ($todo as $tid)
            $out[$tid] = ['last_activity_at' => null, 'last_message_at' => null, 'last_response_at' => null,
                          'max_entry_id' => 0, 'max_event_id' => 0, 'last_entry' => null];
        if ($threads) {
            $tin = implode(',', array_keys($threads));
            $byThread = [];
            foreach ($threads as $thid => $r) $byThread[$thid] = (int) $r['object_id'];
            foreach ($threads as $thid => $r) {
                $o =& $out[(int) $r['object_id']];
                $o['last_message_at'] = Time::iso($r['lastmessage']);
                $o['last_response_at'] = Time::iso($r['lastresponse']);
                unset($o);
            }
            $q = Store::q('SELECT thread_id, MAX(id) AS m FROM ' . THREAD_ENTRY_TABLE . ' WHERE thread_id IN (' . $tin . ') GROUP BY thread_id');
            while ($q && ($r = db_fetch_array($q))) $out[$byThread[(int) $r['thread_id']]]['max_entry_id'] = (int) $r['m'];
            $q = Store::q('SELECT thread_id, MAX(id) AS m FROM ' . THREAD_EVENT_TABLE . ' WHERE thread_id IN (' . $tin . ') GROUP BY thread_id');
            while ($q && ($r = db_fetch_array($q))) $out[$byThread[(int) $r['thread_id']]]['max_event_id'] = (int) $r['m'];
            $lastEv = [];
            $q = Store::q('SELECT e.thread_id, MAX(e.timestamp) AS t FROM ' . THREAD_EVENT_TABLE . ' e JOIN ' . TABLE_PREFIX . 'event ev ON ev.id=e.event_id'
                . ' WHERE e.thread_id IN (' . $tin . ') AND ev.name<>\'viewed\' GROUP BY e.thread_id');
            while ($q && ($r = db_fetch_array($q))) $lastEv[$byThread[(int) $r['thread_id']]] = $r['t'];
            // last VISIBLE entry per thread
            $q = Store::q('SELECT thread_id, MAX(id) AS m FROM ' . THREAD_ENTRY_TABLE . ' WHERE thread_id IN (' . $tin . ') AND (flags & ' . \ThreadEntry::FLAG_HIDDEN . ')=0 GROUP BY thread_id');
            $lastIds = [];
            while ($q && ($r = db_fetch_array($q))) $lastIds[$byThread[(int) $r['thread_id']]] = (int) $r['m'];
            $rows = [];
            if ($lastIds) {
                $q = Store::q('SELECT id, type, staff_id, user_id, poster, created, body, format FROM ' . THREAD_ENTRY_TABLE . ' WHERE id IN (' . implode(',', $lastIds) . ')');
                while ($q && ($r = db_fetch_array($q))) $rows[(int) $r['id']] = $r;
                $files = [];
                $q = Store::q('SELECT DISTINCT object_id FROM ' . ATTACHMENT_TABLE . ' WHERE type=\'H\' AND inline=0 AND object_id IN (' . implode(',', $lastIds) . ')');
                while ($q && ($r = db_fetch_array($q))) $files[(int) $r['object_id']] = true;
            }
            foreach ($lastIds as $tid => $eid) {
                if (!isset($rows[$eid])) continue;
                $r = $rows[$eid];
                $text = $r['format'] === 'html' ? Threading::htmlToText($r['body']) : (string) $r['body'];
                $text = trim(preg_replace('/\s+/u', ' ', $text));
                $out[$tid]['last_entry'] = [
                    'id' => $eid, 'type' => $r['type'], 'kind' => ['M' => 'message', 'R' => 'response', 'N' => 'note'][$r['type']] ?? $r['type'],
                    'audience' => $r['type'] === 'N' ? 'internal' : 'customer',
                    'actor' => ['type' => $r['staff_id'] ? 'staff' : ($r['user_id'] ? 'user' : 'system'),
                                'id' => (int) ($r['staff_id'] ?: $r['user_id']) ?: null, 'name' => (string) $r['poster']],
                    'excerpt' => mb_strlen($text) > 140 ? mb_substr($text, 0, 139) . '…' : $text,
                    'has_files' => isset($files[$eid]),
                    'created' => Time::iso($r['created']),
                ];
                $c = Time::gm($r['created']);
                if ($c) $out[$tid]['_entry_ts'] = $c;
            }
            foreach ($lastEv as $tid => $t) {
                $c = Time::gm($t);
                if ($c && $c > ($out[$tid]['_ev_ts'] ?? 0)) $out[$tid]['_ev_ts'] = $c;
            }
        }
        foreach ($out as $tid => $o) {
            $ts = max($o['_entry_ts'] ?? 0, $o['_ev_ts'] ?? 0);
            $o['last_activity_at'] = $ts ? gmdate('Y-m-d\TH:i:s\Z', $ts) : null;
            unset($o['_entry_ts'], $o['_ev_ts']);
            self::$activity[$tid] = $o;
        }
    }

    static function activityOf($ticketId) {
        if (!isset(self::$activity[(int) $ticketId])) self::prime([(int) $ticketId]);
        return self::$activity[(int) $ticketId];
    }

    // ------------------------------------------------------------------
    // DTOs
    // ------------------------------------------------------------------

    /** Compact representation for lists, lookups and write responses. */
    static function summary(\Ticket $t, array $row = null) {
        $row = $row ?: (self::rows([$t->getId()])[(int) $t->getId()] ?? []);
        $act = self::activityOf($t->getId());
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
            'owner'     => $owner ? ['id' => (int) $owner->getId(), 'name' => (string) $owner->getName(), 'email' => (string) $owner->getEmail(),
                             'org_id' => $owner->getOrgId() ? (int) $owner->getOrgId() : null] : null,
            'assignee'  => self::assignee($t),
            'source'    => (string) ($t->ht['source'] ?? ''),
            'is_overdue'  => !empty($row['isoverdue']),
            'is_answered' => !empty($row['isanswered']),
            'created'   => Time::iso($row['created'] ?? null),
            'updated'   => Time::iso($row['updated'] ?? null),
            'last_activity' => $act['last_activity_at'] ?: Time::iso($row['created'] ?? null),
            'activity'  => $act,
            'closed'    => Time::iso($row['closed'] ?? null),
            'due'       => ['manual' => $dueManual, 'sla' => $dueSla, 'effective' => $dueManual ?: $dueSla],
        ];
    }

    /** Header for GET /tickets/{id}: summary + owner contact, counts, lock, marker. */
    static function detail(\Ticket $t, \Staff $viewer = null) {
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
        if ($viewer) {
            $d['allowed_actions'] = array_keys(array_filter(self::actions($t, $viewer), function ($x) { return $x['allowed']; }));
            $d['visible_to_caller'] = (bool) $t->checkStaffPerm($viewer);
        }
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

    // ------------------------------------------------------------------
    // What the caller can do with a ticket (with the reason when not)
    // ------------------------------------------------------------------

    /** @return array name => ['allowed'=>bool, 'reason'=>string?, 'requires'=>string[]?, 'message'=>string?] */
    static function actions(\Ticket $t, \Staff $s) {
        $perm = function ($p) use ($t, $s) { return (bool) $t->checkStaffPerm($s, $p); };
        $need = function ($p) use ($perm) {
            return $perm($p) ? ['allowed' => true] : ['allowed' => false, 'reason' => 'missing_permission', 'permission' => $p];
        };
        $a = [];
        $a['note'] = ['allowed' => true];
        $a['reply'] = $need('ticket.reply');
        if ($a['reply']['allowed']) {
            require_once(INCLUDE_DIR . 'class.banlist.php');
            if ($t->isChild() && $t->getMergeType() != 'visual') $a['reply'] = ['allowed' => false, 'reason' => 'merged_child', 'parent_id' => (int) $t->getPid()];
            elseif (\Banlist::isBanned($t->getEmail())) $a['reply'] = ['allowed' => false, 'reason' => 'email_banned'];
        }
        $a['edit_fields'] = $need('ticket.edit');
        $a['manage_collaborators'] = $a['edit_fields'];
        $a['mark_answered'] = $need('ticket.markanswered');
        $a['create_task'] = $perm('task.create') ? ['allowed' => true] : ['allowed' => false, 'reason' => 'missing_permission', 'permission' => 'task.create'];
        $a['transfer'] = $need('ticket.transfer');
        $a['refer'] = $need('ticket.assign');
        $a['assign'] = $need('ticket.assign');
        if ($a['assign']['allowed'] && $t->isClosed()) $a['assign']['requires'] = ['reopen'];
        $a['claim'] = $need('ticket.assign');
        if ($a['claim']['allowed']) {
            if (!$t->isOpen()) $a['claim'] = ['allowed' => false, 'reason' => 'ticket_closed'];
            elseif ($t->getStaff()) $a['claim'] = ['allowed' => false, 'reason' => $t->getStaffId() == $s->getId() ? 'already_mine' : 'already_assigned'];
        }
        $rel = $perm('ticket.release') || $s->isManager();
        $a['release'] = $rel ? ['allowed' => true] : ['allowed' => false, 'reason' => 'missing_permission', 'permission' => 'ticket.release'];
        if ($rel && (!$t->isAssigned() || $t->isClosed())) $a['release'] = ['allowed' => false, 'reason' => $t->isClosed() ? 'ticket_closed' : 'not_assigned'];
        if ($t->isClosed()) {
            $a['close'] = ['allowed' => false, 'reason' => 'already_closed'];
            $a['reopen'] = ($perm('ticket.close') || $perm('ticket.create'))
                ? ($t->isReopenable() ? ['allowed' => true] : ['allowed' => false, 'reason' => 'not_reopenable'])
                : ['allowed' => false, 'reason' => 'missing_permission', 'permission' => 'ticket.close'];
        } else {
            $a['reopen'] = ['allowed' => false, 'reason' => 'not_closed'];
            $a['close'] = $need('ticket.close');
            if ($a['close']['allowed'] && ($c = $t->isCloseable()) !== true)
                $a['close'] = ['allowed' => false, 'reason' => 'not_closeable', 'message' => is_string($c) ? $c : null];
        }
        return $a;
    }

    /** Assignment and referral destinations for one ticket, each with `available` and the reason when not. */
    static function targets(\Ticket $t, \Staff $s) {
        $dept = $t->getDept();
        $agents = []; $teams = []; $refAgents = []; $refTeams = []; $refDepts = [];
        $q = $s->applyDeptVisibility(\Staff::objects()->filter(['isactive' => 1]));
        $n = 0;
        foreach (\Staff::nsort($q) as $st) {
            if (++$n > 500) break;
            $base = ['id' => (int) $st->getId(), 'name' => $st->getName()->getOriginal(), 'token' => 's' . $st->getId()];
            $ok = $dept->canAssign($st);
            $why = null;
            if (!$st->isAvailable()) $why = 'unavailable';
            elseif (!$ok) $why = $dept->assignPrimaryOnly() && !$dept->isPrimaryMember($st) ? 'not_primary_member' : 'not_department_member';
            elseif ((int) $t->getStaffId() === (int) $st->getId() && $t->isOpen()) $why = 'already_assigned';
            $agents[] = $base + ['assignable' => !$why, 'reason' => $why];
            if ((int) $st->getDeptId() !== (int) $dept->getId()) {
                $rw = !$st->isAvailable() ? 'unavailable' : ((int) $t->getStaffId() === (int) $st->getId() ? 'is_assignee' : null);
                $refAgents[] = $base + ['referable' => !$rw, 'reason' => $rw];
            }
        }
        foreach (\Team::getActiveTeams() as $id => $name) {
            $tm = \Team::lookup((int) $id);
            if (!$tm) continue;
            $base = ['id' => (int) $id, 'name' => (string) $name, 'token' => 't' . $id, 'members' => (int) $tm->getNumMembers()];
            $why = !$tm->getNumMembers() ? 'no_members' : ((int) $t->getTeamId() === (int) $id && $t->isOpen() ? 'already_assigned' : null);
            $teams[] = $base + ['assignable' => !$why, 'reason' => $why];
            $rw = (int) $t->getTeamId() === (int) $id ? 'is_assignee' : null;
            $refTeams[] = $base + ['referable' => !$rw, 'reason' => $rw];
        }
        foreach (\Dept::getActiveDepartments() as $id => $name) {
            $rw = (int) $id === (int) $dept->getId() ? 'same_department' : null;
            $refDepts[] = ['id' => (int) $id, 'name' => (string) $name, 'referable' => !$rw, 'reason' => $rw];
        }
        return ['assign' => ['agents' => $agents, 'teams' => $teams], 'refer' => ['agents' => $refAgents, 'teams' => $refTeams, 'depts' => $refDepts]];
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
        self::prime(array_keys($raw));
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
        $r = Store::row('SELECT e.timestamp, e.uid, e.uid_type, e.staff_id, ev.name FROM ' . THREAD_EVENT_TABLE . ' e JOIN '
            . TABLE_PREFIX . 'event ev ON ev.id=e.event_id WHERE e.thread_id=' . (int) $t->getThreadId()
            . ' AND ev.name IN (' . $in . ') ORDER BY e.id DESC LIMIT 1');
        if (!$r) return null;
        return ['event' => $r['name'], 'at' => Time::iso($r['timestamp']), 'actor' => self::eventActor($r['uid'], $r['uid_type']),
                'staff_id' => $r['staff_id'] ? (int) $r['staff_id'] : null];
    }

    /**
     * D2 (MSOLIS 2026-09-28): the normalized actor {type, id, name} of the API (OW-REQ-03), never the login
     * (thread_event.username holds the agent's user name; exposing it would undo H-5).
     */
    private static function eventActor($uid, $uidType) {
        if ($uid && $uidType === 'S') {
            $s = \Staff::lookup((int) $uid);
            return ['type' => 'staff', 'id' => (int) $uid, 'name' => $s ? Threading::personName($s) : null];
        }
        if ($uid && $uidType === 'U') {
            $u = \User::lookup((int) $uid);
            return ['type' => 'user', 'id' => (int) $uid, 'name' => $u ? Threading::personName($u) : null];
        }
        return ['type' => 'system', 'id' => null, 'name' => 'SYSTEM'];
    }

    /** Map a failed core call to a typed error (403 vs validation vs conflict). */
    static function fail(array $errors, $fallback) {
        if (!$errors)
            throw new ApiError('forbidden', $fallback);
        throw ApiError::fromErrors($errors, $fallback);
    }

    /**
     * Collect errors from an osTicket Form or DynamicFormEntry (TaskForm::getInstance() is the latter; a type-hint on
     * Form turned every invalid task form into a 500). Dynamic entries key their errors by field id: name them.
     */
    static function formErrors($form) {
        $out = [];
        foreach ((array) $form->errors() as $k => $v) {
            if (!is_string($k) && is_int($k) && method_exists($form, 'getFields')) {
                foreach ($form->getFields() as $f)
                    if ((int) $f->get('id') === $k && $f->get('name')) { $k = (string) $f->get('name'); break; }
            }
            $out[is_string($k) ? $k : 'err'] = is_array($v) ? implode('; ', $v) : (string) $v;
        }
        return $out;
    }
}
