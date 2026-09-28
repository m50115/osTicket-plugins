# Sync and reports — `/workflow/v1`

Handlers `Sync.php`, `Reports.php` (Architecture §J).

## Why the ticket feed is composite
Measured in the sandbox: adding an internal note does **not** change `ticket.updated`. A cursor on `updated` alone would silently lose notes and successive replies. `GET /sync/tickets` returns a ticket when ANY of these is past the client's state: `ticket.updated` (5 s overlap), `MAX(thread_entry.id)`, `MAX(thread_event.id)`, `form_entry.updated` (5 s overlap). Entries and events are then read **by id** with `GET /tickets/{id}/activity`.

## `GET /sync/tickets?limit=&cursor=&state=`
* First pass: no `state` → every visible ticket (`meta.full:true`). Next passes: `state=<meta.sync_state of the previous complete pass>`.
* Paging inside a pass: `cursor` from `meta.cursor` while `has_more`. On the last page `meta.sync_state` is the watermark for the next pass, **captured when the pass started** (max entry id, max event id, form `updated`, UTC time), so changes made during the pass are picked up next time.
* Items: `{id, updated, last_entry_id, last_event_id, form_updated, ticket:<summary>}`. Dedupe by `(id, updated)` on the client; the feed is deliberately a superset (overlap window).
* Deletions/visibility loss are never reported by the delta: use `GET /sync/visible-ticket-ids` (paged ascending ids of every visible ticket, all states; `next_cursor`, `server_time`) periodically and diff.

## Date-window feeds
`GET /sync/users?since=`, `GET /sync/organizations?since=` — rows with `updated >= since − 5 s`, ordered by `(updated, id)`; no `since` = full. Pages via `meta.cursor`; on the last page `meta.next_since` (UTC, taken at pass start) is the next `since`. `user.updated` is a local `datetime` and `organization.updated` a `timestamp`: both are normalized to UTC before they leave.
`GET /sync/tasks?since=&event_since=` — tasks updated/closed in the window **or** with thread events past `event_since`; `meta.sync_state` carries `{u, v}`.

## `GET /reports/support`
`?from=&to=` (ISO-8601, tickets **created** in `[from,to)`, default last 30 days), `?group_by=dept|topic|status|agent|team|source|priority`. Per group: `created, closed, open, overdue, answered, avg_close_seconds`; `meta.totals`, `meta.basis`. Visibility is resolved by osTicket (ORM) and the aggregation runs in SQL over those ids (max 20 000, else `413 too_large`). SLA-snapshot metrics wait for decisions P-11/P-13.

Verified: full multi-page pass, delta after a note on ticket 1 returned ticket 1 while `ticket.updated` stayed unchanged; users/orgs/tasks feeds; visible ids; report totals equal the DB.
