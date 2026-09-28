<?php
namespace OstWorkflow\Handlers;

use OstWorkflow\ApiError;
use OstWorkflow\Idempotency;
use OstWorkflow\Request;
use OstWorkflow\Res;
use OstWorkflow\Runtime;
use OstWorkflow\Store;
use OstWorkflow\Threading;
use OstWorkflow\Ticketing;
use OstWorkflow\Time;

/**
 * Tickets: reads (list/detail/search/…) and writes (create/status/assignment/…).
 * Every write goes through osTicket's domain methods with $thisstaff set (Pipeline),
 * behind the Policy table; updates carry a base value (Architecture §K).
 */
final class Tickets {
    const MAX_SUBJECT = 200;
    const MAX_MESSAGE = 32000;
    const MAX_NAME    = 100;
    const MAX_EMAIL   = 254;
    const FIELDS      = ['priority', 'topic', 'sla', 'duedate'];

    static function routes() {
        $id = '/tickets/(?P<id>\d+)';
        return [
            ['GET',    '/tickets',                    'index',    ['policy' => 'auth']],
            ['POST',   '/tickets',                    'create',   ['policy' => 'anydept.ticket.create']],
            ['GET',    '/tickets/lookup',             'lookup',   ['policy' => 'auth']],
            ['GET',    '/search',                     'search',   ['policy' => 'auth']],
            ['GET',    $id,                           'detail',   ['policy' => 'ticket.view']],
            ['GET',    "$id/missing-fields",          'missing',  ['policy' => 'ticket.view']],
            ['GET',    "$id/participants",            'participants', ['policy' => 'ticket.view']],
            ['GET',    "$id/recipients",              'recipients',   ['policy' => 'ticket.view']],
            ['GET',    "$id/fields",                  'fields',   ['policy' => 'ticket.view']],
            ['GET',    "$id/related",                 'related',  ['policy' => 'ticket.view']],
            ['GET',    "$id/collaborators",           'collaborators', ['policy' => 'ticket.view']],
            ['GET',    "$id/actions",                 'actions',  ['policy' => 'ticket.view']],
            ['GET',    "$id/targets",                 'targets',  ['policy' => 'ticket.view']],
            ['PATCH',  "$id/collaborators/(?P<uid>\d+)", 'setCollaborator',    ['policy' => 'ticket.edit']],
            ['DELETE', "$id/collaborators/(?P<uid>\d+)", 'removeCollaborator', ['policy' => 'ticket.edit']],
            ['POST',   "$id/collaborators",           'addCollaborator', ['policy' => 'ticket.edit']],
            ['POST',   "$id/status",                  'status',   ['policy' => 'ticket.view']],
            ['POST',   "$id/assignment",              'assign',   ['policy' => 'ticket.assign']],
            ['DELETE', "$id/assignment",              'release',  ['policy' => 'ticket.release_or_manager']],
            ['POST',   "$id/claim",                   'claim',    ['policy' => 'ticket.assign']],
            ['POST',   "$id/transfer",                'transfer', ['policy' => 'ticket.transfer']],
            ['POST',   "$id/referrals",               'refer',    ['policy' => 'ticket.assign']],
            ['PATCH',  "$id/fields/(?P<name>[A-Za-z0-9_]+)", 'editField', ['policy' => 'ticket.edit']],
            ['PUT',    "$id/forms",                   'forms',    ['policy' => 'ticket.edit']],
            ['PUT',    "$id/owner",                   'owner',    ['policy' => 'ticket.edit']],
            ['POST',   "$id/answered",                'answered', ['policy' => 'ticket.markanswered']],
        ];
    }

    static function policy() {
        return [
            // SCP: release needs ticket.release OR being a department manager (ajax.tickets.php:926)
            'ticket.release_or_manager' => function (\OstWorkflow\Request $req) {
                require_once(INCLUDE_DIR . 'class.ticket.php');
                $t = \Ticket::lookup((int) $req->param('id'));
                if (!$t) throw ApiError::notFound('ticket');
                if (!$t->checkStaffPerm($req->staff))
                    throw new ApiError('forbidden', 'You cannot access this ticket');
                if (!$t->checkStaffPerm($req->staff, 'ticket.release') && !$req->staff->isManager())
                    throw new ApiError('forbidden', 'Missing permission: ticket.release');
                $req->ctx['ticket'] = $t;
            },
        ];
    }

    // ==================================================================
    // READ
    // ==================================================================

    /** GET /tickets?state=open|closed|all&… — cursor on (sort, id); every filter is explicit. */
    static function index(Request $req) {
        $state = $req->q('state');
        if (!in_array($state, ['open', 'closed', 'all'], true))
            throw ApiError::validation("'state' is required and must be open, closed or all", 'state');
        $sort = $req->q('sort', 'updated');
        if (!in_array($sort, ['created', 'updated'], true))
            throw ApiError::validation("'sort' must be created or updated", 'sort');
        $order = $req->q('order', 'desc');
        if (!in_array($order, ['asc', 'desc'], true))
            throw ApiError::validation("'order' must be asc or desc", 'order');
        $limit = $req->intQuery('limit', 25, 1, 100);

        $qs = self::visible($req);
        if ($state !== 'all')
            $qs = $qs->filter(['status__state' => $state]);
        foreach (['status_id', 'dept_id', 'topic_id', 'staff_id', 'team_id', 'user_id'] as $k) {
            if ($req->q($k) !== null) {
                if (!ctype_digit((string) $req->q($k)))
                    throw ApiError::validation("'$k' must be an integer", $k);
                $qs = $qs->filter([$k => (int) $req->q($k)]);
            }
        }
        if ($req->q('unassigned') !== null) {
            self::flag($req, 'unassigned');
            if ($req->q('unassigned') === '1')
                $qs = $qs->filter(['staff_id' => 0, 'team_id' => 0]);
        }
        foreach (['overdue' => 'isoverdue', 'answered' => 'isanswered'] as $k => $col) {
            if ($req->q($k) !== null)
                $qs = $qs->filter([$col => self::flag($req, $k)]);
        }
        if (($number = $req->q('number')) !== null)
            $qs = $qs->filter(['number' => (string) $number]);
        if (($q = $req->q('q')) !== null)
            $qs = self::textFilter($qs, (string) $q);

        if (($cur = $req->q('cursor')) !== null) {
            $c = Ticketing::decodeCursor($cur);
            $cmp = $order === 'desc' ? '__lt' : '__gt';
            $qs = $qs->filter(\Q::any([
                new \Q([$sort . $cmp => $c['v']]),
                new \Q([$sort => $c['v'], 'ticket_id' . $cmp => $c['i']]),
            ]));
        }

        $dir = $order === 'desc' ? '-' : '';
        $rows = [];
        foreach ($qs->order_by($dir . $sort, $dir . 'ticket_id')->limit($limit + 1) as $t)
            $rows[] = $t;
        $more = count($rows) > $limit;
        $rows = array_slice($rows, 0, $limit);

        $raw = Ticketing::rows(array_map(function ($t) { return $t->getId(); }, $rows));
        Ticketing::prime(array_keys($raw));
        $items = [];
        foreach ($rows as $t)
            $items[] = Ticketing::summary($t, $raw[(int) $t->getId()] ?? []);
        $next = null;
        if ($more && $rows) {
            $last = end($rows);
            $next = Ticketing::encodeCursor(['v' => (string) $raw[(int) $last->getId()][$sort], 'i' => (int) $last->getId()]);
        }
        return Res::page($items, $next, ['filters' => ['state' => $state, 'sort' => $sort, 'order' => $order]]);
    }

    static function lookup(Request $req) {
        $req->query['limit'] = min(20, (int) $req->q('limit', 10));
        return self::search($req);
    }

    /** GET /search?q= — number / subject / contact search over visible tickets (no input sanitizing: escaped on output only). */
    static function search(Request $req) {
        $q = trim((string) $req->q('q', ''));
        if (strlen($q) < 2) throw ApiError::validation("'q' must have at least 2 characters", 'q');
        if (strlen($q) > 100) throw ApiError::validation("'q' is too long (max 100)", 'q');
        $limit = $req->intQuery('limit', 25, 1, 50);
        $rows = [];
        foreach (self::textFilter(self::visible($req), $q)->order_by('-updated', '-ticket_id')->limit($limit + 1) as $t)
            $rows[] = $t;
        $more = count($rows) > $limit;
        $rows = array_slice($rows, 0, $limit);
        $raw = Ticketing::rows(array_map(function ($t) { return $t->getId(); }, $rows));
        Ticketing::prime(array_keys($raw));
        $items = [];
        foreach ($rows as $t)
            $items[] = Ticketing::summary($t, $raw[(int) $t->getId()] ?? []);
        // No total is promised (legacy B-13): only whether more matches exist.
        return Res::ok($items, ['count' => count($items), 'has_more' => $more, 'query' => $q]);
    }

    static function detail(Request $req) {
        return Res::ok(Ticketing::detail($req->ctx['ticket'], $req->staff));
    }

    static function missing(Request $req) {
        $t = $req->ctx['ticket'];
        $c = $t->isCloseable();
        $fields = [];
        foreach ((array) \Ticket::getMissingRequiredFields($t) as $f) {
            $fields[] = ['id' => method_exists($f, 'getId') ? (int) $f->getId() : null, 'label' => method_exists($f, 'getLabel') ? (string) $f->getLabel() : null];
        }
        return Res::ok(['closeable' => $c === true, 'reason' => $c === true ? null : (string) $c, 'missing_fields' => $fields]);
    }

    /**
     * Everyone involved: owner, collaborators (active flag), assignee (agent/team), department, referrals and the
     * last agent who replied. `type` tells what `id` is (contact|agent|team|dept).
     */
    static function participants(Request $req) {
        $t = $req->ctx['ticket'];
        $out = [];
        if (($o = $t->getOwner()))
            $out[] = ['role' => 'owner', 'type' => 'contact', 'id' => (int) $o->getId(), 'user_id' => (int) $o->getId(), 'name' => (string) $o->getName(), 'email' => (string) $o->getEmail(), 'is_active' => true];
        foreach ($t->getCollaborators() as $c)
            $out[] = ['role' => 'collaborator', 'type' => 'contact', 'id' => (int) $c->getUserId(), 'user_id' => (int) $c->getUserId(),
                      'name' => (string) $c->getName(), 'email' => (string) $c->getEmail(), 'is_active' => (bool) $c->isActive()];
        if (($a = Ticketing::assignee($t)))
            $out[] = ['role' => 'assignee', 'type' => $a['type'] === 'staff' ? 'agent' : 'team', 'id' => $a['id'], 'name' => $a['name']];
        if (($d = $t->getDept()))
            $out[] = ['role' => 'department', 'type' => 'dept', 'id' => (int) $d->getId(), 'name' => (string) $d->getName()];
        foreach ($t->getThread()->getReferrals() as $r) {
            $obj = $r->getObject();
            $type = ['S' => 'agent', 'E' => 'team', 'D' => 'dept'][$r->object_type] ?? null;
            if ($obj && $type) $out[] = ['role' => 'referral', 'type' => $type, 'id' => (int) $r->object_id, 'name' => (string) $r->getName()];
        }
        if (($lr = $t->getLastRespondent()))
            $out[] = ['role' => 'last_respondent', 'type' => 'agent', 'id' => (int) $lr->getId(), 'name' => $lr->getName()->getOriginal()];
        return Res::ok($out);
    }

    /** GET /tickets/{id}/actions — what the caller can do with this ticket, and why not. */
    static function actions(Request $req) {
        $t = $req->ctx['ticket'];
        return Res::ok(['ticket_id' => (int) $t->getId(), 'actions' => Ticketing::actions($t, $req->staff)]);
    }

    /** GET /tickets/{id}/targets — assignment and referral destinations with `available` and the reason. */
    static function targets(Request $req) {
        $t = $req->ctx['ticket'];
        $can = Ticketing::actions($t, $req->staff);
        return Res::ok(Ticketing::targets($t, $req->staff), ['caller_can_assign' => $can['assign']['allowed'], 'caller_can_refer' => $can['refer']['allowed']]);
    }

    /** PATCH /tickets/{id}/collaborators/{uid} {active: bool, base: bool} — toggle the copy flag. */
    static function setCollaborator(Request $req) {
        $t = $req->ctx['ticket'];
        $b = $req->json();
        if (!isset($b['active']) || !is_bool($b['active'])) throw ApiError::validation("'active' must be true or false", 'active');
        $base = Ticketing::requireBase($req);
        if (!is_bool($base)) throw ApiError::validation("'base' must be true or false", 'base');
        $c = $t->getCollaborators()->findFirst(['user_id' => $req->intParam('uid')]);
        if (!$c) throw ApiError::notFound('collaborator');
        $cur = (bool) $c->isActive();
        if ($cur === $b['active']) return Res::ok(['applied' => false, 'user_id' => (int) $c->getUserId(), 'is_active' => $cur]);
        if ($cur !== $base)
            throw new ApiError('conflict', 'The collaborator changed on the server since you read it', null, ['current' => $cur, 'base' => $base]);
        $c->setFlag(\Collaborator::FLAG_ACTIVE, $b['active']);
        $c->save();
        return Res::ok(['applied' => true, 'user_id' => (int) $c->getUserId(), 'is_active' => (bool) $b['active']]);
    }

    /** DELETE /tickets/{id}/collaborators/{uid} — remove the collaborator (idempotent). */
    static function removeCollaborator(Request $req) {
        $t = $req->ctx['ticket'];
        $c = $t->getCollaborators()->findFirst(['user_id' => $req->intParam('uid')]);
        if (!$c) return Res::ok(['applied' => false, 'user_id' => $req->intParam('uid')]);
        $label = (string) $c;
        $uid = (int) $c->getUserId();
        if (!$c->delete()) throw new ApiError('internal_error', 'The collaborator could not be removed');
        // Event payload keyed by user id with a name (the shape ThreadEvent's describer reads without errors).
        $t->logEvent('collab', ['del' => [$uid => ['name' => $label]]]);
        return Res::ok(['applied' => true, 'user_id' => $req->intParam('uid')]);
    }

    /** GET /tickets/{id}/recipients?reply_to=all|user|collabs — who a reply with that scope reaches. */
    static function recipients(Request $req) {
        $t = $req->ctx['ticket'];
        $who = $req->q('reply_to', 'all');
        if (!in_array($who, ['all', 'user', 'collabs'], true))
            throw ApiError::validation("'reply_to' must be all, user or collabs", 'reply_to');
        $out = [];
        if ($who !== 'collabs' && ($o = $t->getOwner()))
            $out[] = ['role' => 'to', 'user_id' => (int) $o->getId(), 'name' => (string) $o->getName(), 'email' => (string) $o->getEmail()];
        if ($who !== 'user')
            foreach ($t->getActiveCollaborators() as $c)
                $out[] = ['role' => 'cc', 'user_id' => (int) $c->getUserId(), 'name' => (string) $c->getName(), 'email' => (string) $c->getEmail()];
        return Res::ok($out, ['reply_to' => $who]);
    }

    /** Dynamic form entries of the ticket (values as osTicket renders them; fields never filled show an empty value). */
    static function fields(Request $req) {
        $t = $req->ctx['ticket'];
        $forms = [];
        foreach (\DynamicFormEntry::forTicket($t->getId()) as $entry) {
            $fields = [];
            foreach ($entry->getFields() as $f) {
                if (!$f->isVisibleToStaff() || $f->isPresentationOnly() || !$f->isStorable()) continue;
                $a = $f->getAnswer();
                $raw = $a ? $a->getValue() : null;
                $fields[] = [
                    'id' => (int) $f->get('id'), 'name' => $f->get('name'), 'label' => (string) $f->getLabel(),
                    'type' => $f->get('type'), 'editable' => (bool) $f->isEditableToStaff(),
                    'value' => $a ? (string) $a->toString() : '',
                    'raw' => (is_object($raw) || $raw instanceof \Traversable || is_array($raw)) ? null : $raw,
                ];
            }
            $forms[] = ['entry_id' => (int) $entry->get('id'), 'form_id' => (int) $entry->get('form_id'),
                        'title' => (string) $entry->getTitle(), 'fields' => $fields];
        }
        return Res::ok($forms);
    }

    /** Merged/linked family of the ticket (parent and children); read-only — merging is not exposed. */
    static function related(Request $req) {
        $t = $req->ctx['ticket'];
        $children = [];
        foreach ((array) $t->getChildren() as $row) {
            $tid = is_array($row) ? (int) ($row['ticket_id'] ?? 0) : (int) (is_object($row) ? $row->getId() : $row);
            if ($tid && ($c = \Ticket::lookup($tid)) && $c->checkStaffPerm($req->staff))
                $children[] = ['id' => $tid, 'number' => (string) $c->getNumber(), 'subject' => (string) $c->getSubject()];
        }
        $parent = null;
        if ($t->getPid() && ($p = \Ticket::lookup((int) $t->getPid())) && $p->checkStaffPerm($req->staff))
            $parent = ['id' => (int) $p->getId(), 'number' => (string) $p->getNumber(), 'subject' => (string) $p->getSubject()];
        return Res::ok(['is_merged' => (bool) $t->isMerged(), 'parent' => $parent, 'children' => $children]);
    }

    static function collaborators(Request $req) {
        $out = [];
        foreach ($req->ctx['ticket']->getCollaborators() as $c)
            $out[] = ['user_id' => (int) $c->getUserId(), 'name' => (string) $c->getName(),
                      'email' => (string) $c->getEmail(), 'is_active' => (bool) $c->isActive()];
        return Res::ok($out);
    }

    // ==================================================================
    // WRITE — append-only
    // ==================================================================

    /**
     * POST /tickets — Ticket::create as staff. Explicit topic (or PluginConfig default, else 422),
     * explicit notification flag, contact by user_id or email+name, marker source_extra=wf:<key>.
     */
    static function create(Request $req) {
        require_once(INCLUDE_DIR . 'class.ticket.php');
        $b = $req->json();
        $staff = $req->staff;

        $subject = self::str($b, 'subject', true, self::MAX_SUBJECT);
        $message = self::str($b, 'message', true, self::MAX_MESSAGE);
        $notify = Threading::boolInput($req, 'notify', false);

        $topicId = self::intOrNull($b, 'topic_id') ?: (int) Runtime::setting('default_topic_id', 0);
        if (!$topicId)
            throw ApiError::validation("'topic_id' is required (no default configured)", 'topic_id');
        if (!($topic = \Topic::lookup($topicId)) || !$topic->isActive())
            throw ApiError::validation('Unknown or inactive help topic', 'topic_id');

        $vars = ['topicId' => $topicId, 'subject' => $subject, 'message' => $message, 'uid' => 0,
                 'source' => 'API'];
        if (isset($b['source'])) {
            $src = (string) $b['source'];
            if (!array_key_exists($src, \Ticket::getSources()))
                throw ApiError::validation('Invalid source', 'source', ['allowed' => array_keys(\Ticket::getSources())]);
            $vars['source'] = $src;
        }

        // Department: only when explicit (the topic decides otherwise). Role check per department.
        if (($deptId = self::intOrNull($b, 'dept_id'))) {
            if (!($dept = \Dept::lookup($deptId)))
                throw ApiError::validation('Unknown department', 'dept_id');
            $role = $staff->getRole($dept);
            if (!$role || !$role->hasPerm('ticket.create'))
                throw new ApiError('forbidden', 'You do not have permission to create tickets in this department');
            $vars['deptId'] = $deptId;
        }

        // Contact: existing user, or email + name (User::fromVars needs user.create — enforced by the core).
        if (($uid = self::intOrNull($b, 'user_id'))) {
            if (!\User::lookup($uid)) throw ApiError::validation('Unknown user', 'user_id');
            $vars['uid'] = $uid;
        } else {
            $email = self::str($b, 'email', true, self::MAX_EMAIL);
            $name = self::str($b, 'name', true, self::MAX_NAME);
            if (!filter_var($email, FILTER_VALIDATE_EMAIL))
                throw ApiError::validation('Invalid email address', 'email');
            $vars['email'] = $email;
            $vars['name'] = $name;
        }

        if (($pid = self::intOrNull($b, 'priority_id'))) $vars['priorityId'] = $pid;
        if (isset($b['assignee'])) {
            $a = $b['assignee'];
            if (!is_array($a) || !isset($a['type'], $a['id']) || !in_array($a['type'], ['staff', 'team'], true) || !ctype_digit((string) $a['id']))
                throw ApiError::validation("'assignee' must be {type: staff|team, id}", 'assignee');
            $ok = $staff->hasPerm('ticket.assign', false);
            if (!$ok) throw new ApiError('forbidden', 'Missing permission: ticket.assign');
            $vars[$a['type'] === 'staff' ? 'staffId' : 'teamId'] = (int) $a['id'];
        }
        // Dynamic form fields by name (topic forms); reserved keys cannot be overridden.
        if (isset($b['fields']) && is_array($b['fields'])) {
            foreach ($b['fields'] as $k => $v)
                if (is_string($k) && !array_key_exists($k, $vars) && is_scalar($v))
                    $vars[$k] = $v;
        }

        $errors = [];
        $ticket = \Ticket::create($vars, $errors, 'staff', $notify, $notify);
        if (!$ticket)
            throw ApiError::fromErrors($errors ?: [], 'Could not create the ticket');

        // Durable marker: lets a retry after a crash adopt this ticket instead of duplicating (§I).
        Store::q('UPDATE ' . TICKET_TABLE . ' SET source_extra=' . Store::esc(Idempotency::marker($req->idemKey))
            . ' WHERE ticket_id=' . (int) $ticket->getId());
        return Res::created(Ticketing::detail(\Ticket::lookup((int) $ticket->getId()), $staff));
    }

    /** POST /tickets/{id}/collaborators {user_id | email+name, cc?} */
    static function addCollaborator(Request $req) {
        $t = $req->ctx['ticket'];
        $b = $req->json();
        if (($uid = self::intOrNull($b, 'user_id'))) {
            $user = \User::lookup($uid);
            if (!$user) throw ApiError::validation('Unknown user', 'user_id');
        } else {
            $email = self::str($b, 'email', true, self::MAX_EMAIL);
            $name = self::str($b, 'name', true, self::MAX_NAME);
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) throw ApiError::validation('Invalid email address', 'email');
            if (!$req->staff->hasPerm('user.create'))
                throw new ApiError('forbidden', 'Missing permission: user.create (pass an existing user_id instead)');
            $user = \User::fromVars(['email' => $email, 'name' => $name], true);
            if (!$user) throw ApiError::validation('Could not create the contact', 'email');
        }
        foreach ($t->getCollaborators() as $c)
            if ((int) $c->getUserId() === (int) $user->getId())
                return Res::ok(['user_id' => (int) $user->getId(), 'already' => true]);
        $errors = [];
        $c = $t->addCollaborator($user, ['isactive' => 1], $errors);
        if (!$c) throw ApiError::fromErrors($errors, 'Could not add the collaborator');
        return Res::created(['user_id' => (int) $user->getId(), 'name' => (string) $user->getName(), 'email' => (string) $user->getEmail(), 'is_active' => true]);
    }

    // ==================================================================
    // WRITE — updates with base value
    // ==================================================================

    /** POST /tickets/{id}/status {status_id, base, comment?} — same rule as note_status_id. */
    static function status(Request $req) {
        $t = $req->ctx['ticket'];
        $b = $req->json();
        $desired = self::intOrNull($b, 'status_id');
        if (!$desired) throw ApiError::validation("'status_id' is required", 'status_id');
        $base = Ticketing::requireBase($req);
        $comment = isset($b['comment']) ? (string) $b['comment'] : '';

        // Permission and closeability first (never reveal state to someone who may not change it).
        $apply = Threading::authorizeStatus($t, $req->staff, $desired, 'status_id');
        $current = (int) $t->getStatusId();
        if ($apply === null || Ticketing::precondition($t, $current, $base, $desired, 'status') === 'noop')
            return Res::ok(['applied' => false, 'ticket' => Ticketing::summary($t)]);

        $before = Threading::snapshot($t);
        $errors = [];
        if (!$t->setStatus($desired, $comment, $errors))
            Ticketing::fail($errors, 'The status change was refused');
        return Res::ok(self::result($t, $before));
    }

    /** POST /tickets/{id}/assignment {assignee:{type,id}, base, comment?, refer?, reopen?, alert?} */
    static function assign(Request $req) {
        $t = $req->ctx['ticket'];
        $b = $req->json();
        $a = $b['assignee'] ?? null;
        if (!is_array($a) || !isset($a['type'], $a['id']) || !in_array($a['type'], ['staff', 'team'], true) || !ctype_digit((string) $a['id']))
            throw ApiError::validation("'assignee' must be {type: staff|team, id}", 'assignee');
        $token = ($a['type'] === 'staff' ? 's' : 't') . (int) $a['id'];
        $base = Ticketing::requireBase($req);
        $reopen = Threading::boolInput($req, 'reopen', false);
        $alert = Threading::boolInput($req, 'alert', false);
        $refer = Threading::boolInput($req, 'refer', false);

        $current = Ticketing::assigneeToken($t);
        if ($t->isClosed()) {
            if (!$reopen)
                throw new ApiError('conflict', 'The ticket is closed; send reopen:true to reopen and assign', 'reopen',
                    ['reason' => 'ticket_closed', 'state' => $t->getState()]);
            if (!$req->staff->hasPerm('ticket.close', false) || !$t->checkStaffPerm($req->staff, 'ticket.close'))
                if (!$t->checkStaffPerm($req->staff, 'ticket.create'))
                    throw new ApiError('forbidden', 'Missing permission to reopen: ticket.close or ticket.create');
        }
        if (Ticketing::precondition($t, $current, $base, $token, 'assign') === 'noop')
            return Res::ok(['applied' => false, 'ticket' => Ticketing::summary($t)]);

        $before = Threading::snapshot($t);
        if ($t->isClosed()) {
            $errors = [];
            if (!$t->reopen()) throw new ApiError('conflict', 'The ticket could not be reopened');
            $t = \Ticket::lookup((int) $t->getId());
            // Reopening auto-assigns to the closing/last agent: already what was asked.
            if (Ticketing::assigneeToken($t) === $token)
                return Res::ok(self::result($t, $before));
        }
        if ($a['type'] === 'team') {
            $team = \Team::lookup((int) $a['id']);
            if (!$team) throw ApiError::validation('Unknown team', 'assignee');
            if (!$team->isActive()) throw ApiError::validation('The team is disabled', 'assignee');
            if (!$team->getNumMembers()) throw ApiError::validation('The team has no members', 'assignee');
        } elseif (!\Staff::lookup((int) $a['id'])) {
            throw ApiError::validation('Unknown agent', 'assignee');
        }
        $form = $t->getAssignmentForm(['assignee' => [$token], 'comments' => (string) ($b['comment'] ?? ''), 'refer' => $refer],
            ['target' => $a['type'] === 'staff' ? 'agents' : 'teams']);
        if (!$form || !$form->isValid())
            throw ApiError::fromErrors($form ? Ticketing::formErrors($form) : [], 'Invalid assignee');
        $errors = [];
        if (!$t->assign($form, $errors, $alert))
            Ticketing::fail($errors, 'The assignment was refused');
        return Res::ok(self::result($t, $before));
    }

    /** DELETE /tickets/{id}/assignment {base, comment?} — release; writes the 'released' event like the SCP. */
    static function release(Request $req) {
        $t = $req->ctx['ticket'];
        $b = $req->json();
        $base = array_key_exists('base', $b) ? $b['base'] : ($req->q('base') !== null ? $req->q('base') : '__missing__');
        if ($base === '__missing__')
            throw ApiError::validation("'base' is required: the assignee token you saw, e.g. s12 or t3", 'base');
        $current = Ticketing::assigneeToken($t);
        if ($current === null || Ticketing::precondition($t, $current, $base, null, 'assign') === 'noop')
            return Res::ok(['applied' => false, 'ticket' => Ticketing::summary($t)]);

        $before = Threading::snapshot($t);
        $staff = $t->getStaff(); $team = $t->getTeam();
        $info = [];
        if ($t->getStaffId()) $info['sid'] = $t->getStaffId();
        if ($t->getTeamId()) $info['tid'] = $t->getTeamId();
        $errors = [];
        if (!$t->release($info, $errors))
            throw new ApiError('conflict', 'The ticket could not be released (only open tickets can be)');
        $data = [];
        if ($staff && !$t->getStaff()) $data['staff'] = [$staff->getId(), (string) $staff->getName()->getOriginal()];
        if ($team && !$t->getTeam()) $data['team'] = $team->getId();
        $t->logEvent('released', $data);
        if (!empty($b['comment'])) {
            $e = [];
            $t->postNote(['note' => Threading::textToHtml((string) $b['comment']), 'title' => __('Assignment Released')], $e, $req->staff, false);
        }
        return Res::ok(self::result($t, $before));
    }

    /** POST /tickets/{id}/claim {comment?} — only open and unassigned tickets. */
    static function claim(Request $req) {
        $t = $req->ctx['ticket'];
        $b = $req->json();
        $me = $req->staff;
        if ($t->getStaffId() == $me->getId() && $t->isOpen())
            return Res::ok(['applied' => false, 'ticket' => Ticketing::summary($t)]);
        if (!$t->isOpen() || $t->getStaff())
            throw new ApiError('conflict', 'Only open, unassigned tickets can be claimed', null,
                ['assignee' => Ticketing::assignee($t), 'state' => $t->getState()]);
        $before = Threading::snapshot($t);
        $form = $t->getClaimForm(['assignee' => ['s' . $me->getId()], 'comments' => (string) ($b['comment'] ?? '')]);
        if (!$form || !$form->isValid())
            throw ApiError::fromErrors($form ? Ticketing::formErrors($form) : [], 'Invalid claim');
        $errors = [];
        if (!$t->claim($form, $errors))
            Ticketing::fail($errors, 'The claim was refused');
        return Res::ok(self::result($t, $before));
    }

    /** POST /tickets/{id}/transfer {dept_id, base, comment?, refer?, alert?} */
    static function transfer(Request $req) {
        $t = $req->ctx['ticket'];
        $b = $req->json();
        $deptId = self::intOrNull($b, 'dept_id');
        if (!$deptId) throw ApiError::validation("'dept_id' is required", 'dept_id');
        $base = Ticketing::requireBase($req);
        $refer = Threading::boolInput($req, 'refer', false);
        $alert = Threading::boolInput($req, 'alert', false);
        if (Ticketing::precondition($t, (int) $t->getDeptId(), $base, $deptId, 'dept') === 'noop')
            return Res::ok(['applied' => false, 'ticket' => Ticketing::summary($t)]);
        $before = Threading::snapshot($t);
        $form = $t->getTransferForm(['dept' => [$deptId], 'refer' => $refer, 'comments' => (string) ($b['comment'] ?? '')]);
        if (!$form->isValid())
            throw ApiError::fromErrors(Ticketing::formErrors($form), 'Invalid department');
        $errors = [];
        if (!$t->transfer($form, $errors, $alert))
            Ticketing::fail($errors, 'The transfer was refused');
        return Res::ok(self::result($t, $before));
    }

    /** POST /tickets/{id}/referrals {target: agent|team|dept, id, comment?} (append-only) */
    static function refer(Request $req) {
        $t = $req->ctx['ticket'];
        $b = $req->json();
        $target = $b['target'] ?? null;
        if (!in_array($target, ['agent', 'team', 'dept'], true))
            throw ApiError::validation("'target' must be agent, team or dept", 'target');
        $id = self::intOrNull($b, 'id');
        if (!$id) throw ApiError::validation("'id' is required", 'id');
        $form = $t->getReferralForm(['target' => $target, $target => $id, 'comments' => (string) ($b['comment'] ?? '')], ['target' => $target]);
        if (!$form || !$form->isValid())
            throw ApiError::fromErrors($form ? Ticketing::formErrors($form) : [], 'Invalid referral');
        $errors = [];
        if (!$t->refer($form, $errors, false))
            Ticketing::fail($errors, 'The referral was refused');
        return Res::created(['ticket' => Ticketing::summary($t)]);
    }

    /**
     * PATCH /tickets/{id}/fields/{name} {value, base, comment?}
     * name = priority | topic | sla | duedate | <dynamic field id or name>. Dynamic fields: scalar values only
     * (text, number, boolean, phone, choice key); compared as displayed text and as raw value.
     */
    static function editField(Request $req) {
        $t = $req->ctx['ticket'];
        $b = $req->json();
        if (!array_key_exists('value', $b)) throw ApiError::validation("'value' is required", 'value');
        $base = Ticketing::requireBase($req);
        $plan = self::planField($req, $t, (string) $req->param('name'), $b['value'], $base);
        if ($plan['noop'])
            return Res::ok(['applied' => false, 'ticket' => Ticketing::summary($t)]);
        $before = Threading::snapshot($t);
        if (!self::commitField($t, $plan, (string) ($b['comment'] ?? '')))
            return Res::ok(['applied' => false, 'ticket' => Ticketing::summary($t)]);
        return Res::ok(self::result(\Ticket::lookup((int) $t->getId()), $before));
    }

    /**
     * PUT /tickets/{id}/forms {fields:{key:value…}, base:{key:value…}, comment?}
     * Batch of field edits with per-field base values. Everything is validated first (one 409 lists every
     * conflicting field); then applied in order. The core has no transaction: if a later field is refused the
     * error carries `applied_before_failure`.
     */
    static function forms(Request $req) {
        $t = $req->ctx['ticket'];
        $b = $req->json();
        if (!isset($b['fields']) || !is_array($b['fields']) || !$b['fields'] || array_values($b['fields']) === $b['fields'])
            throw ApiError::validation("'fields' must be an object {field: value}", 'fields');
        if (!isset($b['base']) || !is_array($b['base']))
            throw ApiError::validation("'base' is required: the current value of every field you change", 'base');
        $plans = []; $conflicts = [];
        foreach ($b['fields'] as $key => $value) {
            $key = (string) $key;
            if (!array_key_exists($key, $b['base']))
                throw ApiError::validation("'base.$key' is required", "base.$key");
            try {
                $plans[$key] = self::planField($req, $t, $key, $value, $b['base'][$key]);
            } catch (ApiError $e) {
                if ($e->errorCode !== 'conflict') throw $e;
                $conflicts[$key] = $e->details['current'] ?? null;
            }
        }
        if ($conflicts)
            throw new ApiError('conflict', 'One or more fields changed on the server since you read them', null,
                ['current' => $conflicts, 'last_change' => Ticketing::lastChange($t, 'field')]);
        $todo = array_filter($plans, function ($p) { return !$p['noop']; });
        if (!$todo) return Res::ok(['applied' => false, 'changed' => [], 'unchanged' => array_keys($plans), 'ticket' => Ticketing::summary($t)]);
        // Validate every value BEFORE touching anything: a bad value must not leave the batch half applied.
        $forms = []; $first = true;
        foreach ($todo as $key => $plan) {
            try {
                $forms[$key] = self::editForm($plan, $first ? (string) ($b['comment'] ?? '') : '');
            } catch (ApiError $e) {
                throw new ApiError($e->errorCode, $e->getMessage(), $e->field ?? $key,
                    array_merge($e->details, ['failed_field' => $key, 'applied_before_failure' => []]), $e->headers, $e->status());
            }
            $first = false;
        }
        $before = Threading::snapshot($t);
        $done = [];
        foreach ($forms as $key => $form) {
            try {
                if (self::commitForm(\Ticket::lookup((int) $t->getId()), $form)) $done[] = $key;
            } catch (ApiError $e) {
                throw new ApiError($e->errorCode, $e->getMessage(), $e->field ?? $key,
                    array_merge($e->details, ['failed_field' => $key, 'applied_before_failure' => $done]), $e->headers, $e->status());
            }
        }
        $out = self::result(\Ticket::lookup((int) $t->getId()), $before);
        $out['changed'] = $done;
        $out['unchanged'] = array_values(array_diff(array_keys($plans), $done));
        return Res::ok($out);
    }

    /** Validates one field edit and returns a plan; throws 409 conflict / 422 / 403 / 404. */
    private static function planField(Request $req, \Ticket $t, $name, $value, $base) {
        global $cfg;
        if (in_array($name, self::FIELDS, true)) {
            if (($value === null || (string) $value === '0') && in_array($name, ['priority', 'topic', 'sla'], true))
                throw ApiError::validation("'$name' cannot be cleared here: send an id" . ($name === 'sla' ? ' (removing or restarting the SLA needs its own operation)' : ''), 'value');
            switch ($name) {
            case 'priority': $current = (int) $t->getPriorityId(); break;
            case 'topic':    $current = (int) $t->getTopicId(); break;
            case 'sla':      $current = (int) $t->getSLAId(); break;
            default:         $current = Time::iso(Ticketing::rows([$t->getId()])[(int) $t->getId()]['duedate'] ?? null);
            }
            if ($name === 'duedate') {
                $desired = $value === null ? null : Time::iso(Time::toDb((string) $value));
                if ($value !== null && !$desired) throw ApiError::validation('Invalid ISO-8601 date', 'value');
                $base = $base === null ? null : Time::iso(Time::toDb((string) $base));
            } else {
                if ($value !== null && !ctype_digit((string) $value)) throw ApiError::validation("'value' must be an id", 'value');
                $desired = $value === null ? 0 : (int) $value;
                $base = $base === null ? 0 : (int) $base;
            }
            $noop = Ticketing::precondition($t, $current, $base, $desired, 'field') === 'noop';
            $field = $t->getField($name);
            if (!$field) throw ApiError::notFound('field');
            $val = $name === 'duedate'
                ? ($desired ? (new \DateTime($desired))->setTimezone(new \DateTimeZone($cfg->getTimezone($req->staff)))->format('Y-m-d H:i:s') : '')
                : ($desired ?: '');
            return ['noop' => $noop, 'field' => $field, 'value' => $val, 'name' => $name];
        }
        // Dynamic form field
        $field = self::dynamicField($t, $name);
        if (!$field->isEditableToStaff())
            throw new ApiError('forbidden', "Field '$name' cannot be edited");
        if (is_array($value) || is_object($value))
            throw ApiError::validation("'value' must be a scalar or null", 'value');
        $ans = $field->getAnswer();
        $curText = $ans ? trim((string) $ans->toString()) : '';
        $raw = $ans ? $ans->getValue() : null;
        $curRaw = is_scalar($raw) ? (string) $raw : null;
        $ds = $value === null ? '' : (is_bool($value) ? ($value ? '1' : '0') : trim((string) $value));
        $bs = $base === null ? '' : (is_bool($base) ? ($base ? '1' : '0') : trim((string) $base));
        $isNow = function ($x) use ($curText, $curRaw) { return Ticketing::same($curText, $x) || ($curRaw !== null && Ticketing::same($curRaw, $x)); };
        $noop = $isNow($ds);
        if (!$noop && !$isNow($bs))
            throw new ApiError('conflict', 'The value changed on the server since you read it', null,
                ['current' => $curText, 'base' => $base, 'last_change' => Ticketing::lastChange($t, 'field')]);
        return ['noop' => $noop, 'field' => $field, 'value' => $ds, 'name' => $name];
    }

    /** Builds and validates the field's edit form (no side effects). */
    private static function editForm(array $plan, $comment) {
        $field = $plan['field'];
        // The edit form reads the value by the field's own form names (hash / name / id): provide all of them.
        $src = ['comments' => $comment];
        foreach (array_filter([$field->getFormName(), $field->get('name'), $field->get('id'), 'field']) as $k)
            $src[$k] = $plan['value'];
        $form = $field->getEditForm($src);
        if (!$form->isValid())
            throw ApiError::fromErrors(Ticketing::formErrors($form), 'Invalid value');
        return $form;
    }

    private static function commitForm(\Ticket $t, $form) {
        $errors = [];
        if (!$t->updateField($form, $errors)) {
            if (isset($errors['field']) && stripos((string) $errors['field'], 'already') !== false) return false;   // core: no change
            Ticketing::fail($errors, 'The field could not be updated');
        }
        return true;
    }

    private static function commitField(\Ticket $t, array $plan, $comment) {
        return self::commitForm($t, self::editForm($plan, $comment));
    }

    /** A dynamic field of the ticket by numeric id or by name (the special fields are not dynamic). */
    private static function dynamicField(\Ticket $t, $key) {
        foreach (\DynamicFormEntry::forTicket($t->getId()) as $entry) {
            $entry->addMissingFields();   // fields added to the form after the ticket was created (the SCP does the same on edit)
            foreach ($entry->getFields() as $f) {
                if ($f->get('name') === 'priority') continue;
                if ((ctype_digit((string) $key) && (int) $f->get('id') === (int) $key) || (!ctype_digit((string) $key) && $f->get('name') === $key)) {
                    // A field added to the form after the ticket was created may have no stored answer (addMissingFields
                    // skips empty ones); updateField needs one to save into.
                    if (!$f->getAnswer() && $f->isStorable() && !$f->isPresentationOnly()) {
                        $a = new \DynamicFormEntryAnswer(['field_id' => $f->get('id'), 'entry' => $entry]);
                        $entry->answers->add($a);
                        $a->save();
                        $f->setAnswer($a);
                    }
                    return $f;
                }
            }
        }
        throw new ApiError('not_found', "Field '$key' not found on this ticket", null, ['allowed_special' => self::FIELDS]);
    }

    /** PUT /tickets/{id}/owner {user_id, base} */
    static function owner(Request $req) {
        $t = $req->ctx['ticket'];
        $b = $req->json();
        $uid = self::intOrNull($b, 'user_id');
        if (!$uid) throw ApiError::validation("'user_id' is required", 'user_id');
        $base = Ticketing::requireBase($req);
        if (!($user = \User::lookup($uid))) throw ApiError::validation('Unknown user', 'user_id');
        if (Ticketing::precondition($t, (int) $t->getOwnerId(), $base === null ? 0 : (int) $base, $uid, 'owner') === 'noop')
            return Res::ok(['applied' => false, 'ticket' => Ticketing::summary($t)]);
        if (!$t->changeOwner($user))
            throw new ApiError('forbidden', 'The owner could not be changed');
        return Res::ok(['applied' => true, 'ticket' => Ticketing::summary(\Ticket::lookup((int) $t->getId()))]);
    }

    /** POST /tickets/{id}/answered {answered: bool} — idempotent by nature. */
    static function answered(Request $req) {
        $t = $req->ctx['ticket'];
        $want = Threading::boolInput($req, 'answered', true);
        if ((bool) $t->isAnswered() === $want)
            return Res::ok(['applied' => false, 'ticket' => Ticketing::summary($t)]);
        $want ? $t->markAnswered() : $t->markUnAnswered();
        return Res::ok(['applied' => true, 'ticket' => Ticketing::summary(\Ticket::lookup((int) $t->getId()))]);
    }

    // ==================================================================
    // helpers
    // ==================================================================

    /** After a transfer/assign/refer/release the ticket may have left the caller's queues: tell the app why. */
    private static function visibleToCaller(\Ticket $t) {
        global $thisstaff;
        return $thisstaff ? (bool) $t->checkStaffPerm($thisstaff) : true;
    }

    private static function result(\Ticket $t, array $before) {
        $t = \Ticket::lookup((int) $t->getId());
        return ['applied' => true, 'ticket' => Ticketing::summary($t),
                'visible_to_caller' => self::visibleToCaller($t),
                'effects' => Threading::effects($before, Threading::snapshot($t))];
    }

    private static function visible(Request $req) {
        require_once(INCLUDE_DIR . 'class.ticket.php');
        return Ticketing::visible($req->staff);
    }

    private static function textFilter($qs, $q) {
        $q = trim($q);
        if (strlen($q) < 2) throw ApiError::validation("'q' must have at least 2 characters", 'q');
        if (strlen($q) > 100) throw ApiError::validation("'q' is too long (max 100)", 'q');
        if (preg_match('/^\d{2,}$/', $q))
            return $qs->filter(\Q::any(['number__startswith' => $q, 'cdata__subject__contains' => $q]));
        return $qs->filter(\Q::any([
            'cdata__subject__contains' => $q, 'user__name__contains' => $q,
            'user__default_email__address__contains' => $q, 'number__startswith' => $q,
        ]));
    }

    private static function flag(Request $req, $key) {
        $v = $req->q($key);
        if ($v !== '0' && $v !== '1')
            throw ApiError::validation("'$key' must be 0 or 1", $key);
        return (int) $v;
    }

    private static function str(array $b, $key, $required, $max) {
        $v = isset($b[$key]) && is_string($b[$key]) ? trim($b[$key]) : '';
        if ($v === '' && $required) throw ApiError::validation("'$key' is required", $key);
        if (strlen($v) > $max) throw ApiError::validation("'$key' exceeds $max characters", $key);
        return $v;
    }

    private static function intOrNull(array $b, $key) {
        if (!isset($b[$key]) || $b[$key] === '') return null;
        if (!is_int($b[$key]) && !(is_string($b[$key]) && ctype_digit($b[$key])))
            throw ApiError::validation("'$key' must be an integer id", $key);
        return (int) $b[$key];
    }
}
