<?php
/**
 * Mobile API — Departments
 *
 * Endpoints:
 *   GET /api/mobile/departments  — list active departments
 */

require_once(INCLUDE_DIR . 'class.dept.php');

class MobileDepartments {

    // ------------------------------------------------------------------
    // GET /api/mobile/departments
    // ------------------------------------------------------------------

    static function handleList() {
        header('Content-Type: application/json');

        MobileTickets::requireAuth();

        $departments = array();
        foreach (Dept::getDepartments() as $id => $name) {
            $departments[] = array(
                'id'   => (int) $id,
                'name' => (string) $name,
            );
        }

        http_response_code(200);
        echo json_encode(array('data' => $departments));
        exit;
    }
}
