<?php
namespace OstWorkflow;

/**
 * All timestamps leave the plugin as ISO-8601 UTC. osTicket stores/returns
 * naive datetimes in the *database* timezone (R-C19, RC-14): normalize via
 * Misc::db2gmtime, never with date() on raw values.
 */
final class Time {
    /** DB datetime string | unix int | DateTime|null  =>  '2026-09-27T18:04:05Z' | null */
    static function iso($value) {
        if ($value === null || $value === '' || $value === '0000-00-00 00:00:00')
            return null;
        if ($value instanceof \DateTimeInterface)
            return gmdate('Y-m-d\TH:i:s\Z', $value->getTimestamp());
        $ts = self::gm($value);
        return $ts ? gmdate('Y-m-d\TH:i:s\Z', $ts) : null;
    }

    /** null = osTicket's DB timezone is trustworthy; int = fixed offset (seconds) measured from MySQL itself. */
    private static $fixedOffset;
    private static $calibrated = false;

    /**
     * osTicket derives the DB timezone from MySQL's tz *name*. An ambiguous
     * abbreviation (e.g. system_time_zone 'CST') may resolve to a DST-observing
     * zone and shift everything by an hour (RC-14). Cross-check once per
     * request against MySQL's own clock and fall back to the measured offset.
     */
    private static function calibrate() {
        if (self::$calibrated) return;
        self::$calibrated = true;
        $r = Store::row('SELECT UNIX_TIMESTAMP() AS u, NOW() AS n');
        if (!$r) return;
        $u = (int) $r['u'];
        $viaCore = self::coreGm($r['n']);
        if ($viaCore === null || abs($viaCore - $u) > 120)
            self::$fixedOffset = strtotime($r['n'] . ' UTC') - $u;
    }

    private static function coreGm($value) {
        $naive = strtotime($value . ' UTC');
        if ($naive === false) return null;
        global $cfg;
        if (!$cfg) return null;
        try {
            $tz = new \DateTimeZone($cfg->getDbTimezone());
        } catch (\Exception $e) {
            return null;
        }
        $D = \DateTime::createFromFormat('U', (string) $naive);
        return $D ? $naive - $tz->getOffset($D) : null;
    }

    /** DB local datetime => unix timestamp (UTC), or null. */
    static function gm($value) {
        if ($value === null || $value === '') return null;
        if (is_int($value)) return $value;
        self::calibrate();
        if (self::$fixedOffset !== null) {
            $naive = strtotime($value . ' UTC');
            return $naive === false ? null : $naive - self::$fixedOffset;
        }
        $ts = self::coreGm($value);
        return $ts ?: null;
    }

    /** ISO-8601 (any offset) from the client => 'Y-m-d H:i:s' in DB timezone. */
    static function toDb($iso) {
        try {
            $d = new \DateTime($iso);
        } catch (\Exception $e) {
            return null;
        }
        self::calibrate();
        if (self::$fixedOffset !== null)
            return gmdate('Y-m-d H:i:s', $d->getTimestamp() + self::$fixedOffset);
        global $cfg;
        $d->setTimezone(new \DateTimeZone($cfg ? $cfg->getDbTimezone() : 'UTC'));
        return $d->format('Y-m-d H:i:s');
    }
}
