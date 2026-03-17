<?php
/**
 * Mobile API — Push Notifications
 *
 * Token storage: plugin directory / device_tokens.json
 *   Format: { "<staff_id>": "<fcm_token>", ... }
 *
 * Push delivery: Firebase Cloud Messaging Legacy HTTP API
 *   Server key stored in:  plugin directory / fcm_server_key.txt
 *   (Set by msolis after creating the Firebase project)
 */

class MobileNotifications {

    const TOKEN_FILE      = __DIR__ . '/device_tokens.json';
    const SERVER_KEY_FILE = __DIR__ . '/fcm_server_key.txt';
    const FCM_URL         = 'https://fcm.googleapis.com/fcm/send';

    // ------------------------------------------------------------------
    // POST /api/mobile/device/token
    // Body: { "token": "<fcm_token>" }
    // ------------------------------------------------------------------

    static function handleRegisterToken() {
        header('Content-Type: application/json');

        $staff = MobileTickets::requireAuth();

        $raw  = stream_get_contents(fopen('php://input', 'r'), 4 * 1024);
        $body = $raw ? json_decode($raw, true) : null;

        $token = isset($body['token']) ? trim($body['token']) : '';

        // SEC-007: Validate FCM token format (alphanumeric + :_-)
        // Real FCM tokens are 140–200 chars; allow 50–512 as a safe range.
        if (!$token
            || !preg_match('/^[a-zA-Z0-9:_\-]+$/', $token)
            || strlen($token) < 50
            || strlen($token) > 512) {
            http_response_code(400);
            echo json_encode(array('error' => 'Invalid token'));
            exit;
        }

        self::saveToken($staff->getId(), $token);

        http_response_code(200);
        echo json_encode(array('status' => 'ok'));
        exit;
    }

    // ------------------------------------------------------------------
    // Notify all staff except the actor (avoids self-notification)
    // $data keys used by Flutter: ticket_id, type (new_ticket|reply)
    // ------------------------------------------------------------------

    static function sendToAllExcept($excludeStaffId, $title, $body, $data = array()) {
        $tokens = self::loadTokens();
        unset($tokens[(string) $excludeStaffId]);
        if (empty($tokens)) return;
        self::sendFcm(array_values($tokens), $title, $body, $data);
    }

    static function sendToAll($title, $body, $data = array()) {
        $tokens = self::loadTokens();
        if (empty($tokens)) return;
        self::sendFcm(array_values($tokens), $title, $body, $data);
    }

    // ------------------------------------------------------------------
    // FCM delivery — Legacy HTTP API
    // ------------------------------------------------------------------

    private static function sendFcm($tokens, $title, $body, $data) {
        $serverKey = self::getServerKey();
        if (!$serverKey) return;

        $payload = json_encode(array(
            'registration_ids' => $tokens,
            'notification'     => array(
                'title' => $title,
                'body'  => $body,
                'sound' => 'default',
            ),
            'data'     => $data,
            'priority' => 'high',
        ));

        $opts = array(
            'http' => array(
                'method'  => 'POST',
                'header'  => implode("\r\n", array(
                    'Authorization: key=' . $serverKey,
                    'Content-Type: application/json',
                )),
                'content' => $payload,
                'timeout' => 10,
                'ignore_errors' => true,
            ),
        );
        @file_get_contents(self::FCM_URL, false, stream_context_create($opts));
    }

    // ------------------------------------------------------------------
    // Token persistence
    // ------------------------------------------------------------------

    static function saveToken($staffId, $token) {
        $tokens = self::loadTokens();
        $tokens[(string) $staffId] = $token;
        file_put_contents(self::TOKEN_FILE, json_encode($tokens));
    }

    static function loadTokens() {
        if (!file_exists(self::TOKEN_FILE)) return array();
        $data = json_decode(file_get_contents(self::TOKEN_FILE), true);
        return is_array($data) ? $data : array();
    }

    static function getServerKey() {
        if (!file_exists(self::SERVER_KEY_FILE)) return null;
        $key = trim(file_get_contents(self::SERVER_KEY_FILE));
        return $key ?: null;
    }
}
