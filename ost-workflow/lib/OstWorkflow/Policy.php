<?php
namespace OstWorkflow;

/**
 * The Policy table: route policy name -> check. osTicket's core methods
 * almost never check permissions (their SCP controllers do), so the plugin
 * replicates the SCP matrix here, BEFORE the handler runs (Architecture §R).
 *
 * Names accepted by a route's 'policy' option:
 *   'auth'            any active agent (authentication already done)
 *   'ticket.view'     ticket exists and the agent can access it (dept/assigned/referral)
 *   'ticket.<perm>'   ... and the agent's effective role has ticket.<perm>  (reply, edit, assign, release, transfer, refer, close, markanswered)
 *   'task.view|<perm>'same for tasks (edit, assign, transfer, close, reply)
 *   'global.<perm>'   global (non-role) permission, e.g. global.user.edit, global.org.create
 *   'anydept.<perm>'  role permission in at least one department, e.g. anydept.ticket.create
 *   any name defined by a handler's static policy(): array<string, callable(Request)>
 * Loaded objects are left in $req->ctx['ticket'|'task'] (param 'id').
 * On failure: 403 forbidden / 404 not_found — never a silent allow.
 */
final class Policy {
    private static $custom;

    static function authorize(Request $req) {
        $name = $req->route['policy'] ?? null;
        if ($name === null || $name === 'auth')
            return;
        $custom = self::custom();
        if (isset($custom[$name])) {
            call_user_func($custom[$name], $req);
            return;
        }
        if (preg_match('/^ticket\.(\w+)$/', $name, $m)) return self::ticket($req, $m[1]);
        if (preg_match('/^task\.(\w+)$/', $name, $m)) return self::task($req, $m[1]);
        if (preg_match('/^global\.(.+)$/', $name, $m)) return self::need($req->staff->hasPerm($m[1]), $m[1]);
        if (preg_match('/^anydept\.(.+)$/', $name, $m)) return self::need($req->staff->hasPerm($m[1], false), $m[1]);
        // A route naming an unknown policy is a bug: fail closed.
        throw new \LogicException("Unknown policy '$name'");
    }

    private static function custom() {
        if (self::$custom === null) {
            self::$custom = [];
            foreach (Router::HANDLERS as $class)
                try {
                    if (class_exists($class) && method_exists($class, 'policy'))
                        self::$custom = array_merge(self::$custom, $class::policy());
                } catch (\Throwable $t) { /* handler disabled by Router already */ }
        }
        return self::$custom;
    }

    private static function need($ok, $perm) {
        if (!$ok) throw new ApiError('forbidden', 'Missing permission: ' . $perm);
    }

    static function ticket(Request $req, $action, $param = 'id') {
        require_once(INCLUDE_DIR . 'class.ticket.php');
        $t = \Ticket::lookup((int) $req->param($param));
        if (!$t) throw ApiError::notFound('ticket');
        if (!$t->checkStaffPerm($req->staff))
            throw new ApiError('forbidden', 'You cannot access this ticket');
        if ($action !== 'view' && !$t->checkStaffPerm($req->staff, 'ticket.' . $action))
            throw new ApiError('forbidden', 'Missing permission: ticket.' . $action);
        $req->ctx['ticket'] = $t;
    }

    static function task(Request $req, $action, $param = 'id') {
        require_once(INCLUDE_DIR . 'class.task.php');
        $t = \Task::lookup((int) $req->param($param));
        if (!$t) throw ApiError::notFound('task');
        if (!$t->checkStaffPerm($req->staff))
            throw new ApiError('forbidden', 'You cannot access this task');
        if ($action !== 'view' && !$t->checkStaffPerm($req->staff, 'task.' . $action))
            throw new ApiError('forbidden', 'Missing permission: task.' . $action);
        $req->ctx['task'] = $t;
    }
}
