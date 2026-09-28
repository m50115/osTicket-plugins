<?php
namespace OstWorkflow;

/**
 * Authentication stage. Login reuses the SCP backends (native, LDAP, OAuth2…)
 * through StaffAuthenticationBackend::process(). Every authenticated request
 * re-reads the Staff (a deactivated agent loses access immediately) and sets
 * osTicket's global $thisstaff BEFORE any core call — Ticket::create,
 * postReply, setStatus, assign, transfer and Task::create all read it
 * (R-C09, PP-06).
 */
final class Auth {
    static function bearer(Request $req) {
        $h = (string) $req->header('Authorization');
        return preg_match('/^Bearer\s+(\S+)$/i', $h, $m) ? $m[1] : null;
    }

    static function authenticate(Request $req) {
        $payload = Token::verify(self::bearer($req));
        $staff = \Staff::lookup((int) $payload['id']);
        if (!$staff || !$staff->isActive())
            throw new ApiError('unauthorized', 'Account is not active');
        $req->staff = $staff;
        $req->tokenId = $payload;
        self::bindStaff($staff);
    }

    static function bindStaff(\Staff $staff) {
        global $thisstaff;
        $thisstaff = $staff;
    }

    /** @return \Staff */
    static function login($username, $password) {
        require_once(INCLUDE_DIR . 'class.staff.php');
        require_once(INCLUDE_DIR . 'class.auth.php');
        $errors = [];
        $result = \StaffAuthenticationBackend::process($username, $password, $errors);
        // Some backends return a redirect/object requiring a 2nd factor: not supported over API.
        if (!$result instanceof \AuthenticatedUser && !$result instanceof \Staff && !is_object($result))
            return null;
        $staff = \Staff::lookup($result->getId());
        return ($staff && $staff->isActive()) ? $staff : null;
    }
}
