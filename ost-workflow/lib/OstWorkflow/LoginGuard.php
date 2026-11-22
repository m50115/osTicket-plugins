<?php
namespace OstWorkflow;

/**
 * Makes osTicket's NATIVE login lockout (StaffAuthStrikeBackend: staff_max_logins /
 * staff_login_timeout) work for a stateless token API. The core counts strikes in $_SESSION, but an API request never
 * creates a session record (class.session.php pre_save: "API is stateless"), so the counter is lost on every request.
 * The core DOES update an EXISTING session record, so: seed one row of the native ost_session table per
 * (agent id | real IP), keyed with an HMAC of the plugin's own secret (not guessable, never sent to the client), load
 * it, let the core count/veto, and report the veto as 429 + Retry-After. Expiry/purge = the session table's own TTL.
 */
final class LoginGuard {
    private $locked = false;

    /** Stable key: the resolved agent id (username/e-mail/id aliases collapse); every non-agent name shares one bucket per IP. */
    static function id($username, $ip) {
        require_once(INCLUDE_DIR . 'class.staff.php');
        $s = \Staff::lookup($username);
        $who = $s ? 's' . (int) $s->getId() : 'unknown';   // one bucket per IP for names that are not agents (bounded rows)
        return substr(hash_hmac('sha256', 'login|' . $who . '|' . $ip, Token::secret()), 0, 32);
    }

    /** Swap in the persisted strike session; throws rate_limited while the core says the pair is locked. */
    static function enter($username, $ip) {
        global $cfg;
        $id = self::id($username, $ip);
        $window = max(600, 2 * (int) $cfg->getStaffLoginTimeout());
        db_query(sprintf("INSERT IGNORE INTO %s (session_id, session_data, session_expire, session_updated, user_id, user_ip, user_agent) "
            . "VALUES (%s, '', DATE_ADD(NOW(), INTERVAL %d SECOND), NOW(), 0, %s, NULL)",
            SESSION_TABLE, db_input($id), $window, db_input(substr((string) $ip, 0, 64))));
        @session_write_close();
        ini_set('session.use_cookies', '0');           // never hand the key to the client
        session_id($id);
        session_start();
        self::throwIfLocked();
    }

    /** Remaining lock seconds from the core's own state (0 = not locked). */
    static function remaining() {
        global $cfg;
        $a = $_SESSION['_auth']['staff'] ?? [];
        if (empty($a['laststrike'])) return 0;
        return max(0, (int) $cfg->getStaffLoginTimeout() - (time() - (int) $a['laststrike']));
    }

    static function throwIfLocked() {
        if (($n = self::remaining()) > 0)
            throw new ApiError('rate_limited', 'Too many failed attempts; try again later', null,
                ['retry_after' => $n], ['Retry-After' => (string) $n]);
    }

    /** A successful login clears the pair's strikes. */
    static function success() {
        unset($_SESSION['_auth']['staff']['strikes'], $_SESSION['_auth']['staff']['laststrike']);
    }
}
