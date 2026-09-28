<?php
namespace OstWorkflow\Handlers;

use OstWorkflow\ApiError;
use OstWorkflow\Request;
use OstWorkflow\Res;
use OstWorkflow\Store;
use OstWorkflow\Time;

/**
 * GET /reports/support — v1 aggregate over the tickets the agent can see.
 *   ?from=&to= (ISO-8601, on ticket creation; default: last 30 days)   ?group_by=dept|topic|status|agent|team|source|priority
 * SLA-snapshot metrics wait for decisions P-11/P-13.
 */
final class Reports {
    const GROUPS = [
        'dept'   => ['t.dept_id', 'dept'], 'topic' => ['t.topic_id', 'topic'], 'status' => ['t.status_id', 'status'],
        'agent'  => ['t.staff_id', 'staff'], 'team' => ['t.team_id', 'team'], 'source' => ['t.source', null],
        'priority' => ['c.priority', 'priority'],
    ];
    const MAX_IDS = 20000;

    static function routes() {
        return [['GET', '/reports/support', 'support', ['policy' => 'auth']]];
    }

    static function support(Request $req) {
        require_once(INCLUDE_DIR . 'class.ticket.php');
        $group = $req->q('group_by', 'dept');
        if (!isset(self::GROUPS[$group])) throw ApiError::validation("'group_by' must be one of " . implode(', ', array_keys(self::GROUPS)), 'group_by', ['allowed' => array_keys(self::GROUPS)]);
        $toTs = $req->q('to') !== null ? strtotime((string) $req->q('to')) : time();
        $fromTs = $req->q('from') !== null ? strtotime((string) $req->q('from')) : $toTs - 30 * 86400;
        if ($toTs === false) throw ApiError::validation("'to' must be ISO-8601", 'to');
        if ($fromTs === false) throw ApiError::validation("'from' must be ISO-8601", 'from');
        if ($fromTs >= $toTs) throw ApiError::validation("'from' must be before 'to'", 'from');
        $from = Time::toDb(gmdate('Y-m-d\TH:i:s\Z', $fromTs)); $to = Time::toDb(gmdate('Y-m-d\TH:i:s\Z', $toTs));

        // Visibility is resolved by osTicket (ORM); aggregation runs in SQL over those ids.
        $ids = [];
        $qs = \OstWorkflow\Ticketing::visible($req->staff)->filter(['created__gte' => $from, 'created__lt' => $to]);
        foreach ($qs->values_flat('ticket_id')->limit(self::MAX_IDS + 1) as $r) $ids[] = (int) $r[0];
        if (count($ids) > self::MAX_IDS)
            throw new ApiError('too_large', 'The range matches more than ' . self::MAX_IDS . ' tickets; narrow from/to', null, ['max' => self::MAX_IDS]);

        list($col, $kind) = self::GROUPS[$group];
        $rows = [];
        foreach (array_chunk($ids, 2000) as $chunk) {
            $in = implode(',', $chunk);
            $q = Store::q('SELECT ' . $col . ' AS k, COUNT(*) AS created_n, SUM(s.state=\'closed\') AS closed_n, SUM(s.state=\'open\') AS open_n,'
                . ' SUM(t.isoverdue=1 AND s.state=\'open\') AS overdue_n, SUM(t.isanswered=1) AS answered_n,'
                . ' SUM(CASE WHEN t.closed IS NOT NULL THEN TIMESTAMPDIFF(SECOND, t.created, t.closed) END) AS close_secs, SUM(t.closed IS NOT NULL) AS close_n'
                . ' FROM ' . TICKET_TABLE . ' t JOIN ' . TICKET_STATUS_TABLE . ' s ON s.id=t.status_id'
                . ($group === 'priority' ? ' LEFT JOIN ' . TABLE_PREFIX . 'ticket__cdata c ON c.ticket_id=t.ticket_id' : '')
                . ' WHERE t.ticket_id IN (' . $in . ') GROUP BY k');
            while ($q && ($r = db_fetch_array($q))) {
                $k = (string) $r['k'];
                foreach (['created_n', 'closed_n', 'open_n', 'overdue_n', 'answered_n', 'close_secs', 'close_n'] as $m)
                    $rows[$k][$m] = ($rows[$k][$m] ?? 0) + (float) $r[$m];
            }
        }
        $out = [];
        foreach ($rows as $k => $m) {
            $out[] = ['key' => $k === '' ? null : (is_numeric($k) ? (int) $k : $k), 'label' => self::label($kind, $k),
                      'created' => (int) $m['created_n'], 'closed' => (int) $m['closed_n'], 'open' => (int) $m['open_n'],
                      'overdue' => (int) $m['overdue_n'], 'answered' => (int) $m['answered_n'],
                      'avg_close_seconds' => $m['close_n'] ? (int) round($m['close_secs'] / $m['close_n']) : null];
        }
        usort($out, function ($a, $b) { return $b['created'] <=> $a['created']; });
        $tot = ['created' => count($ids), 'closed' => array_sum(array_column($out, 'closed')), 'open' => array_sum(array_column($out, 'open')), 'overdue' => array_sum(array_column($out, 'overdue'))];
        return Res::ok($out, ['group_by' => $group, 'from' => Time::iso($fromTs), 'to' => Time::iso($toTs), 'totals' => $tot,
                              'basis' => 'tickets created in [from,to), visible to the agent; open/overdue = state now']);
    }

    private static function label($kind, $k) {
        if ($k === '' || $k === '0' || $kind === null) return $k === '0' ? null : ($k ?: null);
        $id = (int) $k;
        switch ($kind) {
        case 'dept':     $o = \Dept::lookup($id); return $o ? $o->getName() : null;
        case 'topic':    $o = \Topic::lookup($id); return $o ? $o->getName() : null;
        case 'status':   $o = \TicketStatus::lookup($id); return $o ? $o->getName() : null;
        case 'staff':    $o = \Staff::lookup($id); return $o ? $o->getName()->getOriginal() : null;
        case 'team':     $o = \Team::lookup($id); return $o ? $o->getName() : null;
        case 'priority': $o = \Priority::lookup($id); return $o ? $o->getDesc() : null;
        }
        return null;
    }
}
