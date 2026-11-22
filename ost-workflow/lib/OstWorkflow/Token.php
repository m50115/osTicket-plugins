<?php
namespace OstWorkflow;

/**
 * Signed, stateless bearer token (no table, no config rows).
 *   b64url(payload) . '.' . b64url(HMAC-SHA256(secret))
 *   payload = {v:2, id:<staff>, fp:<fingerprint>, iat, exp}
 * The secret lives in PluginConfig (PasswordField), NOT in SECRET_SALT (R-C15).
 *
 * Invalidation is derived from the native staff record: `fp` is a short HMAC of the staff's current password hash,
 * active flag and second factor (backend + seed), so a password change, a lock or a second-factor change invalidates
 * every outstanding token, and a deleted agent has none
 * (the lookup fails). Authentication belongs to osTicket: logout does not revoke a still-valid token before its TTL
 * (the client discards it).
 */
final class Token {
    static function secret() {
        $s = Runtime::setting('signing_secret');
        if (!is_string($s) || strlen($s) < 32)
            throw new ApiError('not_configured', 'The plugin instance has no token signing secret (>= 32 chars) configured');
        return $s;
    }

    /**
     * Short fingerprint of the native credentials state of the agent: password hash, active flag and (SEC-01 R09) the
     * second factor — the selected backend and a hash of the stored authenticator item (`auth.agent`, i.e. the seed).
     * Changing the seed, switching or removing the backend revokes every token and challenge. Agents that never had a
     * second factor keep the former fingerprint (their outstanding tokens stay valid across this upgrade).
     */
    static function fingerprint(\Staff $staff) {
        $f = 'fp|' . $staff->getId() . '|' . (string) $staff->getPasswd() . '|' . ($staff->isActive() ? '1' : '0');
        $bk = (string) $staff->get2FABackendId();
        $conf = $staff->getConfig();
        $seed = is_array($conf) ? (string) ($conf['auth.agent'] ?? '') : '';
        if ($bk !== '' || $seed !== '')
            $f .= '|2fa|' . $bk . '|' . hash('sha256', $seed);
        return substr(hash_hmac('sha256', $f, self::secret()), 0, 16);
    }

    /**
     * @param bool $mfa true when the agent ALSO proved its osTicket second factor (POST /auth/login/2fa). For an agent with a
     *                  second factor, Auth::authenticate accepts only tokens carrying `mfa` (a token issued before the factor
     *                  was enabled stops working).
     */
    static function issue(\Staff $staff, $mfa = false) {
        $ttl = max(1, Runtime::intSetting('token_ttl_days', 14)) * 86400;
        $now = time();
        $payload = ['v' => 2, 'id' => (int) $staff->getId(), 'fp' => self::fingerprint($staff), 'iat' => $now, 'exp' => $now + $ttl];
        if ($mfa) $payload['mfa'] = 1;
        $p = self::b64(json_encode($payload));
        return [$p . '.' . self::b64(hash_hmac('sha256', $p, self::secret(), true)), $payload];
    }

    /** @return array payload (signature and expiry checked; the caller still compares `fp` with the live staff); throws unauthorized */
    static function verify($token) {
        $bad = new ApiError('unauthorized', 'Invalid or expired token');
        if (!$token || substr_count($token, '.') !== 1) throw $bad;
        list($p, $sig) = explode('.', $token);
        $expected = self::b64(hash_hmac('sha256', $p, self::secret(), true));
        if (!hash_equals($expected, $sig)) throw $bad;
        $d = json_decode(self::unb64($p), true);
        if (!is_array($d) || ($d['v'] ?? 0) !== 2 || !isset($d['id'], $d['fp'], $d['exp']) || $d['exp'] < time())
            throw $bad;
        return $d;
    }

    /** Does the token still match the agent's native credentials (password, active flag)? */
    static function matches(array $payload, \Staff $staff) {
        return hash_equals(self::fingerprint($staff), (string) $payload['fp']);
    }

    /**
     * Short-lived, signed second-factor challenge (SEC-01): proves that THIS agent passed the password step (native
     * backends + LoginGuard) a moment ago. Stateless like the token, but a different version and a different HMAC domain,
     * so a challenge is never a bearer token and a token is never a challenge. Bound to the credentials fingerprint: a
     * password change or a lock voids it.
     */
    const CHALLENGE_TTL = 300;

    static function challenge(\Staff $staff) {
        $now = time();
        $p = self::b64(json_encode(['v' => 'c1', 'id' => (int) $staff->getId(), 'fp' => self::fingerprint($staff),
            'iat' => $now, 'exp' => $now + self::CHALLENGE_TTL]));
        return [$p . '.' . self::b64(hash_hmac('sha256', 'challenge|' . $p, self::secret(), true)), self::CHALLENGE_TTL];
    }

    /** @return array challenge payload (signature + expiry checked); throws unauthorized */
    static function verifyChallenge($c) {
        $bad = new ApiError('unauthorized', 'Invalid or expired verification challenge; sign in again', null,
            ['reason' => 'challenge_invalid']);
        if (!is_string($c) || strlen($c) > 1024 || substr_count($c, '.') !== 1) throw $bad;
        list($p, $sig) = explode('.', $c);
        if (!hash_equals(self::b64(hash_hmac('sha256', 'challenge|' . $p, self::secret(), true)), $sig)) throw $bad;
        $d = json_decode(self::unb64($p), true);
        if (!is_array($d) || ($d['v'] ?? '') !== 'c1' || !isset($d['id'], $d['fp'], $d['exp']) || $d['exp'] < time())
            throw $bad;
        return $d;
    }

    static function b64($d) { return rtrim(strtr(base64_encode($d), '+/', '-_'), '='); }
    static function unb64($d) { return base64_decode(strtr($d, '-_', '+/')); }
}
