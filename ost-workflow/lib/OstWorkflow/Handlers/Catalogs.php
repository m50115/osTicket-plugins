<?php
namespace OstWorkflow\Handlers;

use OstWorkflow\ApiError;
use OstWorkflow\Request;
use OstWorkflow\Res;
use OstWorkflow\Time;

/**
 * Read-only catalogs: departments, agents, topics, statuses, priorities,
 * SLAs, teams, ticket sources. Base of the app's offline cache. The plugin
 * never edits any of them (P-03).
 *
 * Visibility mirrors the SCP: an agent without `visibility.departments`
 * (Dept::PERM_DEPT) only sees its own departments/topics
 * (Staff::getDepartmentNames / getTopicNames), and agents are filtered with
 * Staff::applyDeptVisibility (`visibility.agents`). Every list echoes the
 * filters it applied in `meta.filters` — no hidden defaults.
 */
final class Catalogs {
    const MAX_ROWS = 1000;

    static function routes() {
        return [
            ['GET', '/departments',                          'departments', ['policy' => 'auth']],
            ['GET', '/departments/(?P<id>\d+)/assignees',    'assignees',   ['policy' => 'auth']],
            ['GET', '/staff',                                'staff',       ['policy' => 'auth']],
            ['GET', '/topics',                               'topics',      ['policy' => 'auth']],
            ['GET', '/statuses',                             'statuses',    ['policy' => 'auth']],
            ['GET', '/priorities',                           'priorities',  ['policy' => 'auth']],
            ['GET', '/slas',                                 'slas',        ['policy' => 'auth']],
            ['GET', '/teams',                                'teams',       ['policy' => 'auth']],
            ['GET', '/teams/(?P<id>\d+)/members',            'teamMembers', ['policy' => 'auth']],
            ['GET', '/catalog/sources',                      'sources',     ['policy' => 'auth']],
        ];
    }

    // ------------------------------------------------------------------
    // Departments
    // ------------------------------------------------------------------

    /** GET /departments?include_disabled=0|1 */
    static function departments(Request $req) {
        $includeDisabled = self::boolQuery($req, 'include_disabled', false);
        require_once(INCLUDE_DIR . 'class.dept.php');

        // Same list the SCP shows this agent (class.staff.php:473).
        $names = $req->staff->getDepartmentNames(!$includeDisabled);
        $out = [];
        foreach ($names as $id => $fullName) {
            $d = \Dept::lookup((int) $id);
            if (!$d) continue;
            $out[] = [
                'id'          => (int) $d->getId(),
                'name'        => $d->getName(),
                'full_name'   => $fullName,
                'parent_id'   => ($p = (int) $d->pid) ? $p : null,
                'is_active'   => (bool) $d->isActive(),
                'is_public'   => (bool) $d->isPublic(),
                'manager_id'  => ($m = (int) $d->getManagerId()) ? $m : null,
                'sla_id'      => ($s = (int) $d->getSLAId()) ? $s : null,
                'members_only_assign' => (bool) ($d->assignMembersOnly() || $d->assignPrimaryOnly()),
            ];
        }
        return Res::ok($out, ['count' => count($out), 'filters' => ['include_disabled' => $includeDisabled]]);
    }

    /**
     * GET /departments/{id}/assignees
     * Agents that can receive a ticket of this department, honouring the
     * department's assignment policy and the caller's agent visibility
     * (Dept::getAssignees, class.dept.php:278).
     */
    static function assignees(Request $req) {
        require_once(INCLUDE_DIR . 'class.dept.php');
        $dept = \Dept::lookup($req->intParam('id'));
        if (!$dept) throw ApiError::notFound('department');
        if (!array_key_exists($dept->getId(), $req->staff->getDepartmentNames(false)))
            throw new ApiError('forbidden', 'You cannot access this department');

        $rows = [];
        foreach ($dept->getAssignees(['staff' => $req->staff]) as $a)
            $rows[] = self::agent($a);
        return Res::ok($rows, ['count' => count($rows), 'dept_id' => (int) $dept->getId()]);
    }

    // ------------------------------------------------------------------
    // Agents
    // ------------------------------------------------------------------

    /**
     * GET /staff?dept_id=&include_inactive=0|1
     * Agents visible to the caller (Staff::applyDeptVisibility, class.staff.php:746).
     */
    static function staff(Request $req) {
        $includeInactive = self::boolQuery($req, 'include_inactive', false);
        $deptId = $req->q('dept_id') !== null ? $req->intQuery('dept_id', null, 1) : null;

        $qs = \Staff::objects();
        if (!$includeInactive)
            $qs->filter(['isactive' => 1]);
        if ($deptId)
            $qs->filter(\Q::any(['dept_id' => $deptId, 'dept_access__dept_id' => $deptId]));
        $qs = $req->staff->applyDeptVisibility($qs->distinct('staff_id'));
        $qs = \Staff::nsort($qs);

        $rows = [];
        foreach ($qs as $a) {
            $rows[] = self::agent($a);
            if (count($rows) >= self::MAX_ROWS) break;
        }
        return Res::ok($rows, ['count' => count($rows),
            'filters' => ['dept_id' => $deptId, 'include_inactive' => $includeInactive]]);
    }

    private static function agent($a) {
        return [
            'id'         => (int) $a->getId(),
            'name'       => (string) $a->getName(),
            'first_name' => $a->getFirstName(),
            'last_name'  => $a->getLastName(),
            'email'      => $a->getEmail(),
            'dept_id'    => ($d = (int) $a->getDeptId()) ? $d : null,
            'is_active'  => (bool) $a->isActive(),
            'on_vacation'=> (bool) $a->onvacation,
        ];
    }

    // ------------------------------------------------------------------
    // Help topics
    // ------------------------------------------------------------------

    /** GET /topics?include_disabled=0|1 */
    static function topics(Request $req) {
        $includeDisabled = self::boolQuery($req, 'include_disabled', false);
        require_once(INCLUDE_DIR . 'class.topic.php');

        // Visibility as in the SCP (class.staff.php:487): private topics of
        // departments the agent cannot access are hidden.
        $names = $req->staff->getTopicNames(false, $includeDisabled);
        $out = [];
        foreach ($names as $id => $fullName) {
            $t = \Topic::lookup((int) $id);
            if (!$t) continue;
            $out[] = self::topic($t, $fullName);
        }
        usort($out, function ($a, $b) { return [$a['sort'], $a['id']] <=> [$b['sort'], $b['id']]; });
        return Res::ok($out, ['count' => count($out), 'filters' => ['include_disabled' => $includeDisabled]]);
    }

    /** @internal shared with Forms::topicForms */
    static function topic($t, $fullName = null) {
        return [
            'id'          => (int) $t->getId(),
            'name'        => $t->getName(),
            'full_name'   => $fullName ?: $t->getFullName(),
            'parent_id'   => ($p = (int) $t->getPid()) ? $p : null,
            'dept_id'     => ($v = (int) $t->getDeptId()) ? $v : null,
            'priority_id' => ($v = (int) $t->getPriorityId()) ? $v : null,
            'sla_id'      => ($v = (int) $t->getSLAId()) ? $v : null,
            'status_id'   => ($v = (int) $t->getStatusId()) ? $v : null,
            'staff_id'    => ($v = (int) $t->getStaffId()) ? $v : null,
            'team_id'     => ($v = (int) $t->getTeamId()) ? $v : null,
            'is_public'   => (bool) $t->isPublic(),
            'is_active'   => (bool) $t->isActive(),
            'sort'        => (int) $t->sort,
            'updated'     => Time::iso($t->updated),
        ];
    }

    // ------------------------------------------------------------------
    // Ticket statuses
    // ------------------------------------------------------------------

    const STATES = ['open', 'closed', 'archived', 'deleted'];

    /** GET /statuses?state=open,closed&include_disabled=0|1 */
    static function statuses(Request $req) {
        $includeDisabled = self::boolQuery($req, 'include_disabled', false);
        $states = null;
        if (($raw = $req->q('state')) !== null) {
            $states = array_values(array_filter(array_map('trim', explode(',', strtolower($raw)))));
            foreach ($states as $s)
                if (!in_array($s, self::STATES, true))
                    throw ApiError::validation("Unknown state '$s'", 'state', ['allowed' => self::STATES]);
        }
        require_once(INCLUDE_DIR . 'class.list.php');
        $criteria = ['enabled' => !$includeDisabled];
        if ($states) $criteria['states'] = $states;

        $out = [];
        // Statuses come in the list's configured order (TicketStatusList::getItems, class.list.php:915).
        foreach (\TicketStatusList::getStatuses($criteria) as $s) {
            $props = self::props($s);
            $reopen = isset($props['reopenstatus']) ? (int) $props['reopenstatus'] : 0;
            $out[] = [
                'id'          => (int) $s->getId(),
                'name'        => $s->getName(),
                'state'       => $s->state,
                'sort'        => (int) $s->sort,
                'is_enabled'  => (bool) ((int) $s->mode & 1),
                // only meaningful for state=closed
                'allowreopen' => !empty($props['allowreopen']),
                // 0 in osTicket = "system default" -> null here
                'reopenstatus'=> $reopen ?: null,
                'description' => isset($props['description']) ? $props['description'] : null,
                'updated'     => Time::iso($s->updated),
            ];
        }
        return Res::ok($out, ['count' => count($out),
            'filters' => ['state' => $states, 'include_disabled' => $includeDisabled]]);
    }

    private static function props($s) {
        $p = $s->properties;
        if (is_string($p)) $p = json_decode($p, true);
        return is_array($p) ? $p : [];
    }

    // ------------------------------------------------------------------
    // Priorities, SLAs, teams, sources
    // ------------------------------------------------------------------

    /** GET /priorities  (urgency: lower = more urgent) */
    static function priorities(Request $req) {
        require_once(INCLUDE_DIR . 'class.priority.php');
        $out = [];
        foreach (\Priority::objects()->order_by('priority_urgency') as $p) {
            $out[] = [
                'id'       => (int) $p->priority_id,
                'key'      => $p->priority,
                'name'     => $p->priority_desc,
                'color'    => $p->priority_color ?: null,
                'urgency'  => (int) $p->priority_urgency,
                'is_public'=> (bool) $p->ispublic,
            ];
        }
        return Res::ok($out, ['count' => count($out)]);
    }

    /** GET /slas */
    static function slas(Request $req) {
        require_once(INCLUDE_DIR . 'class.sla.php');
        $out = [];
        foreach (\SLA::objects()->order_by('name') as $s) {
            $out[] = [
                'id'                 => (int) $s->getId(),
                'name'               => $s->getName(),
                'grace_period_hours' => (int) $s->getGracePeriod(),
                'is_active'          => (bool) $s->isActive(),
                'schedule_id'        => ($v = (int) $s->getScheduleId()) ? $v : null,
                'updated'            => Time::iso($s->updated),
            ];
        }
        return Res::ok($out, ['count' => count($out)]);
    }

    /** GET /teams */
    static function teams(Request $req) {
        require_once(INCLUDE_DIR . 'class.team.php');
        $out = [];
        foreach (\Team::objects()->order_by('name') as $t) {
            $out[] = [
                'id'            => (int) $t->getId(),
                'name'          => $t->getName(),
                'is_enabled'    => (bool) ((int) $t->flags & \Team::FLAG_ENABLED),
                'lead_id'       => ($v = (int) $t->getLeadId()) ? $v : null,
                'members_count' => (int) $t->getNumMembers(),
                'updated'       => Time::iso($t->updated),
            ];
        }
        return Res::ok($out, ['count' => count($out)]);
    }

    /** GET /teams/{id}/members */
    static function teamMembers(Request $req) {
        require_once(INCLUDE_DIR . 'class.team.php');
        $team = \Team::lookup($req->intParam('id'));
        if (!$team) throw ApiError::notFound('team');
        $rows = [];
        foreach ($team->getMembers() as $m)
            if ($m) $rows[] = self::agent($m);
        return Res::ok($rows, ['count' => count($rows), 'team_id' => (int) $team->getId()]);
    }

    /** GET /catalog/sources — ticket origin values (Ticket::getSources, class.ticket.php:4767). */
    static function sources(Request $req) {
        require_once(INCLUDE_DIR . 'class.ticket.php');
        $out = [];
        foreach (\Ticket::getSources() as $key => $label)
            $out[] = ['key' => (string) $key, 'label' => (string) $label];
        return Res::ok($out, ['count' => count($out)]);
    }

    // ------------------------------------------------------------------

    /** Strict 0|1|true|false query flag; 422 otherwise. */
    static function boolQuery(Request $req, $name, $default) {
        $v = $req->q($name);
        if ($v === null) return $default;
        $l = strtolower((string) $v);
        if ($l === '1' || $l === 'true')  return true;
        if ($l === '0' || $l === 'false') return false;
        throw ApiError::validation("'$name' must be 0 or 1", $name);
    }
}
