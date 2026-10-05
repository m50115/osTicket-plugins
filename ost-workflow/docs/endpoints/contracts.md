# Contracts / Service Agreements store (module 10)

OW-REQ-71 (APPROVED V1 consumer requirement, msolis D-38; D-22 bounded exception authorized 2026-10-04, OPTION A). The second plugin store that holds business truth osTicket cannot rebuild: versioned, immutable contract terms with authorship, the equipment they cover, server-held invariants, historical decision bases and the contract PDF. Handler: `lib/OstWorkflow/Handlers/Contracts.php`; storage and queries: `lib/OstWorkflow/Contracts.php`; pure rules (normalization, `terms_hash`, applicable period, overlap, resolution): `lib/OstWorkflow/ContractTerms.php`. Design authority: *Contracts & Service Agreements* CT-E2 (architecture, invariants I1-I9), CT-E3 (validations V1-V16, resolution), CT-E4 (F-E4-xx), CT-E5/CT-D14 (permissions).

Out of scope on purpose (MUST NOT / MAY LATER of the requirement): billing, e-signature, CRM, offline editing, automatic SLA assignment, new contractual fields in M1/M6/M7/M8, writing `organization.extra`, any SCP administration screen, deleting records (a draft is discarded, a version or contract voided, a contract terminated), a Customer App projection, server-side filters/counts/reports, feed pagination, bases of other `purpose`, `contract_ref` in other modules.

## Tables

Six own tables in the osTicket database, prefix `<prefix>workflow_contract…` (not the idempotency table): `workflow_contract`, `_version`, `_event`, `_document`, `_catalog`, `_basis`.

- Created by `enable()` with `CREATE TABLE IF NOT EXISTS` beside the other installers (never in init/bootstrap); re-running is safe. **After upgrading an already-enabled plugin, disable and enable it once** (sandbox: `prod-sandbox/plugin-admin.php <app> enable`): until then every contracts route answers `503 not_configured`.
- **Durable:** no TTL, no purge (`Store::purge` only deletes expired rows of the idempotency table), never dropped on disable/uninstall, in the osTicket database so the normal backup includes it. `schema_version` on every row (currently 1). Rows are never deleted. Timestamps are UTC with microseconds and leave the API as ISO-8601 `Z`.
- **Held by the database:** `UNIQUE (contract_id, open_draft)` one open draft per contract (I5); `UNIQUE (contract_id, eff_from_key)` no two EFFECTIVE versions with the same start (I6; VOIDED ones do not count); `UNIQUE (organization_id, ext_key)` contract number per organization (V2; a VOIDED contract frees its number); `UNIQUE (number)` catalog versions; `UNIQUE (chain_key, prev_key)` one successor per basis. Writers use `INSERT/UPDATE IGNORE` + a `rev` compare-and-swap, so a race answers a typed 409, never a database error.
- **Immutability:** the only statement that rewrites a version body is `UPDATE … WHERE status='DRAFT' AND rev=?`; an EFFECTIVE version changes state only (`EFFECTIVE → VOIDED`). `ContractEvent` is only ever INSERTed (no update path exists). Bases and catalog versions are insert-only.
- **Serialization per organization (CT-D8):** `activate`, `termination` (revoke) and `void` run in one transaction (READ COMMITTED) that first does `SELECT … FOR UPDATE` on every contract row of the organization (ordered by id), then revalidates the overlap rule over the whole organization and rolls back on a violation. Two simultaneous activations in two contracts of one organization: exactly one wins, the other gets `409 overlap`.
- **The PDF:** `POST /files` (existing) uploads; linking it to a document stores a core `attachment` row (`type='W'`, `object_id` = the document number, `file_id`), so `AttachmentFile::deleteOrphans` (the daily cron cleanup: `ft='T'`, no attachment row, older than a day) never removes it. A reference only in the plugin table would not protect the file.

## Setting

`installed_equipment_list_id` (shared with OW-REQ-70). Every `equipment` id must be an item of that custom list. The owner organization of an item is read from the item's `extra` as defined by the Equipment design §13.2 (`BCW|1|…|O=<organization_id>|…|E=1`; anything else is fail-safe: no owner). Empty setting: activation of coverage with equipment, `GET /contracts/entitlement?equipment_id=` and a basis with `equipment_ref` answer `503 not_configured` (nothing is guessed).

## Authorization

| Who | Policy |
|---|---|
| **Manager** (create, edit header, draft, discard, activate, terminate/revoke, void, documents, PDF download, publish catalog) | `contract.manager` = administrator or manager of **ANY** department (`ost_department.manager_id`), the OW-REQ-70 predicate (`Maintenance::isManagerOfAny`; `isManager()` alone only covers the primary department, F-E4-19). Editor and Approver are the same primitive in V1. 403 `details.reason = manager_required`. |
| **Reader** (feed, contract, events, entitlement) | A manager, or an agent with authorization to read the contract's **Organization**: the existing `Directory::mayRead` (`user.dir`, the organization on a Ticket the agent can see, or created by the agent). It matches CT-D14 exactly, so no new capability. Unknown and not-readable contracts answer the same `404`; `GET /contracts/entitlement` answers `403` (the organization id is given by the caller). The feed and its ETag are **per agent**. |
| **Basis** | `ticket.edit` (write) / `ticket.view` (read) on the consumer Ticket. |
| **Catalog read** | `auth`: labels only, no customer or commercial data. |

Reader view: versions in `EFFECTIVE` or `VOIDED` state (no drafts, no discarded drafts) and, for each document, only `document_ref, contract_version_ref, role, display_title, status, registered_at`: **no URL, path, hash, size or author**. Managers also get `sha256`, `size`, `registered_by`, `superseded_by`, `download` and the drafts. Ids only: no organization or equipment names.

## Routes

| Route | Policy | Notes |
|---|---|---|
| `GET /contracts?cursor=` | `auth` + org authorization | Feed (contract + all versions + documents), changed after the cursor. |
| `POST /contracts` | `contract.manager` | Create Contract (DRAFT) + first draft version. 201. |
| `GET /contracts/entitlement?organization_id=&equipment_id=&service_line=&as_of=` | `auth` + org authorization | Resolution `as_of`, nine outcomes. |
| `GET /contracts/catalogs` | `auth` | All catalog versions. |
| `POST /contracts/catalogs` | `contract.manager` | Publish a new immutable catalog version. 201. |
| `GET /contracts/{uuid}` | `contract.read` | Contract with every version (the version retrieval). |
| `PATCH /contracts/{uuid}` | `contract.manager` | Header (title, number). |
| `GET /contracts/{uuid}/events?cursor=&limit=` | `contract.read` | History (append-only). |
| `PUT /contracts/{uuid}/draft` | `contract.manager` | Create (base null) or replace the one open draft. |
| `POST /contracts/{uuid}/draft/discard` | `contract.manager` | DRAFT → DISCARDED. |
| `POST /contracts/{uuid}/activate` | `contract.manager` | Activate the reviewed draft (also the CORRECTION). |
| `PUT /contracts/{uuid}/termination` | `contract.manager` | `terminated_on` date = terminate; `null` = revoke. |
| `POST /contracts/{uuid}/versions/{version_uuid}/void` | `contract.manager` | EFFECTIVE → VOIDED without replacement. |
| `POST /contracts/{uuid}/versions/{version_uuid}/documents` | `contract.manager` | Link an uploaded PDF. 201. |
| `GET /contracts/{uuid}/documents/{doc_uuid}/file` | `contract.manager` | The PDF. |
| `GET /tickets/{id}/entitlement-bases` | `ticket.view` | Bases of a Ticket. |
| `POST /tickets/{id}/entitlement-bases` | `ticket.edit` | Create a basis (resolved in the server). 201. |

Every write needs `Idempotency-Key` (pipeline) and `base`. Same key + same body replays the response (`Idempotent-Replayed: true`); same key + other body → 422 `idempotency_key_reused`. Writes are **online only**; the feed is the offline cache.

## `base` and conflicts

| Operation | `base` is |
|---|---|
| header, termination, void, documents | the contract `rev` seen |
| draft (PUT) | the draft `rev` seen; `null` to create the first one |
| discard, **activate** | the draft `rev` (activate: the one the approver REVIEWED; a later save → 409) |
| publish catalog | the latest catalog `number` seen (`null` when none) |
| basis | the id of the latest basis of that Ticket seen (`null` when none) |

Desired state already in place → `200` with `meta.applied=false`. Otherwise `409 conflict` with `details`: `reason`, `current`, `base`, `last_change` (the latest event), `record` (the server's contract, so the app can say "changed on another device"). `reason` ∈ `base_mismatch`, `invalid_state` (`required`), `draft_exists`, `no_draft`, `number_taken`, `duplicate_effective_from`, `after_termination` (I8), `duplicate_document`, `overlap` (`conflicts[]` = `{service_line, from, to, a:{contract_ref, contract_version_ref}, b:{…}}`, `conflict_count`). A rejected write changes nothing. **Every state-changing write carries a `reason`** (422 without it): activate, discard, termination, void, documents, header edit and catalog publish; create and draft save do not (nothing is decided yet).

## Records (exact keys)

**Contract:** `contract_ref` (server uuid), `organization_id` (fixed for life), `title`, `external_number`, `status` (`DRAFT` | `ACTIVE` | `CANCELLED` | `VOIDED`), `terminated_on` (first day WITHOUT coverage), `termination_reason`, `rev`, `created_at/by`, `updated_at/by`, `versions[]`.

**Version:** `contract_version_ref`, `number`, `kind` (`INITIAL` | `RENEWAL` | `AMENDMENT` | `CORRECTION`), `status` (`DRAFT` | `EFFECTIVE` | `VOIDED` | `DISCARDED`), `supersedes`, `effective_from/to` (inclusive, declared), `applicable_from/to` (derived, CT-D2: `min(effective_to, next.effective_from − 1, terminated_on − 1)`), `coverage` (`{mode:"LISTED", equipment_ids[]}` | `{mode:"ORG_ALL", excluded_ids[]}`), `entitlements[5]` (always the five lines `REMOTE_SUPPORT, PREVENTIVE_MAINTENANCE, ONSITE_LABOR, GENERAL_PARTS, SPECIALTY_PARTS`; `treatment` ∈ `INCLUDED_UNLIMITED | INCLUDED_QUANTITY | BILLABLE | EXCLUDED`; `quantity` + `period_basis` (`CALENDAR_YEAR | CONTRACT_YEAR`) only for PM by quantity; `exclusions_text`), `response_policy` (`tier_code`, `schedule:{type:"24X7"|"LIMITED", description}`, `response_objective_hours`, `native_sla_ref` – a recommendation, outside the hash) or null, `catalog_version_ref`, `hash_schema`, `terms_hash` (null while DRAFT), `registered_at` (activation), `documents[]`; managers also `rev`, `registered_by`, `created_at/by`.

**`terms_hash`:** sha256 of the canonical JSON (keys sorted, no spaces, UTF-8, ISO dates, id lists ascending) of exactly `hash_schema, effective_from, effective_to, coverage, entitlements[5 fixed lines: service_line, treatment, quantity, period_basis, exclusions_text], response_policy{tier_code, schedule, response_objective_hours}, catalog_version_ref` (CT-D1/F-E4-03). Documents, `native_sla_ref`, kind, supersedes, authorship and state are NOT in it. Computed in the server on activation.

**Event:** `id, seq, contract_ref, contract_version_ref, kind, actor, at, reason, detail`. Kinds: `CREATED, DRAFT_DISCARDED, VERSION_ACTIVATED, VERSION_VOIDED, TERMINATED, TERMINATION_REVOKED, DOCUMENT_ADDED, DOCUMENT_SUPERSEDED, HEADER_EDITED` (`HEADER_EDITED.detail` = `before`/`after`). Events inform; they never decide state.

**Catalog version:** `catalog_version_ref, number, codes[{code,label,treatment COVERED|BILLABLE|EXCLUDED|OTHER}], reason, registered_at/by`.

**Basis:** `id, supersedes, consumer ("ticket:<id>"), purpose ("RS_CLASSIFICATION"), as_of, as_of_local_date, tz, service_line, equipment_ref, organization_id_at_evaluation, result, contract_ref, contract_version_ref, terms_hash, treatment, tier_code, response_objective_hours, catalog_version_ref, code, ticket_sla_id_at_evaluation, ticket_sla_name_at_evaluation, seen_contract_version_ref, seen_result, seen_cache_etag, seen_mismatch, detail, recorded_at/by, reason`.

## Feed: `GET /contracts?cursor=`

Not paginated (MAY LATER): one response with every readable contract changed after `cursor`, ordered by `(updated_at, id)`; no cursor = full sync. `meta`: `watermark` (send it as `cursor` next time; an empty answer keeps it), `count`, `tz` (the installation's default time zone, CT-D9), `today` (the installation's local date), `scope` (changes when the set of organizations the agent may read changes – then resync from scratch), `catalog_number` (latest catalog version). The response carries `ETag`; `If-None-Match` with it → `304`. A contract is re-sent when anything about it changes (draft saves, documents, events); clients dedupe by `contract_ref` + `rev`. Malformed cursor → 422.

## Writes

- **Create** `POST /contracts {organization_id, title, external_number?, version:{effective_from, effective_to, coverage?, entitlements?, response_policy?, catalog_version_ref?}}`: only organization (must exist), name and valid dates are mandatory; completeness is checked on activation. The version is `INITIAL`.
- **Draft** `PUT …/draft {base, kind?, supersedes?, effective_from, effective_to, coverage?, …}`: `kind` is `INITIAL` for a contract that has no version yet, and `RENEWAL | AMENDMENT | CORRECTION` afterwards; a `CORRECTION` must name `supersedes` (a version of the same contract that is EFFECTIVE or VOIDED, I7). Unknown keys → 422. Identical content → `applied=false`.
- **Activate** `POST …/activate {base, reason}` revalidates in one serialized transaction: completeness (V7 LISTED needs equipment, V9 one line other than EXCLUDED, V10 PM quantity + period), kind vs state (I1/I3), I8 (`effective_from < terminated_on` while CANCELLED), equipment (V8: must exist in the Installed list; ACTIVE and of the contract's organization is a **block**, except in a `CORRECTION` or a version with `effective_from` earlier than today, where it is a **warning**; excluded ids only need to exist), I6, and the overlap rule CT-D8 over the whole organization on the **derived** applicable periods: same line **claimed** (treatment ≠ `EXCLUDED`; `BILLABLE` claims), intersecting scope (`LISTED`∩`LISTED` share an id; `ORG_ALL`∩`ORG_ALL` always; `ORG_ALL`∩`LISTED` only if a listed id is not excluded), intersecting inclusive days. A `CORRECTION` also voids the version it supersedes. `200 {contract}` with `meta.warnings[]`.
- **Termination** `PUT …/termination {base, terminated_on|null, reason}`: ACTIVE → CANCELLED (a draft contract is discarded, not terminated; another date on a CANCELLED one: revoke first); `null` revokes (CANCELLED → ACTIVE) and revalidates overlaps. Terms are never touched.
- **Void** `POST …/versions/{v}/void {base, reason}`: for a version registered by mistake; the only EFFECTIVE version voids the contract (I4: terminal, ignored by the resolver, its open draft is discarded); revalidates overlaps (the previous version recovers days).
- **Documents** `POST …/versions/{v}/documents {base, reason, file_id, role, display_title?, supersedes?}`: `file_id` from `POST /files` (a file the same agent uploaded; `410 file_expired` if the core already removed it); `role` ∈ `CONTRACT | ANNEX | AMENDMENT | OTHER`; must be a PDF (detected type **and** `%PDF-` header → else `415 unsupported_type`); DRAFT or EFFECTIVE versions only; `display_title` defaults to the Spanish label of the role and is controlled: ≤ 80 characters, never the file name (nor ending `.pdf`), no currency amounts (422); `supersedes` (a `document_ref` of the same version) marks the old one `SUPERSEDED` (kept). Terms and `terms_hash` do not change. The same PDF twice as active in one version → 409 `duplicate_document`. The document exists only once the durable link is stored.

## Resolution: `GET /contracts/entitlement`

`as_of` = ISO-8601 instant (converted to the **installation's** local day) or a plain date (default now). The evaluation organization is the **current** organization of `equipment_id` (from its `extra`) when it has one, otherwise `organization_id` (required then). `service_line` omitted → the five lines. `data = {organization_id_at_evaluation, equipment_ref, as_of, as_of_local_date, tz, results[]}`; each result: `result` ∈ `COVERED | NO_CONTRACT | NOT_IN_SCOPE | NOT_INCLUDED | EXPIRED | TERMINATED | ORG_MISMATCH | AMBIGUOUS | EQUIPMENT_REQUIRED`, `service_line`, `contract_ref`, `contract_version_ref`, `terms_hash`, `treatment`, `effective_from/to`, `tier_code`, `response_objective_hours`, `schedule`, `catalog_version_ref`, `native_sla_ref`, `terminated_on` (TERMINATED), `ended_on` (EXPIRED), `candidates[]` (AMBIGUOUS), `organization_id_at_evaluation`, `equipment_ref`. Algorithm: CT-E2 RESOLUTION_ALGORITHM (VOIDED contracts ignored; `COVERED` + `BILLABLE` is still "a contract governs", the cost is the `treatment`). Without equipment: `COVERED` only if the single claiming version is `ORG_ALL` without exclusions, else `EQUIPMENT_REQUIRED`. `AMBIGUOUS` can only appear if CT-D8 was violated outside the API.

## Bases: `POST /tickets/{id}/entitlement-bases`

Body `{base, purpose:"RS_CLASSIFICATION", service_line, equipment_ref?, code?, seen?:{contract_version_ref, result, cache_etag}, reason?}`. The server resolves with `as_of` = the Ticket's `reported_at` field when it holds a valid instant, otherwise the Ticket's creation time (`detail.as_of_source`); the evaluation organization is the equipment's current organization, else the Ticket owner's; treatment, tier, objective and catalog are **copied from the resolved version** (anything else the client sends is ignored); the Ticket's native SLA id and name are read by the server; `seen_mismatch = (seen_contract_version_ref ≠ resolved version) or (seen_result ≠ result)` (null when `seen` is absent). Immutable; a reclassification is another POST with `base` = the previous id and supersedes it. A later correction, void or termination never rewrites a stored basis. `GET /tickets/{id}/entitlement-bases` lists the chain oldest first; `meta.current` = the latest id per purpose.

## Tested

`prod-sandbox/e2e.py`, section «contracts»: validation of every body; idempotency (replay, reuse, missing key); `base` conflicts on every write; immutability (hash recomputed independently, body unchanged in the database, no route rewrites a version); the invariants held by the database (one draft, one start date, number per organization, catalog number, basis chain) including **two parallel activations** and two parallel drafts; overlap (claim rules, an `EXCLUDED` line, scopes, inclusive dates, revalidation on void and on revoke); correction (voids the superseded version, history intact); amendment (derived applicable period); termination/revoke/I8; the nine resolution outcomes, resolution without equipment and the installation time zone; permissions (manager of ANY department, reader by organization authorization, agent2/agent3); PDF validation, controlled title, ACL, **file durability against the core's orphan cleanup** (aged files, `prod-sandbox/purge-check.php <app> orphans`); bases (server resolution, stale cache, chain, immutability); feed (cursor, ETag, scope, time zone); durability (tables, `schema_version`, the plugin's cron purge leaves every row, no hard delete, `enable()` idempotent). Fixtures use the `[BCWF TEST] e2e-ow71` prefix with per-run unique values and the persistent `[BCWF TEST] e2e-ow70 Installed` list; organizations, equipment items, contacts, tickets, contracts, PDFs and catalog versions are **retained intentionally** (rows are never deleted by design). The suite temporarily sets the installation time zone and an agent3 department manager and restores both.
