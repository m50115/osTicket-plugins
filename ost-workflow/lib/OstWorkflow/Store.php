<?php
namespace OstWorkflow;

/**
 * The plugin's ONLY table (Architecture §B13/§M): plumbing for idempotency,
 * token revocation and login throttling. Never the source of truth for
 * business data. Created in enable(), never touched in init()/bootstrap().
 *
 * kind = 'idem'      one row per (staff_id, Idempotency-Key)
 *        'file'      uploaded file ownership (resource_type='file')  [alias of idem row]
 *        'revoked'   revoked token, idem_key = jti, expires = token exp
 *        'tokgen'    per-agent token generation, counter = generation
 *        'ratelimit' idem_key = 'rl:<hash>', counter = failures, lease_until = lockout end
 *        'throttle'  idem_key = 'th:<bucket>:<UTC hour>', staff_id = agent, counter = operations this hour (Throttle)
 */
final class Store {
    static function table() { return TABLE_PREFIX . 'workflow_idempotency'; }

    static function install() {
        $sql = 'CREATE TABLE IF NOT EXISTS `' . self::table() . '` (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `kind` VARCHAR(12) NOT NULL DEFAULT \'idem\',
            `staff_id` INT UNSIGNED NOT NULL DEFAULT 0,
            `idem_key` VARCHAR(80) NOT NULL,
            `method` VARCHAR(8) NOT NULL DEFAULT \'\',
            `route` VARCHAR(190) NOT NULL DEFAULT \'\',
            `body_hash` CHAR(64) NOT NULL DEFAULT \'\',
            `status` VARCHAR(16) NOT NULL DEFAULT \'in_progress\',
            `lease_until` DATETIME NULL,
            `http_code` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            `response` MEDIUMTEXT NULL,
            `resource_type` VARCHAR(20) NULL,
            `resource_id` VARCHAR(64) NULL,
            `counter` INT UNSIGNED NOT NULL DEFAULT 0,
            `created` DATETIME NOT NULL,
            `expires` DATETIME NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uq_staff_key` (`staff_id`, `idem_key`),
            KEY `idx_expires` (`expires`),
            KEY `idx_resource` (`staff_id`, `resource_type`, `resource_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4';
        return db_query($sql) !== false;
    }

    /** Run a query; a DB failure becomes an exception (=> 500 JSON), never a silent false. */
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

    /** Opportunistic purge (~1% of writes); retention is 30 days. */
    static function purge($force = false) {
        if (!$force && mt_rand(1, 100) !== 1) return;
        try {
            self::q('DELETE FROM ' . self::table() . ' WHERE expires IS NOT NULL AND expires < NOW() LIMIT 500');
        } catch (\Throwable $t) { /* housekeeping only */ }
    }
}
