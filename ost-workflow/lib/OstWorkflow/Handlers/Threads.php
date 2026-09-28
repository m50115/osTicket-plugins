<?php
namespace OstWorkflow\Handlers;

use OstWorkflow\ApiError;
use OstWorkflow\Attachments;
use OstWorkflow\RateLimit;
use OstWorkflow\Request;
use OstWorkflow\Res;
use OstWorkflow\Threading;

/**
 * Ticket thread: composite activity feed (entries + events), public replies,
 * internal notes and late attachments on a note.
 * Every write runs through Pipeline (auth -> $thisstaff -> idempotency -> Policy).
 */
final class Threads {
    static function routes() {
        return [
            ['GET',  '/tickets/(?P<id>\d+)/activity', 'activity', ['policy' => 'ticket.view']],
            ['POST', '/tickets/(?P<id>\d+)/replies',  'reply',    ['policy' => 'ticket.reply']],
            ['POST', '/tickets/(?P<id>\d+)/notes',    'note',     ['policy' => 'ticket.view']],
            ['POST', '/tickets/(?P<id>\d+)/notes/(?P<entry>\d+)/files', 'noteFiles', ['policy' => 'ticket.view']],
        ];
    }

    // ------------------------------------------------------------------
    // GET /tickets/{id}/activity?after_entry=&after_event=&limit=&include_hidden=
    // Two id cursors (entries and events have separate id spaces; ids, not
    // dates, because an edit creates a row with an old `created`, R-C26).
    // ------------------------------------------------------------------
    static function activity(Request $req) {
        Attachments::boot();
        $ticket = $req->ctx['ticket'];
        $thread = $ticket->getThread();
        if (!$thread)
            throw ApiError::notFound('thread');

        $limit = $req->intQuery('limit', 50, 1, 200);
        $afterEntry = $req->intQuery('after_entry', 0, 0);
        $afterEvent = $req->intQuery('after_event', 0, 0);
        $ih = $req->q('include_hidden', '0');
        if (!in_array($ih, ['0', '1', 'true', 'false'], true))
            throw ApiError::validation("'include_hidden' must be 0/1/true/false", 'include_hidden');
        $includeHidden = ($ih === '1' || $ih === 'true');

        $eq = \ThreadEntry::objects()
            ->filter(['thread_id' => $thread->getId(), 'id__gt' => $afterEntry])
            ->order_by('id')->limit($limit + 1);
        if (!$includeHidden)
            $eq = $eq->exclude(['flags__hasbit' => \ThreadEntry::FLAG_HIDDEN]);
        $entries = [];
        foreach ($eq as $e)
            $entries[] = $e;

        $vq = \ThreadEvent::objects()
            ->filter(['thread_id' => $thread->getId(), 'id__gt' => $afterEvent])
            ->exclude(['event_id' => \Event::getIdByName('viewed')])
            ->order_by('id')->limit($limit + 1);
        $events = [];
        foreach ($vq as $v)
            $events[] = $v;

        $moreEntries = count($entries) > $limit;
        $moreEvents = count($events) > $limit;
        $entries = array_slice($entries, 0, $limit);
        $events = array_slice($events, 0, $limit);

        $items = [];
        $lastEntry = $afterEntry;
        foreach ($entries as $e) {
            $items[] = Threading::entry($e);
            $lastEntry = max($lastEntry, (int) $e->getId());
        }
        $lastEvent = $afterEvent;
        foreach ($events as $v) {
            $items[] = Threading::event($v);
            $lastEvent = max($lastEvent, (int) $v->id);
        }
        // Display order: time, entries before events on ties, then id.
        usort($items, function ($a, $b) {
            $c = strcmp((string) $a['created'], (string) $b['created']);
            if ($c) return $c;
            if ($a['kind'] !== $b['kind']) return $a['kind'] === 'entry' ? -1 : 1;
            return $a['id'] <=> $b['id'];
        });

        return Res::ok($items, [
            'count'        => count($items),
            'ticket_id'    => (int) $ticket->getId(),
            'thread_id'    => (int) $thread->getId(),
            'next_cursor'  => ['after_entry' => $lastEntry, 'after_event' => $lastEvent],
            'has_more'     => $moreEntries || $moreEvents,
        ]);
    }

    // ------------------------------------------------------------------
    // POST /tickets/{id}/replies  — public reply to the customer
    // {body, body_format?, notify: all|user|none (REQUIRED), claim?: bool (default false),
    //  signature?: none|mine|dept, file_ids?: [int], status_id?: int}
    // ------------------------------------------------------------------
    static function reply(Request $req) {
        $ticket = $req->ctx['ticket'];
        $staff = $req->staff;

        $body = Threading::bodyFromRequest($req);
        $notify = $req->input('notify');
        if (!in_array($notify, ['all', 'user', 'none'], true))
            throw ApiError::validation("'notify' is required: all, user or none", 'notify');
        $claim = Threading::boolInput($req, 'claim', false);
        $signature = $req->input('signature', 'none');
        if (!in_array($signature, ['none', 'mine', 'dept'], true))
            throw ApiError::validation("'signature' must be none, mine or dept", 'signature');
        $files = Attachments::resolve($req->input('file_ids'), $staff);
        $statusId = $req->input('status_id') !== null
            ? Threading::authorizeStatus($ticket, $staff, $req->input('status_id'), 'status_id') : null;

        // Checks the SCP controller does and the core does not (inventory row 14).
        require_once(INCLUDE_DIR . 'class.banlist.php');
        if ($ticket->isChild() && $ticket->getMergeType() != 'visual')
            throw new ApiError('conflict', 'This ticket is merged into another ticket; reply on the parent',
                null, ['reason' => 'merged_child', 'parent_id' => (int) $ticket->getPid()]);
        if (\Banlist::isBanned($ticket->getEmail()))
            throw new ApiError('conflict', 'The contact email is banned; remove it from the ban list to reply',
                null, ['reason' => 'email_banned']);

        $vars = [
            'response'   => $body,
            'staffId'    => $staff->getId(),
            'poster'     => $staff,
            'reply-to'   => $notify,
            'ccs'        => [],
            'files'      => Attachments::forCreate($files, $staff),
            'signature'  => $signature,
            'ip_address' => RateLimit::ip($req),
        ];
        if ($statusId !== null)
            $vars['reply_status_id'] = $statusId;

        $before = Threading::snapshot($ticket);
        $errors = [];
        $entry = $ticket->postReply($vars, $errors, $notify !== 'none', $claim);
        if (!$entry)
            throw self::coreError($errors, 'The reply could not be posted');
        Attachments::assertAttached($entry, $files);

        $effects = Threading::effects($before, Threading::snapshot($ticket));
        $effects['notify'] = $notify;
        $effects['claim_requested'] = $claim;
        $effects['claimed'] = $effects['assignee_changed'] && ($effects['assignee']['type'] ?? null) === 'staff'
            && ($effects['assignee']['id'] ?? null) === (int) $staff->getId();
        return Res::created(['entry' => Threading::entry($entry), 'effects' => $effects]);
    }

    // ------------------------------------------------------------------
    // POST /tickets/{id}/notes — internal note (only ticket access is required, like the SCP)
    // {body, body_format?, title?, file_ids?: [int], note_status_id?: int, alert?: bool (default true)}
    // ------------------------------------------------------------------
    static function note(Request $req) {
        $ticket = $req->ctx['ticket'];
        $staff = $req->staff;

        $body = Threading::bodyFromRequest($req);
        $title = Threading::title($req);
        $alert = Threading::boolInput($req, 'alert', true);
        $files = Attachments::resolve($req->input('file_ids'), $staff);
        $statusId = $req->input('note_status_id') !== null
            ? Threading::authorizeStatus($ticket, $staff, $req->input('note_status_id'), 'note_status_id') : null;

        $vars = [
            'note'       => $body,
            'title'      => $title,
            'staffId'    => $staff->getId(),
            'files'      => Attachments::forCreate($files, $staff),
            'ip_address' => RateLimit::ip($req),
        ];
        if ($statusId !== null)
            $vars['note_status_id'] = $statusId;

        $before = Threading::snapshot($ticket);
        $errors = [];
        $entry = $ticket->postNote($vars, $errors, $staff, $alert);
        if (!$entry)
            throw self::coreError($errors, 'The note could not be posted');
        Attachments::assertAttached($entry, $files);

        $effects = Threading::effects($before, Threading::snapshot($ticket));
        $effects['alert'] = $alert;
        return Res::created(['entry' => Threading::entry($entry), 'effects' => $effects]);
    }

    // ------------------------------------------------------------------
    // POST /tickets/{id}/notes/{entry}/files — attach uploaded files to an existing note
    // {file_ids: [int]}  (ThreadEntry::createAttachments, class.thread.php:1235-1272)
    // ------------------------------------------------------------------
    static function noteFiles(Request $req) {
        $ticket = $req->ctx['ticket'];
        $staff = $req->staff;
        $thread = $ticket->getThread();

        $entry = \ThreadEntry::lookup($req->intParam('entry'));
        if (!$entry || !$thread || (int) $entry->getThreadId() !== (int) $thread->getId())
            throw ApiError::notFound('entry');
        if ($entry->getType() !== 'N')
            throw ApiError::validation('Files can only be added to internal notes', 'entry');

        // Adding to an entry is an edit of it: author, department manager or thread.edit.
        $own = (int) $entry->getStaffId() === (int) $staff->getId();
        if (!$own && !$ticket->checkStaffPerm($staff, \ThreadEntry::PERM_EDIT) && !$staff->isManager($ticket->getDept()))
            throw new ApiError('forbidden', 'Only the author, a department manager or an agent with thread.edit can add files to this note');

        $files = Attachments::resolve($req->input('file_ids'), $staff);
        if (!$files)
            throw ApiError::validation("'file_ids' must list at least one file", 'file_ids');

        $have = Attachments::attachedIds($entry);
        $new = 0;
        foreach ($files as $f)
            if (!in_array((int) $f->getId(), $have, true)) $new++;
        if (count($have) + $new > Attachments::maxFiles())
            throw new ApiError('validation_failed', 'A note holds at most ' . Attachments::maxFiles() . ' files (continue in a new note)',
                'file_ids', ['max_files' => Attachments::maxFiles(), 'attached' => count($have)]);

        Attachments::attachAll($entry, $files, $staff);
        return Res::created(['entry' => Threading::entry(\ThreadEntry::lookup($entry->getId())),
                             'attached_file_ids' => array_map(function ($f) { return (int) $f->getId(); }, $files)]);
    }

    /** osTicket `$errors` -> typed validation error, renaming core keys to API field names. */
    private static function coreError(array $errors, $fallback) {
        $map = ['response' => 'body', 'note' => 'body', 'message' => 'body', 'files' => 'file_ids', 'attachments' => 'file_ids'];
        $out = [];
        foreach ($errors as $k => $v)
            $out[is_string($k) && isset($map[$k]) ? $map[$k] : $k] = $v;
        return ApiError::fromErrors($out, $fallback);
    }
}
