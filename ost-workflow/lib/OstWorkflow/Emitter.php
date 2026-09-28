<?php
namespace OstWorkflow;

/**
 * The single place where bytes leave the plugin (PP-05). Discards anything
 * that leaked into the output buffers (warnings, stray echo), so the body is
 * always valid JSON even with display_errors=1 (R-C08).
 */
final class Emitter {
    static function send($obLevel, $status, $body, array $headers = []) {
        while (ob_get_level() > $obLevel)
            ob_end_clean();

        $stream = $body instanceof Stream ? $body : null;
        if ($stream) {
            $status = $stream->status;
            $headers = array_merge($headers, $stream->headers);
        }

        if (!headers_sent()) {
            http_response_code($status);
            header('X-Content-Type-Options: nosniff');
            header('X-Frame-Options: DENY');
            if (!isset($headers['Cache-Control'])) {
                header('Cache-Control: no-store');
                header('Pragma: no-cache');
            }
            if (!$stream && !isset($headers['Content-Type']))
                header('Content-Type: application/json; charset=utf-8');
            foreach ($headers as $k => $v)
                header("$k: $v");
        }

        if ($stream) {
            $stream->write();
            return;
        }

        if ($status === 304)
            return;   // conditional GET satisfied: headers only

        $json = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PRESERVE_ZERO_FRACTION);
        if ($json === false) {
            if (!headers_sent()) http_response_code(500);
            $json = '{"error":{"code":"internal_error","message":"Response could not be encoded"}}';
        }
        echo $json;
    }
}
