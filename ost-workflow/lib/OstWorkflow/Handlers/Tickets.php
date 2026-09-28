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
            ['GET',    "$id/pdf",                     'pdf',      ['policy' => 'ticket.view']],
            ['GET',    "$id/targets",                 'targets',  ['policy' => 'ticket.view']],
            ['PATCH',  "$id/collaborators/(?P<uid>\d+)", 'setCollaborator',    ['policy' => 'ticket.edit']],
            ['POST',   "$id/collaborators",           'addCollaborator', ['policy' => 'ticket.edit']],
            ['POST',   "$id/status",                  'status',   ['policy' => 'ticket.view']],
            ['POST',   "$id/assignment",              'assign',   ['policy' => 'ticket.assign']],
            ['DELETE', "$id/assignment",              'release',  ['policy' => 'ticket.release_or_manager']],
            ['POST',   "$id/claim",                   'claim',    ['policy' => 'ticket.assign']],
            ['POST',   "$id/transfer",                'transfer', ['policy' => 'ticket.transfer']],
            ['POST',   "$id/referrals",               'refer',    ['policy' => 'ticket.assign']],
            ['PATCH',  "$id/fields/(?P<name>[A-Za-z0-9_]+)", 'editField', ['policy' => 'ticket.edit']],
            ['PUT',    "$id/forms",                   'forms',    ['policy' => 'ticket.edit']],
            ['GET',    "$id/sla",                     'slaState', ['policy' => 'ticket.view']],
            ['POST',   "$id/sla",                     'sla',      ['policy' => 'ticket.edit']],
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

    /**
     * GET /tickets/{id}/pdf?paper=Letter|Legal|Ledger|A4|A3&notes=0|1&events=0|1 — the SCP's "Print" export.
     * Uses osTicket's own Ticket2PDF (mPDF) and returns the bytes: Ticket::pdfExport() would send them itself and
     * exit. Internal notes and events are included only when asked (`notes`, `events`).
     */
    static function pdf(Request $req) {
        $t = $req->ctx['ticket'];
        $paper = (string) $req->q('paper', $req->staff->getDefaultPaperSize() ?: 'Letter');
        if (!in_array($paper, ['Letter', 'Legal', 'Ledger', 'A4', 'A3'], true))
            throw ApiError::validation("'paper' must be Letter, Legal, Ledger, A4 or A3", 'paper');
        $flags = [];
        foreach (['notes', 'events'] as $k) {
            $v = $req->q($k, '0');
            if ($v !== '0' && $v !== '1') throw ApiError::validation("'$k' must be 0 or 1", $k);
            $flags[$k] = $v === '1';
        }
        \OstWorkflow\Throttle::hit($req->staff, 'pdf');   // rendering is CPU/memory heavy: bounded per agent
        require_once(INCLUDE_DIR . 'class.pdf.php');
        if (!class_exists('Ticket2PDF'))
            throw new ApiError('not_configured', 'PDF export is not available on this installation (mPDF missing)');
        $name = 'Ticket-' . $t->getNumber() . '.pdf';
        $warn = [];
        try {
            $bytes = (new \Ticket2PDF($t, $paper, $flags['notes'], $flags['events']))->output($name, 'S');
        } catch (\Throwable $e) {
            // An event the core cannot describe (e.g. a malformed 'collab' payload on PHP 8) must not lose the whole
            // document: print again without the event log and say so.
            if (!$flags['events']) throw $e;
            error_log('[ost-workflow] pdf events omitted: ' . $e->getMessage());
            $warn[] = 'events_omitted';
            $bytes = (new \Ticket2PDF($t, $paper, $flags['notes'], false))->output($name, 'S');
        }
        if (!is_string($bytes) || strncmp($bytes, '%PDF', 4) !== 0)
            throw new ApiError('internal_error', 'The PDF could not be generated');
        return new \OstWorkflow\Stream(function () use ($bytes) { echo $bytes; }, [
            'Content-Type'           => 'application/pdf',
            'Content-Length'         => (string) strlen($bytes),
            'Content-Disposition'    => 'attachment; filename="' . $name . '"',
            'X-Content-Type-Options' => 'nosniff',
        ] + ($warn ? ['X-Workflow-Warnings' => implode(',', $warn)] : []));
    }

    /** GET /tickets/{id}/targets — assignment and referral destinations with `available` and the reason. */
    static function targets(Request $req) {
        $t = $req->ctx['ticket'];
        $can = Ticketing::actions($t, $req->staff);
        return Res::ok(Ticketing::targets($t, $req->staff), ['caller_can_assign' => $can['assign']['allowed'], 'caller_can_refer' => $can['refer']['allowed']]);
    }

    /**
     * PATCH /tickets/{id}/collaborators/{uid} {active: bool, base: bool} — toggle the copy flag.
     * There is no DELETE: deactivating is reversible and keeps the trace (hardening 2026-09-28).
     */
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

    /**
     * GET /tickets/{id}/recipients?reply_to=all|user|collabs
     * `email`: who gets the message by mail with that scope (to/cc). `portal`: who can SEE the ticket (and so the
     * reply) in the client portal, by the core's own access rule (Ticket::checkUserAccess): the owner, collaborators
     * and, when the organization shares tickets, its members.
     */
    static function recipients(Request $req) {
        $t = $req->ctx['ticket'];
        $who = $req->q('reply_to', 'all');
        if (!in_array($who, ['all', 'user', 'collabs'], true))
            throw ApiError::validation("'reply_to' must be all, user or collabs", 'reply_to');
        $email = [];
        if ($who !== 'collabs' && ($o = $t->getOwner()))
            $email[] = ['role' => 'to', 'user_id' => (int) $o->getId(), 'name' => (string) $o->getName(), 'email' => (string) $o->getEmail()];
        if ($who !== 'user')
            foreach ($t->getActiveCollaborators() as $c)
                $email[] = ['role' => 'cc', 'user_id' => (int) $c->getUserId(), 'name' => (string) $c->getName(), 'email' => (string) $c->getEmail()];

        $portal = []; $seen = [];
        $add = function ($u, $reason) use (&$portal, &$seen, $t) {
            if (!$u || isset($seen[$u->getId()])) return;
            $seen[$u->getId()] = true;
            try {
                if (!$t->checkUserAccess(new \EndUser($u))) return;   // the portal's own rule
            } catch (\Throwable $e) {
                // the rule could not be evaluated for this contact: keep the reason-based answer
            }
            $portal[] = ['user_id' => (int) $u->getId(), 'name' => (string) $u->getName(), 'email' => (string) $u->getEmail(), 'reason' => $reason];
        };
        $owner = $t->getOwner();
        $add($owner, 'owner');
        foreach ($t->getCollaborators() as $c) $add(\User::lookup((int) $c->getUserId()), 'collaborator');
        if ($owner && ($org = $owner->getOrganization()) && ($org->shareWithEverybody() || $org->shareWithPrimaryContacts())) {
            $n = 0;
            foreach ($org->allMembers() as $m) {
                if (++$n > 200) break;
                $add($m, $m->isPrimaryContact() ? 'organization_primary_contact' : 'organization_member');
            }
        }
        return Res::ok($email, ['reply_to' => $who, 'portal' => $portal,
                                'note' => '`data` = who receives the e-mail; `meta.portal` = who can see the ticket in the portal']);
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
                    'client_visible' => (bool) $f->isVisibleToUsers(), 'client_editable' => (bool) $f->isEditableToUsers(),
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
            $deptRole = $role;
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
            // With an explicit department the permission must hold THERE, not merely in some department.
            $ok = isset($deptRole) ? $deptRole->hasPerm('ticket.assign') : $staff->hasPerm('ticket.assign', false);
            if (!$ok) throw new ApiError('forbidden', 'Missing permission: ticket.assign');
            $vars[$a['type'] === 'staff' ? 'staffId' : 'teamId'] = (int) $a['id'];
        }
        // Dynamic form fields by name. Ticket::create reads dept, assignee, status, SLA, due date and the
        // autoresponse/alert switches from the same array: only fields the topic's forms define are accepted,
        // never a core key (that would bypass the permission checks above).
        if (isset($b['fields'])) {
            if (!is_array($b['fields']) || ($b['fields'] && array_values($b['fields']) === $b['fields']))
                throw ApiError::validation("'fields' must be an object {field: value}", 'fields');
            $allowed = self::creationFields($topic, $vars);
            foreach ($b['fields'] as $k => $v) {
                if (!isset($allowed[$k]))
                    throw ApiError::validation("Unknown field '$k' for this help topic (see GET /topics/{id}/forms)", 'fields.' . $k,
                        ['allowed' => array_keys($allowed)]);
                if (!is_scalar($v))
                    throw ApiError::validation("Field '$k' must be a scalar value", 'fields.' . $k);
                $vars[$k] = $v;
            }
        }

        if ($notify) \OstWorkflow\Throttle::hit($staff, 'mail');
        $errors = [];
        $ticket = \Ticket::create($vars, $errors, 'staff', $notify, $notify);
        if (!$ticket)
            throw ApiError::fromErrors($errors ?: [], 'Could not create the ticket');

        // Durable marker: lets a retry after a crash adopt this ticket instead of duplicating (§I).
        Store::q('UPDATE ' . TICKET_TABLE . ' SET source_extra=' . Store::esc(Idempotency::marker($req->idemKey))
            . ' WHERE ticket_id=' . (int) $ticket->getId());
        return Res::created(Ticketing::detail(\Ticket::lookup((int) $ticket->getId()), $staff));
    }

    /** Keys Ticket::create() reads from its variables besides form fields: never settable through `fields`. */
    const CORE_KEYS = ['topicid', 'deptid', 'staffid', 'teamid', 'statusid', 'slaid', 'duedate', 'priorityid', 'priority',
        'autorespond', 'alertstaff', 'alertuser', 'uid', 'user_id', 'email', 'name', 'subject', 'message', 'source', 'source_extra',
        'sourceextra', 'emailid', 'ip', 'ip_address', 'attachments', 'files', 'cannedattachments', 'response', 'reply-to', 'ccs',
        'flags', 'pid', 'parent_id', 'assign', 'number', 'notify'];

    /** Names of the custom fields of the topic's forms that a creation may fill (name => true). */
    private static function creationFields(\Topic $topic, array $vars) {
        $core = array_merge(self::CORE_KEYS, array_map('strtolower', array_keys($vars)));
        $ok = [];
        foreach ($topic->getForms() as $form)
            foreach ($form->getFields() as $f) {
                $n = (string) $f->get('name');
                if ($n !== '' && !in_array(strtolower($n), $core, true)) $ok[$n] = true;
            }
        return $ok;
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
                ? ($desired ? Time::forCore($desired, $cfg->getTimezone($req->staff)) : '')
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

    // ------------------------------------------------------------------
    // SLA: restart / extend / disable / enable / clear overdue
    // ------------------------------------------------------------------

    /** GET /tickets/{id}/sla — plan, deadlines and overdue flag, with what can be done. */
    static function slaState(Request $req) {
        return Res::ok(self::slaView($req->ctx['ticket']), ['actions' => self::slaActions($req->ctx['ticket'], $req->staff)]);
    }

    private static function slaView(\Ticket $t) {
        $row = Ticketing::rows([$t->getId()])[(int) $t->getId()] ?? [];
        $sla = $t->getSLA();
        $manual = Time::iso($row['duedate'] ?? null); $slaDue = Time::iso($row['est_duedate'] ?? null);
        return [
            'plan' => $sla ? ['id' => (int) $sla->getId(), 'name' => $sla->getName(), 'grace_hours' => (int) $sla->getGracePeriod(),
                              'active' => (bool) $sla->isActive(), 'transient' => (bool) $sla->isTransient()] : null,
            'due' => ['manual' => $manual, 'sla' => $slaDue, 'effective' => $manual ?: $slaDue],
            'is_overdue' => !empty($row['isoverdue']),
            'counting_from' => Time::iso($row['reopened'] ?? null) ?: Time::iso($row['created'] ?? null),
            'state' => $t->getState(),
        ];
    }

    /** The department's manager (or an administrator) may disable the SLA or clear the overdue flag. */
    private static function mayCurbSla(\Staff $s, \Ticket $t) {
        return $s->isAdmin() || ($t->getDept() && $s->isManager($t->getDept()));
    }

    private static function slaActions(\Ticket $t, \Staff $s) {
        $open = $t->isOpen(); $has = (bool) $t->getSLA();
        $curb = self::mayCurbSla($s, $t);
        return [
            'restart'       => $open && $has,
            'extend'        => $open && ($has || $t->getDueDate()),
            'disable'       => $curb && $has,
            'enable'        => $open,
            'clear_overdue' => $curb && $open && $t->isOverdue(),
        ];
    }

    /** Wall-clock "now" of the database, the clock the core cron compares est_duedate against. */
    private static function dbNow() {
        return Store::row('SELECT NOW() AS n')['n'];
    }

    /** now + the plan's grace period, respecting the department's business hours (the core's own computation). */
    private static function slaDueFromNow(\Ticket $t, \SLA $sla) {
        global $cfg;
        $tz = new \DateTimeZone($cfg->getDbTimezone());
        $dt = new \DateTime(self::dbNow(), $tz);
        $schedule = $t->getDept() ? $t->getDept()->getSchedule() : null;
        $dt = $sla->addGracePeriod($dt, $schedule);
        $dt->setTimezone($tz);
        return $dt->format('Y-m-d H:i:s');
    }

    /**
     * POST /tickets/{id}/sla {action, base:{sla_id, due}, hours?, sla_id?, clear_manual?, comment?}
     *   restart        est. due date := now + grace (business hours), overdue cleared
     *   extend         + `hours` (1-720) on the effective deadline (the manual one when it exists)
     *   disable        no SLA on this ticket: no deadline, not overdue
     *   enable         assign `sla_id` and count from now
     *   clear_overdue  the core's clearOverdue: flag off and past deadlines dropped (so the cron does not flag it again)
     * `base` = the plan id and the effective due date (ISO or null) the client saw; a mismatch is a 409.
     */
    static function sla(Request $req) {
        $t = $req->ctx['ticket'];
        $b = $req->json();
        $action = $b['action'] ?? null;
        if (!in_array($action, ['restart', 'extend', 'disable', 'enable', 'clear_overdue'], true))
            throw ApiError::validation("'action' must be restart, extend, disable, enable or clear_overdue", 'action');
        // PC-S2 (MSOLIS 2026-09-28): both can hide an SLA breach, so ticket.edit is not enough.
        if (in_array($action, ['disable', 'clear_overdue'], true) && !self::mayCurbSla($req->staff, $t))
            throw new ApiError('forbidden', "SLA '$action' is reserved to the department manager", 'action',
                ['reason' => 'department_manager_required', 'action' => $action]);
        $base = Ticketing::requireBase($req);
        if (!is_array($base) || !array_key_exists('sla_id', $base) || !array_key_exists('due', $base))
            throw ApiError::validation("'base' must be {sla_id, due}: the plan id and the effective due date you saw (null when none)", 'base');

        $cur = self::slaView($t);
        $curPlan = $cur['plan'] ? $cur['plan']['id'] : null;
        $baseDue = $base['due'] === null ? null : Time::iso(Time::toDb((string) $base['due']));
        if ($base['due'] !== null && !$baseDue) throw ApiError::validation('Invalid ISO-8601 date', 'base.due');
        if (!Ticketing::same($curPlan, $base['sla_id']) || !Ticketing::same($cur['due']['effective'], $baseDue))
            throw new ApiError('conflict', 'The SLA changed on the server since you read it', null,
                ['current' => ['sla_id' => $curPlan, 'due' => $cur['due']['effective']], 'base' => $base, 'last_change' => Ticketing::lastChange($t, 'field')]);
        $note = trim((string) ($b['comment'] ?? ''));
        if (strlen($note) > 2000) throw ApiError::validation("'comment' is too long", 'comment');
        $before = Threading::snapshot($t);
        $when = null;

        if ($action !== 'disable' && $action !== 'enable' && !$t->isOpen() && $action !== 'clear_overdue')
            throw new ApiError('conflict', 'The ticket is closed; reopen it first (reopening restarts the SLA count)', null, ['reason' => 'ticket_closed']);

        switch ($action) {
        case 'restart':
            $sla = $t->getSLA();
            if (!$sla || !$sla->isActive()) throw new ApiError('conflict', 'The ticket has no active SLA plan; use enable', null, ['reason' => 'no_sla']);
            if ($cur['due']['manual'] && empty($b['clear_manual']))
                throw new ApiError('conflict', 'A manual due date overrides the SLA; send clear_manual:true to drop it', 'clear_manual', ['reason' => 'manual_due_date']);
            $when = self::slaDueFromNow($t, $sla);
            self::applyDue($t, $when, !empty($b['clear_manual']));
            $msg = 'SLA restarted';
            break;
        case 'enable':
            $id = $b['sla_id'] ?? null;
            if (!(is_int($id) || (is_string($id) && ctype_digit($id))) || !($sla = \SLA::lookup((int) $id)) || !$sla->isActive())
                throw ApiError::validation("'sla_id' must be the id of an active SLA plan", 'sla_id');
            if (!$t->isOpen()) throw new ApiError('conflict', 'The ticket is closed; reopen it first', null, ['reason' => 'ticket_closed']);
            $t->setSLAId((int) $id);
            $when = self::slaDueFromNow($t, $sla);
            self::applyDue($t, $when, !empty($b['clear_manual']));
            $msg = 'SLA enabled: ' . $sla->getName();
            break;
        case 'extend':
            $h = $b['hours'] ?? null;
            if (!is_int($h) || $h < 1 || $h > 720) throw ApiError::validation("'hours' must be an integer between 1 and 720", 'hours');
            $row = Ticketing::rows([$t->getId()])[(int) $t->getId()] ?? [];
            $manual = !empty($row['duedate']);
            $curDue = $manual ? $row['duedate'] : ($row['est_duedate'] ?? null);
            if (!$curDue) throw new ApiError('conflict', 'The ticket has no deadline to extend; use enable', null, ['reason' => 'no_deadline']);
            $new = (new \DateTime($curDue))->modify('+' . $h . ' hours')->format('Y-m-d H:i:s');
            if ($new <= self::dbNow()) $new = (new \DateTime(self::dbNow()))->modify('+' . $h . ' hours')->format('Y-m-d H:i:s');
            if ($manual) { $t->duedate = $new; $t->isoverdue = 0; $t->save(); }
            else self::applyDue($t, $new, false);
            $when = $new;
            $msg = 'SLA extended by ' . $h . ' hours';
            break;
        case 'disable':
            if (!$t->getSLA() && !$cur['due']['sla'])
                return Res::ok(['applied' => false, 'sla' => $cur, 'ticket' => Ticketing::summary($t)]);
            $t->setSLAId(0);
            $t->est_duedate = null;
            if (!$cur['due']['manual']) $t->isoverdue = 0;
            $t->save();
            $msg = 'SLA disabled';
            break;
        case 'clear_overdue':
            if (!$t->isOverdue())
                return Res::ok(['applied' => false, 'sla' => $cur, 'ticket' => Ticketing::summary($t)]);
            $t->clearOverdue();
            $msg = 'Overdue flag cleared (past deadlines dropped)';
            break;
        }
        // A visible trace in the thread (an internal note), so the change is auditable without guessing.
        $e = [];
        $t->postNote(['title' => 'SLA', 'note' => Threading::textToHtml($msg . ($when ? ' — new due: ' . Time::iso($when) : '') . ($note !== '' ? ' — ' . $note : ''))],
            $e, $req->staff, false);
        $fresh = \Ticket::lookup((int) $t->getId());
        return Res::ok(['applied' => true, 'action' => $action, 'sla' => self::slaView($fresh), 'ticket' => Ticketing::summary($fresh),
                        'effects' => Threading::effects($before, Threading::snapshot($fresh))]);
    }

    /** Sets the SLA deadline and clears the overdue flag (and the manual deadline when asked). */
    private static function applyDue(\Ticket $t, $dbDatetime, $clearManual) {
        $t->est_duedate = $dbDatetime;
        $t->isoverdue = 0;
        if ($clearManual) $t->duedate = null;
        $t->save();
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
