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

    /**
     * A1 (MSOLIS 2026-09-28, fail closed): an agent with a second factor cannot log in through this API. The core does
     * not enforce that factor here: process() only flags it in the SCP session ($_SESSION['_auth']['staff']['2fa'],
     * class.auth.php:645-660), which only the panel honours (StaffSession::isValid). Without this check a password alone
     * would yield a token. It runs BEFORE process(), so no token, no API session and no OTP e-mail are produced.
     * @return \Staff
     * @throws ApiError two_factor_required (403)
     */
    static function login($username, $password) {
        require_once(INCLUDE_DIR . 'class.staff.php');
        require_once(INCLUDE_DIR . 'class.auth.php');
        // Same resolution the panel uses (username, e-mail or id).
        if (($known = \Staff::lookup($username)) && self::hasSecondFactor($known))
            throw self::twoFactorRequired();
        $errors = [];
        $result = \StaffAuthenticationBackend::process($username, $password, $errors);
        if (!$result instanceof \AuthenticatedUser && !$result instanceof \Staff && !is_object($result))
            return null;
        $staff = \Staff::lookup($result->getId());
        // Defense in depth: a backend may resolve the account differently from the lookup above.
        if ($staff && self::hasSecondFactor($staff))
            throw self::twoFactorRequired();
        return ($staff && $staff->isActive()) ? $staff : null;
    }

    /** True when the agent has a second-factor backend selected in osTicket (whether or not it is currently registered). */
    static function hasSecondFactor(\Staff $staff) {
        return (string) $staff->get2FABackendId() !== '';
    }

    private static function twoFactorRequired() {
        return new ApiError('two_factor_required',
            'This account has two-factor authentication enabled in osTicket; it cannot sign in through this API');
    }
}
