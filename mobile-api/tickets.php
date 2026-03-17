<?php
/**
 * Mobile API — Tickets
 *
 * All endpoints require:  Authorization: Bearer <token>
 *
 * Endpoints:
 *   GET /api/mobile/tickets          — list tickets visible to the staff member
 *   GET /api/mobile/tickets/{id}     — single ticket detail (stub, next step)
 */

require_once(INCLUDE_DIR . 'class.ticket.php');
require_once(INCLUDE_DIR . 'class.staff.php');

class MobileTickets {

    // Maximum accepted lengths to prevent DoS via large payloads
    const MAX_SUBJECT   = 200;
    const MAX_MESSAGE   = 32000;
    const MAX_NAME      = 100;
    const MAX_EMAIL     = 254; // RFC 5321
    const MAX_FILE_SIZE = 1048576;  // 1 MB per file
    const MAX_FILES     = 5;

    // Allowed MIME types for attachments (validated via finfo, not client header)
    static $ALLOWED_MIME_TYPES = array(
        'image/jpeg', 'image/png', 'image/gif',
        'image/webp', 'image/heic', 'image/heif',
        'application/pdf',
    );

    // Allowed values for the ticket source field
    const ALLOWED_SOURCES = array('Phone', 'Web', 'Email', 'API', 'Other');

    // ------------------------------------------------------------------
    // GET /api/mobile/tickets
    //
    // Query params:
    //   page   (int, default 1)
    //   limit  (int, default 25, max 100)
    //   status (open|closed|all, default open)
    // ------------------------------------------------------------------

    static function handleList() {
        header('Content-Type: application/json');

        $staff = self::requireAuth();

        $page   = max(1, (int) ($_GET['page']  ?? 1));
        $limit  = min(100, max(1, (int) ($_GET['limit'] ?? 25)));
        $status_raw = isset($_GET['status']) ? strtolower($_GET['status']) : 'open';
        $status = in_array($status_raw, array('open', 'closed', 'all'), true) ? $status_raw : 'open';

        // Base query filtered by what this staff member can see
        $qs = Ticket::objects()
            ->filter($staff->getTicketsVisibility())
            ->order_by('-created');

        // Status filter
        if ($status === 'open') {
            $qs = $qs->filter(array('status__state' => 'open'));
        } elseif ($status === 'closed') {
            $qs = $qs->filter(array('status__state' => 'closed'));
        }
        // 'all' — no extra filter

        $total  = $qs->count();
        $offset = ($page - 1) * $limit;
        $rows   = $qs->limit($limit)->offset($offset);

        $tickets = array();
        foreach ($rows as $t) {
            $tickets[] = self::summarize($t);
        }

        http_response_code(200);
        echo json_encode(array(
            'data' => $tickets,
            'meta' => array(
                'total'  => $total,
                'page'   => $page,
                'limit'  => $limit,
                'pages'  => (int) ceil($total / $limit),
            ),
        ));
        exit;
    }

    // ------------------------------------------------------------------
    // POST /api/mobile/tickets
    //
    // Body (JSON):
    //   subject  (string, required)
    //   message  (string, required)
    //   email    (string, required)  — ticket owner
    //   name     (string, required)  — ticket owner display name
    //   topicId  (int, optional)     — help topic, defaults to first active
    //   deptId   (int, optional)
    //   source   (string, optional)  — Phone, Web, Other; default "Phone"
    // ------------------------------------------------------------------

    static function handleCreate() {
        header('Content-Type: application/json');

        $staff = self::requireAuth();

        // S2 — cap raw body size before json_decode to prevent DoS
        $raw = stream_get_contents(fopen('php://input', 'r'), 64 * 1024);
        $body = $raw ? json_decode($raw, true) : null;

        $subject = isset($body['subject']) ? trim($body['subject']) : '';
        $message = isset($body['message']) ? trim($body['message']) : '';
        $email   = isset($body['email'])   ? trim($body['email'])   : '';
        $name    = isset($body['name'])    ? trim($body['name'])    : '';

        if (!$subject || !$message || !$email || !$name) {
            http_response_code(400);
            echo json_encode(array('error' => 'subject, message, email and name are required'));
            exit;
        }

        // S2 — enforce field length limits
        if (strlen($subject) > self::MAX_SUBJECT || strlen($message) > self::MAX_MESSAGE ||
            strlen($name) > self::MAX_NAME       || strlen($email)   > self::MAX_EMAIL) {
            http_response_code(400);
            echo json_encode(array('error' => 'One or more fields exceed maximum allowed length'));
            exit;
        }

        // S8 — basic email format check before passing to osTicket
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            http_response_code(400);
            echo json_encode(array('error' => 'Invalid email address'));
            exit;
        }

        // S4 — whitelist source field
        $source_raw = isset($body['source']) ? ucfirst(strtolower(trim($body['source']))) : 'Phone';
        $source = in_array($source_raw, self::ALLOWED_SOURCES, true) ? $source_raw : 'Phone';

        // Note: 'staffId' in vars means "assign to staff" — not creator attribution.
        // origin='staff' is sufficient for osTicket to log this as a staff-created ticket.
        $vars = array(
            'topicId' => isset($body['topicId']) ? (int) $body['topicId'] : 1,
            'uid'     => 0,
            'email'   => $email,
            'name'    => $name,
            'subject' => $subject,
            'message' => $message,
            'source'  => $source,
        );

        if (isset($body['deptId'])) {
            $vars['deptId'] = (int) $body['deptId'];
        }

        $errors = array();
        $ticket = Ticket::create($vars, $errors, 'staff', false, false);

        if (!$ticket) {
            // S1 — do not expose internal error detail to client
            http_response_code(422);
            echo json_encode(array('error' => 'Could not create ticket'));
            exit;
        }

        http_response_code(201);
        echo json_encode(self::summarize($ticket));
        exit;
    }

    // ------------------------------------------------------------------
    // GET /api/mobile/tickets/{id}
    // ------------------------------------------------------------------

    static function handleDetail($id) {
        header('Content-Type: application/json');

        $staff = self::requireAuth();

        $ticket = Ticket::lookup((int) $id);

        if (!$ticket) {
            http_response_code(404);
            echo json_encode(array('error' => 'Ticket not found'));
            exit;
        }

        if (!$ticket->checkStaffPerm($staff)) {
            http_response_code(403);
            echo json_encode(array('error' => 'Access denied'));
            exit;
        }

        $thread  = $ticket->getThread();
        $entries = array();

        if ($thread) {
            foreach ($thread->getEntries() as $entry) {
                $type = $entry->getType();
                // Skip internal notes for now (type 'N')
                if ($type === 'N') continue;

                $attachments = array();
                foreach ($entry->getAttachments() as $att) {
                    $attachments[] = array(
                        'name' => $att->getFilename(),
                    );
                }

                $body_obj = $entry->getBody();
                $entries[] = array(
                    'type'        => $type === 'M' ? 'message' : 'response',
                    'author'      => (string) $entry->getName(),
                    'body'        => $body_obj ? (string) $body_obj->convertTo('text') : '',
                    'created'     => $entry->created,
                    'attachments' => $attachments,
                );
            }
        }

        $owner    = $ticket->getOwner();
        $assignee = null;
        if ($ticket->getStaffId() && ($s = $ticket->getStaff())) {
            $assignee = array('type' => 'staff', 'name' => (string) $s->getName());
        } elseif ($ticket->getTeamId() && ($team = $ticket->getTeam())) {
            $assignee = array('type' => 'team', 'name' => (string) $team->getName());
        }

        http_response_code(200);
        echo json_encode(array(
            'id'         => (int) $ticket->getId(),
            'number'     => $ticket->getNumber(),
            'subject'    => $ticket->getSubject(),
            'status'     => (string) $ticket->getStatus()->getName(),
            'department' => $ticket->getDept() ? (string) $ticket->getDept()->getName() : null,
            'assignee'   => $assignee,
            'user'       => $owner ? (string) $owner->getName() : null,
            'overdue'    => (bool) $ticket->isOverdue(),
            'answered'   => (bool) $ticket->isAnswered(),
            'created'    => $ticket->getCreateDate(),
            'updated'    => $ticket->getUpdateDate(),
            'due_date'   => $ticket->getDueDate(),
            'thread'     => $entries,
        ));
        exit;
    }

    // ------------------------------------------------------------------
    // POST /api/mobile/tickets/{id}/reply
    //
    // Accepts both:
    //   - application/json  { "message": "...", "alert": true }
    //   - multipart/form-data with optional attachments[]
    //
    // Attachments: images (JPEG/PNG/GIF/WEBP/HEIC) or PDF, max 1 MB each,
    // max 5 files total. Validated server-side by MIME type via finfo.
    // ------------------------------------------------------------------

    static function handleReply($id) {
        header('Content-Type: application/json');

        $staff  = self::requireAuth();
        $ticket = Ticket::lookup((int) $id);

        if (!$ticket) {
            http_response_code(404);
            echo json_encode(array('error' => 'Ticket not found'));
            exit;
        }

        if (!$ticket->checkStaffPerm($staff, Ticket::PERM_REPLY)) {
            http_response_code(403);
            echo json_encode(array('error' => 'Access denied'));
            exit;
        }

        // Detect request type — check $_POST first (populated for any multipart
        // regardless of how the Content-Type header is named by the server).
        if (!empty($_POST)) {
            $message = isset($_POST['message']) ? trim($_POST['message']) : '';
            $alert   = isset($_POST['alert'])   ? (bool)(int)$_POST['alert'] : true;
        } else {
            // S2 — cap raw body size before json_decode to prevent DoS
            $raw  = stream_get_contents(fopen('php://input', 'r'), 64 * 1024);
            $body = $raw ? json_decode($raw, true) : null;
            $message = isset($body['message']) ? trim($body['message']) : '';
            $alert   = isset($body['alert'])   ? (bool) $body['alert'] : true;
        }

        if (!$message) {
            http_response_code(400);
            echo json_encode(array('error' => 'message is required'));
            exit;
        }

        // S2 — enforce message length limit
        if (strlen($message) > self::MAX_MESSAGE) {
            http_response_code(400);
            echo json_encode(array('error' => 'Message exceeds maximum allowed length'));
            exit;
        }

        // Process uploaded files (multipart only)
        $files = array();
        if (!empty($_FILES)) {
            $uploaded = self::normalizeFiles($_FILES);
            if (count($uploaded) > self::MAX_FILES) {
                http_response_code(400);
                echo json_encode(array('error' => 'Too many attachments (max 5)'));
                exit;
            }
            foreach ($uploaded as $f) {
                if ($f['error'] !== UPLOAD_ERR_OK) continue;
                if ($f['size'] > self::MAX_FILE_SIZE) continue; // skip oversized
                // SEC — validate MIME via finfo, not client-supplied type
                $mime = self::detectMime($f['tmp_name']);
                if (!in_array($mime, self::$ALLOWED_MIME_TYPES, true)) continue;
                // Pre-compute key + signature from the tmp file so that
                // GenericFile::create() stores them correctly.
                // Without this, create() only generates key/signature when
                // $file['data'] (raw bytes) is present; passing only tmp_name
                // leaves both fields empty and the file becomes unservable.
                list($fkey, $fsig) = AttachmentFile::_getKeyAndHash($f['tmp_name'], true);
                $files[] = array(
                    'name'      => basename($f['name']),
                    'type'      => $mime,
                    'size'      => $f['size'],
                    'tmp_name'  => $f['tmp_name'],
                    'error'     => UPLOAD_ERR_OK,
                    'key'       => $fkey,
                    'signature' => $fsig,
                );
            }
        }

        // Set global $thisstaff expected by postReply internals
        global $thisstaff;
        $thisstaff = $staff;

        $owner   = $ticket->getOwner();
        $replyTo = ($alert && $owner) ? $owner->getEmail() : 'none';

        $vars = array(
            'response' => $message,
            'staffId'  => $staff->getId(),
            'poster'   => (string) $staff->getName(),
            'reply-to' => $replyTo,
            'ccs'      => array(),
            'files'    => $files,
        );

        $errors   = array();
        $response = $ticket->postReply($vars, $errors, $alert);

        if (!$response) {
            // S1 — do not expose internal error detail to client
            http_response_code(422);
            echo json_encode(array('error' => 'Could not post reply'));
            exit;
        }

        http_response_code(201);
        echo json_encode(array(
            'type'    => 'response',
            'author'  => (string) $staff->getName(),
            'body'    => $message,
            'created' => $response->created,
        ));
        exit;
    }

    // ------------------------------------------------------------------
    // File upload helpers
    // ------------------------------------------------------------------

    /**
     * Normalize $_FILES into a flat array of file entries.
     * Handles both 'attachments' (single) and 'attachments[N]' (array) keys.
     */
    static function normalizeFiles($files) {
        $result = array();
        foreach ($files as $field => $data) {
            if (is_array($data['tmp_name'])) {
                $count = count($data['tmp_name']);
                for ($i = 0; $i < $count; $i++) {
                    $result[] = array(
                        'name'     => $data['name'][$i],
                        'tmp_name' => $data['tmp_name'][$i],
                        'size'     => $data['size'][$i],
                        'error'    => $data['error'][$i],
                    );
                }
            } else {
                $result[] = array(
                    'name'     => $data['name'],
                    'tmp_name' => $data['tmp_name'],
                    'size'     => $data['size'],
                    'error'    => $data['error'],
                );
            }
        }
        return $result;
    }

    /**
     * Detect actual MIME type via finfo (not client-supplied Content-Type).
     */
    static function detectMime($path) {
        if (function_exists('finfo_file')) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mime  = finfo_file($finfo, $path);
            finfo_close($finfo);
            return $mime ?: 'application/octet-stream';
        }
        // Fallback if fileinfo extension is unavailable
        return mime_content_type($path) ?: 'application/octet-stream';
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    /**
     * Verify Bearer token and return Staff object, or exit with 401.
     */
    static function requireAuth() {
        // S5 — prevent MIME-type sniffing on all authenticated responses
        header('X-Content-Type-Options: nosniff');

        $token = MobileAuth::tokenFromRequest();
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

    /**
     * Build the ticket summary array for list responses.
     */
    static function summarize($t) {
        $assignee = null;
        if ($t->getStaffId() && ($s = $t->getStaff())) {
            $assignee = array('type' => 'staff', 'name' => (string) $s->getName());
        } elseif ($t->getTeamId() && ($team = $t->getTeam())) {
            $assignee = array('type' => 'team', 'name' => (string) $team->getName());
        }

        $owner = $t->getOwner();

        return array(
            'id'         => (int) $t->getId(),
            'number'     => $t->getNumber(),
            'subject'    => $t->getSubject(),
            'status'     => (string) $t->getStatus()->getName(),
            'department' => $t->getDept() ? (string) $t->getDept()->getName() : null,
            'assignee'   => $assignee,
            'user'       => $owner ? (string) $owner->getName() : null,
            'overdue'    => (bool) $t->isOverdue(),
            'answered'   => (bool) $t->isAnswered(),
            'created'    => $t->getCreateDate(),
            'updated'    => $t->getUpdateDate(),
        );
    }
}
