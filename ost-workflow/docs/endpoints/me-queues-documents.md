# Profile, saved queues and documents — `/workflow/v1`

Handlers `Me.php`, `Queues.php`, `Documents.php` (+ `OstWorkflow\Documents`). Base-value rule as in [tickets](tickets.md).

## `GET /me`, `GET /me/permissions` (read-only)
`PATCH /me` was **removed** in the 2026-09-28 hardening: the agent's `signature` is appended to customer e-mails (a stolen token could plant a phishing link in every later reply) and `on_vacation` disables assignment; no module needs a profile edit. It can come back, without `signature`, if the Configuration module demonstrates the need. Negative test in `e2e.py`.

## Saved queues (read-only)
* `GET /queues[?counts=1]` — the agent's queues (osTicket's own hierarchy: "Open", "My Tickets", "Closed", custom searches…) as a flat list `{id, name, full_name, parent_id, depth, is_public, is_owner[, count]}`. `counts=1` adds the visible ticket count of each queue (one query per queue).
* `GET /queues/{id}/tickets?limit=&cursor=` — tickets of the queue: the queue's own criteria (`getBasicQuery`) ∩ the agent's visibility (as the SCP does), DISTINCT, **newest first by ticket id**, cursor pagination, ticket summaries. Not accessible / disabled / unknown → 404. The queue's own column set and sort are not reproduced (the app chooses its columns).
Verified: counts equal the visible ticket lists (17 / 6 / 1 in the sandbox); each agent sees their own counts.

## Documents (identity of "documento" notes)
A document is an internal note that carries a `documento.json` + PDFs; its identity is the `uuid` **inside the JSON**, not the thread entry id (an edit creates another entry). `POST /tickets/{id}/notes` accepts `document:{uuid, version, supersedes?}` (UUID, version 1–9999, `supersedes` an earlier version number). There is **no index table**: the note body is stamped with one marker line `BCW_DOC:<uuid>@<version>[^<supersedes>]` (appended; carried over when the note is edited) and the thread entries are read through the native ORM (visible internal notes only; at most 200 entries inspected per lookup). So:
* a retry of the same (uuid, version) **on the same ticket** **adopts** the existing note: `200 {applied:false, adopted:true, entry, document}` instead of creating a duplicate;
* `supersedes` a version that does not exist on this ticket → `409` (`missing_previous_version`). (The former cross-ticket `document_on_other_ticket` check was dropped: it needed a global index.)
* `GET /documents/{uuid}` → `{uuid, latest_version, versions[{version, supersedes, entry_id, ticket_id, ticket_number, created}]}` (only versions of tickets the caller can see; none → 404); `GET /tickets/{id}/documents` → the ticket's documents with their versions.
The note (and its JSON) is the only source of truth. Notes written before this change have no marker and are not listed. The PDF-needs-text rule of [threads-files](threads-files.md) applies to document notes.
