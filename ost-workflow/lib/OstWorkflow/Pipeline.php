<?php
namespace OstWorkflow;

/**
 * Fixed pipeline (Architecture §M):
 *   auth -> $thisstaff -> idempotency -> Policy -> handler -> single emitter
 * Handlers return [status, body(, headers)] | Stream; they never echo or exit.
 * Any failure becomes typed JSON; the plugin never takes down SCP/portal/cron.
 */
final class Pipeline {
    static function run($plugin, $config, $rest) {
        Runtime::init($plugin, $config);
        $level = ob_get_level();
        ob_start();

        $status = 500;
        $body = null;
        $headers = [];
        $idem = false;

        try {
            $req = Request::capture($rest);
            $route = Router::match($req);
            $req->route = $route;
            $req->params = $route['params'];

            if ($route['auth'])
                Auth::authenticate($req);

            $replay = null;
            if ($route['auth'] && $req->isWrite() && $route['idem'] !== false) {
                $key = (string) $req->header('Idempotency-Key');
                if (!preg_match(Idempotency::KEY_PATTERN, $key))
                    throw new ApiError('idempotency_key_required',
                        'Every write needs an Idempotency-Key header (8-64 chars: letters, digits, hyphen)');
                $req->idemKey = $key;
                ignore_user_abort(true);
                Store::purge();
                $replay = Idempotency::begin($req);
                $idem = true;
            }

            if ($replay) {
                list($status, $body, $headers) = $replay;
            } else {
                Policy::authorize($req);
                $res = call_user_func([$route['class'], $route['action']], $req);
                if ($res instanceof Stream) {
                    $status = 200; $body = $res;
                } else {
                    $status = (int) $res[0];
                    $body = $res[1];
                    $headers = $res[2] ?? [];
                }
            }
        } catch (ApiError $e) {
            $status = $e->status();
            $body = $e->toBody();
            $headers = $e->headers;
        } catch (\Throwable $t) {
            $rid = bin2hex(random_bytes(4));
            error_log(sprintf('[ost-workflow] %s: %s at %s:%d (request_id=%s)',
                get_class($t), $t->getMessage(), basename($t->getFile()), $t->getLine(), $rid));
            $status = 500;
            $body = ['error' => ['code' => 'internal_error', 'message' => 'Internal error',
                                 'details' => ['request_id' => $rid]]];
            $headers = [];
        }

        if ($idem) {
            try {
                Idempotency::finish($status, $body);
            } catch (\Throwable $t) {
                error_log('[ost-workflow] idempotency finish failed: ' . $t->getMessage());
            }
        }

        Emitter::send($level, $status, $body, $headers);
        return '';
    }
}
