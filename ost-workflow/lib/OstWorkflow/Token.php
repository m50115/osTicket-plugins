<?php
namespace OstWorkflow;

/**
 * Signed, stateless bearer token (no table, no config rows).
 *   b64url(payload) . '.' . b64url(HMAC-SHA256(secret))
 *   payload = {v:2, id:<staff>, fp:<fingerprint>, iat, exp}
 * The secret lives in PluginConfig (PasswordField), NOT in SECRET_SALT (R-C15).
 *
 * Invalidation is derived from the native staff record: `fp` is a short HMAC of the staff's current password hash
 * and active flag, so a password change or a lock invalidates every outstanding token, and a deleted agent has none
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

    /** Short fingerprint of the native credentials state of the agent. */
    static function fingerprint(\Staff $staff) {
        return substr(hash_hmac('sha256', 'fp|' . $staff->getId() . '|' . (string) $staff->getPasswd() . '|' . ($staff->isActive() ? '1' : '0'),
            self::secret()), 0, 16);
    }

    static function issue(\Staff $staff) {
        $ttl = max(1, Runtime::intSetting('token_ttl_days', 14)) * 86400;
        $now = time();
        $payload = ['v' => 2, 'id' => (int) $staff->getId(), 'fp' => self::fingerprint($staff), 'iat' => $now, 'exp' => $now + $ttl];
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

    static function b64($d) { return rtrim(strtr(base64_encode($d), '+/', '-_'), '='); }
    static function unb64($d) { return base64_decode(strtr($d, '-_', '+/')); }
}
