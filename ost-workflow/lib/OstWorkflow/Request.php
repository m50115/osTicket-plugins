<?php
namespace OstWorkflow;

/** One HTTP request as seen by the pipeline and the handlers. */
final class Request {
    const MAX_JSON_BYTES = 262144; // 256 KB; per-endpoint overrides via route 'max_body'

    public $method;
    public $path;
    public $query = [];
    /** route captures (strings) */
    public $params = [];
    /** authenticated \Staff (set by Auth) */
    public $staff;
    /** objects loaded by Policy (ticket, task, user, org, ...) */
    public $ctx = [];
    public $idemKey;
    public $route = [];
    public $tokenId;

    private $raw;
    private $json;

    static function capture($rest) {
        $r = new self();
        $r->method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
        $rest = (string) $rest;
        $r->path = '/' . trim($rest, '/');
        if ($r->path === '/') $r->path = '/';
        $r->query = $_GET;
        return $r;
    }

    function isWrite() { return $this->method !== 'GET' && $this->method !== 'HEAD' && $this->method !== 'OPTIONS'; }

    function header($name) {
        $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
        if (isset($_SERVER[$key])) return $_SERVER[$key];
        if (function_exists('getallheaders')) {
            foreach (getallheaders() as $k => $v)
                if (strcasecmp($k, $name) === 0) return $v;
        }
        return null;
    }

    function isMultipart() {
        return stripos((string) ($_SERVER['CONTENT_TYPE'] ?? ''), 'multipart/form-data') === 0;
    }

    /** Raw body, capped. Throws payload_too_large. */
    function raw($max = null) {
        if ($this->raw === null) {
            $max = $max ?: self::MAX_JSON_BYTES;
            $len = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
            if ($len > $max)
                throw new ApiError('payload_too_large', 'Request body exceeds ' . $max . ' bytes', null, ['max_bytes' => $max]);
            $this->raw = (string) stream_get_contents(fopen('php://input', 'r'), $max + 1);
            if (strlen($this->raw) > $max)
                throw new ApiError('payload_too_large', 'Request body exceeds ' . $max . ' bytes', null, ['max_bytes' => $max]);
        }
        return $this->raw;
    }

    /** Decoded JSON object body ([] when empty). */
    function json() {
        if ($this->json === null) {
            if ($this->isMultipart()) {
                $this->json = $_POST ?: [];
            } else {
                $raw = trim($this->raw(($this->route['max_body'] ?? null)));
                if ($raw === '') {
                    $this->json = [];
                } else {
                    $d = json_decode($raw, true);
                    if (!is_array($d) || json_last_error() !== JSON_ERROR_NONE)
                        throw ApiError::validation('Body must be a JSON object');
                    $this->json = $d;
                }
            }
        }
        return $this->json;
    }

    function input($key, $default = null) {
        $b = $this->json();
        return array_key_exists($key, $b) ? $b[$key] : $default;
    }

    function q($key, $default = null) {
        return isset($this->query[$key]) && $this->query[$key] !== '' ? $this->query[$key] : $default;
    }

    function param($key) { return $this->params[$key] ?? null; }

    function intParam($key) { return (int) ($this->params[$key] ?? 0); }

    /** Positive int from a query arg with bounds, 422 when malformed. */
    function intQuery($key, $default, $min = 1, $max = PHP_INT_MAX) {
        $v = $this->q($key);
        if ($v === null) return $default;
        if (!preg_match('/^-?\d+$/', (string) $v))
            throw ApiError::validation("'$key' must be an integer", $key);
        return max($min, min($max, (int) $v));
    }

    /** Stable fingerprint of the request body (idempotency body_hash). */
    function bodyHash() {
        if ($this->isMultipart()) {
            $parts = [json_encode($_POST)];
            foreach ($_FILES as $name => $f) {
                $names = (array) ($f['name'] ?? []);
                $tmps = (array) ($f['tmp_name'] ?? []);
                foreach ($tmps as $i => $tmp)
                    $parts[] = $name . ':' . ($names[$i] ?? '') . ':' . (is_file($tmp) ? hash_file('sha256', $tmp) : '');
            }
            return hash('sha256', implode('|', $parts));
        }
        return hash('sha256', $this->raw(($this->route['max_body'] ?? null)));
    }

    /** Flatten $_FILES[$field] (single or array style) to a list of file descriptors. */
    function files($field = 'files') {
        $out = [];
        if (empty($_FILES[$field])) return $out;
        $f = $_FILES[$field];
        if (is_array($f['name'])) {
            foreach ($f['name'] as $i => $n)
                $out[] = ['name' => $n, 'type' => $f['type'][$i], 'tmp_name' => $f['tmp_name'][$i],
                          'error' => $f['error'][$i], 'size' => $f['size'][$i]];
        } else {
            $out[] = $f;
        }
        return $out;
    }
}
