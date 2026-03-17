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
 * Payload:      {"id":<staff_id>, "exp":<unix_timestamp>}
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

    static function handleLogin() {
        header('Content-Type: application/json');
        header('Cache-Control: no-store, no-cache, must-revalidate');
        header('Pragma: no-cache');

        $body     = json_decode(file_get_contents('php://input'), true);
        $username = isset($body['username']) ? trim($body['username']) : '';
        $password = isset($body['password']) ? $body['password'] : '';

        if (!$username || !$password) {
            http_response_code(400);
            echo json_encode(array('error' => 'username and password are required'));
            exit;
        }

        // Use the same authentication path as the browser (scp/login.php).
        // This supports all registered backends: native, LDAP, OAuth2, etc.
        $errors = array();
        $user = StaffAuthenticationBackend::process($username, $password, $errors);

        if (!$user) {
            http_response_code(401);
            // SEC-016: Always return a generic message regardless of the
            // specific failure reason (wrong password, LDAP error, account
            // locked, etc.) to avoid leaking system information.
            echo json_encode(array('error' => 'Invalid credentials'));
            exit;
        }

        // Load the full Staff object to build the response payload.
        $staff = Staff::lookup($user->getId());

        if (!$staff || !$staff->isActive()) {
            http_response_code(401);
            echo json_encode(array('error' => 'Account is not active'));
            exit;
        }

        $token = self::createToken($staff->getId());

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
        if (!$payload || !isset($payload['id']) || !isset($payload['exp'])) return null;
        if ($payload['exp'] < time()) return null;

        return (int) $payload['id'];
    }

    // ------------------------------------------------------------------
    // Internal token creation
    // ------------------------------------------------------------------

    static function createToken($staff_id) {
        $payload    = json_encode(array(
            'id'  => (int) $staff_id,
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
