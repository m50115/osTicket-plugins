<?php
namespace OstWorkflow\Handlers;

use OstWorkflow\ApiError;
use OstWorkflow\Attachments;
use OstWorkflow\Contacts;
use OstWorkflow\RateLimit;
use OstWorkflow\Request;
use OstWorkflow\Res;
use OstWorkflow\Store;
use OstWorkflow\Threading;
use OstWorkflow\Ticketing;
use OstWorkflow\Time;
use OstWorkflow\Token;

/**
 * osTicket tasks: standalone or linked to a ticket. Same rules as tickets:
 * Policy first, $thisstaff set, updates carry a base value, writes are idempotent.
 */
final class Tasks {
    static function routes() {
        $t = '/tasks/(?P<id>\d+)';
        return [
            ['GET',  '/tickets/(?P<id>\d+)/tasks', 'ticketTasks', ['policy' => 'ticket.view']],
            ['POST', '/tickets/(?P<id>\d+)/tasks', 'create',      ['policy' => 'ticket.task_create']],
            ['GET',  '/tasks',                    'index',        ['policy' => 'auth']],
            ['GET',  $t,                          'detail',       ['policy' => 'task.view']],
            ['GET',  "$t/thread",                 'thread',       ['policy' => 'task.view']],
            ['POST', "$t/notes",                  'note',         ['policy' => 'task.view']],
            ['POST', "$t/replies",                'reply',        ['policy' => 'task.reply']],
            ['PATCH', "$t/notes/(?P<entry>\d+)",  'editNote',     ['policy' => 'task.view']],
            ['POST', "$t/status",                 'status',       ['policy' => 'task.view']],
            ['POST', "$t/assignment",             'assign',       ['policy' => 'task.assign']],
            ['POST', "$t/transfer",               'transfer',     ['policy' => 'task.transfer']],
            ['PUT',  $t,                          'update',       ['policy' => 'task.edit']],
        ];
    }

    static function policy() {
        return [
            // SCP: creating a task on a ticket needs task.create in the ticket's department (ajax.tickets.php addTask)
            'ticket.task_create' => function (Request $req) {
                \OstWorkflow\Policy::ticket($req, 'view');
                $t = $req->ctx['ticket'];
                if (!$t->checkStaffPerm($req->staff, 'task.create'))
                    throw new ApiError('forbidden', 'Missing permission: task.create');
            },
        ];
    }

    // ==================================================================
    // Reads
    // ==================================================================

    static function ticketTasks(Request $req) {
        require_once(INCLUDE_DIR . 'class.task.php');
        $t = $req->ctx['ticket'];
        $out = [];
        foreach (\Task::objects()->filter(['object_id' => $t->getId(), 'object_type' => 'T'])->order_by('-id') as $task)
            $out[] = self::dto($task);
        return Res::ok($out);
    }

    /** GET /tasks?state=open|closed|all&… — visible = my departments, assigned to me, or to my teams. */
    static function index(Request $req) {
        require_once(INCLUDE_DIR . 'class.task.php');
        $state = $req->q('state');
        if (!in_array($state, ['open', 'closed', 'all'], true))
            throw ApiError::validation("'state' is required and must be open, closed or all", 'state');
        $limit = $req->intQuery('limit', 25, 1, 100);
        $s = $req->staff;
        $vis = [ 'staff_id' => $s->getId() ];
        $any = [new \Q($vis)];
        if ($s->getDepts()) $any[] = new \Q(['dept_id__in' => $s->getDepts()]);
        if ($s->getTeams()) $any[] = new \Q(['team_id__in' => $s->getTeams()]);
        $qs = \Task::objects()->filter(\Q::any($any));
        if ($state !== 'all') $qs = $qs->filter(['flags__hasbit' => \Task::ISOPEN]);
        if ($state === 'closed') $qs = \Task::objects()->filter(\Q::any($any))->exclude(['flags__hasbit' => \Task::ISOPEN]);
        foreach (['dept_id', 'staff_id', 'team_id'] as $k)
            if ($req->q($k) !== null) {
                if (!ctype_digit((string) $req->q($k))) throw ApiError::validation("'$k' must be an integer", $k);
                $qs = $qs->filter([$k => (int) $req->q($k)]);
            }
        if ($req->q('ticket_id') !== null) {
            if (!ctype_digit((string) $req->q('ticket_id'))) throw ApiError::validation("'ticket_id' must be an integer", 'ticket_id');
            $qs = $qs->filter(['object_id' => (int) $req->q('ticket_id'), 'object_type' => 'T']);
        }
        if (($c = $req->q('cursor')) !== null) {
            $d = json_decode(Token::unb64($c), true);
            if (!is_array($d) || !isset($d['i']) || !is_int($d['i'])) throw ApiError::validation('Invalid cursor', 'cursor');
            $qs = $qs->filter(['id__lt' => $d['i']]);
        }
        $rows = [];
        foreach ($qs->order_by('-id')->limit($limit + 1) as $t) $rows[] = $t;
        $more = count($rows) > $limit;
        $rows = array_slice($rows, 0, $limit);
        $items = array_map(function ($t) { return self::dto($t); }, $rows);
        return Res::page($items, ($more && $rows) ? Token::b64(json_encode(['i' => (int) end($rows)->getId()])) : null, ['filters' => ['state' => $state]]);
    }

    static function detail(Request $req) {
        return Res::ok(self::dto($req->ctx['task'], true));
    }

    /** Thread of the task in the same normalized format as ticket activity (entries by id + events by id). */
    static function thread(Request $req) {
        Attachments::boot();
        $task = $req->ctx['task'];
        $thread = $task->getThread();
        if (!$thread) throw ApiError::notFound('thread');
        $limit = $req->intQuery('limit', 50, 1, 200);
        $afterEntry = $req->intQuery('after_entry', 0, 0);
        $afterEvent = $req->intQuery('after_event', 0, 0);
        $entries = []; $events = [];
        foreach (\ThreadEntry::objects()->filter(['thread_id' => $thread->getId(), 'id__gt' => $afterEntry])
                     ->exclude(['flags__hasbit' => \ThreadEntry::FLAG_HIDDEN])->order_by('id')->limit($limit + 1) as $e) $entries[] = $e;
        foreach (\ThreadEvent::objects()->filter(['thread_id' => $thread->getId(), 'id__gt' => $afterEvent])
                     ->exclude(['event_id' => \Event::getIdByName('viewed')])->order_by('id')->limit($limit + 1) as $v) $events[] = $v;
        $more = count($entries) > $limit || count($events) > $limit;
        $entries = array_slice($entries, 0, $limit); $events = array_slice($events, 0, $limit);
        $items = []; $le = $afterEntry; $lv = $afterEvent;
        foreach ($entries as $e) { $items[] = Threading::entry($e); $le = max($le, (int) $e->getId()); }
        foreach ($events as $v) { $items[] = Threading::event($v); $lv = max($lv, (int) $v->id); }
        usort($items, function ($a, $b) {
            $c = strcmp((string) $a['created'], (string) $b['created']);
            if ($c) return $c;
            if ($a['kind'] !== $b['kind']) return $a['kind'] === 'entry' ? -1 : 1;
            return $a['id'] <=> $b['id'];
        });
        return Res::ok($items, ['count' => count($items), 'task_id' => (int) $task->getId(), 'thread_id' => (int) $thread->getId(),
                                'next_cursor' => ['after_entry' => $le, 'after_event' => $lv], 'has_more' => $more]);
    }

    // ==================================================================
    // Writes
    // ==================================================================

    /** POST /tickets/{id}/tasks {title, description?, assignee?:{type,id}, due_at?, dept_id?} */
    static function create(Request $req) {
        require_once(INCLUDE_DIR . 'class.task.php');
        $ticket = $req->ctx['ticket'];
        $b = $req->json();
        $title = isset($b['title']) && is_string($b['title']) ? trim($b['title']) : '';
        if ($title === '') throw ApiError::validation("'title' is required", 'title');
        if (strlen($title) > 200) throw ApiError::validation("'title' exceeds 200 characters", 'title');
        $post = ['title' => $title, 'description' => isset($b['description']) && is_string($b['description']) ? $b['description'] : '',
                 'dept_id' => (int) ($b['dept_id'] ?? $ticket->getDeptId())];
        if ($post['dept_id'] !== (int) $ticket->getDeptId()) {
            $d = \Dept::lookup($post['dept_id']);
            $role = $d ? $req->staff->getRole($d) : null;
            if (!$d || !$role || !$role->hasPerm('task.create')) throw new ApiError('forbidden', 'Missing permission: task.create in that department');
        }
        if (isset($b['assignee'])) {
            $a = $b['assignee'];
            if (!is_array($a) || !isset($a['type'], $a['id']) || !in_array($a['type'], ['staff', 'team'], true) || !ctype_digit((string) $a['id']))
                throw ApiError::validation("'assignee' must be {type: staff|team, id}", 'assignee');
            if (!$req->staff->hasPerm('task.assign', false)) throw new ApiError('forbidden', 'Missing permission: task.assign');
            $post['assignee'] = ($a['type'] === 'staff' ? 's' : 't') . (int) $a['id'];
        }
        if (!empty($b['due_at'])) {
            global $cfg;
            $d = Time::toDb((string) $b['due_at']);
            if (!$d) throw ApiError::validation('Invalid ISO-8601 date', 'due_at');
            $post['duedate'] = (new \DateTime((string) $b['due_at']))->setTimezone(new \DateTimeZone($cfg->getTimezone($req->staff)))->format('Y-m-d H:i:s');
        }

        $result = Contacts::asPost($post, function () use ($post, $ticket, $req) {
            $form = \TaskForm::getInstance();
            $form->setSource($post);
            $iform = \TaskForm::getInternalForm($post);
            $errors = [];
            if (!$iform->isValid()) $errors += Ticketing::formErrors($iform);
            if (!$form->isValid()) $errors += Ticketing::formErrors($form);
            if ($errors) return [null, $errors];
            $vars = $post;
            $vars['object_id'] = $ticket->getId();
            $vars['object_type'] = 'T';
            $vars['default_formdata'] = $form->getClean();
            $vars['internal_formdata'] = $iform->getClean();
            $vars['description'] = $form->getField('description')->getClean();
            $vars['staffId'] = $req->staff->getId();
            $vars['poster'] = $req->staff;
            $vars['ip_address'] = RateLimit::ip($req);
            $e = [];
            return [\Task::create($vars, $e), $e];
        });
        list($task, $errors) = $result;
        if (!$task) throw ApiError::fromErrors($errors, 'The task could not be created');
        return Res::created(self::dto(\Task::lookup((int) $task->getId()), true));
    }

    static function note(Request $req) {
        $task = $req->ctx['task'];
        $body = Threading::bodyFromRequest($req);
        $files = Attachments::resolve($req->input('file_ids'), $req->staff);
        $vars = ['note' => $body, 'title' => Threading::title($req), 'staffId' => $req->staff->getId(),
                 'files' => Attachments::forCreate($files, $req->staff), 'ip_address' => RateLimit::ip($req)];
        $errors = [];
        $entry = $task->postNote($vars, $errors, $req->staff, Threading::boolInput($req, 'alert', true));
        if (!$entry) throw ApiError::fromErrors($errors, 'The note could not be posted');
        Attachments::assertAttached($entry, $files);
        return Res::created(['entry' => Threading::entry($entry)]);
    }

    /** PATCH /tasks/{id}/notes/{entry} — edit an internal note of the task thread (same rules as tickets). */
    static function editNote(Request $req) {
        $task = $req->ctx['task'];
        list($applied, $current, $previous) = Threading::editNote($req, $task, $task->getThreadId(), $req->intParam('entry'));
        return Res::ok(['applied' => $applied, 'entry' => Threading::entry($current),
                        'superseded_entry_id' => $previous ? (int) $previous->getId() : null]);
    }

    static function reply(Request $req) {
        $task = $req->ctx['task'];
        $body = Threading::bodyFromRequest($req);
        $files = Attachments::resolve($req->input('file_ids'), $req->staff);
        $vars = ['response' => $body, 'staffId' => $req->staff->getId(), 'poster' => $req->staff,
                 'files' => Attachments::forCreate($files, $req->staff), 'ip_address' => RateLimit::ip($req)];
        $errors = [];
        $entry = $task->postReply($vars, $errors, Threading::boolInput($req, 'alert', false));
        if (!$entry) throw ApiError::fromErrors($errors, 'The reply could not be posted');
        Attachments::assertAttached($entry, $files);
        return Res::created(['entry' => Threading::entry($entry)]);
    }

    /** POST /tasks/{id}/status {status: open|closed, base: open|closed, comment?} */
    static function status(Request $req) {
        $t = $req->ctx['task'];
        $b = $req->json();
        $want = $b['status'] ?? null;
        if (!in_array($want, ['open', 'closed'], true)) throw ApiError::validation("'status' must be open or closed", 'status');
        $base = Ticketing::requireBase($req);
        if (!in_array($base, ['open', 'closed'], true)) throw ApiError::validation("'base' must be open or closed", 'base');
        // SCP: closing needs task.close; reopening is an edit.
        if ($want === 'closed' && !$t->checkStaffPerm($req->staff, 'task.close')) throw new ApiError('forbidden', 'Missing permission: task.close');
        if ($want === 'open' && !$t->checkStaffPerm($req->staff, 'task.edit') && !$t->checkStaffPerm($req->staff, 'task.close'))
            throw new ApiError('forbidden', 'Missing permission: task.edit or task.close');
        $cur = $t->isOpen() ? 'open' : 'closed';
        if (Ticketing::precondition($t, $cur, $base, $want, 'task_state') === 'noop')
            return Res::ok(['applied' => false, 'task' => self::dto($t)]);
        $errors = [];
        if (!$t->setStatus($want, (string) ($b['comment'] ?? ''), $errors))
            Ticketing::fail($errors, 'The status change was refused');
        return Res::ok(['applied' => true, 'task' => self::dto(\Task::lookup((int) $t->getId()))]);
    }

    /** POST /tasks/{id}/assignment {assignee:{type,id}, base, comment?, alert?} */
    static function assign(Request $req) {
        $t = $req->ctx['task'];
        $b = $req->json();
        $a = $b['assignee'] ?? null;
        if (!is_array($a) || !isset($a['type'], $a['id']) || !in_array($a['type'], ['staff', 'team'], true) || !ctype_digit((string) $a['id']))
            throw ApiError::validation("'assignee' must be {type: staff|team, id}", 'assignee');
        $token = ($a['type'] === 'staff' ? 's' : 't') . (int) $a['id'];
        $base = Ticketing::requireBase($req);
        $cur = $t->isOpen() && $t->getAssigneeId() ? $t->getAssigneeId() : null;
        if (!$t->isOpen()) throw new ApiError('conflict', 'The task is closed; reopen it first', null, ['reason' => 'task_closed']);
        if (Ticketing::precondition($t, $cur, $base, $token, 'assign') === 'noop')
            return Res::ok(['applied' => false, 'task' => self::dto($t)]);
        $form = $t->getAssignmentForm(['assignee' => [$token], 'comments' => (string) ($b['comment'] ?? '')],
            ['target' => $a['type'] === 'staff' ? 'agents' : 'teams']);
        if (!$form || !$form->isValid()) throw ApiError::fromErrors($form ? Ticketing::formErrors($form) : [], 'Invalid assignee');
        $errors = [];
        if (!$t->assign($form, $errors, Threading::boolInput($req, 'alert', false)))
            Ticketing::fail($errors, 'The assignment was refused');
        return Res::ok(['applied' => true, 'task' => self::dto(\Task::lookup((int) $t->getId()))]);
    }

    /** POST /tasks/{id}/transfer {dept_id, base, comment?, alert?} */
    static function transfer(Request $req) {
        $t = $req->ctx['task'];
        $b = $req->json();
        $dept = $b['dept_id'] ?? null;
        if (!(is_int($dept) || (is_string($dept) && ctype_digit($dept))) || !\Dept::lookup((int) $dept)) throw ApiError::validation('Unknown department', 'dept_id');
        $base = Ticketing::requireBase($req);
        if (Ticketing::precondition($t, (int) $t->getDeptId(), $base, (int) $dept, 'dept') === 'noop')
            return Res::ok(['applied' => false, 'task' => self::dto($t)]);
        $form = $t->getTransferForm(['dept' => [(int) $dept], 'comments' => (string) ($b['comment'] ?? '')]);
        if (!$form->isValid()) throw ApiError::fromErrors(Ticketing::formErrors($form), 'Invalid department');
        $errors = [];
        if (!$t->transfer($form, $errors, Threading::boolInput($req, 'alert', false)))
            Ticketing::fail($errors, 'The transfer was refused');
        return Res::ok(['applied' => true, 'task' => self::dto(\Task::lookup((int) $t->getId()))]);
    }

    /**
     * PUT /tasks/{id} {title?, due_at?, fields?:{name:scalar}, base:{same keys}, comment?}
     * Edits the task's own fields with per-field base values (validated as a whole before anything is applied).
     * The description is the first entry of the thread (an edit would create another entry): post a note instead.
     */
    static function update(Request $req) {
        $t = $req->ctx['task'];
        $b = $req->json();
        $want = [];
        foreach (['title', 'due_at'] as $k) if (array_key_exists($k, $b)) $want[$k] = $b[$k];
        if (isset($b['fields'])) {
            if (!is_array($b['fields']) || array_values($b['fields']) === $b['fields']) throw ApiError::validation("'fields' must be an object", 'fields');
            foreach ($b['fields'] as $k => $v) $want[(string) $k] = $v;
        }
        if (!$want) throw ApiError::validation('Nothing to update: send title, due_at or fields');
        if (!isset($b['base']) || !is_array($b['base'])) throw ApiError::validation("'base' is required: the current value of every field you change", 'base');
        $comment = (string) ($b['comment'] ?? '');
        global $cfg;

        $plans = []; $conflicts = [];
        foreach ($want as $key => $value) {
            if (!array_key_exists($key, $b['base'])) throw ApiError::validation("'base.$key' is required", "base.$key");
            $base = $b['base'][$key];
            if ($key === 'due_at') {
                $cur = Time::iso(Store::row('SELECT duedate FROM ' . TASK_TABLE . ' WHERE id=' . (int) $t->getId())['duedate'] ?? null);
                $desired = $value === null ? null : Time::iso(Time::toDb((string) $value));
                if ($value !== null && !$desired) throw ApiError::validation('Invalid ISO-8601 date', 'due_at');
                $bs = $base === null ? null : Time::iso(Time::toDb((string) $base));
                if (Ticketing::same($cur, $desired)) { $plans[$key] = ['noop' => true]; continue; }
                if (!Ticketing::same($cur, $bs)) { $conflicts[$key] = $cur; continue; }
                $field = $t->getField('duedate');
                $val = $desired ? (new \DateTime($desired))->setTimezone(new \DateTimeZone($cfg->getTimezone($req->staff)))->format('Y-m-d H:i:s') : '';
                $plans[$key] = ['noop' => false, 'field' => $field, 'value' => $val];
                continue;
            }
            if (is_array($value) || is_object($value)) throw ApiError::validation("'$key' must be a scalar", $key);
            $field = self::dynamicField($t, $key);
            if (!$field->isEditableToStaff()) throw new ApiError('forbidden', "Field '$key' cannot be edited");
            $ans = $field->getAnswer();
            $curText = $ans ? trim((string) $ans->toString()) : '';
            $ds = $value === null ? '' : (is_bool($value) ? ($value ? '1' : '0') : trim((string) $value));
            $bs = $base === null ? '' : (is_bool($base) ? ($base ? '1' : '0') : trim((string) $base));
            if (Ticketing::same($curText, $ds)) { $plans[$key] = ['noop' => true]; continue; }
            if (!Ticketing::same($curText, $bs)) { $conflicts[$key] = $curText; continue; }
            $plans[$key] = ['noop' => false, 'field' => $field, 'value' => $ds];
        }
        if ($conflicts)
            throw new ApiError('conflict', 'One or more fields changed on the server since you read them', null,
                ['current' => $conflicts, 'last_change' => Ticketing::lastChange($t, 'task_field')]);
        $todo = array_filter($plans, function ($p) { return !$p['noop']; });
        if (!$todo) return Res::ok(['applied' => false, 'changed' => [], 'task' => self::dto($t)]);

        $forms = []; $first = true;
        foreach ($todo as $key => $p) {
            $src = ['comments' => $first ? $comment : ''];
            foreach (array_filter([$p['field']->getFormName(), $p['field']->get('name'), $p['field']->get('id'), 'field']) as $k) $src[$k] = $p['value'];
            $form = $p['field']->getEditForm($src);
            if (!$form->isValid())
                throw ApiError::fromErrors(Ticketing::formErrors($form), 'Invalid value');
            $forms[$key] = $form; $first = false;
        }
        $done = [];
        foreach ($forms as $key => $form) {
            $errors = [];
            if (!$t->updateField($form, $errors)) {
                if (isset($errors['field']) && stripos((string) $errors['field'], 'already') !== false) continue;
                throw new ApiError('validation_failed', (string) ($errors['field'] ?? $errors['err'] ?? 'The task could not be updated'), $key,
                    ['failed_field' => $key, 'applied_before_failure' => $done]);
            }
            $done[] = $key;
        }
        return Res::ok(['applied' => (bool) $done, 'changed' => $done, 'task' => self::dto(\Task::lookup((int) $t->getId()), true)]);
    }

    private static function dynamicField(\Task $t, $key) {
        foreach (\DynamicFormEntry::forObject($t->getId(), 'A') as $entry) {
            $entry->addMissingFields();
            foreach ($entry->getFields() as $f) {
                if ((ctype_digit((string) $key) && (int) $f->get('id') === (int) $key) || (!ctype_digit((string) $key) && $f->get('name') === $key)) {
                    if (!$f->getAnswer() && $f->isStorable() && !$f->isPresentationOnly()) {
                        $a = new \DynamicFormEntryAnswer(['field_id' => $f->get('id'), 'entry' => $entry]);
                        $entry->answers->add($a); $a->save(); $f->setAnswer($a);
                    }
                    return $f;
                }
            }
        }
        throw new ApiError('not_found', "Field '$key' not found on this task");
    }

    // ------------------------------------------------------------------

    static function dto(\Task $t, $detail = false) {
        $r = Store::row('SELECT created, updated, closed, duedate, flags FROM ' . TASK_TABLE . ' WHERE id=' . (int) $t->getId()) ?: [];
        $dept = $t->getDept();
        $a = null;
        if ($t->isOpen() && $t->getStaffId() && ($s = $t->getStaff()))
            $a = ['type' => 'staff', 'id' => (int) $s->getId(), 'name' => $s->getName()->getOriginal(), 'token' => 's' . $s->getId()];
        elseif ($t->isOpen() && $t->getTeamId() && ($tm = $t->getTeam()))
            $a = ['type' => 'team', 'id' => (int) $tm->getId(), 'name' => $tm->getName(), 'token' => 't' . $tm->getId()];
        $d = [
            'id' => (int) $t->getId(), 'number' => (string) $t->getNumber(), 'title' => (string) $t->getTitle(),
            'state' => $t->isOpen() ? 'open' : 'closed',
            'dept' => $dept ? ['id' => (int) $dept->getId(), 'name' => $dept->getName()] : null,
            'assignee' => $a,
            'ticket_id' => ($t->ht['object_type'] ?? '') === 'T' ? (int) $t->ht['object_id'] : null,
            'created' => Time::iso($r['created'] ?? null), 'updated' => Time::iso($r['updated'] ?? null),
            'closed' => Time::iso($r['closed'] ?? null), 'due' => Time::iso($r['duedate'] ?? null),
            'is_overdue' => (bool) $t->isOverdue(),
        ];
        if ($detail) {
            $d['is_closeable'] = $t->isCloseable() === true;
            $d['thread_id'] = (int) $t->getThreadId();
        }
        return $d;
    }
}
