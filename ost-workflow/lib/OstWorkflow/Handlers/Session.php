<?php
namespace OstWorkflow\Handlers;

use OstWorkflow\ApiError;
use OstWorkflow\Auth;
use OstWorkflow\RateLimit;
use OstWorkflow\Request;
use OstWorkflow\Res;
use OstWorkflow\Time;
use OstWorkflow\Token;

/** Login / verify / logout. Login is the only route reachable without a token besides /ping. */
final class Session {
    static function routes() {
        return [
            ['POST', '/auth/login',  'login',  ['auth' => false, 'idem' => false, 'max_body' => 4096]],
            ['GET',  '/auth/verify', 'verify', ['policy' => 'auth']],
            ['POST', '/auth/logout', 'logout', ['policy' => 'auth', 'idem' => false, 'max_body' => 1024]],
        ];
    }

    static function login(Request $req) {
        $username = trim((string) $req->input('username', ''));
        $password = (string) $req->input('password', '');
        if ($username === '') throw ApiError::validation('username is required', 'username');
        if ($password === '') throw ApiError::validation('password is required', 'password');
        if (strlen($username) > 128 || strlen($password) > 512)
            throw ApiError::validation('credentials too long');

        $ip = RateLimit::ip($req);
        RateLimit::check($username, $ip);

        $staff = Auth::login($username, $password);
        if (!$staff) {
            RateLimit::fail($username, $ip);
            throw new ApiError('unauthorized', 'Invalid credentials');
        }
        RateLimit::clear($username, $ip);

        list($token, $payload) = Token::issue($staff->getId());
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
     * Revokes the presented token; {"all": true} revokes every token of the agent.
     */
    static function logout(Request $req) {
        if ($req->input('all') === true) {
            Token::revokeAll($req->staff->getId());
            return Res::ok(['revoked' => 'all']);
        }
        Token::revoke($req->tokenId);
        return Res::ok(['revoked' => 'current']);
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
