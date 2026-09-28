# Threads and files — `/workflow/v1`

Handlers: `lib/OstWorkflow/Handlers/Threads.php`, `Files.php`; helpers `Threading.php`, `Attachments.php`.
Common rules (envelope `{data, meta}` / `{error:{code,message,field?,details?}}`, UTC ISO-8601, `Authorization: Bearer`, `Idempotency-Key` required on every POST, `$thisstaff` fixed by the pipeline) are those of the plugin core. Core line references are osTicket 1.17.2.

## Normalized objects

### Entry (`kind: "entry"`)
| Field | Meaning |
|---|---|
| `id`, `type` (`M` message · `R` response · `N` note), `type_name` | thread_entry id / type |
| `audience` | `customer` for M/R, `internal` for N (portal rule: `include/client/view.inc.php:174` renders only `M`,`R`) |
| `created`, `updated` | UTC. An edit keeps the original `created` (sorts in place) |
| `actor` | `{type: staff\|user\|system, id, name}` |
| `title`, `body` (sanitized HTML, `ThreadEntryBody::toHtml`), `body_text` (plain, entities decoded once), `body_format` (`html`\|`text` as stored) | |
| `attachments[]` | non-inline files `{file_id, hash, name, size, type, inline:false}` (`hash` = key for `GET /files/{hash}`) |
| `inline_images[]` | images embedded in the body (same shape, `inline:true`). The stored body references them as `src="cid:<hash>"`: `<hash>` is the key of `GET /files/{hash}` (the core rewrites the original `cid` to the file key on save) |
| `supersedes` | id of the entry this one replaces (only when `edited`); the old entry is `hidden` |
| `reply_to_entry` | `pid` for non-edited entries (which message a reply answers) |
| `hidden`, `edited`, `editor`, `system`, `source` | flags (`FLAG_HIDDEN`, `FLAG_EDITED`, …) |
| `reply_scope` (`all`\|`user`\|null), `recipients` | for `R`: recipients stored by the core (`thread_entry.recipients`) |

### Event (`kind: "event"`)
`id`, `state` (created, closed, reopened, edited, assigned, released, transferred, referred, collab, merged, overdue, …), `audience` (`customer` only for the states the portal renders — created/closed/reopened/edited/collab/merged — **and** whose client-mode description is non-empty; else `internal`), `created` (UTC), `actor`, `description` (staff-mode text, tags stripped), `data` (decoded JSON payload), `staff_id`, `team_id`, `dept_id`, `topic_id`. `viewed` events are excluded.

## Threads

### GET `/tickets/{id}/activity`
Composite feed of entries + events. Policy `ticket.view` (`Ticket::checkStaffPerm`, class.ticket.php:396).
Query: `limit` (1–200, default 50, applies **per stream**), `direction` (`forward` default | `backward`), `after_entry`/`after_event` (forward, default 0), `before_entry`/`before_event` (backward; absent or 0 = the latest), `include_hidden` (`0`/`1`, default 0).
**Backward** = newest first pages (open a long ticket on its latest entries): items of a page still come ascending; `meta.next_cursor` is `{before_entry, before_event}` (the oldest id of each stream in the page) to fetch the previous page; `has_more` = older items exist.
Two independent id cursors (entries and events have separate id spaces; **ids not dates**: an edit creates a new row with an old `created`, R-C26). Items are returned merged, ordered by `created`, entries before events on ties, then id.
Response: `data: [entry|event…]`, `meta: {count, ticket_id, thread_id, next_cursor:{after_entry, after_event}, has_more}`. Pass `next_cursor` values back; `has_more` is true when either stream still has rows.
Edits: the edit shows up as a **new** entry (higher id) with `supersedes:<old id>`; the old row is hidden (omitted unless `include_hidden=1`, where it appears with `hidden:true`). A client already holding the old entry hides it when it sees `supersedes`.
Core: `ThreadEntry::objects()`, `ThreadEvent::objects()` (class.thread.php:798, 1965), `ThreadEvent::getTypedEvent` (2223) for descriptions.
Errors: 403 `forbidden`, 404 `not_found`, 422 `validation_failed` (`limit`, `after_*`, `include_hidden`).

### POST `/tickets/{id}/replies` — public reply
Policy `ticket.reply` (role permission in the ticket's department). Core: `Ticket::postReply` (class.ticket.php:3345) → `Thread::addResponse` → `ThreadEntry::create` (class.thread.php:1640).
Body (JSON):
| Field | Rule |
|---|---|
| `body` | required string ≤ 60000 chars |
| `body_format` | `text` (default: escaped, newlines kept) \| `html` (sanitized by osTicket) |
| `notify` | **required**: `all` \| `user` \| `none` (osTicket's `reply-to`; fixes legacy B-3). `none` sends no email |
| `cc` | optional list of contact user ids. **Omitted = collaborators untouched** (the reply reaches the owner and the active collaborators). Provided = the reply is copied to exactly those contacts, like the SCP checkboxes: unknown ids → 422; new contacts become collaborators; collaborators not listed are set **inactive** (persistent; `[]` = nobody in copy). `effects.collaborators` reports `{added, activated, deactivated}`. Applied even with `notify:"none"`. |
| `claim` | optional boolean, default `false`. `true` still obeys the core's `autoClaimTickets` setting and the department's `disableAutoClaim`; the response says what happened |
| `signature` | `none` (default) \| `mine` \| `dept` |
| `file_ids` | optional `[int]`, ≤ `max_files_per_note` (default 5); each must have been uploaded by this agent (`POST /files`) |
| `status_id` | optional status to apply after the reply; same permission rule as `/status` (below) |
Extra checks the SCP controller does and the core does not: merged child ticket → 409 `conflict` `{reason:"merged_child"}`; banned contact email → 409 `conflict` `{reason:"email_banned"}`. `notify:"all"` reaches the owner + active collaborators like the SCP. Client IP recorded on the entry is the real IP (`RateLimit::ip`, trusted proxies only), not the balancer's.
Response 201: `{entry, effects:{sanitized:{removed_chars}, status_changed, status:{id,name,state,previous_id}|null, assignee_changed, assignee:{type,id,name}|null, notify, claim_requested, claimed}}`. Effects are computed from a before/after read of the ticket row (status, staff, team).
Errors: 401, 403 (`ticket.reply` or ticket access), 404, 409 `conflict`, 422 (`notify`, `body`, `file_ids`, `status_id`, …), 403 `forbidden` for status rule, 409 `not_closeable`, 500 if an attachment ends up missing (never a success with fewer attachments).
Side effects: email to recipients (unless `none`), status/assignee changes, `answered` flag, `object.created` signal, ThreadEntry rows, attachment rows. Not idempotent in the core: retry safety is the plugin's `Idempotency-Key` (same key + same body → the stored response with `Idempotent-Replayed: true`; different body → 422 `idempotency_key_reused`).

### POST `/tickets/{id}/notes` — internal note
Policy `ticket.view` only (like the SCP: no `PERM_*` for notes, scp/tickets.php:253-272). Core: `Ticket::postNote` (class.ticket.php:3509) → `ObjectThread::addNote`.
Body: `body`, `body_format`, `title` (≤ 200), `file_ids`, `alert` (boolean, default `true` = email alert to agents, as the SCP), `note_status_id`.
`note_status_id` **does not exist in the core as a permission check** (class.ticket.php:3543-3548 calls `setStatus` blindly). The plugin applies the `/status` rule first: unknown/disabled → 422; deleted state → 403 (never exposed); closed/archived → `ticket.close` + `isCloseable()` (409 `not_closeable`); other transitions (reopen…) → `ticket.close` **or** `ticket.create`; same status → no-op.
Response 201: `{entry, effects}` (as above, plus `alert`). Closing through a status change may assign the closer (R-C24): `effects.assignee` reports it.
Errors: as replies (no `notify`/merge/banlist checks).

### PATCH `/tickets/{id}/notes/{entry}` — edit an internal note
Policy `ticket.view` + the SCP "Edit" rule (`TEA_EditThreadEntry`, class.thread_actions.php:112-262): the **author**, the **department manager**, or an agent whose role in the ticket's department has `thread.edit` (else 403 naming the permission). **Only internal notes (`N`)**: a public reply was already emailed to the customer and cannot be edited (neither in the SCP): 422 `validation_failed` `details.reason:"not_editable_type"` (post a corrective reply instead); customer messages, system entries and entries of another thread also refused (422/404).
Body: `body` (required), `body_format?` (`text` default | `html`), `title?` (kept when omitted), `file_ids?` (extra uploaded files added to the new version).
Semantics (same as the SCP): a **new entry** is created as child of the old one (`pid`), flagged `edited`, with the **same `created`** (it keeps its place in the thread), the editor recorded (`editor`), the non-inline attachments moved to it, and the old entry **hidden**. **No email is sent.** `{entry}` is the base: it must still be the latest version, otherwise `409 conflict` with `details{current_entry_id, base_entry_id}`. Same body/title and no files → `200 applied:false`. Response `{applied, entry, superseded_entry_id}`; the new entry has `supersedes:<old id>`, `edited:true`, `editor{}` (`actor` stays the original author).
Difference from the SCP: successive edits by the same agent are **not** collapsed (the SCP deletes the previous edit); every version stays as a hidden entry so a client that synced version N finds it hidden, not deleted. Sync: the edit appears in `/activity` and the ticket feed as a new entry id.
Verified: edit, stale 409 with current id, chain of 3 versions (older ones hidden), no-op, `R`/`M` refused, agent without `thread.edit` refused (403), author edits own note, admin edits another agent's note (actor kept), attachment kept + new file added, idempotent replay creates a single version, 0 emails.

### POST `/tickets/{id}/notes/{entry}/files` — add files to an existing note
Policy `ticket.view` + handler check: the entry must belong to this ticket's thread (else 404), be a note (`N`, else 422 `entry`), and the agent must be its author, a department manager or hold `thread.edit` (403). Core: `ThreadEntry::createAttachments` (class.thread.php:1235-1272, public; only inserts `ost_attachment` rows).
Body: `{file_ids:[int]}` (non-empty; owned by the agent). At most `max_files_per_note` attachments per note in total (422; continue in a new note — Architecture §Q). Files already attached are skipped, so retrying is safe.
Response 201: `{entry, attached_file_ids}`. After inserting, the plugin re-reads the attachments and fails (500) if any is missing.

## Files

### POST `/files` — upload one file
`multipart/form-data`, field **`file`**, one part per request (files first, note after; Architecture §H). Policy: any agent.
Validation: size ≤ min(`max_file_bytes` of the instance, core `max_file_size`) → 413 `too_large` `{max_bytes,size}`; empty → 422; MIME detected from content by `finfo` (client `Content-Type` ignored). Allowed: images (jpeg, png, gif, webp, heic, heif), pdf, json, text/plain, csv, **plus** whatever osTicket's *allowed file types* setting permits by extension/pattern — never executables/HTML/SVG/scripts, and an image/pdf extension must carry matching content (`evil.exe` renamed `.jpg` → 415 `unsupported_type` `{detected_type}`). PHP-level overflow (`post_max_size`, the body is dropped before the handler) → 413 `payload_too_large`. Missing part / more than one `file[]` / non-multipart → 422 `file`. Note: with a plain repeated field name (`file=…&file=…`) PHP keeps only the last one; use one part.
Core: `AttachmentFile::_getKeyAndHash($tmp, true)` (class.file.php:287) precomputed and `AttachmentFile::create` (:389; deduplicates identical content by signature+size, so the same `file_id` can come back for the same bytes). Storage backend: whatever osTicket is configured with (sandbox: `D`, database chunks; downloads verified byte-identical).
Response 201: `{file_id, hash, name, size, type, inline:false, sha256, created}`. `sha256` is of the received bytes so the app can verify. Ownership is recorded in the plugin table (`resource_type='file'`, `Idempotency::record`): only this agent may reference `file_id` in notes/replies, and it can download the unattached file itself. The upload name is kept on the attachment when the file is later attached (even if the core reused an existing file row).
Retry: same `Idempotency-Key` + same bytes → the same response (`Idempotent-Replayed`).

### GET `/files/{hash}`
`hash` = `[A-Za-z0-9_-]+` (`hash` field of any attachment; > 64 chars → 422, other characters do not match the route → 404 `not_found`). Bearer token, **no** core download URL (RC-7).
ACL: uploader of the file, or an agent with access (`checkStaffPerm`) to the ticket or task owning an attachment of it (attachment `type='H'` → entry → thread → ticket|task). Unknown hash → 404; no access → 403 `forbidden`.
Streaming (`Stream`, `AttachmentFile::open()->passthru`, chunked; no full-file buffer). Headers: `Content-Type`, `Content-Length`, `Content-Disposition: attachment; filename="<ascii>"; filename*=UTF-8''<pct>` (attachment name if it differs), `ETag` (file signature; `If-None-Match` → 304), `Cache-Control: private, max-age=86400`, `Content-Security-Policy: default-src 'none'; sandbox`, `X-Content-Type-Options: nosniff`. `?inline=1` switches to `inline` only for jpeg/png/gif/webp/pdf.
Thumbnails: `?s=<16..2048>` for images (GD): PNG, longest side = `s` (never upscaled), `ETag` `…-s<px>`; non-image or undecodable → 422 `s`.

## Gaps / notes
- Mail delivery is not testable in the sandbox (no MTA): `notify` effects on `recipients`/`reply_scope` are verified, actual email is not.
- Task thread equivalents (`/tasks/{id}/notes|replies`) belong to the tasks handler; `Attachments::resolve/forCreate/attachAll` and `Threading::entry` are reusable for it.
- Core quirks handled: `Format::safe_html` drops text after a decoded `<` (bodies are escaped so `1 < 2` survives); `Format::html2text` decodes before stripping tags (own `htmlToText`); `TextThreadEntryBody` truncates at `<` (never used); `AttachmentFile::create` dedupes file rows (per-upload name preserved on the attachment); `->created` of a just-created row is an `SqlFunction` (read back).


## Characters the database cannot store, and typed attachment errors
* **`unsupported_chars`** (`report` default | `reject`) on replies, notes, task notes/replies and note edits. The sandbox database is `utf8mb3`, so emoji and other characters above U+FFFF are **silently dropped by the core**. `GET /config` → `text.supplementary_characters_supported` tells which; writes report `effects.sanitized.removed_chars` (edits: `sanitized`), and `reject` answers `422 validation_failed` with `details{reason:"unsupported_characters", count}`.
* **`file_expired` (410)**: the agent uploaded the file but the core's orphan cleanup (about a day) already removed it — upload again. A `file_id` never uploaded by this agent stays `422 validation_failed`.
* **`attachment_missing` (409)**: the entry was created but some files did not attach; `details{entry_id, entry_created:true, missing_file_ids, retry_with}`. Never a success with fewer attachments; finish with `POST …/notes/{entry}/files`.
* **Notes do not alert by default** (`alert:false`): a field document does not email anyone unless the app asks.
