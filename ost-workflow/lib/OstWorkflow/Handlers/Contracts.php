<?php
namespace OstWorkflow\Handlers;

use OstWorkflow\ApiError;
use OstWorkflow\Attachments;
use OstWorkflow\ContractTerms;
use OstWorkflow\Contracts as Repo;
use OstWorkflow\Directory;
use OstWorkflow\Documents;
use OstWorkflow\Maintenance;
use OstWorkflow\Request;
use OstWorkflow\Res;
use OstWorkflow\Store as Db;
use OstWorkflow\Stream;
use OstWorkflow\Ticketing;
use OstWorkflow\Time;

/**
 * Contracts / Service Agreements store (OW-REQ-71, module 10). Rules and invariants I1-I9 live here and in
 * Contracts / ContractTerms; the client keeps the derived views. Records are never deleted: a draft is discarded, a
 * version or contract voided, a contract terminated (cancelled).
 *
 * Writes are ONLINE only, need Idempotency-Key and `base` (compare-and-swap, never last-write-wins):
 *   contract-level operations   base = contract `rev` seen
 *   draft save / discard / activate   base = draft `rev` seen (null when saving the first draft); activate sends the REVIEWED rev
 *   catalog publish             base = latest catalog `number` seen (null when none)
 *   basis                       base = id of the latest basis of that Ticket seen (null when none)
 * A stale base answers 409 conflict {reason, current, base, last_change, record}. Every state-changing write carries a reason.
 *
 * Authorization: reads = Admin or manager of ANY department, or authorization to read the contract's Organization (Directory::mayRead);
 * writes, approvals, drafts and the PDF = Admin or manager of ANY department (Maintenance::isManagerOfAny, F-E4-19);
 * entitlement bases = ticket.edit (write) / ticket.view (read) on the consumer Ticket.
 */
final class Contracts {
    const TEXT_MAX = 2000;
    const EVENTS_DEFAULT = 100;
    const EVENTS_MAX = 200;
    const CONFLICTS_SHOWN = 20;
    const PURPOSES = ['RS_CLASSIFICATION'];
    const ID = '(?P<uuid>[0-9a-fA-F-]{36})';

    static function routes() {
        $id = '/contracts/' . self::ID;
        return [
            ['GET',   '/contracts',                                   'index',       ['policy' => 'auth']],
            ['POST',  '/contracts',                                   'create',      ['policy' => 'contract.manager']],
            ['GET',   '/contracts/entitlement',                       'entitlement', ['policy' => 'auth']],
            ['GET',   '/contracts/catalogs',                          'catalogs',    ['policy' => 'auth']],
            ['POST',  '/contracts/catalogs',                          'publishCatalog', ['policy' => 'contract.manager']],
            ['GET',   $id,                                            'show',        ['policy' => 'contract.read']],
            ['PATCH', $id,                                            'editHeader',  ['policy' => 'contract.manager']],
            ['GET',   "$id/events",                                   'events',      ['policy' => 'contract.read']],
            ['PUT',   "$id/draft",                                    'saveDraft',   ['policy' => 'contract.manager']],
            ['POST',  "$id/draft/discard",                            'discardDraft', ['policy' => 'contract.manager']],
            ['POST',  "$id/activate",                                 'activate',    ['policy' => 'contract.manager']],
            ['PUT',   "$id/termination",                              'termination', ['policy' => 'contract.manager']],
            ['POST',  "$id/versions/(?P<version_uuid>[0-9a-fA-F-]{36})/void",      'voidVersion', ['policy' => 'contract.manager']],
            ['POST',  "$id/versions/(?P<version_uuid>[0-9a-fA-F-]{36})/documents", 'addDocument', ['policy' => 'contract.manager']],
            ['GET',   "$id/documents/(?P<doc_uuid>[0-9a-fA-F-]{36})/file",         'downloadDocument', ['policy' => 'contract.manager']],
            ['GET',   '/tickets/(?P<id>\d+)/entitlement-bases',       'bases',       ['policy' => 'ticket.view']],
            ['POST',  '/tickets/(?P<id>\d+)/entitlement-bases',       'createBasis', ['policy' => 'ticket.edit']],
        ];
    }

    static function policy() {
        return [
            // Gestor de contratos (CT-D14): administrator or manager of ANY department - the OW-REQ-70 predicate, not isManager().
            'contract.manager' => function (Request $req) {
                if (!Maintenance::isManagerOfAny($req->staff))
                    throw new ApiError('forbidden', 'Only an administrator or a department manager can do this', null, ['reason' => 'manager_required']);
            },
            // Reader: authorization to read the contract's Organization. The same answer for "unknown" and "not yours": no probing.
            'contract.read' => function (Request $req) {
                Repo::ready();
                $c = self::find($req->param('uuid'));
                if (!$c || !self::mayRead($req, (int) $c['organization_id']))
                    throw ApiError::notFound('contract');
                $req->ctx['contract'] = $c;
            },
        ];
    }

    // ==================================================================
    // READ
    // ==================================================================

    /**
     * GET /contracts?cursor=
     * The feed: every contract the agent may read (contract + all versions + document metadata) changed after the cursor, ordered by
     * (updated_at, id). Not paginated (MAY LATER): a full sync is cursor-less. `meta`: watermark (cursor to send next time), tz (installation
     * time zone, CT-D9), today (installation local date), scope (changes when the set of readable organizations changes: resync from
     * scratch), catalog_number (latest catalog version). The response is ETag-revalidated (If-None-Match -> 304); the ETag is per agent.
     */
    static function index(Request $req) {
        Repo::ready();
        $after = Repo::decodeCursor($req->q('cursor'));
        $mgr = self::manager($req);
        $orgs = null; $scope = 'all';
        if (!$mgr) {
            $orgs = [];
            foreach (Repo::organizationsWithContracts() as $o)
                if (Directory::mayRead($req->staff, 'organization', $o)) $orgs[] = $o;
            $scope = substr(sha1(implode(',', $orgs)), 0, 16);
        }
        $rows = Repo::feed($after, $orgs);
        $vs = []; $byC = [];
        if ($rows) {
            $in = implode(',', array_map(['OstWorkflow\Store', 'esc'], array_map(function ($r) { return $r['id']; }, $rows)));
            foreach (Repo::rows('SELECT * FROM ' . Repo::table('version') . ' WHERE contract_id IN (' . $in . ') ORDER BY contract_id, number') as $v) {
                $vs[] = $v['id'];
                $byC[$v['contract_id']][] = $v;
            }
        }
        $docs = Repo::documentsOf($vs);
        $items = [];
        foreach ($rows as $r)
            $items[] = Repo::contractDto($r, $byC[$r['id']] ?? [], $docs, $mgr);
        $wm = $rows ? Repo::cursor(end($rows)) : $req->q('cursor');
        $meta = ['count' => count($items), 'watermark' => $wm, 'tz' => Repo::tz(), 'scope' => $scope, 'catalog_number' => Repo::latestCatalogNumber()];
        $etag = '"' . substr(sha1(json_encode([$items, $meta])), 0, 32) . '"';
        $meta['today'] = Repo::today();
        $headers = ['ETag' => $etag, 'Cache-Control' => 'private, max-age=0, must-revalidate'];
        if (trim((string) $req->header('If-None-Match')) === $etag)
            return [304, null, $headers];
        return Res::ok($items, $meta, $headers);
    }

    /** GET /contracts/{uuid} - the contract with every version (terms, derived applicable period, document metadata). */
    static function show(Request $req) {
        return Res::ok(Repo::full($req->ctx['contract']['id'], self::manager($req)));
    }

    /** GET /contracts/{uuid}/events?cursor=&limit= - the append-only history, oldest first (cursor = seq). */
    static function events(Request $req) {
        $c = $req->ctx['contract'];
        $limit = $req->intQuery('limit', self::EVENTS_DEFAULT, 1, self::EVENTS_MAX);
        $cur = $req->q('cursor');
        if ($cur !== null && !ctype_digit((string) $cur)) throw ApiError::validation('Invalid cursor', 'cursor');
        $rows = Repo::events($c['id'], (int) $cur, $limit);
        $more = count($rows) > $limit;
        if ($more) array_pop($rows);
        return Res::page(array_map(['OstWorkflow\Contracts', 'eventDto'], $rows), $more ? (string) end($rows)['seq'] : null);
    }

    /**
     * GET /contracts/entitlement?organization_id=&equipment_id=&service_line=&as_of=
     * Authoritative resolution (nine outcomes) on the installation's local day of `as_of` (ISO-8601 instant, or a plain date). The
     * evaluation organization is the CURRENT organization of the equipment (Installed Equipment `extra`) when there is one, otherwise
     * `organization_id`. Without `service_line` the five lines are resolved. `data.results[]`; read authorization over the evaluation
     * organization. Informative: nothing is stored (a basis is POST /tickets/{id}/entitlement-bases).
     */
    static function entitlement(Request $req) {
        Repo::ready();
        list($orgId, $eq) = self::evaluationContext($req->q('organization_id'), $req->q('equipment_id'));
        $line = $req->q('service_line');
        if ($line !== null && !in_array($line, ContractTerms::LINES, true))
            throw ApiError::validation("'service_line' must be one of " . implode('|', ContractTerms::LINES), 'service_line');
        list($ts, $local) = self::asOf($req->q('as_of'));
        if ($orgId !== null && !self::mayRead($req, $orgId))
            throw new ApiError('forbidden', 'You cannot read the contracts of this organization');
        $results = [];
        foreach ($line ? [$line] : ContractTerms::LINES as $l)
            $results[] = Repo::resolve($orgId, $local, $l, $eq);
        return Res::ok(['organization_id_at_evaluation' => $orgId, 'equipment_ref' => $eq, 'as_of' => $ts === null ? null : gmdate('Y-m-d\TH:i:s\Z', $ts),
                        'as_of_local_date' => $local, 'tz' => Repo::tz(), 'results' => $results]);
    }

    /** GET /contracts/catalogs - every installation-scoped classification catalog version, oldest first. Policy auth: labels/descriptions only. */
    static function catalogs(Request $req) {
        Repo::ready();
        $items = array_map(['OstWorkflow\Contracts', 'catalogDto'], Repo::catalogs());
        return Res::ok($items, ['count' => count($items), 'latest_number' => Repo::latestCatalogNumber()]);
    }

    // ==================================================================
    // WRITE: contract and draft
    // ==================================================================

    /**
     * POST /contracts {organization_id, title, external_number?, version:{effective_from, effective_to, coverage?, entitlements?, ...}}
     * Creates the Contract (DRAFT) and its first version (INITIAL, DRAFT). 201. Only the organization, the name and the dates are
     * mandatory; completeness is checked on activation. The server assigns `contract_ref`.
     */
    static function create(Request $req) {
        Repo::ready();
        $org = $req->input('organization_id');
        if (!is_int($org) || $org < 1)
            throw ApiError::validation("'organization_id' must be a positive integer", 'organization_id');
        require_once(INCLUDE_DIR . 'class.organization.php');
        if (!\Organization::lookup($org))
            throw ApiError::validation('The organization does not exist', 'organization_id');
        $title = self::text($req->input('title'), 'title', true, 255);
        $num = self::text($req->input('external_number'), 'external_number', false, 80);
        $v = $req->input('version');
        if (!is_array($v)) throw ApiError::validation("'version' must be an object with at least effective_from and effective_to", 'version');
        $nv = ContractTerms::normalizeVersion($v);
        if ($nv['kind'] !== null && $nv['kind'] !== 'INITIAL')
            throw ApiError::validation("The first version of a contract is INITIAL", 'version.kind');
        if ($nv['supersedes'] !== null)
            throw ApiError::validation("The first version supersedes nothing", 'version.supersedes');
        self::checkRefs($nv['body']);
        $staffId = (int) $req->staff->getId();
        $cid = Repo::uuid(); $vid = Repo::uuid();
        $out = Repo::tx(function () use ($cid, $vid, $org, $title, $num, $nv, $staffId) {
            if (!Repo::insertContract($cid, $org, $title, $num, $staffId))
                throw ApiError::conflict('That contract number is already used in this organization', ['reason' => 'number_taken', 'external_number' => $num]);
            Repo::insertDraft($vid, $cid, 1, 'INITIAL', null, $nv['effective_from'], $nv['effective_to'], $nv['body'], $staffId);
            Repo::event($cid, $vid, 'CREATED', $staffId, null, ['organization_id' => $org, 'title' => $title, 'external_number' => $num]);
            Repo::touch($cid, $staffId);
            return Repo::full($cid, true);
        });
        return Res::created($out);
    }

    /**
     * PATCH /contracts/{uuid} {base: rev, reason, title?, external_number?} - header only; before and after kept in the event.
     */
    static function editHeader(Request $req) {
        Repo::ready();
        $reason = self::text($req->input('reason'), 'reason', true);
        $base = self::baseRev($req);
        $c0 = self::load($req);
        $b = $req->json();
        if (!array_key_exists('title', $b) && !array_key_exists('external_number', $b))
            throw ApiError::validation("Send 'title' and/or 'external_number'", 'title');
        $staffId = (int) $req->staff->getId();
        return self::guard($c0['id'], function () use ($c0, $b, $reason, $base, $staffId) {
            $c = Repo::contract($c0['id'], true);
            self::notVoided($c);
            $title = array_key_exists('title', $b) ? self::text($b['title'], 'title', true, 255) : $c['title'];
            $num = array_key_exists('external_number', $b) ? self::text($b['external_number'], 'external_number', false, 80) : $c['external_number'];
            if ($title === $c['title'] && $num === $c['external_number'])
                return self::noop($c['id']);
            if ((int) $c['rev'] !== $base) self::conflict('base_mismatch', (int) $c['rev'], $base);
            if (!Repo::updateContract($c, ['title' => Repo::lit($title), 'external_number' => Repo::lit($num), 'ext_key' => Repo::lit(Repo::extKey($num))], $staffId))
                self::conflict('number_taken', (int) $c['rev'], $base, ['external_number' => $num]);
            Repo::event($c['id'], null, 'HEADER_EDITED', $staffId, $reason,
                ['before' => ['title' => $c['title'], 'external_number' => $c['external_number']], 'after' => ['title' => $title, 'external_number' => $num]]);
            Repo::touch($c['id'], $staffId);
            return Res::ok(Repo::full($c['id'], true), ['applied' => true]);
        });
    }

    /**
     * PUT /contracts/{uuid}/draft {base: draft rev | null, kind?, supersedes?, effective_from, effective_to, coverage?, entitlements?,
     * response_policy?, catalog_version_ref?} - creates (base null) or replaces the ONE open draft (I5). Never touches an EFFECTIVE version.
     */
    static function saveDraft(Request $req) {
        Repo::ready();
        $b = $req->json();
        $base = \OstWorkflow\Ticketing::requireBase($req);
        if ($base !== null && (!is_int($base) || $base < 1))
            throw ApiError::validation("'base' must be the draft rev you saw (null when there is no draft)", 'base');
        $in = $b; unset($in['base']);
        $nv = ContractTerms::normalizeVersion($in);
        self::checkRefs($nv['body']);
        $c0 = self::load($req);
        $staffId = (int) $req->staff->getId();
        return self::guard($c0['id'], function () use ($c0, $nv, $base, $staffId) {
            $c = Repo::contract($c0['id'], true);
            self::notVoided($c);
            $kind = self::draftKind($c, $nv['kind']);
            self::checkSupersedes($c, $kind, $nv['supersedes']);
            $d = Repo::draftOf($c['id']);
            if ($base === null) {
                if ($d) self::conflict('draft_exists', (int) $d['rev'], null);
                if (!Repo::insertDraft(Repo::uuid(), $c['id'], Repo::nextNumber($c['id']), $kind, $nv['supersedes'], $nv['effective_from'], $nv['effective_to'], $nv['body'], $staffId))
                    self::conflict('draft_exists', null, null);
                Repo::touch($c['id'], $staffId);
                return Res::created(Repo::full($c['id'], true));
            }
            if (!$d || (int) $d['rev'] !== $base)
                self::conflict('base_mismatch', $d ? (int) $d['rev'] : null, $base);
            $same = $d['kind'] === $kind && $d['supersedes'] === $nv['supersedes'] && $d['effective_from'] === $nv['effective_from']
                && $d['effective_to'] === $nv['effective_to'] && json_encode(Repo::body($d)) === json_encode($nv['body']);
            if ($same)
                return self::noop($c['id']);
            if (!Repo::updateDraft($d, $kind, $nv['supersedes'], $nv['effective_from'], $nv['effective_to'], $nv['body'], $staffId))
                self::conflict('base_mismatch', (int) $d['rev'] + 1, $base);
            Repo::touch($c['id'], $staffId);
            return Res::ok(Repo::full($c['id'], true), ['applied' => true]);
        });
    }

    /** POST /contracts/{uuid}/draft/discard {base: draft rev, reason} - DISCARDED (kept); a never-activated contract becomes VOIDED (I2). */
    static function discardDraft(Request $req) {
        Repo::ready();
        $reason = self::text($req->input('reason'), 'reason', true);
        $base = self::baseRev($req);
        $c0 = self::load($req);
        $staffId = (int) $req->staff->getId();
        return self::guard($c0['id'], function () use ($c0, $reason, $base, $staffId) {
            $c = Repo::contract($c0['id'], true);
            self::notVoided($c);
            $d = Repo::draftOf($c['id']);
            if (!$d) self::conflict('no_draft', null, $base);
            if ((int) $d['rev'] !== $base) self::conflict('base_mismatch', (int) $d['rev'], $base);
            if (!Repo::setVersionState($d, 'DRAFT', ['status' => "'DISCARDED'", 'open_draft' => 'NULL'], $staffId))
                self::conflict('base_mismatch', null, $base);
            $voided = $c['status'] === 'DRAFT';
            Repo::updateContract($c, $voided ? ['status' => "'VOIDED'", 'ext_key' => 'NULL'] : [], $staffId);
            Repo::event($c['id'], $d['id'], 'DRAFT_DISCARDED', $staffId, $reason, ['contract_voided' => $voided]);
            Repo::touch($c['id'], $staffId);
            return Res::ok(Repo::full($c['id'], true), ['applied' => true]);
        });
    }

    /**
     * POST /contracts/{uuid}/activate {base: draft rev REVIEWED, reason}
     * One transaction, serialized per organization (every contract row of the organization is locked first): revalidates V4-V10, I1-I8,
     * equipment (V8) and the overlap rule CT-D8 over the whole organization. A CORRECTION also voids the version it supersedes.
     * 200 {contract, meta.warnings[]}; 409 {reason: base_mismatch | no_draft | overlap | duplicate_effective_from | after_termination | ...}.
     */
    static function activate(Request $req) {
        Repo::ready();
        $reason = self::text($req->input('reason'), 'reason', true);
        $base = self::baseRev($req);
        $c0 = self::load($req);
        $staffId = (int) $req->staff->getId();
        $warnings = [];
        $res = self::guard($c0['id'], function () use ($c0, $reason, $base, $staffId, &$warnings) {
            Repo::lockOrganization($c0['organization_id']);
            $c = Repo::contract($c0['id'], true);
            self::notVoided($c);
            $d = Repo::draftOf($c['id']);
            if (!$d) self::conflict('no_draft', null, $base);
            if ((int) $d['rev'] !== $base) self::conflict('base_mismatch', (int) $d['rev'], $base);
            $body = Repo::body($d);
            if ($p = ContractTerms::incomplete($body))
                throw ApiError::validation('The draft is not complete: ' . implode('; ', $p), 'draft', ['reason' => 'incomplete', 'problems' => $p]);
            $kind = self::draftKind($c, $d['kind']);
            if ($c['status'] === 'CANCELLED' && $d['effective_from'] >= $c['terminated_on'])
                self::conflict('after_termination', $c['terminated_on'], $d['effective_from'], [], 'The version starts on or after the termination date: revoke the termination first (I8)');
            $sup = self::checkSupersedes($c, $kind, $d['supersedes'], true);
            $retro = $kind === 'CORRECTION' || $d['effective_from'] < Repo::today();
            $warnings = self::checkEquipment($c, $body, $retro);
            if ($retro) $warnings[] = ['code' => 'retroactive', 'message' => 'The version is registered today with effect from ' . $d['effective_from'] . '; recorded decisions do not change'];

            if ($kind === 'CORRECTION' && $sup && $sup['status'] === 'EFFECTIVE') {
                Repo::setVersionState($sup, 'EFFECTIVE', ['status' => "'VOIDED'", 'eff_from_key' => 'NULL'], $staffId);
                Repo::event($c['id'], $sup['id'], 'VERSION_VOIDED', $staffId, $reason, ['replaced_by' => $d['id'], 'correction' => true]);
            }
            $dup = Db::row('SELECT id FROM ' . Repo::table('version') . ' WHERE contract_id=' . Db::esc($c['id'])
                . ' AND status=\'EFFECTIVE\' AND effective_from=' . Db::esc($d['effective_from']));
            if ($dup)
                self::conflict('duplicate_effective_from', $dup['id'], $d['effective_from'], ['existing_version' => $dup['id']]);
            $hash = ContractTerms::hash($d['effective_from'], $d['effective_to'], $body);
            if (!Repo::setVersionState($d, 'DRAFT', ['status' => "'EFFECTIVE'", 'open_draft' => 'NULL', 'eff_from_key' => Repo::lit($d['effective_from']),
                    'hash_schema' => (string) ContractTerms::HASH_SCHEMA, 'terms_hash' => Repo::lit($hash), 'registered_at' => 'UTC_TIMESTAMP(6)',
                    'registered_by' => (string) $staffId], $staffId))
                self::conflict('duplicate_effective_from', null, $d['effective_from']);
            Repo::updateContract($c, $c['status'] === 'DRAFT' ? ['status' => "'ACTIVE'"] : [], $staffId);
            if ($conf = Repo::overlapsOfOrganization((int) $c['organization_id']))
                self::conflict('overlap', null, null, ['conflicts' => array_slice($conf, 0, self::CONFLICTS_SHOWN), 'conflict_count' => count($conf)],
                    'The version overlaps another applicable version of the organization on a claimed service line (CT-D8)');
            Repo::event($c['id'], $d['id'], 'VERSION_ACTIVATED', $staffId, $reason,
                ['kind' => $kind, 'number' => (int) $d['number'], 'effective_from' => $d['effective_from'], 'effective_to' => $d['effective_to'], 'terms_hash' => $hash, 'supersedes' => $d['supersedes']]);
            Repo::touch($c['id'], $staffId);
            return Repo::full($c['id'], true);
        });
        return Res::ok($res, ['applied' => true, 'warnings' => $warnings]);
    }

    /**
     * PUT /contracts/{uuid}/termination {base: contract rev, terminated_on: date | null, reason}
     * A date terminates (ACTIVE -> CANCELLED; `terminated_on` = first day WITHOUT coverage, F-E4-09); null revokes the termination
     * (CANCELLED -> ACTIVE; revalidates overlaps like any widening operation). Terms are never touched.
     */
    static function termination(Request $req) {
        Repo::ready();
        $reason = self::text($req->input('reason'), 'reason', true);
        $base = self::baseRev($req);
        $to = $req->input('terminated_on');
        if ($to !== null && ContractTerms::date($to) === null)
            throw ApiError::validation("'terminated_on' must be a valid date YYYY-MM-DD, or null to revoke the termination", 'terminated_on');
        $c0 = self::load($req);
        $staffId = (int) $req->staff->getId();
        $warnings = [];
        $res = self::guard($c0['id'], function () use ($c0, $reason, $base, $to, $staffId, &$warnings) {
            Repo::lockOrganization($c0['organization_id']);
            $c = Repo::contract($c0['id'], true);
            if ($to !== null) {
                if ($c['status'] === 'CANCELLED' && $c['terminated_on'] === $to) return self::noop($c['id']);
                if ($c['status'] !== 'ACTIVE') self::conflict('invalid_state', $c['status'], null, ['required' => 'ACTIVE']);
                if ((int) $c['rev'] !== $base) self::conflict('base_mismatch', (int) $c['rev'], $base);
                Repo::updateContract($c, ['status' => "'CANCELLED'", 'terminated_on' => Repo::lit($to), 'termination_reason' => Repo::lit($reason)], $staffId);
                Repo::event($c['id'], null, 'TERMINATED', $staffId, $reason, ['terminated_on' => $to]);
                if ($to < Repo::today())
                    $warnings[] = ['code' => 'retroactive', 'message' => 'Retroactive termination: recorded decisions do not change'];
            } else {
                if ($c['status'] === 'ACTIVE') return self::noop($c['id']);
                if ($c['status'] !== 'CANCELLED') self::conflict('invalid_state', $c['status'], null, ['required' => 'CANCELLED']);
                if ((int) $c['rev'] !== $base) self::conflict('base_mismatch', (int) $c['rev'], $base);
                Repo::updateContract($c, ['status' => "'ACTIVE'", 'terminated_on' => 'NULL', 'termination_reason' => 'NULL'], $staffId);
                if ($conf = Repo::overlapsOfOrganization((int) $c['organization_id']))
                    self::conflict('overlap', null, null, ['conflicts' => array_slice($conf, 0, self::CONFLICTS_SHOWN), 'conflict_count' => count($conf)],
                        'Revoking the termination would overlap another applicable version of the organization (CT-D8)');
                Repo::event($c['id'], null, 'TERMINATION_REVOKED', $staffId, $reason, ['previous_terminated_on' => $c['terminated_on']]);
            }
            Repo::touch($c['id'], $staffId);
            return Res::ok(Repo::full($c['id'], true), ['applied' => true]);
        });
        if ($warnings && $res[0] === 200) $res[1]['meta']['warnings'] = $warnings;
        return $res;
    }

    /**
     * POST /contracts/{uuid}/versions/{version_uuid}/void {base: contract rev, reason}
     * EFFECTIVE -> VOIDED without a replacement (registered by mistake). If it was the only EFFECTIVE version the contract becomes VOIDED
     * (I4, terminal, ignored by the resolver) and an open draft is discarded. Revalidates overlaps (the previous version recovers days).
     * A version with a replacement is voided by activating a CORRECTION that supersedes it.
     */
    static function voidVersion(Request $req) {
        Repo::ready();
        $reason = self::text($req->input('reason'), 'reason', true);
        $base = self::baseRev($req);
        $c0 = self::load($req);
        $vid = strtolower($req->param('version_uuid'));
        $staffId = (int) $req->staff->getId();
        return self::guard($c0['id'], function () use ($c0, $vid, $reason, $base, $staffId) {
            Repo::lockOrganization($c0['organization_id']);
            $c = Repo::contract($c0['id'], true);
            $v = Repo::version($vid, true);
            if (!$v || $v['contract_id'] !== $c['id']) throw ApiError::notFound('version');
            if ($v['status'] === 'VOIDED') return self::noop($c['id']);
            if ($v['status'] !== 'EFFECTIVE') self::conflict('invalid_state', $v['status'], null, ['required' => 'EFFECTIVE']);
            if ((int) $c['rev'] !== $base) self::conflict('base_mismatch', (int) $c['rev'], $base);
            Repo::setVersionState($v, 'EFFECTIVE', ['status' => "'VOIDED'", 'eff_from_key' => 'NULL'], $staffId);
            $left = Db::row('SELECT COUNT(*) AS n FROM ' . Repo::table('version') . ' WHERE contract_id=' . Db::esc($c['id']) . ' AND status=\'EFFECTIVE\'');
            $contractVoided = (int) $left['n'] === 0;
            Repo::updateContract($c, $contractVoided ? ['status' => "'VOIDED'", 'ext_key' => 'NULL'] : [], $staffId);
            Repo::event($c['id'], $v['id'], 'VERSION_VOIDED', $staffId, $reason, ['contract_voided' => $contractVoided, 'correction' => false]);
            if ($contractVoided && ($d = Repo::draftOf($c['id']))) {
                Repo::setVersionState($d, 'DRAFT', ['status' => "'DISCARDED'", 'open_draft' => 'NULL'], $staffId);
                Repo::event($c['id'], $d['id'], 'DRAFT_DISCARDED', $staffId, 'The contract was voided', ['contract_voided' => true]);
            }
            if ($conf = Repo::overlapsOfOrganization((int) $c['organization_id']))
                self::conflict('overlap', null, null, ['conflicts' => array_slice($conf, 0, self::CONFLICTS_SHOWN), 'conflict_count' => count($conf)],
                    'Voiding this version would overlap another applicable version of the organization (CT-D8)');
            Repo::touch($c['id'], $staffId);
            return Res::ok(Repo::full($c['id'], true), ['applied' => true]);
        });
    }

    // ==================================================================
    // WRITE: documents
    // ==================================================================

    /**
     * POST /contracts/{uuid}/versions/{version_uuid}/documents {base: contract rev, reason, file_id, role, display_title?, supersedes?}
     * `file_id` comes from POST /files (a PDF the agent uploaded). The file is linked DURABLY to the document through a core `attachment`
     * row (type 'W'), so the core's daily orphan cleanup never removes it; the document only exists once that link is stored (V13).
     * Allowed on DRAFT and EFFECTIVE versions (V16); `supersedes` (a document_ref of the same version) marks the old one SUPERSEDED.
     * The terms and `terms_hash` do not change. 201 {contract}.
     */
    static function addDocument(Request $req) {
        Repo::ready();
        $reason = self::text($req->input('reason'), 'reason', true);
        $base = self::baseRev($req);
        $role = $req->input('role');
        if (!is_string($role) || !isset(Repo::ROLES[$role]))
            throw ApiError::validation("'role' must be one of " . implode('|', array_keys(Repo::ROLES)), 'role');
        $fid = $req->input('file_id');
        if (!is_int($fid) || $fid < 1)
            throw ApiError::validation("'file_id' must be the id returned by POST /files", 'file_id');
        $sup = $req->input('supersedes');
        if ($sup !== null && (!is_string($sup) || !preg_match(Documents::UUID, $sup)))
            throw ApiError::validation("'supersedes' must be a document_ref", 'supersedes');
        $c0 = self::load($req);
        $vid = strtolower($req->param('version_uuid'));
        $staffId = (int) $req->staff->getId();

        $file = Attachments::resolve([$fid], $req->staff, 'file_id')[0];
        $data = (string) $file->getData();
        if (!Attachments::isPdf($file) || substr($data, 0, 5) !== '%PDF-')
            throw new ApiError('unsupported_type', 'Only PDF files can be linked to a contract', 'file_id', ['detected_type' => $file->getType()]);
        if (strlen($data) > Attachments::maxBytes() || strlen($data) < 1)
            throw ApiError::validation('The PDF is empty or exceeds the size limit', 'file_id');
        $title = self::displayTitle($req->input('display_title'), $role, (string) $file->getName());
        $sha = hash('sha256', $data);

        return self::guard($c0['id'], function () use ($c0, $vid, $reason, $base, $role, $fid, $sup, $title, $sha, $data, $staffId) {
            $c = Repo::contract($c0['id'], true);
            self::notVoided($c);
            $v = Repo::version($vid, true);
            if (!$v || $v['contract_id'] !== $c['id']) throw ApiError::notFound('version');
            if (!in_array($v['status'], ['DRAFT', 'EFFECTIVE'], true))
                self::conflict('invalid_state', $v['status'], null, ['required' => 'DRAFT|EFFECTIVE']);
            if ((int) $c['rev'] !== $base) self::conflict('base_mismatch', (int) $c['rev'], $base);
            $old = null;
            foreach (Repo::documentsOf([$v['id']])[$v['id']] ?? [] as $d) {
                if ($sup !== null && $d['id'] === strtolower($sup)) $old = $d;
                if ($d['status'] === 'ACTIVE' && (int) $d['file_id'] === $fid && $d['sha256'] === $sha)
                    self::conflict('duplicate_document', $d['id'], null, ['existing_document' => $d['id']]);
            }
            if ($sup !== null && (!$old || $old['status'] !== 'ACTIVE'))
                throw ApiError::validation("'supersedes' is not an active document of this version", 'supersedes');
            $did = Repo::uuid();
            Db::q('INSERT INTO ' . Repo::table('document') . ' SET id=' . Db::esc($did) . ', schema_version=' . Repo::SCHEMA_VERSION
                . ', contract_id=' . Db::esc($c['id']) . ', version_id=' . Db::esc($v['id']) . ', file_id=' . (int) $fid
                . ', sha256=' . Db::esc($sha) . ', size=' . strlen($data) . ', role=' . Db::esc($role)
                . ', display_title=' . Db::esc($title) . ', status=\'ACTIVE\', registered_at=UTC_TIMESTAMP(6), registered_by=' . (int) $staffId);
            $seq = (int) db_insert_id();
            // The durable link: a core attachment row (object_id = the document's number) keeps AttachmentFile::deleteOrphans away.
            Db::q('INSERT IGNORE INTO ' . ATTACHMENT_TABLE . ' SET object_id=' . $seq . ', type=' . Db::esc(Repo::ATTACH_TYPE)
                . ', file_id=' . (int) $fid . ', inline=0');
            $linked = Db::row('SELECT id FROM ' . ATTACHMENT_TABLE . ' WHERE object_id=' . $seq . ' AND type=' . Db::esc(Repo::ATTACH_TYPE) . ' AND file_id=' . (int) $fid);
            if (!$linked)
                throw new \RuntimeException('contract document could not be linked durably');
            if ($old) {
                Db::q('UPDATE ' . Repo::table('document') . ' SET status=\'SUPERSEDED\', superseded_by=' . Db::esc($did) . ' WHERE id=' . Db::esc($old['id']) . ' AND status=\'ACTIVE\'');
                Repo::event($c['id'], $v['id'], 'DOCUMENT_SUPERSEDED', $staffId, $reason, ['document_ref' => $old['id'], 'replaced_by' => $did]);
            }
            Repo::updateContract($c, [], $staffId);
            Repo::event($c['id'], $v['id'], 'DOCUMENT_ADDED', $staffId, $reason, ['document_ref' => $did, 'role' => $role, 'display_title' => $title]);
            Repo::touch($c['id'], $staffId);
            return Res::created(Repo::full($c['id'], true));
        });
    }

    /** GET /contracts/{uuid}/documents/{doc_uuid}/file - the PDF; Admin or manager of ANY department only. */
    static function downloadDocument(Request $req) {
        Repo::ready();
        $c = self::load($req);
        $d = Repo::document(strtolower($req->param('doc_uuid')));
        if (!$d || $d['contract_id'] !== $c['id']) throw ApiError::notFound('document');
        Attachments::boot();
        $file = \AttachmentFile::lookup((int) $d['file_id']);
        if (!$file) throw ApiError::notFound('file');
        $name = Attachments::safeName(preg_replace('/[^\p{L}\p{N} ._-]+/u', '_', $d['display_title'])) . '.pdf';
        $etag = '"' . $d['sha256'] . '"';
        $head = ['ETag' => $etag, 'Cache-Control' => 'private, max-age=0, must-revalidate', 'Content-Security-Policy' => "default-src 'none'; sandbox",
                 'X-Content-Type-Options' => 'nosniff', 'Content-Type' => 'application/pdf',
                 'Content-Disposition' => sprintf('attachment; filename="%s"; filename*=UTF-8\'\'%s', preg_replace('/[^A-Za-z0-9._ -]/', '_', $name), rawurlencode($name))];
        if (trim((string) $req->header('If-None-Match')) === $etag)
            return new Stream(function () {}, $head, 304);
        $bk = $file->open();
        return new Stream(function () use ($bk) {
            @ini_set('zlib.output_compression', 'Off');
            $bk->passthru();
        }, $head + ['Content-Length' => (string) (int) $file->getSize()]);
    }

    // ==================================================================
    // WRITE: classification catalog
    // ==================================================================

    /**
     * POST /contracts/catalogs {base, reason, catalog_key?, codes:[{code, label, description?, treatment}]}
     * Publishes a NEW immutable ClassificationCatalogVersion (number = base + 1). A catalog_key
     * identifies an installation-scoped five-level catalog; omitted keeps legacy catalog compatibility.
     */
    static function publishCatalog(Request $req) {
        Repo::ready();
        $reason = self::text($req->input('reason'), 'reason', true);
        $base = \OstWorkflow\Ticketing::requireBase($req);
        if ($base !== null && (!is_int($base) || $base < 1))
            throw ApiError::validation("'base' must be the latest catalog number you saw (null when none)", 'base');
        $codes = $req->input('codes');
        if (!is_array($codes) || !$codes || array_keys($codes) !== range(0, count($codes) - 1) || count($codes) > 200)
            throw ApiError::validation("'codes' must be a list of 1 to 200 {code, label, treatment}", 'codes');
        $catalogKey = $req->input('catalog_key');
        if ($catalogKey !== null && (!is_string($catalogKey) || !preg_match('/^[A-Za-z0-9_.-]{1,40}$/', $catalogKey)))
            throw ApiError::validation("'catalog_key' must be 1-40 ASCII letters, numbers, _, ., or -", 'catalog_key');
        if ($catalogKey !== null && count($codes) !== 5)
            throw ApiError::validation("a configurable catalog must contain exactly five codes", 'codes');
        $seen = []; $norm = [];
        foreach ($codes as $c) {
            if (!is_array($c) || !isset($c['code'], $c['label'], $c['treatment']) || !is_string($c['code']) || !is_string($c['label'])
                    || !preg_match('/^[A-Za-z0-9_.-]{1,40}$/', $c['code']) || trim($c['label']) === '' || mb_strlen($c['label']) > 120
                    || !in_array($c['treatment'], ['COVERED', 'BILLABLE', 'EXCLUDED', 'OTHER'], true))
                throw ApiError::validation("each code needs code (A-Z a-z 0-9 _ . -, max 40), label (max 120) and treatment COVERED|BILLABLE|EXCLUDED|OTHER", 'codes');
            $description = $c['description'] ?? null;
            if ($catalogKey !== null && (!is_string($description) || trim($description) === '' || mb_strlen($description) > 500))
                throw ApiError::validation("each configurable code needs a non-empty description (max 500)", 'codes.description');
            if (isset($seen[$c['code']])) throw ApiError::validation('Duplicate code ' . $c['code'], 'codes');
            $seen[$c['code']] = true;
            $norm[] = ['code' => $c['code'], 'label' => trim($c['label']),
                       'description' => $description === null ? null : trim($description), 'treatment' => $c['treatment']];
        }
        $staffId = (int) $req->staff->getId();
        $cur = Repo::latestCatalogNumber();
        $fail = function () use ($cur, $base) {
            $e = ApiError::conflict('The catalog changed on the server (base_mismatch)', ['reason' => 'base_mismatch', 'current' => Repo::latestCatalogNumber(), 'base' => $base]);
            $l = Repo::catalogs();
            $e->details['record'] = $l ? Repo::catalogDto(end($l)) : null;
            return $e;
        };
        if ($cur !== $base) throw $fail();
        $id = Repo::uuid();
        Db::q('INSERT IGNORE INTO ' . Repo::table('catalog') . ' SET id=' . Db::esc($id) . ', schema_version=' . Repo::SCHEMA_VERSION
            . ', number=' . ($cur === null ? 1 : $cur + 1) . ', catalog_key=' . Repo::lit($catalogKey)
            . ', codes=' . Db::esc(json_encode($norm, JSON_UNESCAPED_UNICODE))
            . ', reason=' . Db::esc($reason) . ', registered_at=UTC_TIMESTAMP(6), registered_by=' . $staffId);
        if (Db::affected() !== 1) throw $fail();
        return Res::created(Repo::catalogDto(Repo::catalog($id)));
    }

    // ==================================================================
    // Entitlement bases (decision points, resolved in the server)
    // ==================================================================

    /** GET /tickets/{id}/entitlement-bases - every basis of the Ticket, oldest first; meta.current = latest id per purpose. */
    static function bases(Request $req) {
        Repo::ready();
        $t = $req->ctx['ticket'];
        $rows = Repo::rows('SELECT * FROM ' . Repo::table('basis') . ' WHERE consumer=' . Db::esc('ticket:' . (int) $t->getId()) . ' ORDER BY recorded_at, id');
        $cur = [];
        foreach ($rows as $r) $cur[$r['purpose']] = $r['id'];
        return Res::ok(array_map(['OstWorkflow\Contracts', 'basisDto'], $rows), ['count' => count($rows), 'current' => (object) $cur]);
    }

    /**
     * POST /tickets/{id}/entitlement-bases {base: latest basis id seen | null, purpose: RS_CLASSIFICATION, service_line, equipment_ref?, code?,
     *   seen?: {contract_version_ref, result, cache_etag}, reason?}
     * The server resolves the entitlement against the authoritative versions with `as_of` = the Ticket's `reported_at` (field, when valid)
     * or its creation time - never the client's - and the CURRENT organization of the equipment (else the Ticket's organization), copies
     * the policy snapshot from the resolved version, reads the Ticket's native SLA, compares with what the user `seen`, and stores an
     * immutable row. A reclassification is another POST (base = previous id) and supersedes the previous one. 201.
     */
    static function createBasis(Request $req) {
        Repo::ready();
        $t = $req->ctx['ticket'];
        $purpose = $req->input('purpose');
        if (!in_array($purpose, self::PURPOSES, true))
            throw ApiError::validation("'purpose' must be " . implode('|', self::PURPOSES), 'purpose');
        $line = $req->input('service_line');
        if (!in_array($line, ContractTerms::LINES, true))
            throw ApiError::validation("'service_line' must be one of " . implode('|', ContractTerms::LINES), 'service_line');
        $base = \OstWorkflow\Ticketing::requireBase($req);
        if ($base !== null && (!is_string($base) || !preg_match(Documents::UUID, $base)))
            throw ApiError::validation("'base' must be the id of the latest basis you saw (null when none)", 'base');
        $eqIn = $req->input('equipment_ref');
        if ($eqIn !== null && (!is_int($eqIn) || $eqIn < 1))
            throw ApiError::validation("'equipment_ref' must be an Installed Equipment id or null", 'equipment_ref');
        $code = $req->input('code');
        if ($code !== null && (!is_string($code) || !preg_match('/^[A-Za-z0-9_.-]{1,40}$/', $code)))
            throw ApiError::validation("'code' must be the classification code (max 40)", 'code');
        $reason = self::text($req->input('reason'), 'reason', false);
        $seen = $req->input('seen');
        $sv = $sr = $se = null;
        if ($seen !== null) {
            if (!is_array($seen)) throw ApiError::validation("'seen' must be {contract_version_ref, result, cache_etag}", 'seen');
            $sv = $seen['contract_version_ref'] ?? null; $sr = $seen['result'] ?? null; $se = $seen['cache_etag'] ?? null;
            if (($sv !== null && (!is_string($sv) || !preg_match(Documents::UUID, $sv))) || ($sr !== null && !in_array($sr, ContractTerms::RESULTS, true))
                    || ($se !== null && (!is_string($se) || strlen($se) > 80)))
                throw ApiError::validation("'seen' has invalid values", 'seen');
            if ($sv !== null) $sv = strtolower($sv);
        }
        $ticketOrg = Db::row('SELECT org_id FROM ' . USER_TABLE . ' WHERE id=' . (int) $t->getUserId());
        list($orgId, $eq) = self::evaluationContext($ticketOrg && (int) $ticketOrg['org_id'] > 0 ? (string) $ticketOrg['org_id'] : null, $eqIn === null ? null : (string) $eqIn, true);
        list($ts, $source) = self::ticketInstant($t);
        $tz = Repo::tz();
        $local = (new \DateTime('@' . $ts))->setTimezone(new \DateTimeZone($tz))->format('Y-m-d');
        $chain = 'ticket:' . (int) $t->getId() . '|' . $purpose;
        $staffId = (int) $req->staff->getId();

        $res = Repo::resolve($orgId, $local, $line, $eq);
        $mismatch = $seen === null ? null : (($sv !== $res['contract_version_ref']) || ($sr !== $res['result']));
        $sla = $t->getSLA();
        $latest = Repo::latestBasis($chain);
        $fail = function () use ($chain, $base) {
            $now = Repo::latestBasis($chain);
            return ApiError::conflict('A newer basis exists for this Ticket (base_mismatch)',
                ['reason' => 'base_mismatch', 'current' => $now ? $now['id'] : null, 'base' => $base, 'record' => $now ? Repo::basisDto($now) : null]);
        };
        if (($latest ? $latest['id'] : null) !== ($base === null ? null : strtolower($base))) throw $fail();
        $id = Repo::uuid();
        $detail = ['as_of_source' => $source, 'candidates' => $res['candidates'], 'ended_on' => $res['ended_on'], 'terminated_on' => $res['terminated_on'],
                   'schedule' => $res['schedule'], 'native_sla_ref' => $res['native_sla_ref'], 'context_organization_id' => $ticketOrg ? self::n($ticketOrg['org_id']) : null];
        Db::q('INSERT IGNORE INTO ' . Repo::table('basis') . ' SET id=' . Db::esc($id) . ', schema_version=' . Repo::SCHEMA_VERSION
            . ', chain_key=' . Db::esc($chain) . ', prev_key=' . Db::esc($latest ? $latest['id'] : '0')
            . ', consumer=' . Db::esc('ticket:' . (int) $t->getId()) . ', purpose=' . Db::esc($purpose)
            . ', as_of=' . Db::esc(gmdate('Y-m-d H:i:s', $ts)) . ', as_of_local_date=' . Db::esc($local) . ', tz=' . Db::esc($tz)
            . ', service_line=' . Db::esc($line) . ', equipment_ref=' . ($eq === null ? 'NULL' : (int) $eq)
            . ', organization_id_at_evaluation=' . ($orgId === null ? 'NULL' : (int) $orgId) . ', result=' . Db::esc($res['result'])
            . ', contract_ref=' . Repo::lit($res['contract_ref']) . ', contract_version_ref=' . Repo::lit($res['contract_version_ref']) . ', terms_hash=' . Repo::lit($res['terms_hash'])
            . ', treatment=' . Repo::lit($res['treatment']) . ', tier_code=' . Repo::lit($res['tier_code'])
            . ', response_objective_hours=' . ($res['response_objective_hours'] === null ? 'NULL' : (int) $res['response_objective_hours'])
            . ', catalog_version_ref=' . Repo::lit($res['catalog_version_ref']) . ', code=' . Repo::lit($code)
            . ', ticket_sla_id_at_evaluation=' . ($sla ? (int) $sla->getId() : 'NULL') . ', ticket_sla_name_at_evaluation=' . Repo::lit($sla ? mb_substr((string) $sla->getName(), 0, 120) : null)
            . ', seen_contract_version_ref=' . Repo::lit($sv) . ', seen_result=' . Repo::lit($sr) . ', seen_cache_etag=' . Repo::lit($se)
            . ', seen_mismatch=' . ($mismatch === null ? 'NULL' : ($mismatch ? 1 : 0))
            . ', detail=' . Db::esc(json_encode((object) $detail, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES))
            . ', recorded_at=UTC_TIMESTAMP(6), recorded_by=' . $staffId . ', reason=' . Repo::lit($reason));
        if (Db::affected() !== 1) throw $fail();
        return Res::created(Repo::basisDto(Db::row('SELECT * FROM ' . Repo::table('basis') . ' WHERE id=' . Db::esc($id))));
    }

    // ==================================================================
    // helpers
    // ==================================================================

    private static function manager(Request $req) {
        if (!array_key_exists('manager', $req->ctx)) $req->ctx['manager'] = Maintenance::isManagerOfAny($req->staff);
        return $req->ctx['manager'];
    }

    private static function mayRead(Request $req, $orgId) {
        return self::manager($req) || Directory::mayRead($req->staff, 'organization', $orgId);
    }

    private static function find($uuid) {
        $u = strtolower((string) $uuid);
        return preg_match(Documents::UUID, $u) ? Repo::contract($u) : null;
    }

    private static function load(Request $req) {
        $c = self::find($req->param('uuid'));
        if (!$c) throw ApiError::notFound('contract');
        return $c;
    }

    private static function n($v) { return $v === null ? null : (int) $v; }

    private static function noop($contractId) { return Res::ok(Repo::full($contractId, true), ['applied' => false]); }

    private static function notVoided(array $c) {
        if ($c['status'] === 'VOIDED') self::conflict('invalid_state', 'VOIDED', null, ['required' => 'not VOIDED']);
    }

    /** Stale base / invalid state: typed 409; guard() adds the fresh record and the last event after the rollback. */
    private static function conflict($reason, $current, $base, array $extra = [], $message = null) {
        throw ApiError::conflict($message ?: 'The contract changed on the server (' . $reason . ')', array_merge(['reason' => $reason, 'current' => $current, 'base' => $base], $extra));
    }

    /** Run a write in a transaction; a conflict leaves the database untouched and carries the server's current record. */
    private static function guard($contractId, callable $f) {
        try {
            return Repo::tx($f);
        } catch (ApiError $e) {
            if ($e->errorCode === 'conflict' && isset($e->details['reason']) && !isset($e->details['record'])) {
                $e->details['last_change'] = Repo::lastEvent($contractId);
                $e->details['record'] = Repo::full($contractId, true);
            }
            throw $e;
        }
    }

    private static function text($v, $field, $required, $max = null) {
        $max = $max ?: self::TEXT_MAX;
        if ($v === null || (is_string($v) && trim($v) === '')) {
            if ($required) throw ApiError::validation("'$field' is required", $field);
            return null;
        }
        if (!is_string($v) || mb_strlen($v) > $max)
            throw ApiError::validation("'$field' must be text of at most $max characters", $field);
        return trim($v);
    }

    private static function baseRev(Request $req) {
        $b = Ticketing::requireBase($req);
        if (!is_int($b) || $b < 1)
            throw ApiError::validation("'base' must be the rev you saw", 'base');
        return $b;
    }

    /** Kind of the version being saved/activated against the state of the contract (I1/I3). */
    private static function draftKind(array $c, $kind) {
        if ($c['status'] === 'DRAFT') {
            if ($kind !== null && $kind !== 'INITIAL') throw ApiError::validation('The first version of a contract is INITIAL', 'kind');
            return 'INITIAL';
        }
        if ($kind === null || $kind === 'INITIAL')
            throw ApiError::validation("'kind' must be RENEWAL, AMENDMENT or CORRECTION for a contract that already has a version", 'kind');
        return $kind;
    }

    /** I7: CORRECTION needs `supersedes`; it must be a version of the SAME contract that is EFFECTIVE or VOIDED. @return array|null the row */
    private static function checkSupersedes(array $c, $kind, $supId, $forUpdate = false) {
        if ($supId === null) {
            if ($kind === 'CORRECTION') throw ApiError::validation("A CORRECTION must name the version it supersedes ('supersedes')", 'supersedes');
            return null;
        }
        $s = Repo::version($supId, $forUpdate);
        if (!$s || $s['contract_id'] !== $c['id'] || !in_array($s['status'], ['EFFECTIVE', 'VOIDED'], true))
            throw ApiError::validation("'supersedes' must be an EFFECTIVE or VOIDED version of this contract", 'supersedes');
        return $s;
    }

    /** The catalog and the native SLA a body names must exist. */
    private static function checkRefs(array $body) {
        if ($body['catalog_version_ref'] !== null && !Repo::catalog($body['catalog_version_ref']))
            throw ApiError::validation('Unknown catalog_version_ref', 'catalog_version_ref');
        $sla = $body['response_policy']['native_sla_ref'] ?? null;
        if ($sla !== null && !Db::row('SELECT id FROM ' . SLA_TABLE . ' WHERE id=' . (int) $sla))
            throw ApiError::validation('Unknown native_sla_ref (osTicket SLA id)', 'response_policy.native_sla_ref');
    }

    /**
     * V8: every named equipment exists in the Installed Equipment list; being ACTIVE and of the contract's organization is a BLOCK, except in a
     * CORRECTION or a retroactive version, where it is a warning (M7 keeps no history of state or owner). @return array warnings
     */
    private static function checkEquipment(array $c, array $body, $retro) {
        $cov = $body['coverage'];
        $warnings = [];
        $listed = $cov['mode'] === 'LISTED' ? $cov['equipment_ids'] : [];
        $excluded = $cov['mode'] === 'ORG_ALL' ? $cov['excluded_ids'] : [];
        foreach ($listed as $id) {
            $e = Repo::equipment($id);
            if ($e === null)
                throw ApiError::validation("Equipment $id is not an item of the Installed Equipment list", 'coverage.equipment_ids', ['reason' => 'unknown_equipment', 'equipment_id' => $id]);
            $why = $e['status'] !== 'ACTIVE' ? 'equipment_retired' : ((int) $e['organization_id'] !== (int) $c['organization_id'] ? 'equipment_other_organization' : null);
            if ($why === null) continue;
            if (!$retro)
                throw ApiError::validation("Equipment $id " . ($why === 'equipment_retired' ? 'is retired' : 'does not belong to the contract organization'), 'coverage.equipment_ids', ['reason' => $why, 'equipment_id' => $id]);
            $warnings[] = ['code' => $why, 'equipment_id' => $id, 'message' => 'Kept because the version is retroactive or a correction'];
        }
        foreach ($excluded as $id)
            if (Repo::equipment($id) === null)
                throw ApiError::validation("Excluded equipment $id is not an item of the Installed Equipment list", 'coverage.excluded_ids', ['reason' => 'unknown_equipment', 'equipment_id' => $id]);
        return $warnings;
    }

    /** CT-D12: the title is controlled text, never the file name and never an amount. Default = the label of the role. */
    private static function displayTitle($v, $role, $fileName) {
        if ($v === null || (is_string($v) && trim($v) === ''))
            return Repo::ROLES[$role];
        if (!is_string($v) || mb_strlen(trim($v)) > 80 || preg_match('/[\x00-\x1F\x7F]/u', $v))
            throw ApiError::validation("'display_title' must be short plain text (max 80 characters)", 'display_title');
        $t = trim($v);
        if (strcasecmp($t, $fileName) === 0 || preg_match('/\.pdf$/i', $t))
            throw ApiError::validation("'display_title' cannot be the file name", 'display_title', ['reason' => 'title_is_file_name']);
        if (preg_match('/[$€£]\s*\d|\d\s*(USD|MXN|EUR|MN)\b/i', $t))
            throw ApiError::validation("'display_title' cannot carry amounts or commercial terms", 'display_title', ['reason' => 'title_has_amount']);
        return $t;
    }

    /** Evaluation organization and equipment (CT-E2 step 1). @return array [org|null, equipment|null] */
    private static function evaluationContext($orgQ, $eqQ, $lenient = false) {
        $org = null; $eq = null;
        if ($orgQ !== null) {
            if (!ctype_digit((string) $orgQ) || (int) $orgQ < 1) throw ApiError::validation("'organization_id' must be a positive integer", 'organization_id');
            $org = (int) $orgQ;
        }
        if ($eqQ !== null) {
            if (!ctype_digit((string) $eqQ) || (int) $eqQ < 1) throw ApiError::validation("'equipment_id' must be a positive integer", 'equipment_id');
            $eq = (int) $eqQ;
            $e = Repo::equipment($eq);
            if ($e === null) throw ApiError::validation('The equipment is not an item of the Installed Equipment list', 'equipment_id', ['reason' => 'unknown_equipment']);
            if ($e['organization_id'] !== null) $org = $e['organization_id'];
        }
        if ($org === null && !$lenient)
            throw ApiError::validation("'organization_id' is required (the equipment has no owner organization)", 'organization_id');
        return [$org, $eq];
    }

    /** @return array [unix ts|null, installation local date] from `as_of` (ISO-8601 instant, or a plain date; default now) */
    private static function asOf($v) {
        $tz = new \DateTimeZone(Repo::tz());
        if ($v === null) { $ts = time(); return [$ts, (new \DateTime('@' . $ts))->setTimezone($tz)->format('Y-m-d')]; }
        if (!is_string($v)) throw ApiError::validation("'as_of' must be a date or an ISO-8601 instant", 'as_of');
        if (ContractTerms::date($v) !== null) return [null, $v];
        try { $d = new \DateTime($v); } catch (\Exception $e) { throw ApiError::validation("'as_of' must be a date or an ISO-8601 instant", 'as_of'); }
        if (!preg_match('/\d{2}:\d{2}/', $v)) throw ApiError::validation("'as_of' must be a date or an ISO-8601 instant", 'as_of');
        $ts = $d->getTimestamp();
        return [$ts, (new \DateTime('@' . $ts))->setTimezone($tz)->format('Y-m-d')];
    }

    /** as_of of a RS_CLASSIFICATION basis: the Ticket's `reported_at` field when present and valid, else its creation time. @return array [ts, source] */
    private static function ticketInstant(\Ticket $t) {
        require_once(INCLUDE_DIR . 'class.dynamic_forms.php');
        try {
            foreach (\DynamicFormEntry::forTicket($t->getId()) as $entry)
                foreach ($entry->getFields() as $f)
                    if ($f->get('name') === 'reported_at' && ($a = $f->getAnswer())) {
                        $v = $a->getValue();
                        if (is_string($v) && trim($v) !== '' && ($ts = strtotime($v)) !== false && $ts > 0)
                            return [$ts, 'reported_at'];
                    }
        } catch (\Throwable $e) { /* fall through to the creation time */ }
        $r = Db::row('SELECT created FROM ' . TICKET_TABLE . ' WHERE ticket_id=' . (int) $t->getId());
        $ts = $r ? Time::gm($r['created']) : null;
        return [$ts ?: time(), 'ticket_created'];
    }
}
