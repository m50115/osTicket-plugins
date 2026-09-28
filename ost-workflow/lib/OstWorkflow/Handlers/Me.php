<?php
namespace OstWorkflow\Handlers;

use OstWorkflow\ApiError;
use OstWorkflow\Request;
use OstWorkflow\Res;
use OstWorkflow\Runtime;
use OstWorkflow\Store;
use OstWorkflow\Threading;
use OstWorkflow\Ticketing;

/** The agent's own profile and effective permissions (Architecture §R). */
final class Me {
    static function routes() {
        return [
            ['GET', '/me',             'profile',     ['policy' => 'auth']],
            ['GET', '/me/permissions', 'permissions', ['policy' => 'auth']],
            ['PATCH', '/me',           'update',      ['policy' => 'auth', 'max_body' => 16384]],
        ];
    }

    /** API key => [column, max length|type] of the agent's own editable profile. */
    const EDITABLE = [
        'first_name' => ['firstname', 32], 'last_name' => ['lastname', 32], 'phone' => ['phone', 24], 'phone_ext' => ['phone_ext', 6],
        'mobile' => ['mobile', 24], 'signature' => ['signature', 4000], 'timezone' => ['timezone', 'tz'],
        'default_signature_type' => ['default_signature_type', 'sig'], 'on_vacation' => ['onvacation', 'bool'],
    ];

    /**
     * PATCH /me {first_name?, last_name?, phone?, phone_ext?, mobile?, signature?, timezone?, default_signature_type?,
     *            on_vacation?, base:{same keys}}
     * The agent edits only their own profile (never username, e-mail, role, departments or permissions). Every key
     * changed carries the value the app saw in `base`; a stale one is a 409 listing the current values.
     * `on_vacation:true` makes the agent unavailable for assignment (osTicket's "on vacation").
     */
    static function update(Request $req) {
        $s = $req->staff;
        $b = $req->json();
        $want = [];
        foreach (self::EDITABLE as $k => $_)
            if (array_key_exists($k, $b)) $want[$k] = $b[$k];
        $unknown = array_diff(array_keys($b), array_keys(self::EDITABLE), ['base']);
        if ($unknown) throw ApiError::validation('Not editable here: ' . implode(', ', $unknown), (string) reset($unknown), ['editable' => array_keys(self::EDITABLE)]);
        if (!$want) throw ApiError::validation('Nothing to update', null, ['editable' => array_keys(self::EDITABLE)]);
        $base = Ticketing::requireBase($req);
        if (!is_array($base)) throw ApiError::validation("'base' must be an object with the current value of each key you change", 'base');

        $cols = implode(',', array_map(function ($k) { return self::EDITABLE[$k][0]; }, array_keys($want)));
        $cur = Store::row('SELECT ' . $cols . ' FROM ' . STAFF_TABLE . ' WHERE staff_id=' . (int) $s->getId());
        $apply = []; $conflicts = [];
        foreach ($want as $k => $v) {
            list($col, $rule) = self::EDITABLE[$k];
            if (!array_key_exists($k, $base)) throw ApiError::validation("'base.$k' is required", "base.$k");
            $v = self::clean($k, $v, $rule);
            $now = $rule === 'bool' ? ((int) $cur[$col] === 1) : (string) $cur[$col];
            $bs = $rule === 'bool' ? (bool) $base[$k] : trim((string) $base[$k]);
            if ($now === $v) continue;                                   // already desired: idempotent
            if ($now !== $bs) { $conflicts[$k] = $now; continue; }
            $apply[$col] = $rule === 'bool' ? (int) $v : $v;
        }
        if ($conflicts)
            throw new ApiError('conflict', 'The profile changed on the server since you read it', null, ['current' => $conflicts]);
        if ($apply) {
            foreach ($apply as $col => $v) $s->set($col, $v);
            if (!$s->save()) throw new ApiError('internal_error', 'The profile could not be saved');
        }
        return Res::ok(['applied' => (bool) $apply, 'changed' => array_keys($apply), 'profile' => self::profile($req)[1]['data']]);
    }

    private static function clean($key, $v, $rule) {
        if ($rule === 'bool') {
            if (!is_bool($v)) throw ApiError::validation("'$key' must be true or false", $key);
            return $v;
        }
        if (!is_string($v)) throw ApiError::validation("'$key' must be text", $key);
        $v = $key === 'signature' ? trim(\Format::sanitize($v)) : trim(strip_tags($v));
        if ($rule === 'tz') {
            if ($v !== '' && !in_array($v, \DateTimeZone::listIdentifiers(), true)) throw ApiError::validation('Unknown time zone', $key);
            return $v;
        }
        if ($rule === 'sig') {
            if (!in_array($v, ['none', 'mine', 'dept'], true)) throw ApiError::validation("'$key' must be none, mine or dept", $key);
            return $v;
        }
        if (mb_strlen($v) > $rule) throw ApiError::validation("'$key' exceeds $rule characters", $key);
        if (in_array($key, ['first_name', 'last_name'], true) && $v === '') throw ApiError::validation("'$key' cannot be empty", $key);
        return $v;
    }

    static function profile(Request $req) {
        $s = $req->staff;
        $teams = [];
        foreach ($s->getTeams() as $tid) {
            if (($t = \Team::lookup($tid)))
                $teams[] = ['id' => (int) $t->getId(), 'name' => $t->getName()];
        }
        $dept = $s->getDept();
        return Res::ok([
            'id'         => (int) $s->getId(),
            'username'   => $s->getUserName(),
            'name'       => $s->getName()->getOriginal(),
            'first_name' => $s->getFirstName(),
            'last_name'  => $s->getLastName(),
            'email'      => $s->getEmail(),
            'phone'      => $s->ht['phone'] ?? null,
            'timezone'   => $s->getTimezone(),
            'language'   => $s->getLanguage(),
            'primary_dept_id' => $dept ? (int) $dept->getId() : null,
            'departments' => self::departments($s),
            'teams'      => $teams,
            'is_admin'   => (bool) $s->isAdmin(),
            'is_available' => (bool) $s->isAvailable(),
            'signature'  => $s->getSignature(),
        ]);
    }

    static function permissions(Request $req) {
        $s = $req->staff;
        $depts = [];
        foreach ($s->getDepartments() as $did) {
            $dept = \Dept::lookup($did);
            $role = $s->getRole($did);
            $depts[] = [
                'dept_id'    => (int) $did,
                'name'       => $dept ? $dept->getName() : null,
                'role_id'    => $role && method_exists($role, 'getId') ? (int) $role->getId() : null,
                'role'       => $role ? $role->ht['name'] ?? null : null,
                'permissions'=> $role ? array_keys(array_filter((array) $role->getPermissionInfo())) : [],
                'is_manager' => $dept ? (bool) $s->isManager($dept) : false,
            ];
        }
        $primary = $s->getRole();
        return Res::ok([
            'staff_id'      => (int) $s->getId(),
            'is_admin'      => (bool) $s->isAdmin(),
            'assigned_only' => (bool) $s->isAccessLimited(),
            'global'        => array_keys(array_filter((array) $s->getPermissionInfo())),
            'primary_role_permissions' => $primary ? array_keys(array_filter((array) $primary->getPermissionInfo())) : [],
            'departments'   => $depts,
            'modules_enabled' => Runtime::modules(),
        ]);
    }

    private static function departments(\Staff $s) {
        $out = [];
        foreach ($s->getDepartments() as $did) {
            if (($d = \Dept::lookup($did)))
                $out[] = ['id' => (int) $d->getId(), 'name' => $d->getName(), 'is_manager' => (bool) $s->isManager($d)];
        }
        return $out;
    }
}
