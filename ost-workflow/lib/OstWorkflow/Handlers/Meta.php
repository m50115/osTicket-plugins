<?php
namespace OstWorkflow\Handlers;

use OstWorkflow\Build;
use OstWorkflow\Request;
use OstWorkflow\Res;
use OstWorkflow\Runtime;
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
            'attachments' => [
                'max_files'      => Runtime::intSetting('max_files_per_note', 5),
                // effective limit = the stricter of the plugin and osTicket settings
                'max_file_bytes' => $coreMax > 0 ? min($cfgMax, $coreMax) : $cfgMax,
                'core_max_file_bytes' => $coreMax,
                'allowed_types'  => $cfg->getAllowedFileTypes(),
            ],
        ]);
    }
}
