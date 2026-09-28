# Tasks — `/workflow/v1`

Handler `Tasks.php`. Base-value rule as in [tickets](tickets.md). Core: `class.task.php`, `class.forms.php` (Assignment/Transfer forms).

DTO `{id, number, title, state: open|closed, dept, assignee{type,id,name,token}|null, ticket_id|null, created, updated, closed, due, is_overdue}` (+ `is_closeable`, `thread_id` in detail).

| Route (policy) | Notes |
|---|---|
| `GET /tickets/{id}/tasks` (`ticket.view`) | tasks linked to the ticket. |
| `POST /tickets/{id}/tasks` (`task.create` in the ticket's department) | `title` (≤200), `description?`, `assignee?{type,id}` (needs `task.assign`), `due_at?` (ISO-8601), `dept_id?` (per-dept `task.create`). Uses the SCP forms (`TaskForm` + internal form) with the request as POST, then `Task::create`. |
| `GET /tasks?state=open\|closed\|all` (`auth`) | `state` required. Visible = my departments, assigned to me, or my teams. Filters `dept_id, staff_id, team_id, ticket_id`; cursor by id. |
| `GET /tasks/{id}` (`task.view`), `GET /tasks/{id}/thread` | thread in the same normalized shape as ticket activity (entries + events, `after_entry`/`after_event`). |
| `POST /tasks/{id}/notes` (`task.view`), `/replies` (`task.reply`) | `body`, `body_format?`, `title?`, `file_ids?`, `alert?` (default false), `unsupported_chars?`; responses carry `effects.sanitized`; body handling shared with tickets (`Threading::bodyFromRequest`). |
| `PATCH /tasks/{id}/notes/{entry}` (`task.view` + edit rule) | Edits an internal note of the task thread with the same rules and semantics as [tickets](threads-files.md) (`body`, `title?`, `file_ids?`; 409 when `{entry}` is not the latest version). |
| `POST /tasks/{id}/status` | `{status: open\|closed, base, comment?}`; closing needs `task.close` and `isCloseable`, reopening `task.edit` or `task.close`. |
| `POST /tasks/{id}/assignment` (`task.assign`) | `{assignee{type,id}, base (token), comment?, alert?}`; closed task → 409. |
| `POST /tasks/{id}/transfer` (`task.transfer`) | `{dept_id, base, comment?, alert?}`. |
| `PUT /tasks/{id}` (`task.edit`) | `{title?, due_at?, fields?{name:scalar}, base{same keys}, comment?}`. Per-field base values, validated as a whole before applying; `due_at` ISO-8601 or `null`. Response `{applied, changed[], task}`. The description is the first thread entry (an edit would create another one): post a note instead. |

Verified: edit title/due date/clear due date (base, no-op, stale, missing base, `agent2` 403), create with assignee + due date (UTC round trip), list/detail/thread, notes/replies, assign conflict → assign, transfer, close → noop → reopen, `agent2` denied `task.close`.
