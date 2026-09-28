<?php
namespace OstWorkflow;

/**
 * Route table. Each handler class exposes `static routes(): array` of
 *   [METHOD, '/path/(?P<id>\d+)', 'handlerMethod', ['policy'=>'ticket.view', 'auth'=>true, 'idem'=>null, 'max_body'=>bytes]]
 * Paths are relative to /workflow/v1 and are anchored by the router.
 * `idem` null = default (required for every authenticated write).
 */
final class Router {
    /** Handler classes, in match order. Add new handler classes here. */
    const HANDLERS = [
        Handlers\Meta::class,
        Handlers\Session::class,
        Handlers\Me::class,
        Handlers\Catalogs::class,
        Handlers\Forms::class,
        Handlers\Canned::class,
        Handlers\Queues::class,
        Handlers\Tickets::class,
        Handlers\Threads::class,
        Handlers\Documents::class,
        Handlers\Files::class,
        Handlers\Tasks::class,
        Handlers\Users::class,
        Handlers\Orgs::class,
        Handlers\Matching::class,
        Handlers\Sync::class,
        Handlers\Reports::class,
    ];

    private static $table;

    static function table() {
        if (self::$table === null) {
            self::$table = [];
            foreach (self::HANDLERS as $class) {
                // A handler that is not built yet, or that fails to load, must not
                // break the others (PP-13: only that endpoint group is lost).
                try {
                    if (!class_exists($class)) continue;
                    $routes = $class::routes();
                } catch (\Throwable $t) {
                    error_log('[ost-workflow] handler ' . $class . ' disabled: ' . $t->getMessage());
                    continue;
                }
                foreach ($routes as $r) {
                    $opts = $r[3] ?? [];
                    self::$table[] = [
                        'method'  => strtoupper($r[0]),
                        'path'    => $r[1],
                        'regex'   => '#^' . $r[1] . '$#',
                        'class'   => $class,
                        'action'  => $r[2],
                        'policy'  => $opts['policy'] ?? null,
                        'auth'    => $opts['auth'] ?? true,
                        'idem'    => $opts['idem'] ?? null,
                        'max_body'=> $opts['max_body'] ?? null,
                    ];
                }
            }
        }
        return self::$table;
    }

    /** @return array route + 'params' */
    static function match(Request $req) {
        $allowed = [];
        foreach (self::table() as $route) {
            if (!preg_match($route['regex'], $req->path, $m))
                continue;
            if ($route['method'] !== $req->method) {
                $allowed[$route['method']] = true;
                continue;
            }
            $params = [];
            foreach ($m as $k => $v)
                if (is_string($k)) $params[$k] = $v;
            $route['params'] = $params;
            return $route;
        }
        if ($allowed) {
            throw new ApiError('method_not_allowed', 'Method not allowed', null, [],
                ['Allow' => implode(', ', array_keys($allowed))]);
        }
        throw new ApiError('not_found', 'Unknown route');
    }
}
