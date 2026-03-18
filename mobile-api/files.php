<?php

require_once(INCLUDE_DIR . 'class.file.php');
require_once(__DIR__ . '/auth.php');

class MobileFiles {

    static function handleDownload($hash) {
        MobileTickets::requireAuth();

        error_log("[MobileFiles] Looking up hash: $hash");

        $file = AttachmentFile::lookupByHash($hash);
        if (!$file) {
            http_response_code(404);
            header('Content-Type: application/json');
            echo json_encode(array('error' => 'File not found', 'key' => $hash));
            exit;
        }

        error_log("[MobileFiles] Found file: " . $file->getName() . " type=" . $file->getType() . " size=" . $file->getSize());

        $type = $file->getType() ?: 'application/octet-stream';
        $name = $file->getName();

        // Read file data into memory
        error_log("[MobileFiles] Attempting getData()...");
        $data = $file->getData();
        error_log("[MobileFiles] getData() returned " . strlen($data) . " bytes");

        if (!$data || strlen($data) === 0) {
            http_response_code(404);
            header('Content-Type: application/json');
            echo json_encode(array('error' => 'File data empty'));
            exit;
        }

        // Clean any buffered output from osTicket bootstrap
        while (ob_get_level()) ob_end_clean();

        header('Content-Type: ' . $type);
        header('Content-Length: ' . strlen($data));
        header('Content-Disposition: inline; filename="' . $name . '"');
        header('Cache-Control: private, max-age=86400');
        @ini_set('zlib.output_compression', 'Off');
        echo $data;
        exit;
    }
}
