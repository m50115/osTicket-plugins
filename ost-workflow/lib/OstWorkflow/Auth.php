<?php
namespace OstWorkflow;

/**
 * Authentication stage. Login reuses the SCP backends (native, LDAP, OAuth2…)
 * through StaffAuthenticationBackend::process(). Every authenticated request
 * re-reads the Staff (a deactivated or deleted agent, or one whose password changed, loses access immediately) and sets
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
        if (!$staff || !$staff->isActive() || !Token::matches($payload, $staff))
            throw new ApiError('unauthorized', 'Account is not active or its credentials changed');
        // SEC-01 (fail closed): the installation's second-factor policy also applies to tokens already issued. A token
        // minted before the agent's second factor was enabled (no `mfa` claim), or one of an agent who must enrol a
        // second factor and has not, stops working; the agent signs in again through the current policy.
        if (self::hasSecondFactor($staff) ? empty($payload['mfa']) : self::setupRequired($staff))
            throw new ApiError('unauthorized', 'The second-factor policy of this account changed; sign in again', null,
                ['reason' => 'second_factor_policy']);
        $req->staff = $staff;
        $req->tokenId = $payload;
        self::bindStaff($staff);
    }

    static function bindStaff(\Staff $staff) {
        global $thisstaff;
        $thisstaff = $staff;
    }

    /**
     * Password step. Reuses the SCP backends through StaffAuthenticationBackend::process() (native, LDAP, OAuth2...).
     *
     * A1 (MSOLIS 2026-09-28, fail closed) + SEC-01: process() only FLAGS a second factor in the SCP session
     * ($_SESSION['_auth']['staff']['2fa'], class.auth.php:645-660), which only the panel honours (StaffSession::isValid);
     * without these checks a password alone would yield a token.
     *  - Second factor this API can verify with the installation's OWN backend (the official «Two Factor Authenticator»
     *    plugin, backend `auth.agent`, enrolled): the password is checked like in the panel and the caller gets a
     *    challenge for POST /auth/login/2fa (Session::login), never a token.
     *  - Any other second factor (e-mail OTP, a backend that is not active, an agent not enrolled): 403
     *    two_factor_required BEFORE process(): no token, no API session, no OTP e-mail, no password oracle.
     *  - Installation requires a second factor (require_agent_2fa) and the agent has none: 403 two_factor_required
     *    (reason setup_required), only after a correct password (no enumeration of the policy per account).
     * @return \Staff|null the agent (the caller asks for the second step when needsSecondStep()), null = bad credentials
     * @throws ApiError two_factor_required (403)
     */
    static function login($username, $password) {
        require_once(INCLUDE_DIR . 'class.staff.php');
        require_once(INCLUDE_DIR . 'class.auth.php');
        // Same resolution the panel uses (username, e-mail or id).
        if (($known = \Staff::lookup($username)) && self::hasSecondFactor($known) && !self::totpBackend($known))
            throw self::twoFactorRequired();
        $errors = [];
        $result = \StaffAuthenticationBackend::process($username, $password, $errors);
        if (!$result instanceof \AuthenticatedUser && !$result instanceof \Staff && !is_object($result))
            return null;
        $staff = \Staff::lookup($result->getId());
        if (!$staff || !$staff->isActive()) return null;
        // Defense in depth: a backend may resolve the account differently from the lookup above.
        if (self::hasSecondFactor($staff) && !self::totpBackend($staff))
            throw self::twoFactorRequired();
        if (self::setupRequired($staff))
            throw new ApiError('two_factor_required',
                'This installation requires two-factor authentication; set it up in the osTicket staff panel first',
                null, ['reason' => 'setup_required']);
        return $staff;
    }

    /** The agent passed the password step but still owes its second factor (POST /auth/login/2fa). */
    static function needsSecondStep(\Staff $staff) {
        return self::hasSecondFactor($staff);
    }

    /** require_agent_2fa is on and the agent has no second factor selected (the panel forces its setup: force2faConfig). */
    static function setupRequired(\Staff $staff) {
        global $cfg;
        return $cfg && $cfg->require2FAForAgents() && !self::hasSecondFactor($staff);
    }

    /**
     * The installation's own TOTP backend for this agent, or null. Only the official «Two Factor Authenticator» plugin
     * (backend `auth.agent`, active, its Sonata library loaded) with a secret already enrolled by the agent in the panel.
     * The API never enrols, never generates a secret and never shows a QR code.
     */
    static function totpBackend(\Staff $staff) {
        if ((string) $staff->get2FABackendId() !== 'auth.agent') return null;
        $bk = \Staff2FABackend::lookup('auth.agent');
        if (!$bk || !method_exists($bk, 'getSecretKey') || !class_exists('\\Sonata\\GoogleAuthenticator\\GoogleAuthenticator'))
            return null;
        $conf = $staff->get2FAConfig('auth.agent');
        return (is_array($conf) && !empty($conf['config']['key'])) ? $bk : null;
    }

    /**
     * Verifies a code with the plugin's own backend: its stored secret (Auth2FABackend::getSecretKey, read-only here
     * because the agent is enrolled) and its bundled Sonata GoogleAuthenticator::checkCode — exactly what
     * Auth2FABackend::validateLoginCode() does, minus its lookup of the SCP session user, which a stateless API request
     * does not have. No TOTP engine of our own.
     */
    static function checkCode(\Staff $staff, $code) {
        $bk = self::totpBackend($staff);
        if (!$bk || !preg_match('/^[0-9]{6}$/', (string) $code)) return false;
        $ga = new \Sonata\GoogleAuthenticator\GoogleAuthenticator();
        return (bool) $ga->checkCode($bk->getSecretKey($staff), (string) $code);
    }

    /** True when the agent has a second-factor backend selected in osTicket (whether or not it is currently registered). */
    static function hasSecondFactor(\Staff $staff) {
        return (string) $staff->get2FABackendId() !== '';
    }

    private static function twoFactorRequired() {
        return new ApiError('two_factor_required',
            'This account has a second factor in osTicket that this API cannot verify; sign in through the staff panel',
            null, ['reason' => 'unsupported_second_factor']);
    }
}
