<?php
namespace OstWorkflow;

/**
 * Real client IP behind trusted proxies (X-Forwarded-For honoured only from `trusted_proxies`), recorded on posted
 * entries. Login throttling is not done here: osTicket's own auth backends (lockout strikes) own it.
 */
final class ClientIp {
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
