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

    /** DB local datetime => unix timestamp (UTC), or null. */
    static function gm($value) {
        if ($value === null || $value === '') return null;
        if (is_int($value)) return $value;
        $ts = \Misc::db2gmtime($value);
        return $ts ?: null;
    }

    /** ISO-8601 (any offset) from the client => 'Y-m-d H:i:s' in DB timezone. */
    static function toDb($iso) {
        try {
            $d = new \DateTime($iso);
        } catch (\Exception $e) {
            return null;
        }
        global $cfg;
        $tz = $cfg ? $cfg->getDbTimezone() : 'UTC';
        $d->setTimezone(new \DateTimeZone($tz));
        return $d->format('Y-m-d H:i:s');
    }
}
