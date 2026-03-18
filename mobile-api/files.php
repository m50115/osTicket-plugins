<?php
/**
 * Mobile API — Authenticated File Downloads
 *
 * SEC: Files are served only after verifying the requesting staff member
 *      has visibility to at least one ticket that contains the attachment.
 */

require_once(INCLUDE_DIR . 'class.file.php');
require_once(INCLUDE_DIR . 'class.ticket.php');
require_once(__DIR__ . '/auth.php');

class MobileFiles {

    static function handleDownload($hash) {
        $staff = MobileTickets::requireAuth();

        // SEC: Validate hash format — alphanumeric + dash/underscore only
        if (!preg_match('/^[A-Za-z0-9_-]+$/', $hash)) {
            http_response_code(400);
            header('Content-Type: application/json');
            echo json_encode(array('error' => 'Invalid file key format'));
            exit;
        }

        $file = AttachmentFile::lookupByHash($hash);
        if (!$file) {
            http_response_code(404);
            header('Content-Type: application/json');
            echo json_encode(array('error' => 'File not found'));
            exit;
        }

        // SEC: Verify staff has access to a ticket that owns this attachment.
        // Query the attachment table to find which ticket thread this file
        // belongs to, then check staff permissions on that ticket.
        $authorized = false;
        $attachments = Attachment::objects()
            ->filter(array('file_id' => $file->getId()));

        foreach ($attachments as $att) {
            // Walk up: attachment → thread entry → thread → ticket
            $entry = $att->getObject();
            if (!$entry || !method_exists($entry, 'getThread')) continue;
            $thread = $entry->getThread();
            if (!$thread) continue;
            $object = $thread->getObject();
            if ($object instanceof Ticket && $object->checkStaffPerm($staff)) {
                $authorized = true;
                break;
            }
        }

        if (!$authorized) {
            http_response_code(403);
            header('Content-Type: application/json');
            echo json_encode(array('error' => 'Access denied'));
            exit;
        }

        $type = $file->getType() ?: 'application/octet-stream';
        $name = $file->getName();

        $data = $file->getData();

        if (!$data || strlen($data) === 0) {
            http_response_code(404);
            header('Content-Type: application/json');
            echo json_encode(array('error' => 'File data unavailable'));
            exit;
        }

        // Clean any buffered output from osTicket bootstrap
        while (ob_get_level()) ob_end_clean();

        // SEC: Sanitize filename for Content-Disposition header
        $safeName = preg_replace('/[^a-zA-Z0-9._-]/', '_', basename($name));

        header('Content-Type: ' . $type);
        header('Content-Length: ' . strlen($data));
        header('Content-Disposition: attachment; filename="' . $safeName . '"');
        header('Cache-Control: private, max-age=86400');
        header('X-Content-Type-Options: nosniff');
        @ini_set('zlib.output_compression', 'Off');
        echo $data;
        exit;
    }
}
