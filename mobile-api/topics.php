<?php
/**
 * Mobile API — Help Topics
 *
 * Endpoints:
 *   GET /api/mobile/topics  — list active public help topics
 */

require_once(INCLUDE_DIR . 'class.topic.php');

class MobileTopics {

    // ------------------------------------------------------------------
    // GET /api/mobile/topics
    // ------------------------------------------------------------------

    static function handleList() {
        header('Content-Type: application/json');

        MobileTickets::requireAuth();

        $topics = array();
        foreach (Topic::getPublicHelpTopics() as $id => $name) {
            $topics[] = array(
                'id'   => (int) $id,
                'name' => (string) $name,
            );
        }

        http_response_code(200);
        echo json_encode(array('data' => $topics));
        exit;
    }
}
