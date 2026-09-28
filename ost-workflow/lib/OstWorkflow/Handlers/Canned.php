<?php
namespace OstWorkflow\Handlers;

use OstWorkflow\ApiError;
use OstWorkflow\Request;
use OstWorkflow\Res;

/**
 * Canned ("premade") responses: list the ones usable by the agent and render
 * one against a ticket (variables replaced as in the SCP). Read-only:
 * `canned.manage` only governs administration, not use.
 */
final class Canned {
    static function routes() {
        return [
            ['GET', '/canned',                         'index',  ['policy' => 'auth']],
            ['GET', '/canned/(?P<id>\d+)/render',      'render', ['policy' => 'canned.render']],
        ];
    }

    /** Policy 'canned.render': the ticket named by ?ticket= must be accessible to the agent. */
    static function policy() {
        return [
            'canned.render' => function (Request $req) {
                $tid = $req->q('ticket');
                if ($tid === null || !ctype_digit((string) $tid) || (int) $tid < 1)
                    throw ApiError::validation("'ticket' (ticket id) is required", 'ticket');
                require_once(INCLUDE_DIR . 'class.ticket.php');
                $t = \Ticket::lookup((int) $tid);
                if (!$t) throw ApiError::notFound('ticket');
                if (!$t->checkStaffPerm($req->staff))
                    throw new ApiError('forbidden', 'You cannot access this ticket');
                $req->ctx['ticket'] = $t;
            },
        ];
    }

    /**
     * GET /canned?dept=<id>&explicit=0|1
     * Responses enabled for the agent's departments plus the global ones
     * (Canned::getCannedResponses, class.canned.php:231). With `dept`, only that
     * department's and the global ones (or only the department's with explicit=1).
     */
    static function index(Request $req) {
        require_once(INCLUDE_DIR . 'class.canned.php');
        $dept = 0;
        if ($req->q('dept') !== null) {
            if (!ctype_digit((string) $req->q('dept')))
                throw ApiError::validation("'dept' must be a department id", 'dept');
            $dept = (int) $req->q('dept');
        }
        $explicit = Catalogs::boolQuery($req, 'explicit', false);

        $titles = \Canned::getCannedResponses($dept, $explicit);
        $deptOf = [];
        $files = [];
        if ($titles) {
            foreach (\Canned::objects()->filter(['canned_id__in' => array_keys($titles)])
                        ->values_flat('canned_id', 'dept_id') as $row)
                $deptOf[(int) $row[0]] = (int) $row[1];
        }
        $out = [];
        foreach ($titles as $id => $title)
            $out[] = ['id' => (int) $id, 'title' => $title,
                      'dept_id' => ($d = $deptOf[(int) $id] ?? 0) ? $d : null];
        return Res::ok($out, ['count' => count($out),
            'filters' => ['dept' => $dept ?: null, 'explicit' => $explicit]]);
    }

    /**
     * GET /canned/{id}/render?ticket=<id>
     * Text of the response with the ticket's variables replaced for the ticket
     * owner, as ajax.tickets.php:520 does. `body` is the HTML as stored (with
     * variables replaced), `body_text` its plain-text form.
     * `files` lists the response's attachments (metadata only).
     */
    static function render(Request $req) {
        require_once(INCLUDE_DIR . 'class.canned.php');
        $ticket = $req->ctx['ticket'];
        $canned = \Canned::lookup($req->intParam('id'));
        if (!$canned || !$canned->isEnabled())
            throw ApiError::notFound('canned response');
        // Same department rule as the list: a response of a department the agent cannot use is not usable.
        $usable = \Canned::getCannedResponses(0);
        if (!isset($usable[$canned->getId()]))
            throw new ApiError('forbidden', 'This canned response is not available to you');

        $replace = function (&$var) use ($ticket) {
            return $ticket->replaceVars($var, ['recipient' => $ticket->getOwner()]);
        };

        $files = [];
        foreach ($canned->getAttachedFiles(false) as $file)
            $files[] = ['id' => (int) $file->id, 'name' => $file->name, 'size' => (int) $file->size,
                        'type' => $file->type];

        return Res::ok([
            'id'        => (int) $canned->getId(),
            'title'     => $canned->getTitle(),
            'dept_id'   => ($d = (int) $canned->getDeptId()) ? $d : null,
            'ticket_id' => (int) $ticket->getId(),
            'body'      => $canned->getFormattedResponse('text', $replace),
            'body_text' => $canned->getFormattedResponse('text.plain', $replace),
            'files'     => $files,
        ]);
    }
}
