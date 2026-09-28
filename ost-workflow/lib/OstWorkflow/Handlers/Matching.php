<?php
namespace OstWorkflow\Handlers;

use OstWorkflow\ApiError;
use OstWorkflow\Contacts;
use OstWorkflow\Request;
use OstWorkflow\Res;
use OstWorkflow\Store;
use OstWorkflow\Ticketing;
use OstWorkflow\Time;

/**
 * Reconciliation services (Architecture §L). Classification is exactly
 *   safe      the server itself vouches for the identity (same email, server id, marker)
 *   candidate a single plausible match a person must confirm
 *   ambiguous more than one candidate: human decision, never automatic
 * No fuzzy matching and no LIKE-based guessing; only canonical-form equality.
 * (Class is `Matching`: `match` is a reserved word in PHP 8.)
 */
final class Matching {
    static function routes() {
        return [
            ['GET', '/match/contact',      'contact',      ['policy' => 'auth']],
            ['GET', '/match/organization', 'organization', ['policy' => 'auth']],
            ['GET', '/match/ticket',       'ticket',       ['policy' => 'auth']],
        ];
    }

    /** GET /match/contact?email=&phone=&name=&org_id= */
    static function contact(Request $req) {
        $r = self::findContacts((string) $req->q('email', ''), (string) $req->q('phone', ''), (string) $req->q('name', ''),
            $req->q('org_id') !== null ? self::intArg($req, 'org_id') : null);
        return Res::ok($r['result'], ['query' => array_filter(['email' => $req->q('email'), 'phone' => $req->q('phone'), 'name' => $req->q('name'), 'org_id' => $req->q('org_id')])]);
    }

    /** Reusable by POST /users: ['result' => {classification, matches[]}, 'safe' => User|null] */
    static function findContacts($email, $phone, $name, $orgId) {
        $email = trim($email); $phone = trim($phone); $name = trim($name);
        if ($email === '' && $phone === '' && $name === '')
            throw ApiError::validation('Send at least one of email, phone or name');
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL))
            throw ApiError::validation('Invalid email address', 'email');

        // SAFE: the exact email is registered (the server says so).
        if ($email !== '' && ($u = \User::lookupByEmail($email)))
            return ['safe' => $u, 'result' => ['classification' => 'safe', 'matches' => [['reason' => 'same_email', 'user' => Contacts::userBrief($u)]]]];

        $cands = [];
        // CANDIDATE: same last 10 digits of a phone.
        $l10 = Contacts::last10($phone);
        if ($l10 !== '') {
            $tail = substr($l10, -4);
            $q = Store::q('SELECT DISTINCT e.object_id AS uid, v.value FROM ' . FORM_ENTRY_TABLE . ' e JOIN ' . FORM_ANSWER_TABLE . ' v ON v.entry_id=e.id'
                . ' JOIN ' . FORM_FIELD_TABLE . ' f ON f.id=v.field_id WHERE e.object_type=\'U\' AND f.name=\'phone\''
                . ' AND v.value LIKE ' . Store::esc('%' . $tail . '%') . ' LIMIT 200');
            while ($q && ($row = db_fetch_array($q)))
                if (Contacts::last10($row['value']) === $l10)
                    $cands[(int) $row['uid']]['same_phone'] = true;
        }
        // CANDIDATE: same normalized name inside the same organization.
        if ($name !== '' && $orgId) {
            $n = Contacts::norm($name);
            $q = Store::q('SELECT id, name FROM ' . USER_TABLE . ' WHERE org_id=' . (int) $orgId . ' LIMIT 2000');
            while ($q && ($row = db_fetch_array($q)))
                if (Contacts::norm($row['name']) === $n)
                    $cands[(int) $row['id']]['same_name_in_org'] = true;
        }
        $matches = [];
        foreach ($cands as $uid => $reasons) {
            if (($u = \User::lookup($uid)))
                $matches[] = ['reason' => implode('+', array_keys($reasons)), 'user' => Contacts::userBrief($u)];
        }
        $class = !$matches ? 'none' : (count($matches) === 1 ? 'candidate' : 'ambiguous');
        return ['safe' => null, 'result' => ['classification' => $class, 'matches' => $matches]];
    }

    /** GET /match/organization?id=|idem_key=|name=&domain= — by name/domain the answer is never "safe". */
    static function organization(Request $req) {
        require_once(INCLUDE_DIR . 'class.organization.php');
        // SAFE by server identity: an id, or the resource recorded for one of this agent's own idempotency keys.
        if ($req->q('id') !== null) {
            $o = \Organization::lookup(self::intArg($req, 'id'));
            return Res::ok($o ? ['classification' => 'safe', 'matches' => [['reason' => 'server_id', 'organization' => self::orgBrief($o)]]]
                              : ['classification' => 'none', 'matches' => []]);
        }
        if ($req->q('idem_key') !== null) {
            $r = Store::row('SELECT resource_id FROM ' . Store::table() . ' WHERE kind=\'idem\' AND staff_id=' . (int) $req->staff->getId()
                . ' AND idem_key=' . Store::esc((string) $req->q('idem_key')) . ' AND resource_type=\'organization\'');
            $o = $r ? \Organization::lookup((int) $r['resource_id']) : null;
            return Res::ok($o ? ['classification' => 'safe', 'matches' => [['reason' => 'idempotency_record', 'organization' => self::orgBrief($o)]]]
                              : ['classification' => 'none', 'matches' => []]);
        }
        $name = trim((string) $req->q('name', ''));
        $domain = strtolower(trim((string) $req->q('domain', '')));
        if ($name === '' && $domain === '')
            throw ApiError::validation("Send 'id', 'idem_key', 'name' or 'domain'");
        return Res::ok(self::orgCandidates($name, $domain));
    }

    /** Reusable by POST /organizations. */
    static function orgCandidates($name, $domain) {
        require_once(INCLUDE_DIR . 'class.organization.php');
        $cands = [];
        if ($name !== '') {
            $n = Contacts::normOrg($name);
            $q = Store::q('SELECT id, name FROM ' . ORGANIZATION_TABLE . ' LIMIT 5000');
            while ($q && ($row = db_fetch_array($q)))
                if ($n !== '' && Contacts::normOrg($row['name']) === $n)
                    $cands[(int) $row['id']] = 'same_normalized_name';
        }
        if ($domain !== '') {
            $q = Store::q('SELECT id, domain FROM ' . ORGANIZATION_TABLE . ' WHERE domain<>\'\' LIMIT 5000');
            while ($q && ($row = db_fetch_array($q)))
                foreach (array_map('trim', explode(',', strtolower($row['domain']))) as $d)
                    if ($d === $domain && !isset($cands[(int) $row['id']]))
                        $cands[(int) $row['id']] = 'mapped_domain';
        }
        $matches = [];
        foreach ($cands as $id => $why)
            if (($o = \Organization::lookup($id)))
                $matches[] = ['reason' => $why, 'organization' => self::orgBrief($o)];
        return ['classification' => !$matches ? 'none' : (count($matches) === 1 ? 'candidate' : 'ambiguous'), 'matches' => $matches];
    }

    /**
     * GET /match/ticket?marker=|number=|user_id=&subject=&since=
     * SAFE: the marker source_extra='wf:<uuid>' or a server number. CANDIDATE: same contact + open +
     * same normalized subject + created within +-1h of `since` by this agent.
     */
    static function ticket(Request $req) {
        require_once(INCLUDE_DIR . 'class.ticket.php');
        $vis = \Ticket::objects()->filter($req->staff->getTicketsVisibility());
        if (($marker = $req->q('marker')) !== null) {
            if (!preg_match('/^wf:[A-Za-z0-9-]{8,37}$/', (string) $marker))
                throw ApiError::validation("'marker' must look like wf:<uuid>", 'marker');
            $t = $vis->filter(['source_extra' => (string) $marker])->one();
            return Res::ok($t ? ['classification' => 'safe', 'matches' => [['reason' => 'marker', 'ticket' => Ticketing::summary($t)]]]
                             : ['classification' => 'none', 'matches' => []]);
        }
        if (($number = $req->q('number')) !== null) {
            $t = $vis->filter(['number' => (string) $number])->one();
            return Res::ok($t ? ['classification' => 'safe', 'matches' => [['reason' => 'server_number', 'ticket' => Ticketing::summary($t)]]]
                             : ['classification' => 'none', 'matches' => []]);
        }
        $uid = self::intArg($req, 'user_id', true);
        $subject = trim((string) $req->q('subject', ''));
        $since = $req->q('since');
        if ($subject === '' || $since === null)
            throw ApiError::validation("Send 'marker', 'number' or user_id + subject + since");
        $sinceDb = Time::toDb((string) $since);
        if (!$sinceDb) throw ApiError::validation("'since' must be ISO-8601", 'since');
        $ts = strtotime($sinceDb . ' UTC');
        $lo = gmdate('Y-m-d H:i:s', $ts - 3600); $hi = gmdate('Y-m-d H:i:s', $ts + 3600);
        $n = Contacts::norm($subject);
        $matches = [];
        $rows = $vis->filter(['user_id' => $uid, 'status__state' => 'open', 'created__gte' => $lo, 'created__lte' => $hi])->order_by('-ticket_id')->limit(50);
        foreach ($rows as $t) {
            if (Contacts::norm($t->getSubject()) !== $n) continue;
            // created by this same agent (the 'created' event carries the actor)
            $ev = Store::row('SELECT e.id FROM ' . THREAD_EVENT_TABLE . ' e JOIN ' . TABLE_PREFIX . 'event ev ON ev.id=e.event_id WHERE e.thread_id='
                . (int) $t->getThreadId() . ' AND ev.name=\'created\' AND e.staff_id=' . (int) $req->staff->getId());
            if ($ev) $matches[] = ['reason' => 'same_contact_subject_window_author', 'ticket' => Ticketing::summary($t)];
        }
        return Res::ok(['classification' => !$matches ? 'none' : (count($matches) === 1 ? 'candidate' : 'ambiguous'), 'matches' => $matches]);
    }

    private static function orgBrief(\Organization $o) {
        return ['id' => (int) $o->getId(), 'name' => (string) $o->getName(), 'domain' => (string) $o->ht['domain']];
    }

    private static function intArg(Request $req, $key, $required = false) {
        $v = $req->q($key);
        if ($v === null) {
            if ($required) throw ApiError::validation("'$key' is required", $key);
            return null;
        }
        if (!ctype_digit((string) $v)) throw ApiError::validation("'$key' must be an integer", $key);
        return (int) $v;
    }
}
