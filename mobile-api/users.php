<?php
/**
 * Mobile API — User search
 *
 * GET /api/mobile/users/search?q=<query>
 *
 * Returns up to 10 local osTicket users whose name, email, or username
 * contains the query string (minimum 2 characters).
 *
 * Response: { "data": [ {"id":1,"name":"John Doe","email":"john@example.com"}, ... ] }
 */

require_once(INCLUDE_DIR . 'class.user.php');

class MobileUsers {

    static function handleSearch() {
        header('Content-Type: application/json');
        header('X-Content-Type-Options: nosniff');

        self::requireAuth();

        $q = isset($_GET['q']) ? trim($_GET['q']) : '';

        if (strlen($q) < 2) {
            echo json_encode(array('data' => array()));
            exit;
        }

        $q = Format::sanitize($q);

        $base = User::objects()
            ->values_flat('id', 'name', 'default_email__address')
            ->limit(10);

        $users = (clone $base)->filter(array('name__contains' => $q));
        $users->union(
            (clone $base)->filter(array('emails__address__contains' => $q)),
            false
        );
        $users->union(
            (clone $base)->filter(array('account__username__contains' => $q)),
            false
        );

        $seen    = array();
        $results = array();

        foreach ($users as $row) {
            list($id, $name, $email) = $row;
            if (isset($seen[$id])) continue;
            $seen[$id] = true;
            $results[] = array(
                'id'    => (int) $id,
                'name'  => (string) new UsersName($name),
                'email' => (string) $email,
            );
        }

        usort($results, function($a, $b) {
            return strcmp($a['name'], $b['name']);
        });

        echo json_encode(array('data' => array_values($results)));
        exit;
    }

    static function requireAuth() {
        $token   = MobileAuth::tokenFromRequest();
        $staffId = MobileAuth::verify($token);
        if (!$staffId) {
            http_response_code(401);
            echo json_encode(array('error' => 'Authentication required'));
            exit;
        }
        $staff = Staff::lookup($staffId);
        if (!$staff || !$staff->isActive()) {
            http_response_code(401);
            echo json_encode(array('error' => 'Account is not active'));
            exit;
        }
        return $staff;
    }
}
