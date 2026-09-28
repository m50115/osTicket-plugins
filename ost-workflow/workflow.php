<?php
/**
 * ost-workflow — main plugin file.
 *
 * osTicket includes this file on EVERY request (SCP, portal, API, cron) for
 * every row of ost_plugin, active or not. Keep it minimal and side-effect
 * free: only the plugin class and its config are declared here; everything
 * else is lazy-loaded from lib/ (PSR-0, namespace OstWorkflow) when a
 * /workflow/v1 request actually arrives.
 */
require_once(INCLUDE_DIR . 'class.plugin.php');
require_once(__DIR__ . '/config.php');

class OstWorkflowPlugin extends Plugin {
    var $config_class = 'OstWorkflowConfig';

    // init() is intentionally empty: it runs even when the plugin is
    // disabled. Routes are registered in bootstrap() (active instance only).
    function init() {}

    function bootstrap() {
        $plugin = $this;
        // PluginManager clears the side-loaded config right after bootstrap(),
        // so capture it now (values only; no DB access happens here).
        $config = $this->getConfig();
        Signal::connect('api', function ($dispatcher) use ($plugin, $config) {
            // Single catch-all matcher; the plugin owns its routing.
            $dispatcher->append(
                url('^/workflow/v1(/.*)?$', function ($rest = null) use ($plugin, $config) {
                    return \OstWorkflow\Pipeline::run($plugin, $config, $rest);
                })
            );
        });
    }

    function isMultiInstance() {
        return false;
    }

    function enable() {
        try {
            return \OstWorkflow\Store::install();
        } catch (\Throwable $t) {
            global $errors;
            if (is_array($errors))
                $errors['err'] = 'ost-workflow: could not create its table: ' . $t->getMessage();
            return false;
        }
    }
}
