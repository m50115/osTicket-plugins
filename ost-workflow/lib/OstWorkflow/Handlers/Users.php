<?php
namespace OstWorkflow\Handlers;

use OstWorkflow\ApiError;
use OstWorkflow\Contacts;
use OstWorkflow\Directory;
use OstWorkflow\Request;
use OstWorkflow\Res;
use OstWorkflow\Store;
use OstWorkflow\Throttle;
use OstWorkflow\Ticketing;
use OstWorkflow\Threading;

/**
 * Contacts (osTicket "users"). Creation is never a blind find-or-create:
 * org_id is explicit (domain auto-mapping is disabled) and existing/similar
 * contacts come back as 409 candidates (Architecture §L, D-12).
 */
final class Users {
    static function routes() {
        $id = '/users/(?P<id>\d+)';
        return [
            ['GET',   '/users',                 'index',    ['policy' => 'auth']],
            ['POST',  '/users',                 'create',   ['policy' => 'global.user.create']],
            ['GET',   $id,                      'detail',   ['policy' => 'user.load']],
            ['GET',   "$id/tickets",            'tickets',  ['policy' => 'user.load']],
            ['GET',   "$id/fields",             'fields',   ['policy' => 'user.load']],
            ['PATCH', $id,                      'update',   ['policy' => 'user.edit']],
            ['PUT',   "$id/organization",       'setOrg',   ['policy' => 'user.edit']],
            ['GET',   "$id/notes",              'notes',    ['policy' => 'user.load']],
            ['POST',  "$id/notes",              'addNote',  ['policy' => 'user.load']],
        ];
    }

    static function policy() {
        $load = function (Request $req, $perm = null) {
            require_once(INCLUDE_DIR . 'class.user.php');
            $u = \User::lookup((int) $req->param('id'));
            if (!$u) throw ApiError::notFound('user');
            if ($perm && !$req->staff->hasPerm($perm))
                throw new ApiError('forbidden', 'Missing permission: ' . $perm);
            Directory::requireRead($req->staff, 'user', $u->getId());
            $req->ctx['user'] = $u;
        };
        return [
            'user.load' => function (Request $req) use ($load) { $load($req); },
            'user.edit' => function (Request $req) use ($load) { $load($req, 'user.edit'); },
        ];
    }

    /** GET /users?q=|email= (any agent: ticket-creation lookup) — browsing without a query needs user.dir. */
    static function index(Request $req) {
        require_once(INCLUDE_DIR . 'class.user.php');
        $limit = $req->intQuery('limit', 25, 1, 100);
        $q = $req->q('q'); $email = $req->q('email');
        if ($email !== null) {
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) throw ApiError::validation('Invalid email address', 'email');
            if (!Directory::full($req->staff)) Throttle::hit($req->staff, 'lookup');
            $u = \User::lookupByEmail($email);
            return Res::ok($u ? [Contacts::user($u)] : [], ['count' => $u ? 1 : 0, 'has_more' => false]);
        }
        $qs = \User::objects();
        if ($q !== null) {
            $q = trim((string) $q);
            if (strlen($q) < 2) throw ApiError::validation("'q' must have at least 2 characters", 'q');
            if (strlen($q) > 100) throw ApiError::validation("'q' is too long (max 100)", 'q');
        }
        list($limit, $paging) = Directory::searchLimits($req, $limit, $q);
        if ($q !== null)
            $qs = $qs->filter(\Q::any(['name__contains' => $q, 'emails__address__contains' => $q]));
        if ($req->q('org_id') !== null) {
            if (!ctype_digit((string) $req->q('org_id'))) throw ApiError::validation("'org_id' must be an integer", 'org_id');
            $qs = $qs->filter(['org_id' => (int) $req->q('org_id')]);
        }
        $after = Contacts::cursorId($req);
        if ($after) $qs = $qs->filter(['id__gt' => $after]);
        $items = []; $seen = []; $more = false; $lastId = 0;
        foreach ($qs->order_by('id')->limit($limit * 3 + 1) as $u) {   // joins may repeat a user: dedupe
            if (isset($seen[$u->getId()])) continue;
            if (count($items) >= $limit) { $more = true; break; }
            $seen[$u->getId()] = true;
            $items[] = Contacts::user($u);
            $lastId = (int) $u->getId();
        }
        return Res::page($items, ($more && $paging) ? Contacts::nextCursor($lastId) : null);
    }

    static function detail(Request $req) {
        return Res::ok(Contacts::user($req->ctx['user']));
    }

    static function tickets(Request $req) {
        require_once(INCLUDE_DIR . 'class.ticket.php');
        $u = $req->ctx['user'];
        return Ticketing::pageById(Ticketing::visible($req->staff)->filter(['user_id' => $u->getId()]), $req);
    }

    static function fields(Request $req) {
        return Res::ok(Contacts::entries($req->ctx['user']->getForms()));
    }

    /**
     * POST /users {name, email, org_id (required; null = no organization), phone?, force_new?}
     * 409 candidates when the email exists or a similar contact does (unless force_new for non-email candidates).
     */
    static function create(Request $req) {
        require_once(INCLUDE_DIR . 'class.user.php'); require_once(INCLUDE_DIR . 'class.organization.php');
        $b = $req->json();
        $name = self::str($b, 'name', 100); $email = self::str($b, 'email', 254);
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) throw ApiError::validation('Invalid email address', 'email');
        if (!array_key_exists('org_id', $b))
            throw ApiError::validation("'org_id' is required (send null for a contact without organization)", 'org_id');
        $orgId = 0;
        if ($b['org_id'] !== null) {
            if (!(is_int($b['org_id']) || ctype_digit((string) $b['org_id'])) || !\Organization::lookup((int) $b['org_id']))
                throw ApiError::validation('Unknown organization', 'org_id');
            $orgId = (int) $b['org_id'];
        }
        $phone = isset($b['phone']) ? trim((string) $b['phone']) : '';
        $force = Threading::boolInput($req, 'force_new', false);

        $m = Matching::findContacts($email, $phone, $name, $orgId ?: null);
        if ($m['safe'] || ($m['result']['classification'] !== 'none' && !$force))
            throw new ApiError('candidates', $m['safe'] ? 'A contact with this email already exists' : 'Similar contacts exist; confirm or send force_new:true',
                null, $m['result']);

        $vars = ['name' => $name, 'email' => $email, 'org_id' => $orgId];   // org_id 0 disables the domain auto-mapping
        if ($phone !== '') $vars['phone'] = $phone;
        $u = \User::fromVars($vars, true);
        if (!$u) throw new ApiError('validation_failed', 'The contact could not be created', 'email');
        return Res::created(Contacts::user(\User::lookup((int) $u->getId())));
    }

    /**
     * PATCH /users/{id} {name?, email?, phone?, fields?:{name:value}, base:{name:value…}}
     * `base` lists the current value of every field being changed (409 conflict when any differs).
     */
    static function update(Request $req) {
        $u = $req->ctx['user'];
        $b = $req->json();
        $changes = [];
        foreach (['name', 'email', 'phone'] as $k)
            if (array_key_exists($k, $b)) $changes[$k] = trim((string) $b[$k]);
        if (isset($b['fields'])) {
            if (!is_array($b['fields'])) throw ApiError::validation("'fields' must be an object", 'fields');
            foreach ($b['fields'] as $k => $v)
                if (is_string($k) && is_scalar($v)) $changes[$k] = trim((string) $v);
        }
        if (!$changes) throw ApiError::validation('Nothing to update: send name, email, phone or fields');
        if (isset($changes['email']) && !filter_var($changes['email'], FILTER_VALIDATE_EMAIL)) throw ApiError::validation('Invalid email address', 'email');
        if (!isset($b['base']) || !is_array($b['base'])) throw ApiError::validation("'base' is required: the current value of every field you change", 'base');

        $current = Contacts::rawAnswers('U', $u->getId());
        $shown = $current;
        $current['name'] = (string) $u->getName();
        $current['email'] = (string) $u->getEmail();
        $apply = []; $conflicts = [];
        foreach ($changes as $k => $v) {
            if (!array_key_exists($k, $b['base'])) throw ApiError::validation("'base.$k' is required", "base.$k");
            $cur = (string) ($current[$k] ?? '');
            $disp = (string) ($shown[$k] ?? $cur);
            if (self::same($k, $cur, $v) || self::same($k, $disp, $v)) continue;          // already desired: idempotent
            $bs = trim((string) $b['base'][$k]);
            if (!self::same($k, $cur, $bs) && !self::same($k, $disp, $bs)) { $conflicts[$k] = $disp; continue; }
            $apply[$k] = $v;
        }
        if ($conflicts) throw new ApiError('conflict', 'A field changed on the server since you read it', null, ['current' => $conflicts]);
        if (!$apply) return Res::ok(['applied' => false, 'user' => Contacts::user($u)]);
        if (isset($apply['email']) && ($o = \User::lookupByEmail($apply['email'])) && $o->getId() != $u->getId())
            throw new ApiError('candidates', 'That email belongs to another contact', 'email', ['classification' => 'safe', 'matches' => [['reason' => 'same_email', 'user' => Contacts::userBrief($o)]]]);

        $vars = array_merge($current, $apply);
        $errors = [];
        if (!$u->updateInfo($vars, $errors, true))
            throw ApiError::fromErrors($errors, 'The contact could not be updated');
        return Res::ok(['applied' => true, 'user' => Contacts::user(\User::lookup((int) $u->getId()))]);
    }

    /** PUT /users/{id}/organization {org_id|null, base} */
    static function setOrg(Request $req) {
        require_once(INCLUDE_DIR . 'class.organization.php');
        $u = $req->ctx['user'];
        $b = $req->json();
        if (!array_key_exists('org_id', $b)) throw ApiError::validation("'org_id' is required (null removes the organization)", 'org_id');
        $base = Ticketing::requireBase($req);
        $desired = $b['org_id'] === null ? 0 : (int) $b['org_id'];
        if ($desired && !($org = \Organization::lookup($desired))) throw ApiError::validation('Unknown organization', 'org_id');
        $cur = (int) $u->getOrgId();
        if ($cur === $desired) return Res::ok(['applied' => false, 'user' => Contacts::user($u)]);
        if ($cur !== (int) $base)
            throw new ApiError('conflict', 'The organization changed on the server since you read it', null, ['current' => $cur ?: null, 'base' => $base]);
        if ($desired) $u->setOrganization($org);
        else { $o = $u->getOrganization(); $o ? $o->removeUser($u) : null; }
        return Res::ok(['applied' => true, 'user' => Contacts::user(\User::lookup((int) $u->getId()))]);
    }

    static function notes(Request $req) {
        require_once(INCLUDE_DIR . 'class.note.php');
        $out = [];
        foreach (\QuickNote::forUser($req->ctx['user']) as $n) $out[] = Contacts::note($n);
        return Res::ok($out);
    }

    /** POST /users/{id}/notes {body} (append-only; body stored as plain text escaped by osTicket on display). */
    static function addNote(Request $req) {
        require_once(INCLUDE_DIR . 'class.note.php');
        $body = self::str($req->json(), 'body', 32000);
        $n = new \QuickNote(['staff_id' => $req->staff->getId(), 'body' => \Format::sanitize($body),
                             'created' => new \SqlFunction('NOW'), 'ext_id' => 'U' . $req->ctx['user']->getId()]);
        if (!$n->save(true)) throw new ApiError('internal_error', 'Unable to create the note');
        return Res::created(Contacts::note(\QuickNote::lookup((int) $n->id)));
    }

    private static function same($field, $a, $b) {
        if ($field === 'phone' && Contacts::last10($a) !== '' && Contacts::last10($b) !== '')
            return Contacts::last10($a) === Contacts::last10($b);
        return trim((string) $a) === trim((string) $b);
    }

    private static function str(array $b, $key, $max) {
        $v = isset($b[$key]) && is_string($b[$key]) ? trim($b[$key]) : '';
        if ($v === '') throw ApiError::validation("'$key' is required", $key);
        if (strlen($v) > $max) throw ApiError::validation("'$key' exceeds $max characters", $key);
        return $v;
    }
}
