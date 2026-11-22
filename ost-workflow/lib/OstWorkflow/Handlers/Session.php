<?php
namespace OstWorkflow\Handlers;

use OstWorkflow\ApiError;
use OstWorkflow\Auth;
use OstWorkflow\ClientIp;
use OstWorkflow\LoginGuard;
use OstWorkflow\Request;
use OstWorkflow\Res;
use OstWorkflow\Time;
use OstWorkflow\Token;

/** Login / verify / logout. Login is the only route reachable without a token besides /ping. */
final class Session {
    static function routes() {
        return [
            ['POST', '/auth/login',  'login',  ['auth' => false, 'max_body' => 4096]],
            ['POST', '/auth/login/2fa', 'loginSecondFactor', ['auth' => false, 'max_body' => 4096]],
            ['GET',  '/auth/verify', 'verify', ['policy' => 'auth']],
            ['POST', '/auth/logout', 'logout', ['policy' => 'auth', 'max_body' => 1024]],
        ];
    }

    static function login(Request $req) {
        $username = trim((string) $req->input('username', ''));
        $password = (string) $req->input('password', '');
        if ($username === '') throw ApiError::validation('username is required', 'username');
        if ($password === '') throw ApiError::validation('password is required', 'password');
        if (strlen($username) > 128 || strlen($password) > 512)
            throw ApiError::validation('credentials too long');

        // Native lockout (staff_max_logins / staff_login_timeout), made persistent for the stateless API: LoginGuard.
        LoginGuard::enter($username, ClientIp::ip($req));
        $staff = Auth::login($username, $password);
        if (!$staff) {
            LoginGuard::throwIfLocked();   // the failure that crosses the threshold answers 429 too
            throw new ApiError('unauthorized', 'Invalid credentials');
        }
        LoginGuard::success();

        // SEC-01: the installation's own second factor (Auth::totpBackend) is owed: a challenge, never a token. The
        // client cannot skip this step: no token exists until POST /auth/login/2fa accepts a code.
        if (Auth::needsSecondStep($staff)) {
            list($challenge, $ttl) = Token::challenge($staff);
            throw new ApiError('two_factor_required', 'Enter the verification code of your authenticator app', null,
                ['reason' => 'code_required', 'method' => 'totp', 'challenge' => $challenge, 'expires_in' => $ttl]);
        }
        return self::issued($staff, false);
    }

    /**
     * SEC-01 second step: {challenge, code}. The code is checked with the installation's own second-factor backend
     * (Auth::checkCode: the official plugin's secret + its bundled Sonata library); failures count in a persistent
     * per-agent bucket with the native policy (LoginGuard::enterSecondFactor / strike): 401, then 429 + Retry-After.
     */
    static function loginSecondFactor(Request $req) {
        require_once(INCLUDE_DIR . 'class.staff.php');
        require_once(INCLUDE_DIR . 'class.auth.php');
        $challenge = (string) $req->input('challenge', '');
        $code = preg_replace('/\s+/', '', (string) $req->input('code', ''));
        if ($challenge === '') throw ApiError::validation('challenge is required', 'challenge');
        if ($code === '') throw ApiError::validation('code is required', 'code');
        if (strlen($challenge) > 1024 || strlen($code) > 16) throw ApiError::validation('input too long');

        $c = Token::verifyChallenge($challenge);
        $staff = \Staff::lookup((int) $c['id']);
        if (!$staff || !$staff->isActive() || !Token::matches($c, $staff) || !Auth::totpBackend($staff))
            throw new ApiError('unauthorized', 'Invalid or expired verification challenge; sign in again', null,
                ['reason' => 'challenge_invalid']);

        LoginGuard::enterSecondFactor($staff, ClientIp::ip($req));
        if (LoginGuard::codeReused($code) || !Auth::checkCode($staff, $code)) {
            LoginGuard::strike($staff);
            LoginGuard::throwIfLocked();   // the failure that crosses the threshold answers 429 too
            throw new ApiError('unauthorized', 'Invalid verification code', null, ['reason' => 'invalid_code']);
        }
        LoginGuard::success();
        LoginGuard::rememberCode($code);
        return self::issued($staff, true);
    }

    private static function issued(\Staff $staff, $mfa) {
        list($token, $payload) = Token::issue($staff, $mfa);
        return Res::ok([
            'token'      => $token,
            'expires_at' => Time::iso($payload['exp']),
            'expires_in' => $payload['exp'] - $payload['iat'],
            'staff'      => self::staffSummary($staff),
        ]);
    }

    static function verify(Request $req) {
        return Res::ok([
            'valid'      => true,
            'expires_at' => Time::iso($req->tokenId['exp']),
            'staff'      => self::staffSummary($req->staff),
        ]);
    }

    /**
     * Stateless: the token is not revoked server-side (osTicket owns authentication); the client discards it.
     * A password change or a lock of the agent in osTicket invalidates it before its TTL.
     */
    static function logout(Request $req) {
        return Res::ok(['logged_out' => true, 'revoked' => false]);
    }

    static function staffSummary(\Staff $s) {
        return [
            'id'       => (int) $s->getId(),
            'username' => $s->getUserName(),
            'name'     => $s->getName()->getOriginal(),
            'email'    => $s->getEmail(),
        ];
    }
}
