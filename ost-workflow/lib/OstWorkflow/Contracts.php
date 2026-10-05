<?php
namespace OstWorkflow;

/**
 * Durable store of Contracts / Service Agreements (OW-REQ-71, module 10; D-22 bounded exception authorized 2026-10-04).
 * Same pattern as Maintenance (OW-REQ-70), six own tables in the osTicket database:
 *
 *   workflow_contract           Contract (header + state; organization fixed for life)
 *   workflow_contract_version   ContractVersion (immutable terms once EFFECTIVE, terms_hash sha256)
 *   workflow_contract_event     ContractEvent (append-only: this class only INSERTs it)
 *   workflow_contract_document  ContractDocument metadata (the PDF is linked durably through a core `attachment` row)
 *   workflow_contract_catalog   ClassificationCatalogVersion (immutable, numbered)
 *   workflow_contract_basis     EntitlementBasis (immutable; a correction is a new row that supersedes)
 *
 * Durability (MUST V1 point 1): no TTL and no purge (Store::purge only touches the idempotency table), `schema_version` on
 * every row, CREATE TABLE IF NOT EXISTS from enable() only, never dropped on disable/uninstall, in the osTicket database so the
 * normal backup includes it. Rows are never deleted: a draft is DISCARDED, a version or contract VOIDED, a contract CANCELLED.
 *
 * Guarantees held by the database, not by the client: one open draft per contract (UNIQUE contract_id, open_draft), one
 * EFFECTIVE version per effective_from in a contract (UNIQUE contract_id, eff_from_key), contract number unique within an
 * organization (UNIQUE organization_id, ext_key), catalog numbers (UNIQUE number), one basis per link of a chain (UNIQUE chain_key,
 * prev_key); writers use INSERT/UPDATE IGNORE + `rev` compare-and-swap so a race answers a typed 409. Activation, voiding and
 * revoking a termination (the operations that can widen a period) run in one transaction that first locks every contract row
 * of the organization (SELECT ... FOR UPDATE), so two activations in different contracts of one organization serialize.
 * The body of a version is only ever UPDATEd `WHERE status='DRAFT'`.
 *
 * All timestamps are UTC with microseconds.
 */
final class Contracts {
    const SCHEMA_VERSION = 1;
    const ATTACH_TYPE = 'W';           // core `attachment`.type of a contract document (unused by the core): the durable link
    const ROLES = ['CONTRACT' => 'Contrato', 'ANNEX' => 'Anexo', 'AMENDMENT' => 'Enmienda', 'OTHER' => 'Otro documento'];

    static function table($name = '') { return TABLE_PREFIX . 'workflow_contract' . ($name === '' ? '' : '_' . $name); }

    static function install() {
        $ct = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4';
        $sql = [];
        $sql[] = 'CREATE TABLE IF NOT EXISTS `' . self::table() . '` (
            `id` CHAR(36) NOT NULL,
            `schema_version` SMALLINT UNSIGNED NOT NULL DEFAULT ' . self::SCHEMA_VERSION . ',
            `organization_id` INT UNSIGNED NOT NULL,
            `title` VARCHAR(255) NOT NULL,
            `external_number` VARCHAR(80) NULL,
            `ext_key` VARCHAR(80) NULL,
            `status` VARCHAR(10) NOT NULL DEFAULT \'DRAFT\',
            `terminated_on` DATE NULL,
            `termination_reason` TEXT NULL,
            `rev` INT UNSIGNED NOT NULL DEFAULT 1,
            `created_at` DATETIME(6) NOT NULL,
            `created_by` INT UNSIGNED NOT NULL,
            `updated_at` DATETIME(6) NOT NULL,
            `updated_by` INT UNSIGNED NOT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uq_org_number` (`organization_id`, `ext_key`),
            KEY `idx_org` (`organization_id`),
            KEY `idx_updated` (`updated_at`, `id`)
        ) ' . $ct;
        $sql[] = 'CREATE TABLE IF NOT EXISTS `' . self::table('version') . '` (
            `id` CHAR(36) NOT NULL,
            `schema_version` SMALLINT UNSIGNED NOT NULL DEFAULT ' . self::SCHEMA_VERSION . ',
            `contract_id` CHAR(36) NOT NULL,
            `number` INT UNSIGNED NOT NULL,
            `kind` VARCHAR(12) NOT NULL,
            `supersedes` CHAR(36) NULL,
            `status` VARCHAR(10) NOT NULL DEFAULT \'DRAFT\',
            `effective_from` DATE NOT NULL,
            `effective_to` DATE NOT NULL,
            `body` MEDIUMTEXT NOT NULL,
            `hash_schema` SMALLINT UNSIGNED NULL,
            `terms_hash` CHAR(64) NULL,
            `open_draft` TINYINT UNSIGNED NULL,
            `eff_from_key` DATE NULL,
            `rev` INT UNSIGNED NOT NULL DEFAULT 1,
            `created_at` DATETIME(6) NOT NULL,
            `created_by` INT UNSIGNED NOT NULL,
            `registered_at` DATETIME(6) NULL,
            `registered_by` INT UNSIGNED NULL,
            `updated_at` DATETIME(6) NOT NULL,
            `updated_by` INT UNSIGNED NOT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uq_contract_number` (`contract_id`, `number`),
            UNIQUE KEY `uq_one_draft` (`contract_id`, `open_draft`),
            UNIQUE KEY `uq_eff_from` (`contract_id`, `eff_from_key`),
            KEY `idx_contract` (`contract_id`)
        ) ' . $ct;
        $sql[] = 'CREATE TABLE IF NOT EXISTS `' . self::table('event') . '` (
            `seq` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `id` CHAR(36) NOT NULL,
            `schema_version` SMALLINT UNSIGNED NOT NULL DEFAULT ' . self::SCHEMA_VERSION . ',
            `contract_id` CHAR(36) NOT NULL,
            `version_id` CHAR(36) NULL,
            `kind` VARCHAR(24) NOT NULL,
            `actor` INT UNSIGNED NOT NULL,
            `at` DATETIME(6) NOT NULL,
            `reason` TEXT NULL,
            `detail` MEDIUMTEXT NOT NULL,
            PRIMARY KEY (`seq`),
            UNIQUE KEY `uq_id` (`id`),
            KEY `idx_contract` (`contract_id`, `seq`)
        ) ' . $ct;
        $sql[] = 'CREATE TABLE IF NOT EXISTS `' . self::table('document') . '` (
            `seq` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `id` CHAR(36) NOT NULL,
            `schema_version` SMALLINT UNSIGNED NOT NULL DEFAULT ' . self::SCHEMA_VERSION . ',
            `contract_id` CHAR(36) NOT NULL,
            `version_id` CHAR(36) NOT NULL,
            `file_id` INT UNSIGNED NOT NULL,
            `sha256` CHAR(64) NOT NULL,
            `size` INT UNSIGNED NOT NULL,
            `role` VARCHAR(10) NOT NULL,
            `display_title` VARCHAR(120) NOT NULL,
            `status` VARCHAR(10) NOT NULL DEFAULT \'ACTIVE\',
            `superseded_by` CHAR(36) NULL,
            `registered_at` DATETIME(6) NOT NULL,
            `registered_by` INT UNSIGNED NOT NULL,
            PRIMARY KEY (`seq`),
            UNIQUE KEY `uq_id` (`id`),
            KEY `idx_version` (`version_id`),
            KEY `idx_contract` (`contract_id`),
            KEY `idx_file` (`file_id`)
        ) ' . $ct;
        $sql[] = 'CREATE TABLE IF NOT EXISTS `' . self::table('catalog') . '` (
            `id` CHAR(36) NOT NULL,
            `schema_version` SMALLINT UNSIGNED NOT NULL DEFAULT ' . self::SCHEMA_VERSION . ',
            `number` INT UNSIGNED NOT NULL,
            `codes` MEDIUMTEXT NOT NULL,
            `reason` TEXT NOT NULL,
            `registered_at` DATETIME(6) NOT NULL,
            `registered_by` INT UNSIGNED NOT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uq_number` (`number`)
        ) ' . $ct;
        $sql[] = 'CREATE TABLE IF NOT EXISTS `' . self::table('basis') . '` (
            `id` CHAR(36) NOT NULL,
            `schema_version` SMALLINT UNSIGNED NOT NULL DEFAULT ' . self::SCHEMA_VERSION . ',
            `chain_key` VARCHAR(80) NOT NULL,
            `prev_key` CHAR(36) NOT NULL DEFAULT \'0\',
            `consumer` VARCHAR(40) NOT NULL,
            `purpose` VARCHAR(30) NOT NULL,
            `as_of` DATETIME(6) NOT NULL,
            `as_of_local_date` DATE NOT NULL,
            `tz` VARCHAR(64) NOT NULL,
            `service_line` VARCHAR(24) NOT NULL,
            `equipment_ref` INT UNSIGNED NULL,
            `organization_id_at_evaluation` INT UNSIGNED NULL,
            `result` VARCHAR(20) NOT NULL,
            `contract_ref` CHAR(36) NULL,
            `contract_version_ref` CHAR(36) NULL,
            `terms_hash` CHAR(64) NULL,
            `treatment` VARCHAR(20) NULL,
            `tier_code` VARCHAR(40) NULL,
            `response_objective_hours` INT UNSIGNED NULL,
            `catalog_version_ref` CHAR(36) NULL,
            `code` VARCHAR(40) NULL,
            `ticket_sla_id_at_evaluation` INT UNSIGNED NULL,
            `ticket_sla_name_at_evaluation` VARCHAR(120) NULL,
            `seen_contract_version_ref` CHAR(36) NULL,
            `seen_result` VARCHAR(20) NULL,
            `seen_cache_etag` VARCHAR(80) NULL,
            `seen_mismatch` TINYINT UNSIGNED NULL,
            `detail` MEDIUMTEXT NOT NULL,
            `recorded_at` DATETIME(6) NOT NULL,
            `recorded_by` INT UNSIGNED NOT NULL,
            `reason` TEXT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uq_chain` (`chain_key`, `prev_key`),
            KEY `idx_consumer` (`consumer`, `purpose`),
            KEY `idx_contract` (`contract_ref`)
        ) ' . $ct;
        $ok = true;
        foreach ($sql as $s) $ok = (db_query($s) !== false) && $ok;
        return $ok;
    }

    /** 503 when the tables were never created (plugin updated without a disable/enable). */
    static function ready() {
        $n = 0;
        $q = Store::q('SHOW TABLES LIKE ' . Store::esc(str_replace('_', '\\_', self::table()) . '%'));
        while ($q && db_fetch_array($q)) $n++;
        if ($n < 6)
            throw new ApiError('not_configured', 'The contracts store is not installed: disable and enable the plugin once');
    }

    /** The newest basis of a chain: the one no other basis names as its predecessor (deterministic, no clock involved). */
    static function latestBasis($chainKey) {
        return Store::row('SELECT b.* FROM ' . self::table('basis') . ' b WHERE b.chain_key=' . Store::esc($chainKey)
            . ' AND NOT EXISTS (SELECT 1 FROM ' . self::table('basis') . ' n WHERE n.chain_key=b.chain_key AND n.prev_key=b.id) LIMIT 1');
    }

    // ------------------------------------------------------------------ small helpers

    static function uuid() {
        $b = random_bytes(16);
        $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
        $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);
        $h = bin2hex($b);
        return substr($h, 0, 8) . '-' . substr($h, 8, 4) . '-' . substr($h, 12, 4) . '-' . substr($h, 16, 4) . '-' . substr($h, 20);
    }

    static function rows($sql) {
        $q = Store::q($sql);
        $o = [];
        while ($q && ($r = db_fetch_array($q))) $o[] = $r;
        return $o;
    }

    static function utc($v) { return $v ? str_replace(' ', 'T', substr($v, 0, 19)) . 'Z' : null; }
    private static function n($v) { return $v === null ? null : (int) $v; }
    static function lit($v) { return $v === null ? 'NULL' : Store::esc($v); }

    /** Run $f in one transaction; READ COMMITTED so every statement after a lock sees what the previous holder committed. */
    static function tx(callable $f) {
        Store::q('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');
        Store::q('START TRANSACTION');
        try {
            $r = call_user_func($f);
            Store::q('COMMIT');
            return $r;
        } catch (\Throwable $t) {
            db_query('ROLLBACK');
            throw $t;
        }
    }

    /** Installation time zone (CT-D9): the default timezone of osTicket, not the staff's. */
    static function tz() {
        global $cfg;
        $z = $cfg ? (string) $cfg->getDefaultTimezone() : '';
        try { new \DateTimeZone($z); } catch (\Exception $e) { $z = 'UTC'; }
        return $z !== '' ? $z : 'UTC';
    }

    static function today() { return (new \DateTime('now', new \DateTimeZone(self::tz())))->format('Y-m-d'); }

    // ------------------------------------------------------------------ equipment (M7 Installed Equipment, by id)

    /** @return array|null {status: ACTIVE|RETIRED, organization_id: int|null} or null when it is not an item of the Installed Equipment list */
    static function equipment($id) {
        $listId = Runtime::intSetting(Maintenance::SETTING_LIST, 0);
        if ($listId < 1)
            throw new ApiError('not_configured', "The plugin setting 'installed_equipment_list_id' (Installed Equipment list) is not set");
        require_once(INCLUDE_DIR . 'class.list.php');
        $it = \DynamicListItem::lookup((int) $id);
        if (!$it || (int) $it->get('list_id') !== $listId)
            return null;
        $org = null;
        // Equipment design 13.2: extra = 'BCW|1|O=<org>|M=..|S=..|E=1'; anything else is fail-safe (no owner).
        $x = (string) $it->get('extra');
        if (preg_match('/^BCW\|1\|.*\|E=1$/', $x) && preg_match('/\|O=(\d+)(\||$)/', $x, $m))
            $org = (int) $m[1];
        return ['status' => $it->isEnabled() ? 'ACTIVE' : 'RETIRED', 'organization_id' => $org];
    }

    // ------------------------------------------------------------------ reads

    static function contract($id, $lock = false) {
        return Store::row('SELECT * FROM ' . self::table() . ' WHERE id=' . Store::esc($id) . ($lock ? ' FOR UPDATE' : ''));
    }

    /** Lock every contract row of the organization (ordered by id: no deadlock between two activations). */
    static function lockOrganization($orgId) {
        Store::q('SELECT id FROM ' . self::table() . ' WHERE organization_id=' . (int) $orgId . ' ORDER BY id FOR UPDATE');
    }

    static function version($id, $lock = false) {
        return Store::row('SELECT * FROM ' . self::table('version') . ' WHERE id=' . Store::esc($id) . ($lock ? ' FOR UPDATE' : ''));
    }

    static function versionsOf($contractId) {
        return self::rows('SELECT * FROM ' . self::table('version') . ' WHERE contract_id=' . Store::esc($contractId) . ' ORDER BY number');
    }

    static function draftOf($contractId) {
        return Store::row('SELECT * FROM ' . self::table('version') . ' WHERE contract_id=' . Store::esc($contractId) . ' AND status=\'DRAFT\'');
    }

    /** @return array version id => document rows (oldest first) */
    static function documentsOf(array $versionIds) {
        $out = [];
        if (!$versionIds) return $out;
        $in = implode(',', array_map([Store::class, 'esc'], $versionIds));
        foreach (self::rows('SELECT * FROM ' . self::table('document') . ' WHERE version_id IN (' . $in . ') ORDER BY seq') as $d)
            $out[$d['version_id']][] = $d;
        return $out;
    }

    static function document($id) {
        return Store::row('SELECT * FROM ' . self::table('document') . ' WHERE id=' . Store::esc($id));
    }

    static function events($contractId, $afterSeq, $limit) {
        return self::rows('SELECT * FROM ' . self::table('event') . ' WHERE contract_id=' . Store::esc($contractId)
            . ' AND seq>' . (int) $afterSeq . ' ORDER BY seq LIMIT ' . ($limit + 1));
    }

    static function lastEvent($contractId) {
        $r = Store::row('SELECT * FROM ' . self::table('event') . ' WHERE contract_id=' . Store::esc($contractId) . ' ORDER BY seq DESC LIMIT 1');
        return $r ? self::eventDto($r) : null;
    }

    /** Distinct organizations that have contracts. */
    static function organizationsWithContracts() {
        $o = [];
        foreach (self::rows('SELECT DISTINCT organization_id FROM ' . self::table()) as $r) $o[] = (int) $r['organization_id'];
        sort($o, SORT_NUMERIC);
        return $o;
    }

    /** Feed page: contracts changed after the cursor, ordered by (updated_at, id). $orgs null = every organization. */
    static function feed($after, $orgs) {
        $w = ['1=1'];
        if ($after)
            $w[] = '(updated_at > ' . Store::esc($after['t']) . ' OR (updated_at = ' . Store::esc($after['t']) . ' AND id > ' . Store::esc($after['i']) . '))';
        if ($orgs !== null) $w[] = $orgs ? 'organization_id IN (' . implode(',', array_map('intval', $orgs)) . ')' : '0=1';
        return self::rows('SELECT * FROM ' . self::table() . ' WHERE ' . implode(' AND ', $w) . ' ORDER BY updated_at, id');
    }

    static function cursor(array $r) { return Token::b64(json_encode(['t' => $r['updated_at'], 'i' => $r['id']])); }

    static function decodeCursor($c) {
        if ($c === null) return null;
        $d = json_decode(Token::unb64($c), true);
        if (!is_array($d) || !isset($d['t'], $d['i']) || !is_string($d['t']) || !is_string($d['i'])
            || !preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}(\.\d{1,6})?$/', $d['t']) || !preg_match(Documents::UUID, $d['i']))
            throw ApiError::validation('Invalid cursor', 'cursor');
        return $d;
    }

    static function catalogs() {
        return self::rows('SELECT * FROM ' . self::table('catalog') . ' ORDER BY number');
    }

    static function catalog($id) {
        return Store::row('SELECT * FROM ' . self::table('catalog') . ' WHERE id=' . Store::esc($id));
    }

    static function latestCatalogNumber() {
        $r = Store::row('SELECT MAX(number) AS n FROM ' . self::table('catalog'));
        return $r && $r['n'] !== null ? (int) $r['n'] : null;
    }

    // ------------------------------------------------------------------ DTOs

    static function eventDto(array $r) {
        return ['id' => $r['id'], 'seq' => (int) $r['seq'], 'contract_ref' => $r['contract_id'], 'contract_version_ref' => $r['version_id'],
                'kind' => $r['kind'], 'actor' => (int) $r['actor'], 'at' => self::utc($r['at']), 'reason' => $r['reason'],
                'detail' => json_decode((string) $r['detail'], true) ?: (object) []];
    }

    /** Readers get existence, display title, role, state and date; Admin or manager also the hash, size, author and the download path. */
    static function documentDto(array $d, $contractId, $manager) {
        $o = ['document_ref' => $d['id'], 'contract_version_ref' => $d['version_id'], 'role' => $d['role'], 'display_title' => $d['display_title'],
              'status' => $d['status'], 'registered_at' => self::utc($d['registered_at'])];
        if ($manager)
            $o += ['sha256' => $d['sha256'], 'size' => (int) $d['size'], 'registered_by' => (int) $d['registered_by'], 'superseded_by' => $d['superseded_by'],
                   'download' => '/contracts/' . $contractId . '/documents/' . $d['id'] . '/file'];
        return $o;
    }

    static function body(array $v) { return json_decode((string) $v['body'], true); }

    static function versionDto(array $v, array $docs, $manager, $range) {
        $b = self::body($v);
        $o = [
            'contract_version_ref' => $v['id'], 'number' => (int) $v['number'], 'kind' => $v['kind'], 'status' => $v['status'], 'supersedes' => $v['supersedes'],
            'effective_from' => $v['effective_from'], 'effective_to' => $v['effective_to'],
            'applicable_from' => $range ? $range[0] : null, 'applicable_to' => $range ? $range[1] : null,
            'coverage' => $b['coverage'], 'entitlements' => $b['entitlements'], 'response_policy' => $b['response_policy'], 'catalog_version_ref' => $b['catalog_version_ref'],
            'hash_schema' => self::n($v['hash_schema']), 'terms_hash' => $v['terms_hash'], 'registered_at' => self::utc($v['registered_at']),
            'documents' => array_map(function ($d) use ($v, $manager) { return self::documentDto($d, $v['contract_id'], $manager); }, $docs),
        ];
        if ($manager)
            $o += ['rev' => (int) $v['rev'], 'registered_by' => self::n($v['registered_by']), 'created_at' => self::utc($v['created_at']), 'created_by' => (int) $v['created_by']];
        return $o;
    }

    /**
     * A contract with its versions. Readers do not see drafts or discarded drafts (unreviewed terms); Admin or manager see all.
     * @param array[] $versions version rows; @param array $docsBy version id => document rows
     */
    static function contractDto(array $c, array $versions, array $docsBy, $manager) {
        $eff = [];
        foreach ($versions as $v)
            if ($v['status'] === 'EFFECTIVE') $eff[] = ['id' => $v['id'], 'effective_from' => $v['effective_from'], 'effective_to' => $v['effective_to']];
        $ranges = ContractTerms::ranges($eff, $c['terminated_on']);
        $vs = [];
        foreach ($versions as $v) {
            if (!$manager && !in_array($v['status'], ['EFFECTIVE', 'VOIDED'], true)) continue;
            $vs[] = self::versionDto($v, $docsBy[$v['id']] ?? [], $manager, $ranges[$v['id']] ?? null);
        }
        return [
            'contract_ref' => $c['id'], 'organization_id' => (int) $c['organization_id'], 'title' => $c['title'], 'external_number' => $c['external_number'],
            'status' => $c['status'], 'terminated_on' => $c['terminated_on'], 'termination_reason' => $c['termination_reason'], 'rev' => (int) $c['rev'],
            'created_at' => self::utc($c['created_at']), 'created_by' => (int) $c['created_by'], 'updated_at' => self::utc($c['updated_at']), 'updated_by' => (int) $c['updated_by'],
            'versions' => $vs,
        ];
    }

    /** Contract DTO loaded from the database. */
    static function full($contractId, $manager) {
        $c = self::contract($contractId);
        $vs = self::versionsOf($contractId);
        return self::contractDto($c, $vs, self::documentsOf(array_map(function ($v) { return $v['id']; }, $vs)), $manager);
    }

    static function catalogDto(array $r) {
        return ['catalog_version_ref' => $r['id'], 'number' => (int) $r['number'], 'codes' => json_decode((string) $r['codes'], true) ?: [],
                'reason' => $r['reason'], 'registered_at' => self::utc($r['registered_at']), 'registered_by' => (int) $r['registered_by']];
    }

    static function basisDto(array $r) {
        return [
            'id' => $r['id'], 'supersedes' => $r['prev_key'] === '0' ? null : $r['prev_key'], 'consumer' => $r['consumer'], 'purpose' => $r['purpose'],
            'as_of' => self::utc($r['as_of']), 'as_of_local_date' => $r['as_of_local_date'], 'tz' => $r['tz'], 'service_line' => $r['service_line'],
            'equipment_ref' => self::n($r['equipment_ref']), 'organization_id_at_evaluation' => self::n($r['organization_id_at_evaluation']),
            'result' => $r['result'], 'contract_ref' => $r['contract_ref'], 'contract_version_ref' => $r['contract_version_ref'], 'terms_hash' => $r['terms_hash'],
            'treatment' => $r['treatment'], 'tier_code' => $r['tier_code'], 'response_objective_hours' => self::n($r['response_objective_hours']),
            'catalog_version_ref' => $r['catalog_version_ref'], 'code' => $r['code'],
            'ticket_sla_id_at_evaluation' => self::n($r['ticket_sla_id_at_evaluation']), 'ticket_sla_name_at_evaluation' => $r['ticket_sla_name_at_evaluation'],
            'seen_contract_version_ref' => $r['seen_contract_version_ref'], 'seen_result' => $r['seen_result'], 'seen_cache_etag' => $r['seen_cache_etag'],
            'seen_mismatch' => $r['seen_mismatch'] === null ? null : (bool) $r['seen_mismatch'],
            'detail' => json_decode((string) $r['detail'], true) ?: (object) [],
            'recorded_at' => self::utc($r['recorded_at']), 'recorded_by' => (int) $r['recorded_by'], 'reason' => $r['reason'],
        ];
    }

    // ------------------------------------------------------------------ writes (callers hold the transaction where it matters)

    /** Append-only: the ONLY statement that touches the event table besides reads. */
    static function event($contractId, $versionId, $kind, $staffId, $reason, array $detail) {
        Store::q('INSERT INTO ' . self::table('event') . ' SET id=' . Store::esc(self::uuid()) . ', schema_version=' . self::SCHEMA_VERSION
            . ', contract_id=' . Store::esc($contractId) . ', version_id=' . self::lit($versionId) . ', kind=' . Store::esc($kind)
            . ', actor=' . (int) $staffId . ', at=UTC_TIMESTAMP(6), reason=' . self::lit($reason)
            . ', detail=' . Store::esc(json_encode((object) $detail, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)));
    }

    /** Stamp the contract as changed (feed watermark) - the LAST statement of a write, right before COMMIT. */
    static function touch($contractId, $staffId) {
        Store::q('UPDATE ' . self::table() . ' SET updated_at=UTC_TIMESTAMP(6), updated_by=' . (int) $staffId . ' WHERE id=' . Store::esc($contractId));
    }

    /**
     * CAS update of the contract row: $set is [column => SQL literal]. @return bool false when `rev` moved or a UNIQUE key was taken.
     */
    static function updateContract(array $c, array $set, $staffId) {
        $sets = [];
        foreach ($set as $col => $lit) $sets[] = '`' . $col . '`=' . $lit;
        $sets[] = 'rev=rev+1';
        $sets[] = 'updated_at=UTC_TIMESTAMP(6)';
        $sets[] = 'updated_by=' . (int) $staffId;
        Store::q('UPDATE IGNORE ' . self::table() . ' SET ' . implode(', ', $sets) . ' WHERE id=' . Store::esc($c['id']) . ' AND rev=' . (int) $c['rev']);
        return Store::affected() === 1;
    }

    /** The only UPDATE of a version row: guarded by status so an EFFECTIVE body can never change here. */
    static function updateDraft(array $v, $kind, $supersedes, $from, $to, array $body, $staffId) {
        Store::q('UPDATE ' . self::table('version') . ' SET kind=' . Store::esc($kind) . ', supersedes=' . self::lit($supersedes)
            . ', effective_from=' . Store::esc($from) . ', effective_to=' . Store::esc($to) . ', body=' . Store::esc(json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES))
            . ', rev=rev+1, updated_at=UTC_TIMESTAMP(6), updated_by=' . (int) $staffId
            . ' WHERE id=' . Store::esc($v['id']) . ' AND status=\'DRAFT\' AND rev=' . (int) $v['rev']);
        return Store::affected() === 1;
    }

    /** State change of a version (never its body or dates). $set: column => SQL literal; guarded by the expected status. */
    static function setVersionState(array $v, $expectStatus, array $set, $staffId) {
        $sets = [];
        foreach ($set as $col => $lit) $sets[] = '`' . $col . '`=' . $lit;
        $sets[] = 'rev=rev+1';
        $sets[] = 'updated_at=UTC_TIMESTAMP(6)';
        $sets[] = 'updated_by=' . (int) $staffId;
        Store::q('UPDATE IGNORE ' . self::table('version') . ' SET ' . implode(', ', $sets) . ' WHERE id=' . Store::esc($v['id']) . ' AND status=' . Store::esc($expectStatus));
        return Store::affected() === 1;
    }

    /** Insert-if-free of a contract; false when the (organization, number) is taken. */
    static function insertContract($id, $orgId, $title, $number, $staffId) {
        Store::q('INSERT IGNORE INTO ' . self::table() . ' SET id=' . Store::esc($id) . ', schema_version=' . self::SCHEMA_VERSION
            . ', organization_id=' . (int) $orgId . ', title=' . Store::esc($title) . ', external_number=' . self::lit($number)
            . ', ext_key=' . self::lit(self::extKey($number)) . ', status=\'DRAFT\', rev=1, created_at=UTC_TIMESTAMP(6), created_by=' . (int) $staffId
            . ', updated_at=UTC_TIMESTAMP(6), updated_by=' . (int) $staffId);
        return Store::affected() === 1;
    }

    /** Insert-if-free of a draft; false when the contract already has one (UNIQUE open_draft). */
    static function insertDraft($id, $contractId, $number, $kind, $supersedes, $from, $to, array $body, $staffId) {
        Store::q('INSERT IGNORE INTO ' . self::table('version') . ' SET id=' . Store::esc($id) . ', schema_version=' . self::SCHEMA_VERSION
            . ', contract_id=' . Store::esc($contractId) . ', number=' . (int) $number . ', kind=' . Store::esc($kind) . ', supersedes=' . self::lit($supersedes)
            . ', status=\'DRAFT\', effective_from=' . Store::esc($from) . ', effective_to=' . Store::esc($to)
            . ', body=' . Store::esc(json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) . ', open_draft=1, rev=1'
            . ', created_at=UTC_TIMESTAMP(6), created_by=' . (int) $staffId . ', updated_at=UTC_TIMESTAMP(6), updated_by=' . (int) $staffId);
        return Store::affected() === 1;
    }

    static function nextNumber($contractId) {
        $r = Store::row('SELECT MAX(number) AS n FROM ' . self::table('version') . ' WHERE contract_id=' . Store::esc($contractId));
        return ($r && $r['n'] !== null ? (int) $r['n'] : 0) + 1;
    }

    static function extKey($number) {
        $n = $number === null ? '' : mb_strtolower(trim($number));
        return $n === '' ? null : $n;
    }

    // ------------------------------------------------------------------ organization-wide rules

    /** EFFECTIVE versions of the ACTIVE/CANCELLED contracts of an organization, decoded, for overlap and resolution. */
    static function effectiveOfOrganization($orgId) {
        $out = [];
        $rows = self::rows('SELECT v.*, c.terminated_on AS c_terminated_on FROM ' . self::table('version') . ' v JOIN ' . self::table() . ' c ON c.id=v.contract_id'
            . ' WHERE c.organization_id=' . (int) $orgId . ' AND c.status IN (\'ACTIVE\',\'CANCELLED\') AND v.status=\'EFFECTIVE\'');
        foreach ($rows as $r)
            $out[] = ['contract_ref' => $r['contract_id'], 'contract_version_ref' => $r['id'], 'effective_from' => $r['effective_from'], 'effective_to' => $r['effective_to'],
                      'terminated_on' => $r['c_terminated_on'], 'terms_hash' => $r['terms_hash'], 'body' => self::body($r)];
        return $out;
    }

    /**
     * CT-D8 over the whole organization on its derived applicable periods (CT-D2). Called inside the organization lock after every
     * operation that can widen a period (activate, void, revoke). @return array[] conflicts (empty = valid)
     */
    static function overlapsOfOrganization($orgId) {
        $by = [];
        foreach (self::effectiveOfOrganization($orgId) as $v) $by[$v['contract_ref']][] = $v;
        $items = [];
        foreach ($by as $ref => $vs) {
            $ranges = ContractTerms::ranges(array_map(function ($v) { return ['id' => $v['contract_version_ref'], 'effective_from' => $v['effective_from'], 'effective_to' => $v['effective_to']]; }, $vs), $vs[0]['terminated_on']);
            foreach ($vs as $v) {
                $r = $ranges[$v['contract_version_ref']];
                if ($r) $items[] = ['contract_ref' => $ref, 'contract_version_ref' => $v['contract_version_ref'], 'from' => $r[0], 'to' => $r[1], 'body' => $v['body']];
            }
        }
        return ContractTerms::overlaps($items);
    }

    /** Is the equipment named by an applicable LISTED version of a contract of ANOTHER organization on day $d? */
    static function listedElsewhere($orgId, $equipmentId, $d) {
        $rows = self::rows('SELECT v.*, c.terminated_on AS c_terminated_on, c.organization_id AS c_org FROM ' . self::table('version') . ' v JOIN ' . self::table() . ' c ON c.id=v.contract_id'
            . ' WHERE c.organization_id<>' . (int) $orgId . ' AND c.status IN (\'ACTIVE\',\'CANCELLED\') AND v.status=\'EFFECTIVE\' AND v.effective_from<=' . Store::esc($d));
        $byC = [];
        foreach ($rows as $r) $byC[$r['contract_id']][] = $r;
        foreach ($byC as $vs) {
            $ranges = ContractTerms::ranges(array_map(function ($v) { return ['id' => $v['id'], 'effective_from' => $v['effective_from'], 'effective_to' => $v['effective_to']]; }, $vs), $vs[0]['c_terminated_on']);
            foreach ($vs as $v) {
                $r = $ranges[$v['id']];
                $b = self::body($v);
                if ($r && $r[0] <= $d && $d <= $r[1] && $b['coverage'] && $b['coverage']['mode'] === 'LISTED' && in_array($equipmentId, $b['coverage']['equipment_ids'], true))
                    return true;
            }
        }
        return false;
    }

    /**
     * Authoritative entitlement resolution (server side). @param int|null $orgId evaluation organization (null = none)
     * @return array resolution (see ContractTerms::resolve) + organization_id_at_evaluation
     */
    static function resolve($orgId, $d, $line, $equipmentId) {
        if ($orgId === null || $orgId < 1) {
            $r = ContractTerms::resolve($d, $line, $equipmentId, [], function () { return false; });
        } else {
            $r = ContractTerms::resolve($d, $line, $equipmentId, self::effectiveOfOrganization($orgId),
                function () use ($orgId, $equipmentId, $d) { return self::listedElsewhere($orgId, $equipmentId, $d); });
        }
        $r['organization_id_at_evaluation'] = $orgId ? (int) $orgId : null;
        $r['service_line'] = $line;
        $r['equipment_ref'] = $equipmentId;
        return $r;
    }
}
