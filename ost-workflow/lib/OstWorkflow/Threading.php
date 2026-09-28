<?php
namespace OstWorkflow;

/**
 * Thread helpers: normalized entry/event DTOs, body input validation, the
 * status-change permission rule and the "effects" snapshot of a write.
 * All times UTC ISO-8601 (RC-14); audience computed server-side (CAP-AUD).
 */
final class Threading {
    const MAX_BODY_CHARS = 60000;
    const MAX_TITLE_CHARS = 200;

    /** Event states the customer portal renders (include/client/templates/thread-entries.tmpl.php). */
    const CLIENT_EVENT_STATES = ['created', 'closed', 'reopened', 'edited', 'collab', 'merged'];

    // ------------------------------------------------------------------
    // Input
    // ------------------------------------------------------------------

    /**
     * Build a ThreadEntryBody from request input. `body` is required;
     * `body_format` is 'text' (default; escaped, newlines kept) or 'html' (sanitized by osTicket).
     */
    static function bodyFromRequest(Request $req, $field = 'body') {
        $raw = $req->input($field);
        if (!is_string($raw) || trim($raw) === '')
            throw ApiError::validation("'$field' is required", $field);
        if (mb_strlen($raw) > self::MAX_BODY_CHARS)
            throw new ApiError('validation_failed', "'$field' exceeds " . self::MAX_BODY_CHARS . ' characters', $field,
                ['max_chars' => self::MAX_BODY_CHARS]);
        $format = $req->input('body_format', 'text');
        if (!in_array($format, ['text', 'html'], true))
            throw ApiError::validation("'body_format' must be 'text' or 'html'", 'body_format');
        if ($format === 'text') {
            $raw = str_replace(["\r\n", "\r"], "\n", $raw);
            $raw = self::textToHtml($raw);
        } else {
            $raw = self::protectLt($raw);
        }
        return new \HtmlThreadEntryBody($raw);
    }

    /**
     * Plain text -> HTML that survives Format::safe_html() unchanged in meaning.
     * safe_html reduces one level of entity escaping for entity-like sequences
     * ("&amp;lt;" -> "&lt;", "&amp;amp;" -> "&amp;"), so those get one extra
     * level here, and a real "<" is double-encoded (see protectLt()).
     */
    static function textToHtml($text) {
        $h = htmlspecialchars($text, ENT_NOQUOTES, 'UTF-8');
        $h = preg_replace('/&amp;(?=#?[A-Za-z0-9]+;)/', '&amp;amp;', $h);
        $h = str_replace('&lt;', '&amp;lt;', $h);
        return '<div>' . nl2br($h, false) . '</div>';
    }

    /**
     * osTicket's Format::safe_html() (run by HtmlThreadEntryBody::getClean) decodes
     * an entity-encoded "<" and then drops everything up to the next tag, so
     * "<p>1 &lt; 2</p>" is stored as "<p>1 </p>". Double-encoding only that
     * character ("&amp;lt;") survives sanitizing as "&lt;", which renders "<".
     * Plain-text input is escaped here and stored as HTML for the same reason
     * (the core text path uses html_balance and truncates at "<").
     */
    static function protectLt($html) {
        return preg_replace(['/&lt;/i', '/&#0*60;/', '/&#x0*3c;/i'], '&amp;lt;', $html);
    }

    static function title(Request $req) {
        $t = $req->input('title');
        if ($t === null || $t === '') return '';
        if (!is_string($t) || mb_strlen($t) > self::MAX_TITLE_CHARS)
            throw ApiError::validation("'title' must be a string of at most " . self::MAX_TITLE_CHARS . ' characters', 'title');
        return $t;
    }

    static function boolInput(Request $req, $field, $default) {
        $v = $req->input($field);
        if ($v === null) return $default;
        if (!is_bool($v))
            throw ApiError::validation("'$field' must be true or false", $field);
        return $v;
    }

    // ------------------------------------------------------------------
    // Status-change rule (shared by note_status_id / reply_status_id)
    // ------------------------------------------------------------------

    /**
     * Same rule as POST /status: closing needs ticket.close (and the ticket
     * must be closeable); reopening/other transitions need ticket.close OR
     * ticket.create; deleting is never exposed. osTicket itself does NOT check
     * this for note_status_id / reply_status_id (class.ticket.php:3543-3548).
     * @return int|null status id to apply, null when it is already the status
     */
    static function authorizeStatus(\Ticket $ticket, \Staff $staff, $statusId, $field) {
        if (!is_int($statusId) && !(is_string($statusId) && ctype_digit($statusId)))
            throw ApiError::validation("'$field' must be a status id", $field);
        $status = \TicketStatus::lookup((int) $statusId);
        if (!$status || !$status->isEnabled())
            throw ApiError::validation('Unknown or disabled status', $field);
        if ((int) $status->getId() === (int) $ticket->getStatusId())
            return null;
        $state = $status->getState();
        if ($state === 'deleted')
            throw ApiError::forbidden('Deleting tickets through the API is not allowed');
        if ($state === 'closed' || $state === 'archived') {
            if (!$ticket->checkStaffPerm($staff, 'ticket.close'))
                throw new ApiError('forbidden', 'Missing permission: ticket.close');
            $c = $ticket->isCloseable();
            if ($c !== true)
                throw new ApiError('not_closeable', is_string($c) ? $c : 'The ticket cannot be closed yet');
        } elseif (!$ticket->checkStaffPerm($staff, 'ticket.close') && !$ticket->checkStaffPerm($staff, 'ticket.create')) {
            throw new ApiError('forbidden', 'Missing permission: ticket.close or ticket.create');
        }
        return (int) $status->getId();
    }

    // ------------------------------------------------------------------
    // CC handling for replies (mirrors scp/tickets.php:200-218)
    // ------------------------------------------------------------------

    /**
     * Validate `cc`: null = leave the collaborators as they are; a list of contact user ids = the reply is
     * copied to exactly those (new ones become collaborators, the others are set inactive, like the SCP).
     * @return int[]|null
     */
    static function ccFromRequest(Request $req) {
        $cc = $req->input('cc');
        if ($cc === null) return null;
        if (!is_array($cc) || array_values($cc) !== $cc)
            throw ApiError::validation("'cc' must be a list of contact user ids", 'cc');
        $ids = [];
        foreach ($cc as $v) {
            if (!(is_int($v) || (is_string($v) && ctype_digit($v))) || !\User::lookup((int) $v))
                throw ApiError::validation('Unknown contact in cc', 'cc');
            $ids[(int) $v] = (int) $v;
        }
        return array_values($ids);
    }

    /** Applies the collaborator changes; returns {added, activated, deactivated} (contact ids). */
    static function applyCc(\Ticket $ticket, array $ids) {
        $eff = ['added' => [], 'activated' => [], 'deactivated' => []];
        $errors = [];
        $had = [];
        foreach ($ticket->getCollaborators() as $c) $had[(int) $c->getUserId()] = true;
        if ($ids) {
            foreach ((array) $ticket->addCollaborators($ids, ['isactive' => 1], $errors) as $c)
                $eff['added'][] = (int) $c->getUserId();
        }
        foreach ($ticket->getCollaborators() as $c) {
            $uid = (int) $c->getUserId();
            if (!$c->isActive() && in_array($uid, $ids, true)) {
                $c->setFlag(\Collaborator::FLAG_ACTIVE, true); $c->save(); $eff['activated'][] = $uid;
            } elseif ($c->isActive() && !in_array($uid, $ids, true)) {
                $c->setFlag(\Collaborator::FLAG_ACTIVE, false); $c->save(); $eff['deactivated'][] = $uid;
            }
        }
        unset($ticket->active_collaborators);
        $ticket->collaborators = null;
        return $eff;
    }

    // ------------------------------------------------------------------
    // Effects snapshot
    // ------------------------------------------------------------------

    /** Ticket columns that a write may change as a side effect. */
    static function snapshot(\Ticket $t) {
        $r = Store::row('SELECT status_id, staff_id, team_id, dept_id, isanswered FROM ' . TICKET_TABLE
            . ' WHERE ticket_id=' . (int) $t->getId());
        return $r ?: [];
    }

    static function effects(array $before, array $after) {
        $status = null;
        if (($after['status_id'] ?? null) !== null && ($before['status_id'] ?? null) != $after['status_id']) {
            $s = \TicketStatus::lookup((int) $after['status_id']);
            $status = ['id' => (int) $after['status_id'], 'name' => $s ? $s->getName() : null, 'state' => $s ? $s->getState() : null,
                       'previous_id' => isset($before['status_id']) ? (int) $before['status_id'] : null];
        }
        $assignee = null;
        $changed = ($before['staff_id'] ?? 0) != ($after['staff_id'] ?? 0) || ($before['team_id'] ?? 0) != ($after['team_id'] ?? 0);
        if ($changed) {
            if (!empty($after['staff_id']) && ($st = \Staff::lookup((int) $after['staff_id'])))
                $assignee = ['type' => 'staff', 'id' => (int) $st->getId(), 'name' => $st->getName()->getOriginal()];
            elseif (!empty($after['team_id']) && ($tm = \Team::lookup((int) $after['team_id'])))
                $assignee = ['type' => 'team', 'id' => (int) $tm->getId(), 'name' => $tm->getName()];
        }
        return [
            'status_changed'   => $status !== null,
            'status'           => $status,
            'assignee_changed' => $changed,
            'assignee'         => $assignee,
        ];
    }

    // ------------------------------------------------------------------
    // DTOs
    // ------------------------------------------------------------------

    /** @return array normalized thread entry */
    static function entry(\ThreadEntry $e) {
        $type = $e->getType();
        $b = $e->getBody();
        $html = (string) $b->toHtml();
        $format = $e->format ?: 'text';
        $recipients = null;
        if ($e->recipients) {
            $d = json_decode($e->recipients, true);
            $recipients = is_array($d) ? $d : null;
        }
        $scope = null;
        if ($type === 'R') {
            if ($e->hasFlag(\ThreadEntry::FLAG_REPLY_ALL)) $scope = 'all';
            elseif ($e->hasFlag(\ThreadEntry::FLAG_REPLY_USER)) $scope = 'user';
        }
        $edited = $e->hasFlag(\ThreadEntry::FLAG_EDITED);
        $editor = null;
        if ($edited && ($ed = $e->getEditor()))
            $editor = ['type' => $e->editor_type === 'S' ? 'staff' : 'user', 'id' => (int) $ed->getId(), 'name' => self::personName($ed)];

        return [
            'kind'        => 'entry',
            'id'          => (int) $e->getId(),
            'type'        => $type,
            'type_name'   => $e->getTypeName(),
            'audience'    => $type === 'N' ? 'internal' : 'customer',
            'created'     => Time::iso($e->created),
            'updated'     => Time::iso($e->updated),
            'actor'       => self::entryActor($e),
            'title'       => $e->getTitle() ?: null,
            'body'        => $html,
            'body_text'   => self::htmlToText($html),
            'body_format' => $format,
            'attachments' => Attachments::ofEntry($e),
            'supersedes'  => ($edited && $e->getPid()) ? (int) $e->getPid() : null,
            'reply_to_entry' => (!$edited && $e->getPid()) ? (int) $e->getPid() : null,
            'hidden'      => $e->hasFlag(\ThreadEntry::FLAG_HIDDEN),
            'edited'      => $edited,
            'editor'      => $editor,
            'system'      => $e->isSystem(),
            'source'      => $e->getSource() ?: null,
            'reply_scope' => $scope,
            'recipients'  => $recipients,
        ];
    }

    /**
     * HTML -> plain text, decoding entities exactly once at the end. (Format::html2text
     * decodes first and then strips tags, so an escaped "&lt;script&gt;" would be
     * treated as markup and vanish.)
     */
    static function htmlToText($html) {
        $t = preg_replace('#<a\s[^>]*href=(["\'])(.*?)\1[^>]*>(.*?)</a>#is', '$3 ($2)', (string) $html);
        $t = preg_replace('#<br\s*/?>#i', "\n", $t);
        $t = preg_replace('#<li[^>]*>#i', "\n- ", $t);
        $t = preg_replace('#</(p|div|li|tr|h[1-6]|blockquote|pre|ul|ol|table)>#i', "\n", $t);
        $t = strip_tags($t);
        $t = html_entity_decode($t, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $t = preg_replace('/[ \t\x{00A0}]+/u', ' ', $t);
        $t = preg_replace('/ ?\n ?/', "\n", $t);
        $t = preg_replace("/\n{3,}/", "\n\n", $t);
        return trim($t);
    }

    static function personName($p) {
        return self::nameOf($p->getName());
    }

    /** Name objects (PersonsName) and plain strings => string. */
    static function nameOf($n) {
        return is_object($n) && method_exists($n, 'getOriginal') ? (string) $n->getOriginal() : (string) $n;
    }

    static function entryActor(\ThreadEntry $e) {
        if ($e->getStaffId() && ($s = $e->getStaff()))
            return ['type' => 'staff', 'id' => (int) $s->getId(), 'name' => self::personName($s)];
        if ($e->getUserId() && ($u = $e->getUser()))
            return ['type' => 'user', 'id' => (int) $u->getId(), 'name' => self::personName($u)];
        return ['type' => 'system', 'id' => null, 'name' => $e->getPoster() ?: 'SYSTEM'];
    }

    /** Text version of an event description (HTML template stripped). */
    private static function plain($html) {
        $t = html_entity_decode(strip_tags((string) $html), ENT_QUOTES, 'UTF-8');
        return trim(preg_replace('/\s+/u', ' ', $t));
    }

    /** @return array normalized thread event */
    static function event(\ThreadEvent $ev) {
        $state = \Event::getNameById($ev->event_id);
        $ev->state = $state;
        $typed = $ev->getTypedEvent();
        $staffDesc = self::plain($typed->getDescription(\ThreadEvent::MODE_STAFF));
        $customer = false;
        if (in_array($state, self::CLIENT_EVENT_STATES, true))
            $customer = self::plain($typed->getDescription(\ThreadEvent::MODE_CLIENT)) !== '';

        if ($ev->uid && $ev->uid_type === 'S')
            $actor = ['type' => 'staff', 'id' => (int) $ev->uid, 'name' => self::nameOf($ev->getUserName())];
        elseif ($ev->uid && $ev->uid_type === 'U')
            $actor = ['type' => 'user', 'id' => (int) $ev->uid, 'name' => self::nameOf($ev->getUserName())];
        else
            $actor = ['type' => 'system', 'id' => null, 'name' => $ev->username ?: 'SYSTEM'];

        return [
            'kind'        => 'event',
            'id'          => (int) $ev->id,
            'state'       => $state,
            'audience'    => $customer ? 'customer' : 'internal',
            'created'     => Time::iso($ev->timestamp),
            'actor'       => $actor,
            'description' => $staffDesc,
            'data'        => $ev->getData() ?: null,
            'staff_id'    => $ev->staff_id ? (int) $ev->staff_id : null,
            'team_id'     => $ev->team_id ? (int) $ev->team_id : null,
            'dept_id'     => $ev->dept_id ? (int) $ev->dept_id : null,
            'topic_id'    => $ev->topic_id ? (int) $ev->topic_id : null,
        ];
    }
}
