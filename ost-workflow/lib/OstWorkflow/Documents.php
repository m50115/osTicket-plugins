<?php
namespace OstWorkflow;

/**
 * Document identity for internal notes (OW-REQ-34, Architecture §Q). A "document" is a note that carries a
 * `documento.json` + PDFs; its identity is the `uuid` inside the JSON, not the thread entry id (an edit creates
 * another entry). The note may declare `document:{uuid, version, supersedes?}`; the plugin remembers
 * (uuid, version) -> entry in its plumbing table (kind='doc', global) so that:
 *   - a retry after a cut that the idempotency record cannot answer adopts the existing note instead of duplicating;
 *   - GET /documents/{uuid} and GET /tickets/{id}/documents list every version and the chain of supersedes.
 * The source of truth stays the note (and its JSON); this only indexes it.
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

    private static function key(array $d, $version = null) { return 'doc:' . $d['uuid'] . '@' . ($version ?? $d['version']); }

    static function find(array $d, $version = null) {
        $r = Store::row('SELECT resource_id, route, created FROM ' . Store::table() . ' WHERE kind=\'doc\' AND staff_id=0 AND idem_key=' . Store::esc(self::key($d, $version)));
        return $r ? ['entry_id' => (int) $r['resource_id'], 'ticket_id' => (int) substr($r['route'], 1), 'created' => Time::iso($r['created'])] : null;
    }

    /** Before posting: is this version already there? Validates the chain. Returns the existing record or null. */
    static function check(array $d, \Ticket $ticket) {
        if (($ex = self::find($d))) {
            if ($ex['ticket_id'] !== (int) $ticket->getId())
                throw new ApiError('conflict', 'This document version already exists on another ticket', 'document', ['reason' => 'document_on_other_ticket']);
            return $ex;
        }
        if ($d['supersedes'] !== null) {
            $prev = self::find($d, $d['supersedes']);
            if (!$prev || $prev['ticket_id'] !== (int) $ticket->getId())
                throw new ApiError('conflict', 'The version it supersedes does not exist on this ticket', 'document.supersedes',
                    ['reason' => 'missing_previous_version', 'supersedes' => $d['supersedes']]);
        }
        return null;
    }

    /** After posting: index the version (INSERT IGNORE: the first writer wins). */
    static function record(array $d, \Ticket $ticket, \ThreadEntry $entry) {
        Store::q('INSERT IGNORE INTO ' . Store::table() . ' SET kind=\'doc\', staff_id=0, idem_key=' . Store::esc(self::key($d))
            . ', route=' . Store::esc('T' . (int) $ticket->getId()) . ', status=\'done\', resource_type=\'entry\', resource_id=' . Store::esc((string) $entry->getId())
            . ', counter=' . ($d['supersedes'] ?? 0) . ', created=NOW()');
    }

    /** All versions of a document: [{version, supersedes, entry_id, ticket_id, created}] ascending. */
    static function versions($uuid) {
        $out = [];
        $q = Store::q('SELECT idem_key, resource_id, route, counter, created FROM ' . Store::table() . ' WHERE kind=\'doc\' AND staff_id=0 AND idem_key LIKE '
            . Store::esc('doc:' . $uuid . '@%'));
        while ($q && ($r = db_fetch_array($q))) {
            $out[] = ['version' => (int) substr($r['idem_key'], strrpos($r['idem_key'], '@') + 1), 'supersedes' => $r['counter'] ? (int) $r['counter'] : null,
                      'entry_id' => (int) $r['resource_id'], 'ticket_id' => (int) substr($r['route'], 1), 'created' => Time::iso($r['created'])];
        }
        usort($out, function ($a, $b) { return $a['version'] <=> $b['version']; });
        return $out;
    }

    /** Documents of one ticket: uuid => versions. */
    static function ofTicket($ticketId) {
        $out = [];
        $q = Store::q('SELECT idem_key FROM ' . Store::table() . ' WHERE kind=\'doc\' AND staff_id=0 AND route=' . Store::esc('T' . (int) $ticketId));
        $uuids = [];
        while ($q && ($r = db_fetch_array($q))) $uuids[substr($r['idem_key'], 4, 36)] = true;
        foreach (array_keys($uuids) as $u) $out[$u] = self::versions($u);
        return $out;
    }
}
