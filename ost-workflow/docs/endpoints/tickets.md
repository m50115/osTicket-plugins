# Tickets — `/workflow/v1`

Handler `lib/OstWorkflow/Handlers/Tickets.php`; helper `Ticketing.php`. Common rules (envelope, UTC, Bearer, `Idempotency-Key` on writes, `$thisstaff`) are in [README](../../README.md). Core references are osTicket 1.17.2.

**Update rule (Architecture §K).** Every update sends `base` = the value the device saw (`null` = it was empty). Server: `current == desired` → `200 {applied:false}` (idempotent success); `current == base` → applies; otherwise `409 conflict` with `details:{current, base, last_change:{event, at, actor, staff_id}}`. Missing `base` → `422 validation_failed` (field `base`). Never last-write-wins.

**Assignee token** (`base` for assignment): `"s12"` agent, `"t3"` team, `null` unassigned/closed.

## DTOs
`summary`: `id, number, subject, status{id,name,state}, dept, topic, priority{id,name,urgency}, sla, owner{id,name,email}, assignee{type,id,name,token}|null, source, is_overdue, is_answered, created, updated, last_activity, closed, due{manual,sla,effective}`.
`detail` = summary + owner `phone`/`org`, `reopened`, `ip_address`, `collaborators_count`, `tasks_count`, `open_tasks_count`, `messages_count`, `is_closeable`, `source_extra` (idempotency marker `wf:<key>`), `lock{locked, staff_id, staff_name, expires_at}` (read-only; the plugin never acquires the desktop lock, P-16).
`due.manual` = `ticket.duedate`, `due.sla` = SLA estimate, `due.effective` = manual ?: sla (legacy B-8). All timestamps UTC ISO-8601 (`Time::iso`, calibrated against MySQL's clock).

## Reads (policy noted)
| Route | Notes |
|---|---|
| `GET /tickets` (`auth`) | **`state=open\|closed\|all` is required** (422 otherwise; legacy B-9). Optional: `status_id, dept_id, topic_id, staff_id, team_id, user_id` (ints), `unassigned=0\|1`, `overdue=0\|1`, `answered=0\|1`, `number` (exact), `q` (2–100 chars), `sort=updated\|created`, `order=desc\|asc`, `limit` (1–100, default 25), `cursor`. Cursor on `(sort value, ticket_id)` — no offset paging. Visibility = `Staff::getTicketsVisibility()` + DISTINCT. Returns `meta.next_cursor`, `has_more`, echoes `filters`. |
| `GET /search?q=` / `GET /tickets/lookup?q=` (`auth`) | number prefix / subject / contact name / contact email. `q` not sanitized on input. No total is promised (`has_more` only). Lookup caps at 20. |
| `GET /tickets/{id}` (`ticket.view`) | detail. |
| `GET /tickets/{id}/missing-fields` | `{closeable, reason, missing_fields[]}` (`isCloseable`, `getMissingRequiredFields`). |
| `GET /tickets/{id}/participants`, `/collaborators`, `/recipients?reply_to=all\|user\|collabs` | owner + collaborators; `recipients` = who a reply with that scope reaches (`to`/`cc`). |
| `GET /tickets/{id}/fields` | dynamic form entries with displayed values. |
| `GET /tickets/{id}/related` | merge family (`parent`, `children`, `is_merged`); merging itself is not exposed. |

## Writes
| Route (policy) | Body | Core / behaviour |
|---|---|---|
| `POST /tickets` (`anydept.ticket.create`) | `subject`, `message`, contact = `user_id` **or** `email`+`name`; `topic_id` (or PluginConfig default, else 422), `dept_id?` (only when explicit; per-dept `ticket.create` checked), `priority_id?`, `source?` (Ticket::getSources, default `API`), `assignee?{type,id}` (needs `ticket.assign`), `fields?{name:value}`, `notify?` (default **false** → no autorespond/alerts) | `Ticket::create($vars,$errors,'staff',$notify,$notify)`. Marker `source_extra='wf:<Idempotency-Key>'` written right after; a retry after a crash adopts the ticket instead of duplicating. New contact via email+name needs `user.create` (core check). |
| `POST /tickets/{id}/status` (`ticket.view`) | `status_id`, `base`, `comment?` | Same rule as `note_status_id` (`Threading::authorizeStatus`): closing needs `ticket.close` **and** `isCloseable()` (else 409 `not_closeable`); deleting is never exposed. `Ticket::setStatus`. Response `{applied, ticket, effects}`; `effects` reports side effects (reopen auto-assign, closing agent goes into `staff_id`, R-C24). |
| `POST /tickets/{id}/assignment` (`ticket.assign`) | `assignee{type:staff\|team,id}`, `base`, `comment?`, `refer?`, `reopen?`, `alert?` (default false) | Closed ticket → 409 unless `reopen:true` (R-C23; reopening auto-assigns, which counts as done when it equals the request). Team must exist, be active and have members. `Ticket::assign(AssignmentForm)`. |
| `DELETE /tickets/{id}/assignment` (`ticket.release` **or** dept manager) | `base` (token; JSON body or `?base=`), `comment?` | `Ticket::release` + writes the `released` event exactly like the SCP (ajax.tickets.php:944-950; the core does not, R-C22). Unassigned → idempotent no-op. |
| `POST /tickets/{id}/claim` (`ticket.assign`) | `comment?` | Only open + unassigned; already mine → no-op; otherwise 409 with the current assignee. `Ticket::claim(ClaimForm)`. |
| `POST /tickets/{id}/transfer` (`ticket.transfer`) | `dept_id`, `base` (current dept id), `comment?`, `refer?`, `alert?` | `Ticket::transfer(TransferForm)`; never `setDeptId`. |
| `POST /tickets/{id}/referrals` (`ticket.assign`) | `target: agent\|team\|dept`, `id`, `comment?` | append-only; `Ticket::refer(ReferralForm)`. |
| `PATCH /tickets/{id}/fields/{priority\|topic\|sla\|duedate}` (`ticket.edit`) | `value`, `base`, `comment?` | `Ticket::updateField` through the field's edit form. Ids for priority/topic/sla (cannot be cleared: 422), ISO-8601 for `duedate` (`null` clears). |
| `PUT /tickets/{id}/owner` (`ticket.edit`) | `user_id`, `base` (current owner id) | `Ticket::changeOwner`. |
| `POST /tickets/{id}/answered` (`ticket.markanswered`) | `answered?` (default true) | idempotent by nature. |
| `POST /tickets/{id}/collaborators` (`ticket.edit`) | `user_id` or `email`+`name` (`user.create`) | `Ticket::addCollaborator`; already a collaborator → `{already:true}`. |

Replies, notes, activity and files are in [threads-files](threads-files.md); tasks in [tasks](tasks.md).

## Not implemented
`PUT /tickets/{id}/forms` (dynamic-form bulk edit: deferred, needs the app's form design), `PATCH` of dynamic fields by id, merge/link, delete, lock acquisition, `PUT /tickets/{id}` (bulk edit collides with per-field base values).

## Errors
`validation_failed` (`field`), `forbidden` (missing `ticket.*` permission — names the permission), `not_found`, `conflict` (base mismatch; closed ticket without `reopen`; unclaimable), `not_closeable`, `idempotency_key_required`, `idempotency_key_reused`, `in_progress`, `needs_review`.

## Verified (sandbox, PHP 8.0.30 + opcache + nginx)
List/cursor/filters/search, detail, create + replay (0 duplicates, marker set), claim/assign/release/transfer/refer, field updates (DB checked), owner, answered, collaborators, close/noop/conflict, closed→assign needs `reopen`, `agent2` (Limited Access) gets 403 on close/edit. Found and fixed: the visibility filter duplicated tickets (join through referrals) → DISTINCT.
