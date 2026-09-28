<?php
namespace OstWorkflow;

/**
 * Login throttling by (username, real client IP), persisted in the plumbing
 * table (RC-15 / legacy §B-19): 5 failures lock that pair for 30 minutes;
 * a shared ELB address never locks other users out.
 */
final class RateLimit {
    const MAX_FAILURES = 5;
    const LOCK_SECONDS = 1800;

    static function ip(Request $req = null) {
        $remote = $_SERVER['REMOTE_ADDR'] ?? '';
        $trusted = array_filter(array_map('trim', explode(',', (string) Runtime::setting('trusted_proxies', ''))));
        if (!$trusted || !self::inList($remote, $trusted))
            return $remote ?: 'unknown';
        $xff = (string) ($_SERVER['HTTP_X_FORWARDED_FOR'] ?? '');
        $hops = array_reverse(array_filter(array_map('trim', explode(',', $xff))));
        foreach ($hops as $h) {
            if (filter_var($h, FILTER_VALIDATE_IP) && !self::inList($h, $trusted))
                return $h;
        }
        return $remote;
    }

    private static function key($username, $ip) {
        return 'rl:' . substr(sha1(strtolower($username) . '|' . $ip), 0, 40);
    }

    /** Throws rate_limited while the pair is locked. */
    static function check($username, $ip) {
        $r = Store::row('SELECT counter, GREATEST(0, TIMESTAMPDIFF(SECOND, NOW(), lease_until)) AS wait FROM ' . Store::table()
            . ' WHERE staff_id=0 AND kind=\'ratelimit\' AND idem_key=' . Store::esc(self::key($username, $ip)));
        if ($r && (int) $r['counter'] >= self::MAX_FAILURES && (int) $r['wait'] > 0)
            throw new ApiError('rate_limited', 'Too many login attempts; try again later', null,
                ['retry_after' => (int) $r['wait']], ['Retry-After' => (string) (int) $r['wait']]);
    }

    static function fail($username, $ip) {
        $k = Store::esc(self::key($username, $ip));
        // Window restarts after a lock expired.
        Store::q('UPDATE ' . Store::table() . ' SET counter=0 WHERE staff_id=0 AND kind=\'ratelimit\' AND idem_key=' . $k
            . ' AND lease_until < NOW()');
        Store::q('INSERT INTO ' . Store::table() . ' SET kind=\'ratelimit\', staff_id=0, idem_key=' . $k
            . ', counter=1, status=\'done\', created=NOW(), lease_until=DATE_ADD(NOW(), INTERVAL ' . self::LOCK_SECONDS . ' SECOND),'
            . ' expires=DATE_ADD(NOW(), INTERVAL 1 DAY) ON DUPLICATE KEY UPDATE counter=counter+1,'
            . ' lease_until=DATE_ADD(NOW(), INTERVAL ' . self::LOCK_SECONDS . ' SECOND)');
    }

    static function clear($username, $ip) {
        Store::q('DELETE FROM ' . Store::table() . ' WHERE staff_id=0 AND kind=\'ratelimit\' AND idem_key='
            . Store::esc(self::key($username, $ip)));
    }

    private static function inList($ip, array $list) {
        foreach ($list as $entry) {
            if (strpos($entry, '/') === false) {
                if ($ip === $entry) return true;
                continue;
            }
            list($net, $bits) = explode('/', $entry, 2);
            $a = @inet_pton($ip); $b = @inet_pton($net);
            if ($a === false || $b === false || strlen($a) !== strlen($b)) continue;
            $bits = (int) $bits; $full = intdiv($bits, 8); $rem = $bits % 8;
            if (substr($a, 0, $full) !== substr($b, 0, $full)) continue;
            if ($rem === 0) return true;
            $mask = (0xFF << (8 - $rem)) & 0xFF;
            if ((ord($a[$full]) & $mask) === (ord($b[$full]) & $mask)) return true;
        }
        return false;
    }
}
