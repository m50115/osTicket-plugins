<?php
/**
 * Mobile API — Authentication (stateless, no DB)
 *
 * Tokens are HMAC-signed payloads using osTicket's SECRET_SALT.
 * No database table required. Logout is client-side only.
 *
 * Validation goes through StaffAuthenticationBackend::process() — the same
 * path the browser uses — so LDAP, OAuth2 and other backends work out of
 * the box.
 *
 * Token format: base64url(payload) . "." . base64url(hmac)
 * Payload:      {"id":<staff_id>, "fp":<credentials fingerprint>, "exp":<unix_timestamp>}
 *
 * SEC-01: agents with a second factor in osTicket (or required to enrol one) get 403
 *   {"error": "...", "code": "two_factor_required"} on login, verify and every protected call: this API has no
 *   second-factor step, so those agents use the staff panel (or the Workflow API, which has one).
 *
 * Endpoints:
 *   POST /api/mobile/auth/login   {"username":"...", "password":"..."}
 *   POST /api/mobile/auth/logout  (client discards token)
 */

require_once(INCLUDE_DIR . 'class.staff.php');
require_once(INCLUDE_DIR . 'class.auth.php');

class MobileAuth {

    const TOKEN_TTL = 2592000; // 30 days in seconds

    // ------------------------------------------------------------------
    // HTTP handlers
    // ------------------------------------------------------------------

    // Rate limiting: max failed attempts per IP before lockout
    const MAX_LOGIN_ATTEMPTS = 5;
    const LOCKOUT_SECONDS = 1800; // 30 minutes

    static function handleLogin() {
        header('Content-Type: application/json');
        header('Cache-Control: no-store, no-cache, must-revalidate');
        header('Pragma: no-cache');
        header('X-Content-Type-Options: nosniff');

        $body     = json_decode(file_get_contents('php://input'), true);
        $username = isset($body['username']) && is_string($body['username']) ? trim($body['username']) : '';
        $password = isset($body['password']) && is_string($body['password']) ? $body['password'] : '';

        if (!$username || !$password) {
            http_response_code(400);
            echo json_encode(array('error' => 'username and password are required'));
            exit;
        }

        // SEC-01 R01: this API has no second-factor step. An agent with a second factor in osTicket (or one the
        // installation requires to enrol one) is refused BEFORE process(): no token, no OTP e-mail, no password oracle.
        $known = Staff::lookup($username);
        if ($known)
            self::denySecondFactor($known);

        // SEC: Rate limiting — failed attempts per (client address, agent), serialized with flock. One shared bucket per
        // address for names that are not agents. Only that agent's success clears its counter (SEC-01: a success of
        // ANOTHER account from the same address used to clear it, i.e. unlimited guesses).
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        $lockFile = sys_get_temp_dir() . '/mobile_api_login_'
            . hash_hmac('sha256', $ip . '|' . ($known ? 's' . (int) $known->getId() : 'unknown'), SECRET_SALT) . '.json';
        $fh = @fopen($lockFile, 'c+');
        if (!$fh || !flock($fh, LOCK_EX)) {
            http_response_code(429);
            header('Retry-After: 5');
            echo json_encode(array('error' => 'Too many login attempts. Try again later.'));
            exit;   // fail closed
        }
        $lockData = json_decode(stream_get_contents($fh), true);
        $count = 0;
        if ($lockData && isset($lockData['count']) && isset($lockData['last'])) {
            $elapsed = time() - (int) $lockData['last'];
            if ($lockData['count'] >= self::MAX_LOGIN_ATTEMPTS && $elapsed < self::LOCKOUT_SECONDS) {
                $remaining = self::LOCKOUT_SECONDS - $elapsed;
                http_response_code(429);
                header('Retry-After: ' . $remaining);
                echo json_encode(array('error' => 'Too many login attempts. Try again later.'));
                exit;
            }
            // Reset counter if lockout expired
            $count = $elapsed >= self::LOCKOUT_SECONDS ? 0 : (int) $lockData['count'];
        }

        // Use the same authentication path as the browser (scp/login.php).
        // This supports all registered backends: native, LDAP, OAuth2, etc.
        $errors = array();
        $user = StaffAuthenticationBackend::process($username, $password, $errors);

        if (!$user) {
            // SEC: Increment failed attempt counter
            ftruncate($fh, 0); rewind($fh);
            fwrite($fh, json_encode(array('count' => $count + 1, 'last' => time())));
            fflush($fh);

            http_response_code(401);
            echo json_encode(array('error' => 'Invalid credentials'));
            exit;
        }

        // SEC: Clear failed attempts on successful login
        ftruncate($fh, 0);
        fflush($fh);

        // Load the full Staff object to build the response payload.
        $staff = Staff::lookup($user->getId());

        if (!$staff || !$staff->isActive()) {
            http_response_code(401);
            echo json_encode(array('error' => 'Account is not active'));
            exit;
        }
        // Defense in depth: a backend may resolve the account differently from the lookup above.
        self::denySecondFactor($staff);

        $token = self::createToken($staff);

        http_response_code(200);
        echo json_encode(array(
            'token'      => $token,
            'expires_in' => self::TOKEN_TTL,
            'staff'      => array(
                'id'       => $staff->getId(),
                'username' => $staff->getUserName(),
                'name'     => $staff->getFirstName() . ' ' . $staff->getLastName(),
                'email'    => $staff->getEmail(),
            ),
        ));
        exit;
    }

    /**
     * SEC-01 R01: true when osTicket demands a second factor from this agent (one selected, or the installation requires
     * agents to enrol one). This API cannot verify any second factor, so such agents cannot use it (staff panel only).
     */
    static function needsSecondFactor($staff) {
        global $cfg;
        if (method_exists($staff, 'get2FABackendId') && (string) $staff->get2FABackendId() !== '')
            return true;
        return $cfg && method_exists($cfg, 'require2FAForAgents') && $cfg->require2FAForAgents();
    }

    /** 403 two_factor_required and exit when the agent needs a second factor (login, verify and every protected call). */
    static function denySecondFactor($staff) {
        if (!self::needsSecondFactor($staff)) return;
        http_response_code(403);
        echo json_encode(array(
            'error' => 'Two-factor authentication is required for this account; this API cannot verify it. Sign in through the staff panel.',
            'code'  => 'two_factor_required',
        ));
        exit;
    }

    /** Short fingerprint of the agent's credentials (password hash + active flag): a password change or a lock voids
     *  every outstanding token (SEC-01 R01). */
    static function fingerprint($staff) {
        return substr(hash_hmac('sha256', 'mfp|' . (int) $staff->getId() . '|' . (string) $staff->getPasswd() . '|'
            . ($staff->isActive() ? '1' : '0'), SECRET_SALT), 0, 16);
    }

    static function handleVerify() {
        header('Content-Type: application/json');
        header('Cache-Control: no-store');

        $token   = self::tokenFromRequest();
        $staffId = self::verify($token);
        if (!$staffId) {
            http_response_code(401);
            echo json_encode(array('error' => 'Invalid or expired token'));
            exit;
        }
        $staff = Staff::lookup($staffId);
        if (!$staff || !$staff->isActive()) {
            http_response_code(401);
            echo json_encode(array('error' => 'Account is not active'));
            exit;
        }
        self::denySecondFactor($staff);
        echo json_encode(array('valid' => true));
        exit;
    }

    static function handleLogout() {
        // Stateless — the client simply discards the token.
        // Nothing to invalidate server-side.
        header('Content-Type: application/json');
        http_response_code(200);
        echo json_encode(array('status' => 'ok'));
        exit;
    }

    // ------------------------------------------------------------------
    // Token helpers (used by all protected endpoints)
    // ------------------------------------------------------------------

    /**
     * Extract token string from Authorization: Bearer <token> header.
     */
    static function tokenFromRequest() {
        // $_SERVER['HTTP_AUTHORIZATION'] is not populated by PHP-FPM/CGI on
        // some Nginx configs. Fall back to getallheaders() as a safety net.
        $header = '';
        if (isset($_SERVER['HTTP_AUTHORIZATION'])) {
            $header = $_SERVER['HTTP_AUTHORIZATION'];
        } elseif (function_exists('getallheaders')) {
            $all = getallheaders();
            // Header names are case-insensitive per RFC 7230
            foreach ($all as $k => $v) {
                if (strcasecmp($k, 'Authorization') === 0) {
                    $header = $v;
                    break;
                }
            }
        }
        if (preg_match('/^Bearer\s+(\S+)$/i', $header, $m)) {
            return $m[1];
        }
        return null;
    }

    /**
     * Verify a token and return the staff_id, or null if invalid/expired.
     */
    static function verify($token) {
        if (!$token) return null;

        $parts = explode('.', $token);
        if (count($parts) !== 2) return null;

        list($b64_payload, $b64_sig) = $parts;

        // Verify signature
        $expected = self::b64url_encode(
            hash_hmac('sha256', $b64_payload, SECRET_SALT, true)
        );
        if (!hash_equals($expected, $b64_sig)) return null;

        // Decode and validate payload
        $payload = json_decode(self::b64url_decode($b64_payload), true);
        if (!$payload || !isset($payload['id']) || !isset($payload['exp']) || !isset($payload['fp'])) return null;
        if ($payload['exp'] < time()) return null;

        // SEC-01 R01: the token must match the agent's current credentials (password change / lock revokes it). Tokens
        // issued before this check (no `fp`) are refused once: the client signs in again.
        $staff = Staff::lookup((int) $payload['id']);
        if (!$staff || !hash_equals(self::fingerprint($staff), (string) $payload['fp'])) return null;

        return (int) $payload['id'];
    }

    // ------------------------------------------------------------------
    // Internal token creation
    // ------------------------------------------------------------------

    static function createToken($staff) {
        $payload    = json_encode(array(
            'id'  => (int) $staff->getId(),
            'fp'  => self::fingerprint($staff),
            'exp' => time() + self::TOKEN_TTL,
        ));
        $b64_payload = self::b64url_encode($payload);
        $sig         = hash_hmac('sha256', $b64_payload, SECRET_SALT, true);
        return $b64_payload . '.' . self::b64url_encode($sig);
    }

    // ------------------------------------------------------------------
    // Base64url helpers (URL-safe, no padding)
    // ------------------------------------------------------------------

    static function b64url_encode($data) {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    static function b64url_decode($data) {
        return base64_decode(strtr($data, '-_', '+/'));
    }
}
