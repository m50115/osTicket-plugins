<?php
namespace OstWorkflow\Handlers;

use OstWorkflow\ApiError;
use OstWorkflow\Documents as Docs;
use OstWorkflow\Request;
use OstWorkflow\Res;

/** Index of "documents" (notes with a JSON + PDFs that have a uuid and versions). See OstWorkflow\Documents. */
final class Documents {
    static function routes() {
        return [
            ['GET', '/documents/(?P<uuid>[0-9a-fA-F-]{36})', 'show',     ['policy' => 'auth']],
            ['GET', '/tickets/(?P<id>\d+)/documents',        'ofTicket', ['policy' => 'ticket.view']],
        ];
    }

    /** GET /documents/{uuid} — every version the caller can see (ticket access), with the supersedes chain. */
    static function show(Request $req) {
        require_once(INCLUDE_DIR . 'class.ticket.php');
        $uuid = strtolower($req->param('uuid'));
        $out = [];
        foreach (Docs::versions($uuid) as $v) {
            $t = \Ticket::lookup($v['ticket_id']);
            if ($t && $t->checkStaffPerm($req->staff)) $out[] = $v + ['ticket_number' => (string) $t->getNumber()];
        }
        if (!$out) throw ApiError::notFound('document');
        return Res::ok(['uuid' => $uuid, 'latest_version' => (int) end($out)['version'], 'versions' => $out]);
    }

    static function ofTicket(Request $req) {
        $docs = [];
        foreach (Docs::ofTicket($req->ctx['ticket']->getId()) as $uuid => $versions)
            $docs[] = ['uuid' => $uuid, 'latest_version' => (int) end($versions)['version'], 'versions' => $versions];
        return Res::ok($docs);
    }
}
