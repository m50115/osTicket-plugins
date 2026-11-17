# ScheduledMaintenance store (Preventive Maintenance, module 8)

OW-REQ-70 (APPROVED V1 consumer requirement, msolis 2026-09-30). Business truth osTicket has no object for: a maintenance commitment that may have no Ticket yet. Since the M8 "SQL → native" change it lives in a **native osTicket Custom List**, not in a plugin table; the routes, request bodies, DTO and error codes are unchanged. Handler: `lib/OstWorkflow/Handlers/Maintenances.php`; rules and engine: `lib/OstWorkflow/Maintenance.php`; storage: `lib/OstWorkflow/MaintenanceListStore.php` (ORM models `DynamicList` / `DynamicListItem` only, no SQL text on business data). Design authority: *Preventive Maintenance — Functional Design* (§2–§13, §18–§19).

Out of scope on purpose (REJECT / MAY LATER of the requirement): deleting records (cancel instead), plans and recurrence, cron or materializer, notifications, organization queries, generic bulk update, date-range / status filters, server-side counts, reports.

## Store (native Custom List, no table)

One **dedicated** custom list, created empty by an administrator and named by the plugin setting `maintenance_list_id`:

| Where | Holds |
|---|---|
| the list's own `configuration` | registry `{"bcw":"BCW_PMREG\|1","rev":n,"plans":{"<equipment_id>":<item id>}}` (osTicket never rewrites this column of a custom list after creation) |
| one **plan** item per equipment (value `BCW_PM\|1 eq=<id>`) | the state of every maintenance of that equipment (`properties` JSON) |
| one **journal** item per write (value `BCW_PMJ\|1 <uuid>`) | the audit entry (and long texts) of one record; immutable once referenced |

Items are created **internal and not enabled** (status 2): no form dropdown offers them. All JSON is ASCII (non-ASCII is `\u`-escaped), so the core's `utf8` columns hold any text, emoji included.

- **Uniqueness** `(equipment_id, scheduled_date)` among non-cancelled records and **no lost update**: every write re-reads the plan row of its equipment, evaluates the rules (and the client's `base`) and commits with ONE compare-and-swap of that row (`DynamicListItem::objects()->filter(id, properties = what was read)->update(...)`, affected rows = 1). A lost swap re-reads and re-evaluates the same request, so concurrent writers serialize; a client whose `base` became stale gets the typed 409 as before (never a database error). A first record of a new equipment registers its plan with a compare-and-swap of the registry.
- **Visibility:** a journal is written before the swap and is ignored until a swap references it; journals of attempts that did not commit are marked `extra='orphan'` (forensics only).
- **Durable:** no TTL, no purge (`Store::purge` only touches the idempotency table), nothing is ever deleted by the plugin; it lives in the osTicket database, so the normal database backup includes it. Items are read **by id**: an SCP "delete" of an item (which only clears its `list_id`) or "disable" never hides a record.
- **Timestamps:** the database clock (`UTC_TIMESTAMP(6)`, as before), stored with microseconds, strictly increasing per record; served as ISO-8601 `Z`.
- **Capacity:** one plan item is a MySQL `TEXT` (64 KB): about **396 records per equipment** (all states; measured in sandbox 2; the serverless harness, with longer synthetic records, fills at 375). Beyond that, creating answers `413 too_large` (`details.reason = plan_full`) and nothing is written (checked before any write). Sandbox 1 maximum today: 118 records on one equipment.
- **Not a catalog:** `GET /lists` omits the store list and `GET /lists/{id}/items` answers 404 for it.

### Preparation (once, native, in the SCP)

1. *Admin › Manage › Lists › Add New List*: name e.g. `BCW Scheduled Maintenance (ost-workflow)`, no properties, no items.
2. Plugin instance settings: `maintenance_list_id` = that list's id; `installed_equipment_list_id` = the Installed Equipment list (must differ).
3. After that, nobody uses the SCP on this list. Do **not** open its items, press *Save*, delete items, import into it or delete the list, and limit who can reach *Admin › Manage › Lists* (see *Manual SCP edits of the store list* under Known limits).

Unset or invalid `maintenance_list_id`, the Installed Equipment list in its place, or a list whose configuration holds other data: every maintenance route answers `503 not_configured` (nothing is guessed or adopted).

## Setting

`installed_equipment_list_id` (plugin instance config, "Custom list id of Installed Equipment"). `equipment_id` must be an item of that custom list (`DynamicListItem.id`) and **enabled** (= `ACTIVE`; a disabled item is `RETIRED`, Equipment design §6). Empty setting: creating records answers `503 not_configured`.

## Routes

| Route | Policy | Notes |
|---|---|---|
| `GET /maintenances?cursor=&limit=&equipment_id=&ticket_id=` | `auth` | Every state (cancelled included), ordered by `(updated_at, id)`: the incremental feed. |
| `GET /maintenances/{uuid}` | `auth` | One record; 404 when unknown. |
| `POST /maintenances/batch` | `anydept.ticket.create` | Idempotent batch create, per-item result. |
| `POST /maintenances/import` | `maintenance.import` | One-shot migration from the retired table (below). |
| `POST /maintenances/{uuid}/reschedule` | `anydept.ticket.create` | PLANNED only. |
| `POST /maintenances/{uuid}/complete` | `anydept.ticket.create` | PLANNED only. |
| `POST /maintenances/{uuid}/cancel` | `maintenance.manager` | PLANNED only; the record stays. |
| `POST /maintenances/{uuid}/revert` | `maintenance.manager` | COMPLETED to PLANNED. |
| `POST /maintenances/{uuid}/link` | `anydept.ticket.create` + `ticket.view` on the target | PLANNED without a Ticket. |
| `POST /maintenances/{uuid}/unlink` | `anydept.ticket.create` | PLANNED; no access to the Ticket needed. |

Policy `maintenance.manager` (Maintenances::policy) = administrator, or **manager of ANY department** (`Dept.manager_id` = the agent; decision B of the design: with no Ticket there is no natural department). Every write needs `Idempotency-Key` (pipeline, unchanged). The batch is `anydept.ticket.create`, not manager-only: the design lets an agent "prepare the year" (§12/§13); the manager-only "batch" of the requirement is the batch *cancellation*, which is a loop of idempotent `cancel` calls (§9: no bulk endpoint).

## Record (exact keys)

`id` (client UUID), `equipment_id`, `scheduled_date` (DATE `YYYY-MM-DD`), `stored_status` (`PLANNED` | `COMPLETED` | `CANCELLED`), `ticket_id` (null), `completed_on`, `completed_by`, `evidence_ref`, `completion_note`, `cancel_reason`, `changes[]`, `created_at`, `created_by`, `updated_at`, `updated_by` (staff ids; the server sets them). Derived states (OVERDUE / UPCOMING / FUTURE, needs-ticket, requires-review) are **not** stored and not served: the client derives them with the staff time zone.

`changes[]` is append-only audit, never the state: `{kind, at, by, reason, from, to}` with `kind` = `created` | `reschedule` | `link` | `unlink` | `complete` | `cancel` | `revert`. A revert keeps the previous `completed_on`, `completed_by`, `evidence_ref` and `completion_note` in its `from`.

## Reads

`GET /maintenances`: `limit` 1–200 (default 100). `cursor` is opaque (`(updated_at, id)` position; cursors issued by the retired table stay valid); malformed → 422. `meta.next_cursor` is set only while `has_more`; **`meta.watermark` is always set**: store it and send it as `cursor` next time to receive only what changed since (empty page → the same watermark). `equipment_id` and `ticket_id` filters are positive integers (422 otherwise) and combine with the cursor. The feed is not a snapshot: a record updated again after being read appears again (clients dedupe by `id`).

## Create: `POST /maintenances/batch`

Body `{"items": [{"id": "<uuid>", "equipment_id": 12, "scheduled_date": "2027-03-15"}, ...]}` (1–200 items). Response `200 {data: [result per item, same order], meta: {created, exists, rejected}}`:

- `{id, result: "created", maintenance}`
- `{id, result: "exists", maintenance}`: the client id is already stored (any state; the server state is returned, nothing is compared or changed) — this is the per-item idempotency, on top of the per-request `Idempotency-Key`. An id repeated inside the same request answers `exists` the second time.
- `{id, result: "rejected", code, message, details?}` with `code` = `invalid_id`, `invalid_equipment`, `invalid_date`, `equipment_not_found` (not an item of the Installed Equipment list), `equipment_retired`, `duplicate` (`details.existing_id` = the non-cancelled holder of that equipment and date).

A rejected item never fails the batch. Retired equipment is rejected only for **new** records; existing ones stay and can still be completed or cancelled. Items are evaluated in request order (sequential semantics) and each touched equipment is committed with one swap.

## Transitions and `base`

Convention of the plugin (Tickets): the client sends `base`, the value it saw. Server value equals the desired one → `200` with `meta.applied=false` (no audit entry). Server value equals `base` → applied (`meta.applied=true`). Otherwise `409 conflict` with `details`: `reason`, `current`, `base`, `last_change`, `record` (the server state, so the app can show "changed on another device"). `reason` = `base_mismatch` | `invalid_state` (`details.required`) | `duplicate` (`details.existing_id`) | `ticket_already_linked`. `base` is mandatory (422 without it).

| Route | Body | `base` is | Rules |
|---|---|---|---|
| reschedule | `scheduled_date`, `reason` (required) | `scheduled_date` seen | PLANNED; onto a date held by another non-cancelled record of the same equipment → 409 `duplicate`; past dates allowed. |
| complete | `completed_on`, `evidence_ref?`, `completion_note?` | `stored_status` seen | PLANNED; `completed_on` valid and not in the future (staff time zone); `evidence_ref` = `doc:<uuid>@<n>` must be a published document (Documents index) of the **linked** Ticket (else 422 `foreign_evidence`, or `no_ticket`); without `evidence_ref` the `completion_note` is required. `completed_by` = caller. Same data again → applied=false. |
| cancel | `reason` (required) | `stored_status` seen | manager; PLANNED only (a COMPLETED one is reverted first); `cancel_reason`; frees the (equipment, date) slot; never back to PLANNED. |
| revert | `reason` (required) | `stored_status` seen | manager; COMPLETED → PLANNED; `scheduled_date` and `ticket_id` unchanged; completion fields cleared and preserved in `changes[]`; already PLANNED → applied=false. |
| link | `ticket_id`, `reason?` | `ticket_id` seen (null when none) | PLANNED without a Ticket; the caller needs `ticket.view` on it: **403 for a missing and for an inaccessible Ticket alike** (no probing); another Ticket already linked → 409 `ticket_already_linked`. |
| unlink | `reason` (required) | `ticket_id` seen | PLANNED; no Ticket access needed; nothing linked → applied=false. |

"Change Ticket" = `unlink` (with reason) then `link`. Link and unlink on a COMPLETED or CANCELLED record answer 409 `invalid_state` (the design makes CANCELLED read-only and keeps the evidence Ticket of a COMPLETED one; revert first).

New failure modes of the native store (closed error catalog): `413 too_large` (`plan_full`, see Capacity) and, only under sustained contention on one equipment (16 lost swaps in a row), `429 rate_limited` with `details.reason = busy` and `Retry-After: 2` (retry with the same `Idempotency-Key`; the 429 is never stored as the answer for the key, so the retry re-executes).

## Migration: `POST /maintenances/import` (one shot)

Policy `maintenance.import`: administrator **and** plugin setting `maintenance_import_enabled` = `1` (else 403 `admin_required` / `import_disabled`). Body `{"items": [record, ...]}` (1–200), each item **exactly** a record as `GET /maintenances` of the retired store served it (the 15 keys). Stored verbatim (ids, dates, staff ids, timestamps, `changes[]`, notes); equipment membership and evidence are not re-validated (they were when the record was written); `(equipment, date)` uniqueness still applies. Per item: `created`, `exists` (an identical record is stored), or `rejected` with `invalid_record`, `duplicate` or `mismatch` (`details.current` = the stored record).

Procedure (`prod-sandbox/m8-migrate.py`, HTTP only, never the database): `export` with the old phar deployed (walks `GET /maintenances`) → deploy the new phar, prepare the store list, set `maintenance_import_enabled=1` → `import` → `verify` (DTO parity record by record, `--expect TOTAL,PLANNED,COMPLETED,CANCELLED`) → empty `maintenance_import_enabled`. The retired table is left untouched (dropping it is a separate decision).

## Known limits of the native store (residuals, see the review of 2026-11-16)

- **Capacity per equipment (~396 records, all states, measured):** the plan row never shrinks (cancelled records and audit references stay). Once full, a write that adds a record or grows the plan answers `413 too_large plan_full` (checked before writing); a transition that does not grow the plan can still succeed until the remaining space is gone. Not reachable with today's data (max 118); an archive of closed records is the planned remedy.
- **First write of the store (crash window):** the first plan of an equipment is inserted before the registry is sealed by CAS; if the process dies between the two with an empty registry, every route answers `503 not_configured` until the orphan plan item (`BCW_PM|1 eq=…`) is removed from the store list from the database backup/administration. Do the migration (first real writer) supervised.
- **Registry:** about 4 600 equipments per store list (`413 too_large registry_full` beyond, checked before writing).
- **Cost:** an unfiltered feed page and each batch attempt read every plan of the store (O(store) per request; the retired table used indexes). Fine at today's size (55 plans); to be measured in the sandbox spike.
- **Manual SCP edits of the store list: an administrative operation OUTSIDE the functional contract of M8.** Every M8 operation goes Flutter → M16 Outbox → this API; the SCP is used only once, to create the empty list (Preparation). Manipulating the native store by hand is not supported, and this is what it does (measured in sandbox 2 on a disposable list, 2026-11-16):
  - *Save* on an item, even with nothing changed (`ajax.php/list/<id>/item/<iid>/update` → `saveListItem` → `DynamicListItem::setConfiguration`), rewrites its `properties` with the list's form (`[]`) and clears `extra`; `status` is kept. A store item has no column the form leaves alone that could hold its JSON (`value`/`extra` are `varchar(255)` and rewritten too, `sort` is rewritten by the list page's ordering).
  - *Delete* of an item only clears its `list_id` (`DynamicListItem::delete`); the engine reads items by id, so the data stays readable. Deleting the **list** removes the registry.
  - **Detection:** an overwritten plan, journal or registry is never served as empty or guessed: the routes that read it answer `503 not_configured` ("the maintenance store is damaged … restore it from the database backup"). An overwritten **plan** fails every unfiltered feed page and every batch (they read all plans); an overwritten **journal** fails the feed pages, GETs and writes that need that record's audit; `GET /maintenances/{id}` and writes of records whose plan and journals are intact keep working.
  - **Recovery:** restore the affected rows of `ost_list_items` (or the `ost_list` row for the registry) from the database backup; nothing else is needed (no cache, no rebuild). The plugin offers no repair route on purpose.
  - **Prevention:** do not open, save or delete items of the store list; restrict *Admin › Manage › Lists* to the administrators who configured it.
- **Same id under two equipments:** only two concurrent creates of one client id with different equipment (never the app). The later copy retracts itself when it sees the earlier one; if the earlier commits after that check, both stay stored but every reader resolves the id to the earlier copy (the other is never served and still holds its date slot).
- **Feed window:** live writes are stamped with the database clock right before their journal and swap (milliseconds, vs microseconds for the table); an incremental reader could miss a record committed inside that window. The app walks the full feed and does not use `watermark`.
- **Contract deltas:** under contention a lost swap is re-evaluated (it can answer 200 where the table answered 409 `base_mismatch` with null `current`/`base`); 16 lost swaps in a row answer `429 rate_limited busy` (retryable); `413 too_large` is new.
- **Import:** timestamps keep second precision (`.000000`): after the migration, clients must resync from scratch (a watermark issued by the table could skip records). The import switch must be emptied by hand after the migration.

## Tested

- `prod-sandbox/maintenance-unit.php` (no server): the real handler and engine against an in-memory store with the native compare-and-swap semantics — every rule of the state machine, the DTO, the feed, import parity, capacity, damage detection, and **injected concurrent writers** (same slot, other record of the same plan, same record, new-equipment registration, repeated lost swaps).
- `prod-sandbox/e2e.py`, section «maintenances» (needs the store list prepared): uniqueness, per-item and per-request idempotency, `base` conflicts, the full state machine, permissions per role, link to an inaccessible Ticket, retired and other-list equipment, foreign `evidence_ref`, the incremental feed, **parallel requests on the real database** (8 creates of one slot, 6 reschedules of one equipment, 3 reschedules of one record), import, store shape (registry, internal items, hidden from `/lists`), the retired table untouched, durability (cron purge), no hard delete, `enable()` creates no table.
