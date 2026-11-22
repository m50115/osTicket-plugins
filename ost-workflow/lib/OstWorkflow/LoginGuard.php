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
        self::open(self::id($username, $ip), $ip);
    }

    /**
     * SEC-01 second step: ONE bucket per agent (not per IP) for verification codes, separate from the password bucket on
     * purpose — a correct password runs the core's login(), which empties the password bucket's strikes, so sharing it
     * would let whoever knows the password reset the code counter at will. Nothing but a valid code or the native
     * timeout clears this one. Same native policy (staff_max_logins / staff_login_timeout) and the same 429 + Retry-After.
     */
    static function enterSecondFactor(\Staff $staff, $ip) {
        self::open(substr(hash_hmac('sha256', 'login2fa|s' . (int) $staff->getId(), Token::secret()), 0, 32), $ip);
        // not locked: an EXPIRED lock starts a new round, as the core does for passwords before process() (authTimeout)
        \StaffAuthStrikeBackend::authTimeout();
    }

    /** A failed verification code counts as a native failed login (StaffAuthStrikeBackend: counter, log, admin alert). */
    static function strike(\Staff $staff) {
        \StaffAuthStrikeBackend::authstrike(['username' => $staff->getUserName()]);
    }

    /** One accepted code (per agent) is never accepted twice inside its validity window (the panel has no such check). */
    static function codeReused($code) {
        $u = $_SESSION['_bcw2fa']['used'] ?? null;
        return is_array($u) && hash_equals((string) $u['h'], hash('sha256', (string) $code)) && $u['t'] > time() - 120;
    }

    static function rememberCode($code) {
        $_SESSION['_bcw2fa']['used'] = ['h' => hash('sha256', (string) $code), 't' => time()];
    }

    /**
     * SEC-01 R13: a challenge is single-use once it has produced a token: any challenge issued at or before the last
     * accepted second step of this agent is refused (state kept in the agent's code bucket; no table).
     */
    static function challengeSpent(array $c) {
        return (int) ($c['iat'] ?? 0) <= (int) ($_SESSION['_bcw2fa']['iat'] ?? 0);
    }

    static function spendChallenge(array $c) {
        $_SESSION['_bcw2fa']['iat'] = (int) ($c['iat'] ?? 0);
    }

    /**
     * SEC-01 R05: one request at a time per bucket. Without it, parallel requests all read the same strike count before
     * any of them wrote it back (measured: 8-12 evaluated attempts per window instead of 5; 3 tokens from 5 parallel
     * copies of one valid code). MySQL GET_LOCK through osTicket's own connection (no table); held until the bucket's
     * session has been written at the end of the request. Fail CLOSED: no lock, no evaluation (429).
     */
    private static function serialize($id) {
        $name = 'bcw.lg.' . $id;
        $r = db_query('SELECT GET_LOCK(' . db_input($name) . ', 10)');
        if (!$r || (int) db_result($r) !== 1)
            throw new ApiError('rate_limited', 'Too many concurrent attempts; try again later', null,
                ['retry_after' => 5], ['Retry-After' => '5']);
        register_shutdown_function(function () use ($name) {
            @session_write_close();          // persist the strikes BEFORE the next request of this bucket reads them
            db_query('SELECT RELEASE_LOCK(' . db_input($name) . ')');
        });
    }

    private static function open($id, $ip) {
        global $cfg;
        self::serialize($id);
        $window = max(600, 2 * (int) $cfg->getStaffLoginTimeout());
        // SEC-01 fix: the core only UPDATES an existing row and never moves its session_expire; once that time passed,
        // every read returned '' (lookupRecord treats an expired row as empty) and the pair could never lock again until
        // the session GC happened to delete the row (proven in e2e18: 6 wrong passwords, all 401). Keep the row alive
        // while it is used; a row that had already expired starts empty, exactly like a native expired session.
        // user_agent is NOT NULL: '' (INSERT IGNORE used to coerce the NULL silently; a plain upsert would fail).
        db_query(sprintf("INSERT INTO %s (session_id, session_data, session_expire, session_updated, user_id, user_ip, user_agent) "
            . "VALUES (%s, '', DATE_ADD(NOW(), INTERVAL %d SECOND), NOW(), 0, %s, '') "
            . "ON DUPLICATE KEY UPDATE session_data = IF(session_expire < NOW(), '', session_data), "
            . "session_expire = DATE_ADD(NOW(), INTERVAL %d SECOND)",
            SESSION_TABLE, db_input($id), $window, db_input(substr((string) $ip, 0, 64)), $window));
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
