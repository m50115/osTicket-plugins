<?php
namespace OstWorkflow\Handlers;

use OstWorkflow\Build;
use OstWorkflow\Request;
use OstWorkflow\Res;
use OstWorkflow\Runtime;
use OstWorkflow\Threading;
use OstWorkflow\Time;

/** GET /ping (public liveness) and GET /config (installation config, no secrets). */
final class Meta {
    static function routes() {
        return [
            ['GET', '/ping',   'ping',   ['auth' => false]],
            ['GET', '/config', 'config', ['policy' => 'auth']],
        ];
    }

    static function ping(Request $req) {
        // Liveness only — no version/metadata before authentication (S7).
        return Res::ok(['status' => 'ok']);
    }

    static function config(Request $req) {
        global $cfg;
        $coreMax = (int) $cfg->getMaxFileSize();
        $cfgMax = Runtime::intSetting('max_file_bytes', 1048576);
        return Res::ok([
            'plugin_version' => Build::VERSION,
            'build_sha'      => Build::SHA,
            'api_versions'   => Build::API_VERSIONS,
            'schema_version' => \OstWorkflowConfig::SCHEMA_VERSION,
            'server_time'    => Time::iso(time()),
            'osticket_version' => defined('THIS_VERSION') ? THIS_VERSION : null,
            'modules'        => Runtime::modules(),
            'brand' => [
                'name'  => Runtime::setting('brand_name'),
                'color' => Runtime::setting('brand_color'),
            ],
            'defaults' => [
                'dept_id'  => ($v = Runtime::setting('default_dept_id')) ? (int) $v : null,
                'topic_id' => ($v = Runtime::setting('default_topic_id')) ? (int) $v : null,
            ],
            'search' => ['min_length' => 2, 'max_length' => 100],
            'limits' => [
                'json_body_bytes'     => \OstWorkflow\Request::MAX_JSON_BYTES,
                'post_max_size'       => self::iniBytes('post_max_size'),
                'upload_max_filesize' => self::iniBytes('upload_max_filesize'),
                'max_files_per_entry' => Runtime::intSetting('max_files_per_note', 5),
                'min_text_with_pdf'   => Runtime::intSetting('min_text_with_pdf', 15),
            ],
            'text' => [
                // false when the database is utf8mb3: emoji / characters above U+FFFF are dropped (writes report `sanitized`)
                'supplementary_characters_supported' => !Threading::dbDropsSupplementary(),
            ],
            'attachments' => [
                'max_files'      => Runtime::intSetting('max_files_per_note', 5),
                // effective limit = the stricter of the plugin and osTicket settings
                'max_file_bytes' => $coreMax > 0 ? min($cfgMax, $coreMax) : $cfgMax,
                'core_max_file_bytes' => $coreMax,
                'allowed_types'  => $cfg->getAllowedFileTypes(),
            ],
        ]);
    }

    private static function iniBytes($key) {
        $v = trim((string) ini_get($key));
        if ($v === '') return null;
        $n = (int) $v;
        switch (strtolower(substr($v, -1))) {
            case 'g': $n *= 1024;
            case 'm': $n *= 1024;
            case 'k': $n *= 1024;
        }
        return $n;
    }
}
