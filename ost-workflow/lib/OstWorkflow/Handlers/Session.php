<?php
namespace OstWorkflow\Handlers;

use OstWorkflow\ApiError;
use OstWorkflow\Auth;
use OstWorkflow\Request;
use OstWorkflow\Res;
use OstWorkflow\Time;
use OstWorkflow\Token;

/** Login / verify / logout. Login is the only route reachable without a token besides /ping. */
final class Session {
    static function routes() {
        return [
            ['POST', '/auth/login',  'login',  ['auth' => false, 'max_body' => 4096]],
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

        // Lockout / strikes are the native auth backends' business (StaffAuthenticationBackend::process).
        $staff = Auth::login($username, $password);
        if (!$staff)
            throw new ApiError('unauthorized', 'Invalid credentials');

        list($token, $payload) = Token::issue($staff);
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
