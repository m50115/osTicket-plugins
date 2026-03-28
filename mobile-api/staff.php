<?php
/**
 * Mobile API — Staff
 *
 * Endpoints:
 *   GET /api/mobile/staff           — list all active staff
 *   GET /api/mobile/staff?deptId=X  — filter by primary department
 */

require_once(INCLUDE_DIR . 'class.staff.php');

class MobileStaff {

    // ------------------------------------------------------------------
    // GET /api/mobile/staff[?deptId=<int>]
    // ------------------------------------------------------------------

    static function handleList() {
        header('Content-Type: application/json');

        MobileTickets::requireAuth();

        $deptId = isset($_GET['deptId']) ? (int) $_GET['deptId'] : 0;

        $filter = array('isactive' => 1);
        if ($deptId > 0) {
            $filter['dept_id'] = $deptId;
        }

        $members = array();
        foreach (Staff::objects()->filter($filter) as $s) {
            $members[] = array(
                'id'   => (int) $s->getId(),
                'name' => (string) $s->getName(),
            );
        }

        http_response_code(200);
        echo json_encode(array('data' => $members));
        exit;
    }
}
