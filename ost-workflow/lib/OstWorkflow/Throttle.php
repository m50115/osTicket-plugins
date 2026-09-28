<?php
namespace OstWorkflow;

/**
 * Hourly budget per agent and operation class: bounds what a stolen token can do in bulk
 * (customer e-mail, uploads, PDF rendering, contact scraping). Fixed window, persisted in the
 * plumbing table (kind 'throttle'); a limit of 0 disables the class. The budget is charged when
 * the operation starts; an idempotent replay never reaches the handler, so it is not charged twice.
 */
final class Throttle {
    /** bucket => [config key, default per hour] */
    const BUCKETS = [
        'mail'    => ['limit_mail_per_hour', 100],
        'upload'  => ['limit_uploads_per_hour', 200],
        'pdf'     => ['limit_pdf_per_hour', 60],
        'lookup'  => ['limit_lookups_per_hour', 300],
    ];

    /** @throws ApiError rate_limited (429) when the agent spent the hour's budget for $bucket */
    static function hit(\Staff $staff, $bucket) {
        list($key, $default) = self::BUCKETS[$bucket];
        $limit = Runtime::intSetting($key, $default);
        if ($limit <= 0) return;
        $t = Store::table();
        $k = Store::esc('th:' . $bucket . ':' . gmdate('YmdH'));
        $sid = (int) $staff->getId();
        Store::q('INSERT INTO ' . $t . ' SET kind=\'throttle\', staff_id=' . $sid . ', idem_key=' . $k
            . ', status=\'done\', counter=1, created=NOW(), expires=DATE_ADD(NOW(), INTERVAL 2 HOUR)'
            . ' ON DUPLICATE KEY UPDATE counter=counter+1');
        $r = Store::row('SELECT counter FROM ' . $t . ' WHERE staff_id=' . $sid . ' AND idem_key=' . $k);
        if ($r && (int) $r['counter'] > $limit) {
            $wait = 3600 - (time() % 3600);
            throw new ApiError('rate_limited', "Hourly limit reached for '$bucket' ($limit per hour)", null,
                ['bucket' => $bucket, 'limit' => $limit, 'retry_after' => $wait], ['Retry-After' => (string) $wait]);
        }
    }
}
