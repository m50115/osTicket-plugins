<?php
namespace OstWorkflow;

/**
 * Durable store of ScheduledMaintenance (OW-REQ-70, Preventive Maintenance module 8). The ONE plugin table that holds
 * business truth that cannot be rebuilt from osTicket: a maintenance commitment that has no Ticket yet.
 *
 * Durability (MUST V1 point 1): its own table, separate from the plumbing table of Store; NO TTL and no purge (Store::purge
 * only touches the idempotency table); never dropped on disable/uninstall; created idempotently in enable() beside
 * Store::install() (never in init/bootstrap); `schema_version` per row for the migrations the plugin does not have yet;
 * it lives in the osTicket database, so the normal database backup includes it. Rows are never deleted: a maintenance is
 * CANCELLED instead (its audit stays).
 *
 * Uniqueness (equipment_id, scheduled_date) among non-cancelled rows is enforced by the database: `active_date` repeats
 * `scheduled_date` while the row is not CANCELLED and is NULL afterwards, and a UNIQUE key spans (equipment_id, active_date)
 * (NULLs never collide). Writes use `INSERT IGNORE` / `UPDATE IGNORE` + a `rev` compare-and-swap so a race answers a
 * typed conflict instead of a database error (which the core would log and mail as a system error).
 *
 * All timestamps are stored in UTC with microseconds (own table: no osTicket DB-timezone ambiguity).
 */
final class Maintenance {
    const SCHEMA_VERSION = 1;
    const SETTING_LIST = 'installed_equipment_list_id';
    const PLANNED = 'PLANNED';
    const COMPLETED = 'COMPLETED';
    const CANCELLED = 'CANCELLED';

    static function table() { return TABLE_PREFIX . 'workflow_maintenance'; }

    /** CREATE TABLE IF NOT EXISTS: safe to run any number of times. Called from enable(). */
    static function install() {
        $sql = 'CREATE TABLE IF NOT EXISTS `' . self::table() . '` (
            `id` CHAR(36) NOT NULL,
            `schema_version` SMALLINT UNSIGNED NOT NULL DEFAULT ' . self::SCHEMA_VERSION . ',
            `equipment_id` INT UNSIGNED NOT NULL,
            `scheduled_date` DATE NOT NULL,
            `active_date` DATE NULL,
            `stored_status` VARCHAR(10) NOT NULL DEFAULT \'PLANNED\',
            `ticket_id` INT UNSIGNED NULL,
            `completed_on` DATE NULL,
            `completed_by` INT UNSIGNED NULL,
            `evidence_ref` VARCHAR(80) NULL,
            `completion_note` TEXT NULL,
            `cancel_reason` TEXT NULL,
            `changes` MEDIUMTEXT NOT NULL,
            `rev` INT UNSIGNED NOT NULL DEFAULT 1,
            `created_at` DATETIME(6) NOT NULL,
            `created_by` INT UNSIGNED NOT NULL,
            `updated_at` DATETIME(6) NOT NULL,
            `updated_by` INT UNSIGNED NOT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uq_equipment_active` (`equipment_id`, `active_date`),
            KEY `idx_updated` (`updated_at`, `id`),
            KEY `idx_ticket` (`ticket_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4';
        return db_query($sql) !== false;
    }

    /** 503 when the table was never created (plugin updated without a disable/enable). */
    static function ready() {
        if (!Store::row('SHOW TABLES LIKE ' . Store::esc(self::table())))
            throw new ApiError('not_configured', 'The maintenance store is not installed: disable and enable the plugin once');
    }

    // ------------------------------------------------------------------ equipment

    /** @return string|null 'ACTIVE' | 'RETIRED' | null (not an item of the configured Installed Equipment list) */
    static function equipment($equipmentId) {
        $listId = Runtime::intSetting(self::SETTING_LIST, 0);
        if ($listId < 1)
            throw new ApiError('not_configured', "The plugin setting 'installed_equipment_list_id' (Installed Equipment list) is not set");
        require_once(INCLUDE_DIR . 'class.list.php');
        $it = \DynamicListItem::lookup((int) $equipmentId);
        if (!$it || (int) $it->get('list_id') !== $listId)
            return null;
        return $it->isEnabled() ? 'ACTIVE' : 'RETIRED';
    }

    // ------------------------------------------------------------------ reads

    static function find($id) {
        return Store::row('SELECT * FROM ' . self::table() . ' WHERE id=' . Store::esc($id));
    }

    /** The non-cancelled maintenance of this equipment on this date, if any. */
    static function holder($equipmentId, $date) {
        return Store::row('SELECT * FROM ' . self::table() . ' WHERE equipment_id=' . (int) $equipmentId . ' AND active_date=' . Store::esc($date));
    }

    /** @return array [rows, more, watermark cursor] ordered by (updated_at, id) ascending */
    static function page($after, $limit, $equipmentId, $ticketId) {
        $w = ['1=1'];
        if ($after)
            $w[] = '(updated_at > ' . Store::esc($after['t']) . ' OR (updated_at = ' . Store::esc($after['t']) . ' AND id > ' . Store::esc($after['i']) . '))';
        if ($equipmentId) $w[] = 'equipment_id=' . (int) $equipmentId;
        if ($ticketId) $w[] = 'ticket_id=' . (int) $ticketId;
        $q = Store::q('SELECT * FROM ' . self::table() . ' WHERE ' . implode(' AND ', $w) . ' ORDER BY updated_at, id LIMIT ' . ($limit + 1));
        $rows = [];
        while ($q && ($r = db_fetch_array($q))) $rows[] = $r;
        $more = count($rows) > $limit;
        if ($more) array_pop($rows);
        return [$rows, $more];
    }

    static function cursor(array $r) {
        return Token::b64(json_encode(['t' => $r['updated_at'], 'i' => $r['id']]));
    }

    static function decodeCursor($c) {
        if ($c === null) return null;
        $d = json_decode(Token::unb64($c), true);
        if (!is_array($d) || !isset($d['t'], $d['i']) || !is_string($d['t']) || !is_string($d['i'])
            || !preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}(\.\d{1,6})?$/', $d['t']) || !preg_match(Documents::UUID, $d['i']))
            throw ApiError::validation('Invalid cursor', 'cursor');
        return $d;
    }

    // ------------------------------------------------------------------ DTO

    private static function utc($v) { return $v ? str_replace(' ', 'T', substr($v, 0, 19)) . 'Z' : null; }
    private static function n($v) { return $v === null ? null : (int) $v; }

    static function dto(array $r) {
        return [
            'id'              => $r['id'],
            'equipment_id'    => (int) $r['equipment_id'],
            'scheduled_date'  => $r['scheduled_date'],
            'stored_status'   => $r['stored_status'],
            'ticket_id'       => self::n($r['ticket_id']),
            'completed_on'    => $r['completed_on'],
            'completed_by'    => self::n($r['completed_by']),
            'evidence_ref'    => $r['evidence_ref'],
            'completion_note' => $r['completion_note'],
            'cancel_reason'   => $r['cancel_reason'],
            'changes'         => json_decode((string) $r['changes'], true) ?: [],
            'created_at'      => self::utc($r['created_at']),
            'created_by'      => (int) $r['created_by'],
            'updated_at'      => self::utc($r['updated_at']),
            'updated_by'      => (int) $r['updated_by'],
        ];
    }

    static function lastChange(array $r) {
        $c = json_decode((string) $r['changes'], true);
        return $c ? end($c) : null;
    }

    // ------------------------------------------------------------------ writes

    static function change($kind, $staffId, $reason, $from, $to) {
        return ['kind' => $kind, 'at' => gmdate('Y-m-d\TH:i:s\Z'), 'by' => (int) $staffId, 'reason' => $reason, 'from' => $from, 'to' => $to];
    }

    /** Insert-if-absent. @return bool true when the row was created (false: the id or the (equipment, date) slot is taken) */
    static function insert($id, $equipmentId, $date, $staffId) {
        $chg = json_encode([self::change('created', $staffId, null, null, ['equipment_id' => (int) $equipmentId, 'scheduled_date' => $date])], JSON_UNESCAPED_UNICODE);
        Store::q('INSERT IGNORE INTO ' . self::table() . ' SET id=' . Store::esc($id) . ', schema_version=' . self::SCHEMA_VERSION
            . ', equipment_id=' . (int) $equipmentId . ', scheduled_date=' . Store::esc($date) . ', active_date=' . Store::esc($date)
            . ', stored_status=\'PLANNED\', changes=' . Store::esc($chg) . ', rev=1'
            . ', created_at=UTC_TIMESTAMP(6), created_by=' . (int) $staffId . ', updated_at=UTC_TIMESTAMP(6), updated_by=' . (int) $staffId);
        return Store::affected() === 1;
    }

    /**
     * Compare-and-swap update of one row: $set is [column => SQL literal already escaped]; the audit entry is appended to
     * `changes`. @return bool false when someone else changed the row (rev) or the unique slot was taken (UPDATE IGNORE).
     */
    static function update(array $row, array $set, array $change, $staffId) {
        $chg = json_decode((string) $row['changes'], true) ?: [];
        $chg[] = $change;
        $sets = [];
        foreach ($set as $col => $lit) $sets[] = '`' . $col . '`=' . $lit;
        $sets[] = 'changes=' . Store::esc(json_encode($chg, JSON_UNESCAPED_UNICODE));
        $sets[] = 'rev=rev+1';
        $sets[] = 'updated_at=UTC_TIMESTAMP(6)';
        $sets[] = 'updated_by=' . (int) $staffId;
        Store::q('UPDATE IGNORE ' . self::table() . ' SET ' . implode(', ', $sets) . ' WHERE id=' . Store::esc($row['id']) . ' AND rev=' . (int) $row['rev']);
        return Store::affected() === 1;
    }

    /** Is the agent an administrator or the manager of ANY department (decision B of the design)? */
    static function isManagerOfAny(\Staff $s) {
        if ($s->isAdmin()) return true;
        return (bool) Store::row('SELECT id FROM ' . DEPT_TABLE . ' WHERE manager_id=' . (int) $s->getId() . ' LIMIT 1');
    }
}
