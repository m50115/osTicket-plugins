<?php
namespace OstWorkflow;

/**
 * Who may read the contact/organization directory. The SCP gates browsing it with the `user.dir`
 * permission; agents without it still find contacts while opening tickets (autocomplete). The API
 * keeps that split so a stolen token of a normal agent cannot dump every customer:
 *
 *   - `user.dir` agents: browse, page and sync everything (as in the SCP).
 *   - everyone else: search answers are autocomplete-sized (LOOKUP_MAX, no pages, charged to the
 *     'lookup' budget) and a single contact/organization can be read only when it appears on a
 *     ticket the agent can see or the agent created it.
 */
final class Directory {
    const LOOKUP_MAX = 10;
    const MIN_QUERY = 3;      // non-directory agents: a 2-letter query is a scan, not a lookup

    static function full(\Staff $staff) {
        return (bool) $staff->hasPerm('user.dir');
    }

    /** 403 unless the agent has directory access (bulk feeds). */
    static function requireFull(\Staff $staff) {
        if (!self::full($staff))
            throw new ApiError('forbidden', 'Missing permission: user.dir');
    }

    /** May the agent read this contact ('user') or organization ('organization')? */
    static function mayRead(\Staff $staff, $type, $id) {
        $id = (int) $id;
        return self::full($staff)
            || Idempotency::owns($staff->getId(), $type, $id)
            || self::onVisibleTicket($staff, $type, $id);
    }

    static function requireRead(\Staff $staff, $type, $id) {
        if (!self::mayRead($staff, $type, $id))
            throw new ApiError('forbidden', 'This ' . $type . ' is not on any ticket you can see (directory access needs user.dir)');
    }

    /** Owner or collaborator of a ticket the agent can see (for an organization: any member is). */
    private static function onVisibleTicket(\Staff $staff, $type, $id) {
        require_once(INCLUDE_DIR . 'class.ticket.php');
        $owner = $type === 'user' ? ['user_id' => $id] : ['user__org_id' => $id];
        foreach (Ticketing::visible($staff)->filter($owner)->limit(1) as $_)
            return true;
        $who = $type === 'user' ? '= ' . $id : 'IN (SELECT id FROM ' . USER_TABLE . ' WHERE org_id=' . $id . ')';
        $q = Store::q('SELECT DISTINCT th.object_id FROM ' . THREAD_COLLABORATOR_TABLE . ' c JOIN ' . THREAD_TABLE
            . ' th ON th.id=c.thread_id AND th.object_type=\'T\' WHERE c.user_id ' . $who . ' ORDER BY th.object_id DESC LIMIT 50');
        while ($q && ($r = db_fetch_array($q)))
            if (($t = \Ticket::lookup((int) $r['object_id'])) && $t->checkStaffPerm($staff))
                return true;
        return false;
    }

    /**
     * Search limits for GET /users?q= and GET /organizations?q=: [limit, allow_paging].
     * Directory agents keep the caller's limit and cursors; the rest get one small page.
     */
    static function searchLimits(Request $req, $limit, $q) {
        $staff = $req->staff;
        if (self::full($staff)) return [$limit, true];
        if ($q === null)
            throw new ApiError('forbidden', 'Browsing the directory needs user.dir; search with q (or email) instead');
        if (strlen($q) < self::MIN_QUERY)
            throw ApiError::validation("'q' must have at least " . self::MIN_QUERY . ' characters', 'q');
        if ($req->q('cursor') !== null)
            throw ApiError::validation('Paging the directory needs user.dir; refine the search instead', 'cursor');
        Throttle::hit($staff, 'lookup');
        return [min($limit, self::LOOKUP_MAX), false];
    }
}
