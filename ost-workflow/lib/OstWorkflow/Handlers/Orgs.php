<?php
namespace OstWorkflow\Handlers;

use OstWorkflow\ApiError;
use OstWorkflow\Contacts;
use OstWorkflow\Request;
use OstWorkflow\Res;
use OstWorkflow\Store;
use OstWorkflow\Threading;
use OstWorkflow\Ticketing;

/**
 * Organizations. Creation returns 409 candidates BEFORE Organization::fromVars (which silently
 * returns an existing organization of the same name); `extra` is updated with a real compare-and-swap.
 */
final class Orgs {
    const FLAGS = ['collab_all_members' => 'collab-all-flag', 'collab_primary_contact' => 'collab-pc-flag', 'assign_account_manager' => 'assign-am-flag'];

    static function routes() {
        $id = '/organizations/(?P<id>\d+)';
        return [
            ['GET',    '/organizations',              'index',   ['policy' => 'auth']],
            ['POST',   '/organizations',              'create',  ['policy' => 'global.org.create']],
            ['GET',    $id,                           'detail',  ['policy' => 'org.load']],
            ['GET',    "$id/members",                 'members', ['policy' => 'org.load']],
            ['GET',    "$id/tickets",                 'tickets', ['policy' => 'org.load']],
            ['GET',    "$id/fields",                  'fields',  ['policy' => 'org.load']],
            ['POST',   "$id/members",                 'addMember',    ['policy' => 'org.edit']],
            ['DELETE', "$id/members/(?P<uid>\d+)",    'removeMember', ['policy' => 'org.edit']],
            ['PUT',    $id,                           'rename',  ['policy' => 'org.edit']],
            ['PATCH',  "$id/profile",                 'profile', ['policy' => 'org.edit']],
            ['PATCH',  "$id/extra",                   'extra',   ['policy' => 'org.edit']],
            ['GET',    "$id/notes",                   'notes',   ['policy' => 'org.load']],
            ['POST',   "$id/notes",                   'addNote', ['policy' => 'org.load']],
        ];
    }

    static function policy() {
        $load = function (Request $req, $perm = null) {
            require_once(INCLUDE_DIR . 'class.organization.php');
            $o = \Organization::lookup((int) $req->param('id'));
            if (!$o) throw ApiError::notFound('organization');
            if ($perm && !$req->staff->hasPerm($perm))
                throw new ApiError('forbidden', 'Missing permission: ' . $perm);
            $req->ctx['org'] = $o;
        };
        return [
            'org.load' => function (Request $req) use ($load) { $load($req); },
            'org.edit' => function (Request $req) use ($load) { $load($req, 'org.edit'); },
        ];
    }

    static function index(Request $req) {
        require_once(INCLUDE_DIR . 'class.organization.php');
        $limit = $req->intQuery('limit', 25, 1, 100);
        $qs = \Organization::objects();
        if (($q = $req->q('q')) !== null) {
            $q = trim((string) $q);
            if (strlen($q) < 2) throw ApiError::validation("'q' must have at least 2 characters", 'q');
            if (strlen($q) > 100) throw ApiError::validation("'q' is too long (max 100)", 'q');
            $qs = $qs->filter(\Q::any(['name__contains' => $q, 'domain__contains' => $q]));
        }
        if (($d = $req->q('domain')) !== null) $qs = $qs->filter(['domain__contains' => strtolower(trim((string) $d))]);
        if (($after = Contacts::cursorId($req))) $qs = $qs->filter(['id__gt' => $after]);
        $rows = [];
        foreach ($qs->order_by('id')->limit($limit + 1) as $o) $rows[] = $o;
        $more = count($rows) > $limit;
        $rows = array_slice($rows, 0, $limit);
        $items = array_map(function ($o) { return Contacts::org($o); }, $rows);
        return Res::page($items, ($more && $rows) ? Contacts::nextCursor(end($rows)->getId()) : null);
    }

    static function detail(Request $req) {
        $o = $req->ctx['org'];
        return Res::ok(Contacts::org($o), ['warnings' => Contacts::orgWarnings($o)]);
    }

    static function members(Request $req) {
        $out = [];
        foreach ($req->ctx['org']->allMembers() as $u) {
            $b = Contacts::userBrief($u);
            $b['is_primary_contact'] = (bool) $u->isPrimaryContact();
            $out[] = $b;
        }
        return Res::ok($out);
    }

    static function tickets(Request $req) {
        require_once(INCLUDE_DIR . 'class.ticket.php');
        return Ticketing::pageById(\Ticket::objects()->filter($req->staff->getTicketsVisibility())
            ->filter(['user__org_id' => $req->ctx['org']->getId()]), $req);
    }

    static function fields(Request $req) {
        return Res::ok(Contacts::entries($req->ctx['org']->getForms()));
    }

    /** POST /organizations {name, domain?, force_new?} — never a blind find-or-create. */
    static function create(Request $req) {
        require_once(INCLUDE_DIR . 'class.organization.php');
        $b = $req->json();
        $name = isset($b['name']) && is_string($b['name']) ? trim(strip_tags($b['name'])) : '';
        if ($name === '') throw ApiError::validation("'name' is required", 'name');
        if (strlen($name) > 128) throw ApiError::validation("'name' exceeds 128 characters", 'name');
        $domain = self::domain($b['domain'] ?? '');
        $force = Threading::boolInput($req, 'force_new', false);

        $m = Matching::orgCandidates($name, explode(',', $domain)[0]);
        $sameName = false;
        foreach ($m['matches'] as $x) if ($x['reason'] === 'same_normalized_name') $sameName = true;
        if ($m['matches'] && (!$force || $sameName))
            throw new ApiError('candidates', $sameName ? 'An organization with the same normalized name exists' : 'Organizations mapped to this domain exist; confirm or send force_new:true',
                null, $m);

        $org = \Organization::fromVars(['name' => $name]);
        if (!$org || !$org->getId()) throw new ApiError('internal_error', 'The organization could not be created');
        if ($domain !== '') { $org->set('domain', $domain); $org->save(); }
        $org = \Organization::lookup((int) $org->getId());
        return Res::created(Contacts::org($org));
    }

    /** POST /organizations/{id}/members {user_id} */
    static function addMember(Request $req) {
        require_once(INCLUDE_DIR . 'class.user.php');
        $o = $req->ctx['org'];
        $uid = $req->input('user_id');
        if (!(is_int($uid) || (is_string($uid) && ctype_digit($uid))) || !($u = \User::lookup((int) $uid)))
            throw ApiError::validation('Unknown user', 'user_id');
        if ((int) $u->getOrgId() === (int) $o->getId()) return Res::ok(['applied' => false, 'user' => Contacts::user($u)]);
        if ($u->getOrgId())
            throw new ApiError('conflict', 'The contact already belongs to another organization; use PUT /users/{id}/organization with its base', 'user_id',
                ['current_org_id' => (int) $u->getOrgId()]);
        $u->setOrganization($o);
        return Res::created(Contacts::user(\User::lookup((int) $u->getId())));
    }

    static function removeMember(Request $req) {
        require_once(INCLUDE_DIR . 'class.user.php');
        $o = $req->ctx['org'];
        $u = \User::lookup((int) $req->param('uid'));
        if (!$u || (int) $u->getOrgId() !== (int) $o->getId()) return Res::ok(['applied' => false]);
        $o->removeUser($u);
        return Res::ok(['applied' => true, 'user' => Contacts::user(\User::lookup((int) $u->getId()))]);
    }

    /** PUT /organizations/{id} {name, base:{name}} */
    static function rename(Request $req) {
        $o = $req->ctx['org'];
        $b = $req->json();
        $name = isset($b['name']) && is_string($b['name']) ? trim(strip_tags($b['name'])) : '';
        if ($name === '') throw ApiError::validation("'name' is required", 'name');
        if (!isset($b['base']['name'])) throw ApiError::validation("'base.name' is required", 'base.name');
        if ((string) $o->getName() === $name) return Res::ok(['applied' => false, 'organization' => Contacts::org($o)]);
        if ((string) $o->getName() !== trim((string) $b['base']['name']))
            throw new ApiError('conflict', 'The name changed on the server since you read it', null, ['current' => ['name' => (string) $o->getName()]]);
        if (($x = \Organization::lookup(['name' => $name])) && $x->getId() != $o->getId())
            throw new ApiError('candidates', 'Another organization already has that name', 'name', ['classification' => 'safe', 'matches' => [['reason' => 'same_name', 'organization' => ['id' => (int) $x->getId(), 'name' => (string) $x->getName()]]]]);
        $vars = array_merge(Contacts::rawAnswers('O', $o->getId()), self::profileVars($o), ['name' => $name]);
        $errors = [];
        $ok = Contacts::asPost($vars, function () use ($o, $vars, &$errors) { return $o->updateProfile($vars, $errors); });
        if (!$ok) throw ApiError::fromErrors($errors, 'The organization could not be updated');
        return Res::ok(['applied' => true, 'organization' => Contacts::org(\Organization::lookup((int) $o->getId()))]);
    }

    /**
     * PATCH /organizations/{id}/profile {manager?:'s12'|'t3'|null, domain?, flags?:{collab_all_members…}, sharing?:'primary'|'everybody',
     *                                    primary_contacts?:[user ids], base:{same keys}}
     */
    static function profile(Request $req) {
        $o = $req->ctx['org'];
        $b = $req->json();
        if (!isset($b['base']) || !is_array($b['base'])) throw ApiError::validation("'base' is required: the current value of every key you change", 'base');
        $current = self::profileView($o);
        $want = [];
        foreach (['manager', 'domain', 'sharing', 'primary_contacts'] as $k)
            if (array_key_exists($k, $b)) $want[$k] = $b[$k];
        if (isset($b['flags'])) {
            if (!is_array($b['flags'])) throw ApiError::validation("'flags' must be an object", 'flags');
            foreach ($b['flags'] as $k => $v) {
                if (!array_key_exists($k, self::FLAGS) || !is_bool($v)) throw ApiError::validation("Unknown or non-boolean flag '$k'", "flags.$k", ['allowed' => array_keys(self::FLAGS)]);
                $want['flags.' . $k] = $v;
            }
        }
        if (!$want) throw ApiError::validation('Nothing to update');
        if (isset($want['domain'])) $want['domain'] = self::domain($want['domain']);
        if (isset($want['sharing']) && !in_array($want['sharing'], ['primary', 'everybody'], true)) throw ApiError::validation("'sharing' must be primary or everybody", 'sharing');
        if (isset($want['manager']) && !preg_match('/^[st]\d+$/', (string) $want['manager'])) throw ApiError::validation("'manager' must be s<id> or t<id>", 'manager');
        if (isset($want['primary_contacts'])) {
            if (!is_array($want['primary_contacts'])) throw ApiError::validation("'primary_contacts' must be a list of user ids", 'primary_contacts');
            $want['primary_contacts'] = array_values(array_map('intval', $want['primary_contacts']));
            sort($want['primary_contacts']);
        }
        $apply = []; $conflicts = [];
        foreach ($want as $k => $v) {
            if (strpos($k, 'flags.') === 0) {
                $fk = substr($k, 6);
                $has = isset($b['base']['flags']) && is_array($b['base']['flags']) && array_key_exists($fk, $b['base']['flags']);
                $baseVal = $has ? $b['base']['flags'][$fk] : null;
            } else {
                $has = array_key_exists($k, $b['base']);
                $baseVal = $has ? $b['base'][$k] : null;
            }
            if (!$has) throw ApiError::validation("'base.$k' is required", "base.$k");
            $cur = $current[$k];
            if ($cur === $v || (is_array($cur) && $cur == $v)) continue;
            if ($baseVal != $cur && !($baseVal === null && $cur === '') && !($baseVal === '' && $cur === null)) { $conflicts[$k] = $cur; continue; }
            $apply[$k] = $v;
        }
        if ($conflicts) throw new ApiError('conflict', 'A value changed on the server since you read it', null, ['current' => $conflicts]);
        if (!$apply) return Res::ok(['applied' => false, 'organization' => Contacts::org($o)]);
        // A manager token must point to an existing agent/team (osTicket validates it too)
        $merged = array_merge($current, $apply);
        $vars = array_merge(Contacts::rawAnswers('O', $o->getId()), ['name' => (string) $o->getName(),
            'domain' => (string) $merged['domain'], 'manager' => (string) ($merged['manager'] ?? ''),
            'sharing' => $merged['sharing'] === 'everybody' ? 'sharing-all' : 'sharing-primary']);
        foreach (self::FLAGS as $k => $vk) if (!empty($merged['flags.' . $k])) $vars[$vk] = 1;
        if (!empty($merged['primary_contacts'])) $vars['contacts'] = array_map('strval', $merged['primary_contacts']);
        $errors = [];
        $ok = Contacts::asPost($vars, function () use ($o, $vars, &$errors) { return $o->updateProfile($vars, $errors); });
        if (!$ok) throw ApiError::fromErrors($errors, 'The profile could not be updated');
        $fresh = \Organization::lookup((int) $o->getId());
        return Res::ok(['applied' => true, 'organization' => Contacts::org($fresh), 'warnings' => Contacts::orgWarnings($fresh)]);
    }

    /**
     * PATCH /organizations/{id}/extra — compare-and-swap on the raw `extra` text.
     *   {base_hash, set:{KEY:val}, unset:[KEY]}   edits the `BCW|<ver>|K=V|K=V` line (other lines untouched)
     *   {base_hash, text:"…"}                     replaces the whole text
     * base_hash = sha256 of the current text ('' when empty), as returned by GET /organizations/{id}.
     */
    static function extra(Request $req) {
        $o = $req->ctx['org'];
        $b = $req->json();
        if (!isset($b['base_hash']) || !is_string($b['base_hash'])) throw ApiError::validation("'base_hash' is required", 'base_hash');
        $row = Store::row('SELECT extra FROM ' . ORGANIZATION_TABLE . ' WHERE id=' . (int) $o->getId());
        $raw = $row['extra'];
        $text = (string) $raw;
        if (isset($b['text'])) {
            if (!is_string($b['text']) || strlen($b['text']) > 65000) throw ApiError::validation("'text' must be a string up to 65000 bytes", 'text');
            $new = $b['text'];
        } else {
            $new = self::editBlock($text, (array) ($b['set'] ?? []), (array) ($b['unset'] ?? []));
        }
        if ($new === $text) return Res::ok(['applied' => false, 'extra' => $raw, 'extra_hash' => hash('sha256', $text)]);
        if (!hash_equals(hash('sha256', $text), $b['base_hash']))
            throw new ApiError('conflict', 'extra changed on the server since you read it', null,
                ['current_hash' => hash('sha256', $text), 'current' => $raw]);
        // Real CAS: the UPDATE only matches while the stored text is still the one we read.
        Store::q('UPDATE ' . ORGANIZATION_TABLE . ' SET extra=' . Store::esc($new) . ', updated=NOW() WHERE id=' . (int) $o->getId()
            . ' AND (extra <=> ' . ($raw === null ? 'NULL' : Store::esc($raw)) . ')');
        if (Store::affected() !== 1)
            throw new ApiError('conflict', 'extra changed while saving; re-read and retry', null, ['reason' => 'cas_lost']);
        return Res::ok(['applied' => true, 'extra' => $new, 'extra_hash' => hash('sha256', $new)]);
    }

    static function notes(Request $req) {
        require_once(INCLUDE_DIR . 'class.note.php');
        $out = [];
        foreach (\QuickNote::forOrganization($req->ctx['org']) as $n) $out[] = Contacts::note($n);
        return Res::ok($out);
    }

    static function addNote(Request $req) {
        require_once(INCLUDE_DIR . 'class.note.php');
        $body = trim((string) $req->input('body', ''));
        if ($body === '') throw ApiError::validation("'body' is required", 'body');
        if (strlen($body) > 32000) throw ApiError::validation("'body' exceeds 32000 characters", 'body');
        $n = new \QuickNote(['staff_id' => $req->staff->getId(), 'body' => \Format::sanitize($body),
                             'created' => new \SqlFunction('NOW'), 'ext_id' => 'O' . $req->ctx['org']->getId()]);
        if (!$n->save(true)) throw new ApiError('internal_error', 'Unable to create the note');
        return Res::created(Contacts::note(\QuickNote::lookup((int) $n->id)));
    }

    // ------------------------------------------------------------------

    private static function domain($d) {
        $d = strtolower(trim((string) $d));
        if ($d === '') return '';
        foreach (array_map('trim', explode(',', $d)) as $one)
            if (!\Validator::is_email('t@' . $one)) throw ApiError::validation("Invalid email domain '$one'", 'domain');
        return implode(',', array_map('trim', explode(',', $d)));
    }

    /** API view of the profile keys, used for base comparison and rebuilding the full POST-like vars. */
    private static function profileView(\Organization $o) {
        $v = ['manager' => $o->getAccountManagerId() ?: null, 'domain' => (string) $o->ht['domain'],
              'sharing' => $o->shareWithEverybody() ? 'everybody' : 'primary'];
        foreach (self::FLAGS as $k => $vk) {
            $v['flags.' . $k] = (bool) ($k === 'collab_all_members' ? $o->autoAddMembersAsCollabs()
                : ($k === 'collab_primary_contact' ? $o->autoAddPrimaryContactsAsCollabs() : $o->autoAssignAccountManager()));
        }
        $pc = [];
        foreach ($o->allMembers() as $u) if ($u->isPrimaryContact()) $pc[] = (int) $u->getId();
        sort($pc);
        $v['primary_contacts'] = $pc;
        return $v;
    }

    private static function profileVars(\Organization $o) {
        $c = self::profileView($o);
        $vars = ['domain' => $c['domain'], 'manager' => (string) ($c['manager'] ?? ''), 'sharing' => $c['sharing'] === 'everybody' ? 'sharing-all' : 'sharing-primary'];
        foreach (self::FLAGS as $k => $vk) if ($c['flags.' . $k]) $vars[$vk] = 1;
        if ($c['primary_contacts']) $vars['contacts'] = array_map('strval', $c['primary_contacts']);
        return $vars;
    }

    /** Edit the `BCW|<version>|K=V|K=V` line of the text; keeps every other line as is. */
    private static function editBlock($text, array $set, array $unset) {
        foreach ($set as $k => $v) {
            if (!is_string($k) || !preg_match('/^[A-Za-z0-9_.-]{1,40}$/', $k)) throw ApiError::validation("Invalid key '$k'", 'set');
            if (!is_scalar($v) || preg_match('/[|\r\n]/', (string) $v)) throw ApiError::validation("Value of '$k' cannot contain | or line breaks", 'set');
        }
        $lines = $text === '' ? [] : preg_split('/\r\n|\n/', $text);
        $idx = null;
        foreach ($lines as $i => $l) if (strpos($l, 'BCW|') === 0) { $idx = $i; break; }
        $ver = '1'; $pairs = [];
        if ($idx !== null) {
            $parts = explode('|', $lines[$idx]);
            $ver = $parts[1] ?? '1';
            foreach (array_slice($parts, 2) as $p)
                if (($eq = strpos($p, '=')) !== false) $pairs[substr($p, 0, $eq)] = substr($p, $eq + 1);
        }
        foreach ($set as $k => $v) $pairs[$k] = (string) $v;
        foreach ($unset as $k) unset($pairs[(string) $k]);
        $line = $pairs ? 'BCW|' . $ver . '|' . implode('|', array_map(function ($k, $v) { return "$k=$v"; }, array_keys($pairs), $pairs)) : null;
        if ($idx !== null) {
            if ($line === null) array_splice($lines, $idx, 1); else $lines[$idx] = $line;
        } elseif ($line !== null) {
            $lines[] = $line;
        }
        return implode("\n", $lines);
    }
}
