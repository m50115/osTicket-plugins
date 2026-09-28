# Profile, saved queues and documents — `/workflow/v1`

Handlers `Me.php`, `Queues.php`, `Documents.php` (+ `OstWorkflow\Documents`). Base-value rule as in [tickets](tickets.md).

## `PATCH /me` (policy `auth`)
The agent edits **only their own profile**: `first_name`, `last_name` (≤32, not empty), `phone` (≤24), `phone_ext` (≤6), `mobile` (≤24), `signature` (≤4000, sanitized), `timezone` (a PHP time zone id or `""` for the system default), `default_signature_type` (`none|mine|dept`) and **`on_vacation`** (bool; makes the agent unavailable for assignment). Never username, e-mail, role, departments or permissions (422 naming the key). Every key changed carries `base` (the value the app saw); stale → `409 conflict` with `details.current{}`; same value → `applied:false`. Response `{applied, changed[], profile}` (the same object as `GET /me`).

## Saved queues (read-only)
* `GET /queues[?counts=1]` — the agent's queues (osTicket's own hierarchy: "Open", "My Tickets", "Closed", custom searches…) as a flat list `{id, name, full_name, parent_id, depth, is_public, is_owner[, count]}`. `counts=1` adds the visible ticket count of each queue (one query per queue).
* `GET /queues/{id}/tickets?limit=&cursor=` — tickets of the queue: the queue's own criteria (`getBasicQuery`) ∩ the agent's visibility (as the SCP does), DISTINCT, **newest first by ticket id**, cursor pagination, ticket summaries. Not accessible / disabled / unknown → 404. The queue's own column set and sort are not reproduced (the app chooses its columns).
Verified: counts equal the visible ticket lists (17 / 6 / 1 in the sandbox); each agent sees their own counts.

## Documents (identity of "documento" notes)
A document is an internal note that carries a `documento.json` + PDFs; its identity is the `uuid` **inside the JSON**, not the thread entry id (an edit creates another entry). `POST /tickets/{id}/notes` accepts `document:{uuid, version, supersedes?}` (UUID, version 1–9999, `supersedes` an earlier version number). The plugin indexes (uuid, version) → entry in its plumbing table (`kind='doc'`), so:
* a retry **that the idempotency record cannot answer** (a new key, an expired record) **adopts** the existing note: `200 {applied:false, adopted:true, entry, document}` instead of creating a duplicate;
* the same version on **another ticket** → `409 conflict` (`document_on_other_ticket`); `supersedes` a version that does not exist on this ticket → `409` (`missing_previous_version`);
* `GET /documents/{uuid}` → `{uuid, latest_version, versions[{version, supersedes, entry_id, ticket_id, ticket_number, created}]}` (only versions of tickets the caller can see; none → 404); `GET /tickets/{id}/documents` → the ticket's documents with their versions.
The note (and its JSON) stays the source of truth; the index can be rebuilt. The PDF-needs-text rule of [threads-files](threads-files.md) applies to document notes.
