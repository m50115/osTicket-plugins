<?php
namespace OstWorkflow;

/**
 * Document identity for internal notes (OW-REQ-34, Architecture §Q). A "document" is a note that carries a
 * `documento.json` + PDFs; its identity is the `uuid` inside the JSON, not the thread entry id (an edit creates
 * another entry). The note may declare `document:{uuid, version, supersedes?}`; the plugin stamps the note body with
 * one marker line `BCW_DOC:<uuid>@<version>[^<supersedes>]` (see marker()). There is NO index: the thread entries
 * themselves are the record and are read through the native ORM, so that:
 *   - a retry of the same document version on the same ticket finds the existing note instead of duplicating;
 *   - GET /documents/{uuid} and GET /tickets/{id}/documents list every version and the chain of supersedes.
 * Only visible (not edited-away) internal notes count; the marker is carried over when a note is edited.
 */
final class Documents {
    const UUID = '/^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$/';

    /** @return array|null {uuid, version, supersedes|null} */
    static function fromRequest(Request $req) {
        $d = $req->input('document');
        if ($d === null) return null;
        if (!is_array($d)) throw ApiError::validation("'document' must be {uuid, version, supersedes?}", 'document');
        $uuid = isset($d['uuid']) && is_string($d['uuid']) ? strtolower($d['uuid']) : '';
        if (!preg_match(self::UUID, $uuid)) throw ApiError::validation("'document.uuid' must be a UUID", 'document.uuid');
        $v = $d['version'] ?? null;
        if (!is_int($v) || $v < 1 || $v > 9999) throw ApiError::validation("'document.version' must be an integer between 1 and 9999", 'document.version');
        $sup = $d['supersedes'] ?? null;
        if ($sup !== null && (!is_int($sup) || $sup < 1 || $sup >= $v))
            throw ApiError::validation("'document.supersedes' must be an earlier version number of the same document", 'document.supersedes');
        return ['uuid' => $uuid, 'version' => $v, 'supersedes' => $sup];
    }

    const MARK = 'BCW_DOC:';
    const MARK_RE = '/BCW_DOC:([0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12})@(\d{1,4})(?:\^(\d{1,4}))?/i';
    const SEARCH_MAX = 200;   // entries inspected per lookup

    /** HTML line appended to the body of a document note. */
    static function marker(array $d) {
        return '<div>' . self::MARK . $d['uuid'] . '@' . $d['version'] . ($d['supersedes'] ? '^' . $d['supersedes'] : '') . '</div>';
    }

    /** Marker of a stored body (first one), or null. @return array|null {uuid, version, supersedes|null} */
    static function ofBody($html) {
        if (!preg_match(self::MARK_RE, (string) $html, $m)) return null;
        return ['uuid' => strtolower($m[1]), 'version' => (int) $m[2], 'supersedes' => isset($m[3]) && $m[3] !== '' ? (int) $m[3] : null];
    }

    /** Visible internal notes whose body carries `$needle`, newest first. @return \ThreadEntry[] */
    private static function entries($needle, $threadId = null) {
        $q = \ThreadEntry::objects()->filter(['type' => 'N', 'body__contains' => $needle]);
        if ($threadId !== null) $q->filter(['thread_id' => (int) $threadId]);
        $out = [];
        foreach ($q->order_by('-id')->limit(self::SEARCH_MAX) as $e)
            if (!$e->hasFlag(\ThreadEntry::FLAG_HIDDEN)) $out[] = $e;
        return $out;
    }

    private static function record(\ThreadEntry $e, array $mk, $ticketId) {
        return ['version' => $mk['version'], 'supersedes' => $mk['supersedes'], 'entry_id' => (int) $e->getId(),
                'ticket_id' => (int) $ticketId, 'created' => Time::iso($e->created)];
    }

    /** @return int|null ticket id of the thread (null when it is not a ticket thread) */
    private static function ticketOfThread($threadId, array &$cache) {
        if (!array_key_exists($threadId, $cache)) {
            $t = \Thread::lookup((int) $threadId);
            $cache[$threadId] = ($t && $t->getObjectType() === 'T') ? (int) $t->getObjectId() : null;
        }
        return $cache[$threadId];
    }

    /** One version: {entry_id, ticket_id, created, ...}. With $ticketId the search stays inside that ticket's thread. */
    static function find(array $d, $version = null, $ticketId = null) {
        $v = (int) ($version ?? $d['version']);
        $threadId = null;
        if ($ticketId !== null) {
            $t = \Ticket::lookup((int) $ticketId);
            if (!$t) return null;
            $threadId = $t->getThreadId();
        }
        $cache = [];
        foreach (array_reverse(self::entries(self::MARK . $d['uuid'] . '@' . $v, $threadId)) as $e) {   // oldest first: the first writer wins
            $mk = self::ofBody($e->getBody());
            if (!$mk || $mk['uuid'] !== strtolower($d['uuid']) || $mk['version'] !== $v) continue;
            $tid = self::ticketOfThread($e->thread_id, $cache);
            if ($tid === null) continue;
            return self::record($e, $mk, $tid) + ['entry_id' => (int) $e->getId()];
        }
        return null;
    }

    /** Before posting: is this version already on the ticket? Validates the chain. Returns the existing record or null. */
    static function check(array $d, \Ticket $ticket) {
        if (($ex = self::find($d, null, $ticket->getId())))
            return $ex;
        if ($d['supersedes'] !== null && !self::find($d, $d['supersedes'], $ticket->getId()))
            throw new ApiError('conflict', 'The version it supersedes does not exist on this ticket', 'document.supersedes',
                ['reason' => 'missing_previous_version', 'supersedes' => $d['supersedes']]);
        return null;
    }

    /** All versions of a document across tickets: [{version, supersedes, entry_id, ticket_id, created}] ascending (bounded search). */
    static function versions($uuid) {
        $uuid = strtolower($uuid);
        $out = []; $cache = [];
        foreach (array_reverse(self::entries(self::MARK . $uuid . '@')) as $e) {
            $mk = self::ofBody($e->getBody());
            if (!$mk || $mk['uuid'] !== $uuid || isset($out[$mk['version']])) continue;
            if (($tid = self::ticketOfThread($e->thread_id, $cache)) === null) continue;
            $out[$mk['version']] = self::record($e, $mk, $tid);
        }
        ksort($out);
        return array_values($out);
    }

    /** Documents of one ticket: uuid => versions. */
    static function ofTicket($ticketId) {
        $t = \Ticket::lookup((int) $ticketId);
        if (!$t) return [];
        $by = [];
        foreach (array_reverse(self::entries(self::MARK, $t->getThreadId())) as $e) {
            $mk = self::ofBody($e->getBody());
            if (!$mk || isset($by[$mk['uuid']][$mk['version']])) continue;
            $by[$mk['uuid']][$mk['version']] = self::record($e, $mk, $ticketId);
        }
        foreach ($by as $u => $v) { ksort($v); $by[$u] = array_values($v); }
        return $by;
    }
}
