<?php
namespace OstWorkflow\Handlers;

use OstWorkflow\Request;
use OstWorkflow\Res;
use OstWorkflow\Runtime;

/** The agent's own profile and effective permissions (Architecture §R). */
final class Me {
    static function routes() {
        return [
            ['GET', '/me',             'profile',     ['policy' => 'auth']],
            ['GET', '/me/permissions', 'permissions', ['policy' => 'auth']],
        ];
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
