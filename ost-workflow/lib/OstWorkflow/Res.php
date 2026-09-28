<?php
namespace OstWorkflow;

/** Response helpers: handlers return [status, body(, headers)] and never echo/exit. */
final class Res {
    static function ok($data, array $meta = null, array $headers = []) {
        $b = ['data' => $data];
        if ($meta !== null) $b['meta'] = $meta;
        return [200, $b, $headers];
    }
    static function created($data, array $headers = []) {
        return [201, ['data' => $data], $headers];
    }
    /** Cursor-paginated list. */
    static function page(array $items, $nextCursor = null, array $extraMeta = []) {
        return self::ok($items, array_merge(['count' => count($items), 'next_cursor' => $nextCursor,
                                             'has_more' => $nextCursor !== null], $extraMeta));
    }
}
