<?php
namespace OstWorkflow;

/** Holds the active plugin and reads its (secret-free) configuration. */
final class Runtime {
    private static $plugin;
    private static $config;

    static function init($plugin, $config) { self::$plugin = $plugin; self::$config = $config; }

    static function plugin() { return self::$plugin; }

    /** @return \PluginConfig|null  the side-loaded instance config captured in bootstrap() */
    static function config() { return self::$config; }

    static function setting($key, $default = null) {
        $c = self::config();
        $v = $c ? $c->get($key) : null;
        return ($v === null || $v === '') ? $default : $v;
    }

    static function intSetting($key, $default) {
        return (int) self::setting($key, $default);
    }

    static function modules() {
        $m = (string) self::setting('modules', '');
        return array_values(array_filter(array_map('trim', explode(',', $m))));
    }
}
