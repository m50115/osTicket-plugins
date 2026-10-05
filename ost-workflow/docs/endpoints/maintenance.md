# ScheduledMaintenance store (Preventive Maintenance, module 8)

OW-REQ-70 (APPROVED V1 consumer requirement, msolis 2026-09-30; D-22 bounded exception authorized 2026-10-04). The first plugin table that holds business truth osTicket cannot rebuild: a maintenance commitment that has no Ticket yet. Handler: `lib/OstWorkflow/Handlers/Maintenances.php`; storage and rules of the store: `lib/OstWorkflow/Maintenance.php`. Design authority: *Preventive Maintenance — Functional Design* (§2–§13, §18–§19).

Out of scope on purpose (REJECT / MAY LATER of the requirement): deleting records (cancel instead), plans and recurrence, cron or materializer, notifications, organization queries, generic bulk update, date-range / status filters, server-side counts, reports.

## Table

`<prefix>workflow_maintenance` (own table, in the osTicket database; **not** the idempotency table).

- Created by `enable()` with `CREATE TABLE IF NOT EXISTS` beside `Store::install()` (never in init/bootstrap). Re-running `enable()` is safe. **After upgrading a plugin that already was enabled, disable and enable it once** (or run `prod-sandbox/plugin-admin.php <app> enable` in the sandbox): until the table exists, every maintenance route answers `503 not_configured`.
- **Durable:** no TTL, no purge (`Store::purge` only deletes expired rows of the idempotency table), never dropped on disable or uninstall, included in the normal database backup. Rows are never deleted.
- `schema_version` column (per row, currently 1): the plugin has no migrations; adding columns later starts from it.
- Uniqueness `(equipment_id, scheduled_date)` among non-cancelled rows is enforced by the database: `active_date` repeats `scheduled_date` while the row is not CANCELLED and is NULL afterwards; `UNIQUE (equipment_id, active_date)` (NULLs never collide). Concurrent writers get a typed 409, never a database error. A `rev` counter makes every update a compare-and-swap.
- Timestamps are stored in UTC (microsecond precision for the cursor) and leave the API as ISO-8601 `Z`.

## Setting

`installed_equipment_list_id` (plugin instance config, "Custom list id of Installed Equipment"). `equipment_id` must be an item of that custom list (`DynamicListItem.id`) and **enabled** (= `ACTIVE`; a disabled item is `RETIRED`, Equipment design §6). Empty setting: creating records answers `503 not_configured` (nothing is guessed).

## Routes

| Route | Policy | Notes |
|---|---|---|
| `GET /maintenances?cursor=&limit=&equipment_id=&ticket_id=` | `auth` | Every state (cancelled included), ordered by `(updated_at, id)`: the incremental feed. |
| `GET /maintenances/{uuid}` | `auth` | One record; 404 when unknown. |
| `POST /maintenances/batch` | `anydept.ticket.create` | Idempotent batch create, per-item result. |
| `POST /maintenances/{uuid}/reschedule` | `anydept.ticket.create` | PLANNED only. |
| `POST /maintenances/{uuid}/complete` | `anydept.ticket.create` | PLANNED only. |
| `POST /maintenances/{uuid}/cancel` | `maintenance.manager` | PLANNED only; the row stays. |
| `POST /maintenances/{uuid}/revert` | `maintenance.manager` | COMPLETED to PLANNED. |
| `POST /maintenances/{uuid}/link` | `anydept.ticket.create` + `ticket.view` on the target | PLANNED without a Ticket. |
| `POST /maintenances/{uuid}/unlink` | `anydept.ticket.create` | PLANNED; no access to the Ticket needed. |

Policy `maintenance.manager` (Maintenances::policy) = administrator, or **manager of ANY department** (`ost_department.manager_id` = the agent; decision B of the design: with no Ticket there is no natural department). Every write needs `Idempotency-Key` (pipeline). The batch is `anydept.ticket.create`, not manager-only: the design lets an agent "prepare the year" (§12/§13); the manager-only "batch" of the requirement is the batch *cancellation*, which is a loop of idempotent `cancel` calls (§9: no bulk endpoint).

## Record (exact keys)

`id` (client UUID), `equipment_id`, `scheduled_date` (DATE `YYYY-MM-DD`), `stored_status` (`PLANNED` | `COMPLETED` | `CANCELLED`), `ticket_id` (null), `completed_on`, `completed_by`, `evidence_ref`, `completion_note`, `cancel_reason`, `changes[]`, `created_at`, `created_by`, `updated_at`, `updated_by` (staff ids; the server sets them). Derived states (OVERDUE / UPCOMING / FUTURE, needs-ticket, requires-review) are **not** stored and not served: the client derives them with the staff time zone.

`changes[]` is append-only audit, never the state: `{kind, at, by, reason, from, to}` with `kind` = `created` | `reschedule` | `link` | `unlink` | `complete` | `cancel` | `revert`. A revert keeps the previous `completed_on`, `completed_by`, `evidence_ref` and `completion_note` in its `from`.

## Reads

`GET /maintenances`: `limit` 1–200 (default 100). `cursor` is opaque (`(updated_at, id)` position); malformed → 422. `meta.next_cursor` is set only while `has_more`; **`meta.watermark` is always set**: store it and send it as `cursor` next time to receive only what changed since (empty page → the same watermark). `equipment_id` and `ticket_id` filters are positive integers (422 otherwise) and combine with the cursor. The feed is not a snapshot: a record updated again after being read appears again (clients dedupe by `id`).

## Create: `POST /maintenances/batch`

Body `{"items": [{"id": "<uuid>", "equipment_id": 12, "scheduled_date": "2027-03-15"}, ...]}` (1–200 items). Response `200 {data: [result per item, same order], meta: {created, exists, rejected}}`:

- `{id, result: "created", maintenance}`
- `{id, result: "exists", maintenance}`: the client id is already stored (any state; the server state is returned, nothing is compared or changed) — this is the per-item idempotency, on top of the per-request `Idempotency-Key`.
- `{id, result: "rejected", code, message, details?}` with `code` = `invalid_id`, `invalid_equipment`, `invalid_date`, `equipment_not_found` (not an item of the Installed Equipment list), `equipment_retired`, `duplicate` (`details.existing_id` = the non-cancelled holder of that equipment and date).

A rejected item never fails the batch. Retired equipment is rejected only for **new** records; existing ones stay and can still be completed or cancelled.

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

## Tested

`prod-sandbox/e2e.py`, section «maintenances»: uniqueness, per-item and per-request idempotency, `base` conflicts, the full state machine, permissions per role (administrator, manager of another department, agent3, agent2), link to an inaccessible Ticket, retired and other-list equipment, foreign `evidence_ref`, the incremental feed, durability (the plugin's cron purge ran and left every record), table survival (`enable()` re-run) and no hard delete. Self-contained fixtures: a persistent `[BCWF TEST] e2e-ow70 Installed` list with per-run items; records and tickets are retained intentionally (nothing is deleted). The sandbox setting `installed_equipment_list_id` is set by the suite to that list.
