<?php
namespace OstWorkflow;

/**
 * Signed, revocable bearer token.
 *   b64url(payload) . '.' . b64url(HMAC-SHA256(secret))
 *   payload = {v:1, id:<staff>, gen:<generation>, jti:<hex>, iat, exp}
 * The secret lives in PluginConfig (PasswordField), NOT in SECRET_SALT
 * (R-C15). Revocation: per-token (kind='revoked') or per-agent generation
 * (kind='tokgen'), both in the plumbing table — no extra table.
 */
final class Token {
    static function secret() {
        $s = Runtime::setting('signing_secret');
        if (!is_string($s) || strlen($s) < 32)
            throw new ApiError('not_configured', 'The plugin instance has no token signing secret (>= 32 chars) configured');
        return $s;
    }

    static function issue($staffId) {
        $ttl = max(1, Runtime::intSetting('token_ttl_days', 30)) * 86400;
        $now = time();
        $payload = ['v' => 1, 'id' => (int) $staffId, 'gen' => self::generation($staffId),
                    'jti' => bin2hex(random_bytes(12)), 'iat' => $now, 'exp' => $now + $ttl];
        $p = self::b64(json_encode($payload));
        return [$p . '.' . self::b64(hash_hmac('sha256', $p, self::secret(), true)), $payload];
    }

    /** @return array payload; throws unauthorized */
    static function verify($token) {
        $bad = new ApiError('unauthorized', 'Invalid or expired token');
        if (!$token || substr_count($token, '.') !== 1) throw $bad;
        list($p, $sig) = explode('.', $token);
        $expected = self::b64(hash_hmac('sha256', $p, self::secret(), true));
        if (!hash_equals($expected, $sig)) throw $bad;
        $d = json_decode(self::unb64($p), true);
        if (!is_array($d) || ($d['v'] ?? 0) !== 1 || !isset($d['id'], $d['gen'], $d['jti'], $d['exp']) || $d['exp'] < time())
            throw $bad;

        $rows = Store::q('SELECT kind, idem_key, counter FROM ' . Store::table() . ' WHERE staff_id=' . (int) $d['id']
            . ' AND ((kind=\'tokgen\' AND idem_key=\'tokgen\') OR (kind=\'revoked\' AND idem_key=' . Store::esc($d['jti']) . '))');
        $gen = 0;
        while ($rows && ($r = db_fetch_array($rows))) {
            if ($r['kind'] === 'revoked') throw $bad;
            $gen = (int) $r['counter'];
        }
        if ((int) $d['gen'] !== $gen) throw $bad;
        return $d;
    }

    static function generation($staffId) {
        $r = Store::row('SELECT counter FROM ' . Store::table() . ' WHERE staff_id=' . (int) $staffId
            . ' AND kind=\'tokgen\' AND idem_key=\'tokgen\'');
        return $r ? (int) $r['counter'] : 0;
    }

    /** Revoke every token of the agent. */
    static function revokeAll($staffId) {
        Store::q('INSERT INTO ' . Store::table() . ' SET kind=\'tokgen\', staff_id=' . (int) $staffId
            . ', idem_key=\'tokgen\', counter=1, status=\'done\', created=NOW() ON DUPLICATE KEY UPDATE counter=counter+1');
    }

    /** Revoke a single token (until it would have expired anyway). */
    static function revoke(array $payload) {
        Store::q('INSERT IGNORE INTO ' . Store::table() . ' SET kind=\'revoked\', staff_id=' . (int) $payload['id']
            . ', idem_key=' . Store::esc($payload['jti']) . ', status=\'done\', created=NOW(), expires=FROM_UNIXTIME(' . (int) $payload['exp'] . ')');
    }

    static function b64($d) { return rtrim(strtr(base64_encode($d), '+/', '-_'), '='); }
    static function unb64($d) { return base64_decode(strtr($d, '-_', '+/')); }
}
