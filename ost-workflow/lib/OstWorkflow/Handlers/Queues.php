<?php
namespace OstWorkflow\Handlers;

use OstWorkflow\ApiError;
use OstWorkflow\Request;
use OstWorkflow\Res;
use OstWorkflow\Ticketing;

/**
 * osTicket's saved queues (the SCP's "Open", "My Tickets", "Overdue", custom searches…), read-only.
 * The tickets of a queue are the queue's own criteria (`getBasicQuery`) intersected with the agent's visibility
 * (as the SCP does), newest first, cursor by ticket id.
 */
final class Queues {
    static function routes() {
        return [
            ['GET', '/queues',                          'index',   ['policy' => 'auth']],
            ['GET', '/queues/(?P<id>\d+)/tickets',      'tickets', ['policy' => 'auth']],
        ];
    }

    /** GET /queues[?counts=1] — the agent's queues as a flat list with `path` and `parent_id` (counts cost one query each). */
    static function index(Request $req) {
        require_once(INCLUDE_DIR . 'class.queue.php'); require_once(INCLUDE_DIR . 'class.search.php');
        $counts = $req->q('counts', '0');
        if ($counts !== '0' && $counts !== '1') throw ApiError::validation("'counts' must be 0 or 1", 'counts');
        $out = [];
        $walk = function (array $nodes, $depth) use (&$walk, &$out, $req, $counts) {
            foreach ($nodes as list($q, $children)) {
                $row = [
                    'id' => (int) $q->getId(), 'name' => (string) $q->getName(), 'full_name' => (string) $q->getFullName(),
                    'parent_id' => $q->parent_id ? (int) $q->parent_id : null, 'depth' => $depth,
                    'is_public' => (bool) $q->isPublic(), 'is_owner' => (bool) $q->isOwner($req->staff),
                ];
                if ($counts === '1') {
                    try { $row['count'] = (int) self::query($q, $req)->count(); } catch (\Throwable $e) { $row['count'] = null; }
                }
                $out[] = $row;
                $walk($children, $depth + 1);
            }
        };
        $walk(\CustomQueue::getHierarchicalQueues($req->staff), 0);
        return Res::ok($out, ['count' => count($out)]);
    }

    /** GET /queues/{id}/tickets?limit=&cursor= */
    static function tickets(Request $req) {
        require_once(INCLUDE_DIR . 'class.queue.php'); require_once(INCLUDE_DIR . 'class.search.php');
        $q = \SavedQueue::lookup($req->intParam('id'));
        if (!$q || ($q->flags & \CustomQueue::FLAG_DISABLED) || !$q->checkAccess($req->staff))
            throw ApiError::notFound('queue');
        $page = Ticketing::pageById(self::query($q, $req), $req);
        $page[1]['meta']['queue'] = ['id' => (int) $q->getId(), 'name' => (string) $q->getFullName()];
        return $page;
    }

    private static function query($queue, Request $req) {
        $qs = $queue->getBasicQuery();
        if (!$queue->ignoreVisibilityConstraints($req->staff))
            $qs = $qs->filter($req->staff->getTicketsVisibility());
        return $qs->distinct('ticket_id');
    }
}
