<?php
namespace OstWorkflow;

/** Non-JSON response body produced by a callback (file downloads). */
final class Stream {
    public $status;
    public $headers;
    private $writer;

    /** @param callable $writer echoes the body (buffers are already discarded) */
    function __construct(callable $writer, array $headers = [], $status = 200) {
        $this->writer = $writer;
        $this->headers = $headers;
        $this->status = $status;
    }

    function write() { call_user_func($this->writer); }
}
