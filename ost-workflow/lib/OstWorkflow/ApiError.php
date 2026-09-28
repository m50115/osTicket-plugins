<?php
namespace OstWorkflow;

/**
 * Typed API error. The catalog is CLOSED: every error emitted by the plugin
 * uses one of these codes (Contract v1). `$details` is public data for the
 * client (never internal state).
 */
class ApiError extends \Exception {
    /** code => default HTTP status */
    const CATALOG = [
        'unauthorized'            => 401,
        'forbidden'               => 403,
        'not_found'               => 404,
        'method_not_allowed'      => 405,
        'validation_failed'       => 422,
        'idempotency_key_required'=> 400,
        'idempotency_key_reused'  => 422,
        'in_progress'             => 409,
        'needs_review'            => 409,
        'conflict'                => 409,
        'candidates'              => 409,
        'locked'                  => 409,
        'not_closeable'           => 409,
        'unsupported_type'        => 415,
        'file_expired'            => 410,
        'attachment_missing'      => 409,
        'too_large'               => 413,
        'payload_too_large'       => 413,
        'rate_limited'            => 429,
        'not_configured'          => 503,
        'internal_error'          => 500,
    ];

    public $errorCode;
    public $field;
    public $details;
    public $headers;

    function __construct($code, $message = '', $field = null, array $details = [], array $headers = [], $status = null) {
        if (!isset(self::CATALOG[$code]))
            $code = 'internal_error';
        parent::__construct($message !== '' ? $message : $code, $status ?: self::CATALOG[$code]);
        $this->errorCode = $code;
        $this->field = $field;
        $this->details = $details;
        $this->headers = $headers;
    }

    function status() { return $this->getCode(); }

    function toBody() {
        $e = ['code' => $this->errorCode, 'message' => $this->getMessage()];
        if ($this->field !== null)
            $e['field'] = $this->field;
        if ($this->details)
            $e['details'] = $this->details;
        return ['error' => $e];
    }

    // Shorthands ---------------------------------------------------------
    static function validation($message, $field = null, array $details = []) {
        return new self('validation_failed', $message, $field, $details);
    }
    static function notFound($what = 'resource') { return new self('not_found', "$what not found"); }
    static function forbidden($message = 'Access denied') { return new self('forbidden', $message); }
    static function conflict($message, array $details = []) { return new self('conflict', $message, null, $details); }

    /**
     * Map osTicket's `$errors` array into one validation error. Only the
     * per-field messages are exposed (never internals).
     */
    static function fromErrors(array $errors, $fallback = 'Request rejected by osTicket') {
        $field = null; $msg = $fallback; $all = [];
        foreach ($errors as $k => $v) {
            if (is_array($v)) $v = implode('; ', $v);
            $v = (string) $v;
            if ($v === '') continue;
            $all[is_string($k) ? $k : 'error'] = $v;
            if ($field === null) { $field = is_string($k) && $k !== 'err' ? $k : null; $msg = $v; }
        }
        return new self('validation_failed', $msg, $field, count($all) > 1 ? ['fields' => $all] : []);
    }
}
