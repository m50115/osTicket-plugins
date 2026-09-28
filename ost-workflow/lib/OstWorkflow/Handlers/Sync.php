<?php
namespace OstWorkflow\Handlers;

use OstWorkflow\ApiError;
use OstWorkflow\Contacts;
use OstWorkflow\Request;
use OstWorkflow\Res;
use OstWorkflow\Store;
use OstWorkflow\Ticketing;
use OstWorkflow\Time;
use OstWorkflow\Token;

/**
 * Delta sync (Architecture §J). `ticket.updated` alone is NOT a reliable cursor
 * (notes and successive replies do not move it), so the ticket feed is composite:
 *   (ticket.updated, id) + MAX(thread_entry.id) + MAX(thread_event.id) + form_entry.updated
 * A ticket is returned when ANY component is past the client's state. Entries and events are
 * then fetched by id (GET /tickets/{id}/activity). Everything leaves in UTC; a 5 s overlap window is
 * applied to date-only components; clients dedupe by (id, updated).
 *
 * Paging: `cursor` continues a pass; when has_more=false, `sync_state` is the watermark for the NEXT pass
 * (captured when the pass started, so changes made during it are never skipped).
 */
final class Sync {
    const OVERLAP = 5;

    static function routes() {
        return [
            ['GET', '/sync/tickets',            'tickets',   ['policy' => 'auth']],
            ['GET', '/sync/visible-ticket-ids', 'visibleIds', ['policy' => 'auth']],
            ['GET', '/sync/users',              'users',     ['policy' => 'auth']],
            ['GET', '/sync/organizations',      'orgs',      ['policy' => 'auth']],
            ['GET', '/sync/tasks',              'tasks',     ['policy' => 'auth']],
        ];
    }

    // ------------------------------------------------------------------
    // GET /sync/tickets?state=<opaque sync_state from the previous pass>|(none = full)&cursor=&limit=
    // ------------------------------------------------------------------
    static function tickets(Request $req) {
        require_once(INCLUDE_DIR . 'class.ticket.php');
        $limit = $req->intQuery('limit', 50, 1, 200);
        $cur = self::decode($req->q('cursor'));
        $since = $cur ? $cur['since'] : self::decode($req->q('state'), 'state');
        $hi = $cur ? $cur['hi'] : self::watermarkTickets();
        $after = $cur ? (int) $cur['after'] : 0;

        $cond = '1=1';
        if ($since) {
            $u = self::db(($since['u'] ?? null), -self::OVERLAP);
            $f = self::db(($since['f'] ?? null), -self::OVERLAP);
            $cond = '(t.updated >= ' . Store::esc($u)
                . ' OR EXISTS (SELECT 1 FROM ' . THREAD_TABLE . ' th JOIN ' . THREAD_ENTRY_TABLE . ' te ON te.thread_id=th.id WHERE th.object_id=t.ticket_id AND th.object_type=\'T\' AND te.id > ' . (int) ($since['e'] ?? 0) . ')'
                . ' OR EXISTS (SELECT 1 FROM ' . THREAD_TABLE . ' th2 JOIN ' . THREAD_EVENT_TABLE . ' tv ON tv.thread_id=th2.id WHERE th2.object_id=t.ticket_id AND th2.object_type=\'T\' AND tv.id > ' . (int) ($since['v'] ?? 0) . ')'
                . ' OR EXISTS (SELECT 1 FROM ' . FORM_ENTRY_TABLE . ' fe WHERE fe.object_type=\'T\' AND fe.object_id=t.ticket_id AND fe.updated >= ' . Store::esc($f) . '))';
        }
        $items = []; $more = false; $last = $after;
        while (count($items) < $limit) {
            $q = Store::q('SELECT t.ticket_id FROM ' . TICKET_TABLE . ' t WHERE t.ticket_id > ' . $last . ' AND ' . $cond . ' ORDER BY t.ticket_id LIMIT 500');
            $ids = [];
            while ($q && ($r = db_fetch_array($q))) $ids[] = (int) $r['ticket_id'];
            if (!$ids) break;
            $visible = [];
            foreach (Ticketing::visible($req->staff)->filter(['ticket_id__in' => $ids])->order_by('ticket_id') as $t)
                $visible[(int) $t->getId()] = $t;
            $chunkLast = end($ids);
            foreach ($ids as $id) {
                if (!isset($visible[$id])) { $last = $id; continue; }
                if (count($items) >= $limit) { $more = true; break 2; }
                $items[$id] = $visible[$id]; $last = $id;
            }
            if (count($ids) < 500) break;
            $last = $chunkLast;
        }
        if (!$more && count($items) >= $limit) {   // probe for one more
            $more = (bool) Store::row('SELECT t.ticket_id FROM ' . TICKET_TABLE . ' t WHERE t.ticket_id > ' . $last . ' AND ' . $cond . ' LIMIT 1');
        }

        $out = self::components(array_keys($items));
        $raw = Ticketing::rows(array_keys($items));
        Ticketing::prime(array_keys($items));
        $data = [];
        foreach ($items as $id => $t) {
            $c = $out[$id] ?? ['entry' => 0, 'event' => 0, 'form' => null];
            $data[] = ['id' => $id, 'updated' => Time::iso($raw[$id]['updated'] ?? null),
                       'last_entry_id' => $c['entry'], 'last_event_id' => $c['event'], 'form_updated' => Time::iso($c['form']),
                       'ticket' => Ticketing::summary($t, $raw[$id] ?? [])];
        }
        $meta = ['count' => count($data), 'has_more' => $more, 'full' => !$since];
        if ($more) $meta['cursor'] = self::encode(['since' => $since, 'hi' => $hi, 'after' => $last]);
        else       $meta['sync_state'] = self::encode($hi);
        return Res::ok($data, $meta);
    }

    /** ids of every ticket the agent can see (all states), ascending — used to detect removals. */
    static function visibleIds(Request $req) {
        require_once(INCLUDE_DIR . 'class.ticket.php');
        $limit = $req->intQuery('limit', 1000, 1, 5000);
        $qs = Ticketing::visible($req->staff);
        if (($c = $req->q('cursor')) !== null) {
            $d = json_decode(Token::unb64($c), true);
            if (!is_array($d) || !isset($d['i']) || !is_int($d['i'])) throw ApiError::validation('Invalid cursor', 'cursor');
            $qs = $qs->filter(['ticket_id__gt' => $d['i']]);
        }
        $ids = [];
        foreach ($qs->order_by('ticket_id')->limit($limit + 1)->values_flat('ticket_id') as $r) $ids[] = (int) $r[0];
        $more = count($ids) > $limit;
        $ids = array_slice($ids, 0, $limit);
        return Res::ok($ids, ['count' => count($ids), 'has_more' => $more,
                              'next_cursor' => ($more && $ids) ? Token::b64(json_encode(['i' => end($ids)])) : null,
                              'server_time' => Time::iso(time())]);
    }

    // ------------------------------------------------------------------
    // Date-only entities: window >= since - 5 s, ordered by (updated, id)
    // ------------------------------------------------------------------
    static function users(Request $req) {
        \OstWorkflow\Directory::requireFull($req->staff);   // a bulk copy of every contact: directory access only
        return self::byDate($req, USER_TABLE, 'id', function ($id) {
            require_once(INCLUDE_DIR . 'class.user.php');
            $u = \User::lookup((int) $id);
            return $u ? Contacts::user($u) : null;
        });
    }

    static function orgs(Request $req) {
        \OstWorkflow\Directory::requireFull($req->staff);
        return self::byDate($req, ORGANIZATION_TABLE, 'id', function ($id) {
            require_once(INCLUDE_DIR . 'class.organization.php');
            $o = \Organization::lookup((int) $id);
            return $o ? Contacts::org($o) : null;
        });
    }

    /** GET /sync/tasks?since=&event_since= — updated/closed date window OR new thread events on the task. */
    static function tasks(Request $req) {
        require_once(INCLUDE_DIR . 'class.task.php');
        $limit = $req->intQuery('limit', 50, 1, 200);
        $cur = self::decode($req->q('cursor'));
        $since = $cur ? $cur['since'] : ($req->q('since') !== null ? ['u' => (string) $req->q('since'), 'v' => $req->intQuery('event_since', 0, 0)] : null);
        $hi = $cur ? $cur['hi'] : ['u' => Time::iso(time()), 'v' => (int) (Store::row('SELECT MAX(id) AS m FROM ' . THREAD_EVENT_TABLE)['m'] ?? 0)];
        $after = $cur ? (int) $cur['after'] : 0;
        $cond = '1=1';
        if ($since) {
            $u = Store::esc(self::db($since['u'], -self::OVERLAP));
            $cond = '(k.updated >= ' . $u . ' OR k.closed >= ' . $u . ' OR EXISTS (SELECT 1 FROM ' . THREAD_TABLE . ' th JOIN ' . THREAD_EVENT_TABLE
                . ' tv ON tv.thread_id=th.id WHERE th.object_id=k.id AND th.object_type=\'A\' AND tv.id > ' . (int) ($since['v'] ?? 0) . '))';
        }
        $s = $req->staff; $vis = ['k.staff_id=' . (int) $s->getId()];
        if ($s->getDepts()) $vis[] = 'k.dept_id IN (' . implode(',', array_map('intval', $s->getDepts())) . ')';
        if ($s->getTeams()) $vis[] = 'k.team_id IN (' . implode(',', array_map('intval', $s->getTeams())) . ')';
        $q = Store::q('SELECT k.id FROM ' . TASK_TABLE . ' k WHERE k.id > ' . $after . ' AND ' . $cond . ' AND (' . implode(' OR ', $vis) . ') ORDER BY k.id LIMIT ' . ($limit + 1));
        $ids = [];
        while ($q && ($r = db_fetch_array($q))) $ids[] = (int) $r['id'];
        $more = count($ids) > $limit;
        $ids = array_slice($ids, 0, $limit);
        $data = [];
        foreach ($ids as $id) if (($t = \Task::lookup($id))) $data[] = Tasks::dto($t);
        $meta = ['count' => count($data), 'has_more' => $more, 'full' => !$since];
        if ($more) $meta['cursor'] = self::encode(['since' => $since, 'hi' => $hi, 'after' => end($ids)]);
        else $meta['sync_state'] = self::encode($hi);
        return Res::ok($data, $meta);
    }

    private static function byDate(Request $req, $table, $idCol, callable $load) {
        $limit = $req->intQuery('limit', 100, 1, 500);
        $cur = self::decode($req->q('cursor'));
        $since = $cur ? $cur['since'] : ($req->q('since') !== null ? (string) $req->q('since') : null);
        $hi = $cur ? $cur['hi'] : Time::iso(time());
        $cond = '1=1';
        if ($since !== null) {
            $db = self::db($since, -self::OVERLAP);
            if (!$db) throw ApiError::validation("'since' must be ISO-8601", 'since');
            $cond = 'updated >= ' . Store::esc($db);
        }
        $lastU = $cur ? $cur['lu'] : ''; $lastId = $cur ? (int) $cur['li'] : 0;
        $where = $cond . ($cur ? ' AND (updated > ' . Store::esc($lastU) . ' OR (updated = ' . Store::esc($lastU) . ' AND ' . $idCol . ' > ' . $lastId . '))' : '');
        $q = Store::q('SELECT ' . $idCol . ' AS id, updated FROM ' . $table . ' WHERE ' . $where . ' ORDER BY updated, ' . $idCol . ' LIMIT ' . ($limit + 1));
        $rows = [];
        while ($q && ($r = db_fetch_array($q))) $rows[] = $r;
        $more = count($rows) > $limit;
        $rows = array_slice($rows, 0, $limit);
        $data = [];
        foreach ($rows as $r) if (($d = $load((int) $r['id']))) $data[] = $d;
        $meta = ['count' => count($data), 'has_more' => $more, 'full' => $since === null];
        if ($more) { $l = end($rows); $meta['cursor'] = self::encode(['since' => $since, 'hi' => $hi, 'lu' => $l['updated'], 'li' => (int) $l['id']]); }
        else $meta['next_since'] = $hi;   // use as ?since= in the next pass
        return Res::ok($data, $meta);
    }

    // ------------------------------------------------------------------

    /** Component maxima at the start of a pass (per-ticket components are read live). */
    private static function watermarkTickets() {
        $e = Store::row('SELECT MAX(id) AS m FROM ' . THREAD_ENTRY_TABLE);
        $v = Store::row('SELECT MAX(id) AS m FROM ' . THREAD_EVENT_TABLE);
        $f = Store::row('SELECT MAX(updated) AS m FROM ' . FORM_ENTRY_TABLE . ' WHERE object_type=\'T\'');
        return ['u' => Time::iso(time()), 'e' => (int) ($e['m'] ?? 0), 'v' => (int) ($v['m'] ?? 0), 'f' => Time::iso($f['m'] ?? null) ?: Time::iso(time())];
    }

    /** ticket_id => {entry, event, form} maxima */
    private static function components(array $ids) {
        $out = [];
        if (!$ids) return $out;
        $in = implode(',', array_map('intval', $ids));
        $q = Store::q('SELECT th.object_id AS tid, MAX(te.id) AS m FROM ' . THREAD_TABLE . ' th JOIN ' . THREAD_ENTRY_TABLE . ' te ON te.thread_id=th.id'
            . ' WHERE th.object_type=\'T\' AND th.object_id IN (' . $in . ') GROUP BY th.object_id');
        while ($q && ($r = db_fetch_array($q))) $out[(int) $r['tid']]['entry'] = (int) $r['m'];
        $q = Store::q('SELECT th.object_id AS tid, MAX(tv.id) AS m FROM ' . THREAD_TABLE . ' th JOIN ' . THREAD_EVENT_TABLE . ' tv ON tv.thread_id=th.id'
            . ' WHERE th.object_type=\'T\' AND th.object_id IN (' . $in . ') GROUP BY th.object_id');
        while ($q && ($r = db_fetch_array($q))) $out[(int) $r['tid']]['event'] = (int) $r['m'];
        $q = Store::q('SELECT object_id AS tid, MAX(updated) AS m FROM ' . FORM_ENTRY_TABLE . ' WHERE object_type=\'T\' AND object_id IN (' . $in . ') GROUP BY object_id');
        while ($q && ($r = db_fetch_array($q))) $out[(int) $r['tid']]['form'] = $r['m'];
        foreach ($ids as $id) $out[$id] += ['entry' => 0, 'event' => 0, 'form' => null];
        foreach ($out as $id => $c) $out[$id] += ['entry' => 0, 'event' => 0, 'form' => null];
        return $out;
    }

    /** ISO-8601 (client) => DB-local datetime string, shifted by $delta seconds. */
    private static function db($iso, $delta = 0) {
        if ($iso === null || $iso === '') return '1970-01-02 00:00:00';
        $ts = strtotime((string) $iso);
        if ($ts === false) throw ApiError::validation('Invalid ISO-8601 date in state/since');
        return Time::toDb(gmdate('Y-m-d\TH:i:s\Z', $ts + $delta));
    }

    private static function encode(array $a) { return Token::b64(json_encode($a)); }

    private static function decode($raw, $field = 'cursor') {
        if ($raw === null || $raw === '') return null;
        $d = json_decode(Token::unb64((string) $raw), true);
        if (!is_array($d)) throw ApiError::validation("Invalid $field", $field);
        return $d;
    }
}
