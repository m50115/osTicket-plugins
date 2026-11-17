<?php
namespace OstWorkflow;

/**
 * Thin helpers over osTicket's db_query() for the few read-only/aggregate queries the ORM does not express well.
 * The plugin owns NO SQL table (M16 campaign: the former idempotency/plumbing table was removed); business state
 * lives in native osTicket objects (tickets, threads, Custom Lists). A DB failure becomes an exception (=> 500 JSON).
 */
final class Db {
    static function q($sql) {
        $r = db_query($sql);
        if ($r === false)
            throw new \RuntimeException('database error');
        return $r;
    }

    static function row($sql) {
        $r = self::q($sql);
        return $r ? db_fetch_array($r) : null;
    }

    static function affected() { return db_affected_rows(); }

    static function esc($v) { return db_input($v); }
}
