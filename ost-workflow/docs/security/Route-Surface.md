# Superficie de rutas de ost-workflow — matriz de clasificación

> Generado por `prod-sandbox/gen-route-matrix.py` a partir de `docs/openapi.json` (tabla real de rutas) y `docs/security/route-classification.json` (única fuente editable). No editar a mano.

**105 rutas.** Base congelada el 2026-09-28 (hardening). Decisiones, modelo de amenaza y evidencia: nota `2026-09-28-bestcare-workflow-ost-workflow-security-hardening` de la bóveda 02-KE.

## Por impacto

| Valor | Rutas |
|---|---|
| READ_ONLY | 55 |
| SECURITY_SENSITIVE | 18 |
| REVERSIBLE_UPDATE | 18 |
| APPEND_ONLY | 9 |
| HIGH_IMPACT_UPDATE | 5 |

## Por necesidad

| Valor | Rutas |
|---|---|
| REQUIRED_BY_DESIGNED_CONSUMER | 81 |
| REQUIRED_BY_CORE_WORKFLOW | 15 |
| NO_CONSUMER_YET | 5 |
| USEFUL_BUT_NOT_REQUIRED | 4 |

## Por categoría

| Valor | Rutas |
|---|---|
| FIELD_OPERATION | 57 |
| WORKFLOW_OPERATION | 34 |
| SYSTEM_ADMINISTRATION | 14 |

## Por recomendación

| Valor | Rutas |
|---|---|
| KEEP | 105 |

## Rutas retiradas de la superficie pública (2026-09-28)

| Ruta | Motivo | Reemplazo |
|---|---|---|
| `DELETE /tickets/{id}/collaborators/{uid}` | Hard removal of a collaborator: no approved requirement (OW-REQ-58 says add, activate, deactivate), a reversible alternative exists (PATCH ... active:false), no consumer. | PATCH /tickets/{id}/collaborators/{uid} {active:false} |
| `POST /organizations/{id}/members` | Redundant with PUT /users/{id}/organization (base-checked); changes who sees organization-shared tickets; no consumer. | PUT /users/{id}/organization |
| `DELETE /organizations/{id}/members/{uid}` | Same as above (org_id:null removes the organization). | PUT /users/{id}/organization {org_id:null} |
| `PATCH /me` | No consumer designed; `signature` is appended to customer e-mails (phishing vector for a stolen token) and `on_vacation` disables assignment. Re-introduce only if the Configuration module demonstrates the need, without `signature`. | none (GET /me stays) |
| `PUT /tickets/{id}/owner` | PC-S1 (MSOLIS 2026-09-28): moves the whole conversation and portal access to another contact; no approved consumer. Reopen only through UX requirement -> demonstrated gap -> OW-REQ -> review. | none |

Cada una tiene una prueba negativa en `e2e.py` (404/405).

## Matriz

Leyenda: R/W = lectura/escritura; *Permiso* = política que el plugin aplica antes del handler.

| Endpoint | R/W | Permiso | Consumidor | Categoría | Impacto | Reversible | Necesidad | Riesgo de abuso | Recomendación |
|---|---|---|---|---|---|---|---|---|---|
| `POST /auth/login` | W | `auth` | core | FIELD_OPERATION | SECURITY_SENSITIVE | yes (token revocable) | REQUIRED_BY_CORE_WORKFLOW | Credential stuffing: 5 failures per user+real IP lock 30 min; generic 401; token signed with its own secret. Agents with a second factor in osTicket are refused (403 two_factor_required) before any backend runs: no token, no OTP e-mail (MSOLIS A1, 2026-09-28). | KEEP |
| `POST /auth/logout` | W | `auth` | core | FIELD_OPERATION | SECURITY_SENSITIVE | n/a | REQUIRED_BY_CORE_WORKFLOW | Revokes only the caller's own token(s); "all" ends the caller's own sessions. | KEEP |
| `GET /auth/verify` | R | `auth` | core | FIELD_OPERATION | READ_ONLY | n/a | REQUIRED_BY_CORE_WORKFLOW | Own profile/permissions only (signature is readable, no longer writable). | KEEP |
| `GET /canned` | R | `auth` | tickets (templates) | FIELD_OPERATION | READ_ONLY | n/a | USEFUL_BUT_NOT_REQUIRED | Templates per department visibility; render has no external effect. | KEEP |
| `GET /canned/{id}/render` | R | `canned.render` | tickets (templates) | FIELD_OPERATION | READ_ONLY | n/a | USEFUL_BUT_NOT_REQUIRED | Templates per department visibility; render has no external effect. | KEEP |
| `GET /catalog/sources` | R | `auth` | tickets, contacts, service_orders | SYSTEM_ADMINISTRATION | READ_ONLY | n/a | REQUIRED_BY_DESIGNED_CONSUMER | Catalog/form definitions; no business data. ETag/304. | KEEP |
| `GET /config` | R | `auth` | all modules | FIELD_OPERATION | READ_ONLY | n/a | REQUIRED_BY_CORE_WORKFLOW | Authenticated; limits and enabled modules, never secrets. | KEEP |
| `GET /departments` | R | `auth` | tickets (assign/transfer/refer) | SYSTEM_ADMINISTRATION | READ_ONLY | n/a | REQUIRED_BY_DESIGNED_CONSUMER | Colleague names and e-mails inside the agent's visible departments (SCP visibility); login names removed from the payload in the hardening. | KEEP |
| `GET /departments/{id}/assignees` | R | `auth` | tickets (assign/transfer/refer) | SYSTEM_ADMINISTRATION | READ_ONLY | n/a | REQUIRED_BY_DESIGNED_CONSUMER | Colleague names and e-mails inside the agent's visible departments (SCP visibility); login names removed from the payload in the hardening. | KEEP |
| `GET /documents/{uuid}` | R | `auth` | pdf_signer, service_orders, quotes | FIELD_OPERATION | READ_ONLY | n/a | REQUIRED_BY_DESIGNED_CONSUMER | Only versions on tickets the agent can see; unknown/foreign uuid is 404. | KEEP |
| `POST /files` | W | `auth` | tickets, tasks | FIELD_OPERATION | APPEND_ONLY | yes (core purges unattached files) | REQUIRED_BY_DESIGNED_CONSUMER | Storage/disk fill: per-file cap, type sniffing, hourly upload budget; the file is readable by its uploader only until attached. | KEEP |
| `GET /files/{hash}` | R | `auth` | tickets, tasks | FIELD_OPERATION | READ_ONLY | n/a | REQUIRED_BY_DESIGNED_CONSUMER | Exfiltration surface: ACL = uploader, or an attachment on a ticket/task the agent can see; Range/inline/thumbnail run the same check first; CSP sandbox + nosniff. | KEEP |
| `GET /forms/organization` | R | `auth` | tickets, contacts, service_orders | SYSTEM_ADMINISTRATION | READ_ONLY | n/a | REQUIRED_BY_DESIGNED_CONSUMER | Catalog/form definitions; no business data. ETag/304. | KEEP |
| `GET /forms/ticket` | R | `auth` | tickets, contacts, service_orders | SYSTEM_ADMINISTRATION | READ_ONLY | n/a | REQUIRED_BY_DESIGNED_CONSUMER | Catalog/form definitions; no business data. ETag/304. | KEEP |
| `GET /forms/user` | R | `auth` | tickets, contacts, service_orders | SYSTEM_ADMINISTRATION | READ_ONLY | n/a | REQUIRED_BY_DESIGNED_CONSUMER | Catalog/form definitions; no business data. ETag/304. | KEEP |
| `GET /knowledge/articles` | R | `auth` | knowledge base (OW-REQ-64) | FIELD_OPERATION | READ_ONLY | n/a | REQUIRED_BY_DESIGNED_CONSUMER | Any logged-in agent reads every article/category, as the SCP does (no per-article ACL in the core); no notes, attachments or writes; answer HTML sanitized by the core. `q` 2-100 chars, limit <=100. | KEEP |
| `GET /knowledge/articles/{id}` | R | `auth` | knowledge base (OW-REQ-64) | FIELD_OPERATION | READ_ONLY | n/a | REQUIRED_BY_DESIGNED_CONSUMER | Any logged-in agent reads every article/category, as the SCP does (no per-article ACL in the core); no notes, attachments or writes; answer HTML sanitized by the core. | KEEP |
| `GET /knowledge/categories` | R | `auth` | knowledge base (OW-REQ-64) | FIELD_OPERATION | READ_ONLY | n/a | REQUIRED_BY_DESIGNED_CONSUMER | Any logged-in agent reads every article/category, as the SCP does (no per-article ACL in the core); no notes, attachments or writes; answer HTML sanitized by the core. | KEEP |
| `GET /match/contact` | R | `auth` | contacts, tickets (match before create) | WORKFLOW_OPERATION | READ_ONLY | n/a | REQUIRED_BY_DESIGNED_CONSUMER | Candidate lookups need the value being looked up; contact/organization matches are charged to the lookup budget; org-by-id answers only for organizations the agent may read. | KEEP |
| `GET /match/organization` | R | `auth` | contacts, tickets (match before create) | WORKFLOW_OPERATION | READ_ONLY | n/a | REQUIRED_BY_DESIGNED_CONSUMER | Candidate lookups need the value being looked up; contact/organization matches are charged to the lookup budget; org-by-id answers only for organizations the agent may read. | KEEP |
| `GET /match/ticket` | R | `auth` | contacts, tickets (match before create) | WORKFLOW_OPERATION | READ_ONLY | n/a | REQUIRED_BY_DESIGNED_CONSUMER | Candidate lookups need the value being looked up; contact/organization matches are charged to the lookup budget; org-by-id answers only for organizations the agent may read. | KEEP |
| `GET /me` | R | `auth` | core | FIELD_OPERATION | READ_ONLY | n/a | REQUIRED_BY_CORE_WORKFLOW | Own profile/permissions only (signature is readable, no longer writable). | KEEP |
| `GET /me/permissions` | R | `auth` | core | FIELD_OPERATION | READ_ONLY | n/a | REQUIRED_BY_CORE_WORKFLOW | Own profile/permissions only (signature is readable, no longer writable). | KEEP |
| `GET /organizations` | R | `auth` | contacts | WORKFLOW_OPERATION | SECURITY_SENSITIVE | n/a | REQUIRED_BY_DESIGNED_CONSUMER | Same directory rule as users (user.dir, or an organization on a visible ticket, or created by the agent). | KEEP |
| `POST /organizations` | W | `global.org.create` | contacts | WORKFLOW_OPERATION | APPEND_ONLY | partial (no delete) | REQUIRED_BY_DESIGNED_CONSUMER | 409 candidates before creating; needs org.create. | KEEP |
| `GET /organizations/{id}` | R | `org.load` | contacts | WORKFLOW_OPERATION | SECURITY_SENSITIVE | n/a | REQUIRED_BY_DESIGNED_CONSUMER | Same directory rule as users (user.dir, or an organization on a visible ticket, or created by the agent). | KEEP |
| `PUT /organizations/{id}` | W | `org.edit` | contacts | WORKFLOW_OPERATION | REVERSIBLE_UPDATE | yes (base value) | USEFUL_BUT_NOT_REQUIRED | Rename with base value; duplicate names are refused as candidates. Needs org.edit. | KEEP |
| `PATCH /organizations/{id}/extra` | W | `org.edit` | contacts (BCW extra, D-12) | WORKFLOW_OPERATION | REVERSIBLE_UPDATE | yes (compare-and-swap) | REQUIRED_BY_DESIGNED_CONSUMER | Real CAS on the raw text; only the BCW line is touched. | KEEP |
| `GET /organizations/{id}/fields` | R | `org.load` | contacts | WORKFLOW_OPERATION | SECURITY_SENSITIVE | n/a | REQUIRED_BY_DESIGNED_CONSUMER | Same directory rule as users (user.dir, or an organization on a visible ticket, or created by the agent). | KEEP |
| `GET /organizations/{id}/members` | R | `org.load` | contacts | WORKFLOW_OPERATION | SECURITY_SENSITIVE | n/a | REQUIRED_BY_DESIGNED_CONSUMER | Same directory rule as users (user.dir, or an organization on a visible ticket, or created by the agent). | KEEP |
| `GET /organizations/{id}/notes` | R | `org.load` | contacts | WORKFLOW_OPERATION | SECURITY_SENSITIVE | n/a | REQUIRED_BY_DESIGNED_CONSUMER | Same directory rule as users (user.dir, or an organization on a visible ticket, or created by the agent). | KEEP |
| `POST /organizations/{id}/notes` | R | `org.load` | contacts | WORKFLOW_OPERATION | SECURITY_SENSITIVE | n/a | REQUIRED_BY_DESIGNED_CONSUMER | Same directory rule as users (user.dir, or an organization on a visible ticket, or created by the agent). | KEEP |
| `PATCH /organizations/{id}/profile` | W | `org.edit` | none designed yet | WORKFLOW_OPERATION | REVERSIBLE_UPDATE | yes (base values) | NO_CONSUMER_YET | Account manager, domain mapping and primary contacts only, with org.edit + base value. `sharing` and the collaborator/assignment flags are NOT editable (PC-S4, MSOLIS 2026-09-28): typed 422 `not_editable`; they are still read and preserved on save. | KEEP |
| `GET /organizations/{id}/tickets` | R | `org.load` | contacts | WORKFLOW_OPERATION | SECURITY_SENSITIVE | n/a | REQUIRED_BY_DESIGNED_CONSUMER | Same directory rule as users (user.dir, or an organization on a visible ticket, or created by the agent). | KEEP |
| `GET /ping` | R | `auth` | core (health) | FIELD_OPERATION | READ_ONLY | n/a | REQUIRED_BY_CORE_WORKFLOW | Public; returns {"status":"ok"} only. | KEEP |
| `GET /priorities` | R | `auth` | tickets, contacts, service_orders | SYSTEM_ADMINISTRATION | READ_ONLY | n/a | REQUIRED_BY_DESIGNED_CONSUMER | Catalog/form definitions; no business data. ETag/304. | KEEP |
| `GET /queues` | R | `auth` | tickets (saved queues) | FIELD_OPERATION | READ_ONLY | n/a | NO_CONSUMER_YET | Saved-queue query intersected with the agent's ticket visibility; no wider than GET /tickets. | KEEP |
| `GET /queues/{id}/tickets` | R | `auth` | tickets (saved queues) | FIELD_OPERATION | READ_ONLY | n/a | NO_CONSUMER_YET | Saved-queue query intersected with the agent's ticket visibility; no wider than GET /tickets. | KEEP |
| `GET /reports/support` | R | `auth` | support | WORKFLOW_OPERATION | READ_ONLY | n/a | REQUIRED_BY_DESIGNED_CONSUMER | Aggregates over the agent's visible tickets only. | KEEP |
| `GET /search` | R | `auth` | tickets | FIELD_OPERATION | READ_ONLY | n/a | REQUIRED_BY_DESIGNED_CONSUMER | Bounded by ticket visibility (dept/assigned/referral); paging scrapes only what the agent already sees in the SCP. | KEEP |
| `GET /slas` | R | `auth` | tickets, contacts, service_orders | SYSTEM_ADMINISTRATION | READ_ONLY | n/a | REQUIRED_BY_DESIGNED_CONSUMER | Catalog/form definitions; no business data. ETag/304. | KEEP |
| `GET /staff` | R | `auth` | tickets (assign/transfer/refer) | SYSTEM_ADMINISTRATION | READ_ONLY | n/a | REQUIRED_BY_DESIGNED_CONSUMER | Colleague names and e-mails inside the agent's visible departments (SCP visibility); login names removed from the payload in the hardening. | KEEP |
| `GET /statuses` | R | `auth` | tickets, contacts, service_orders | SYSTEM_ADMINISTRATION | READ_ONLY | n/a | REQUIRED_BY_DESIGNED_CONSUMER | Catalog/form definitions; no business data. ETag/304. | KEEP |
| `GET /sync/organizations` | R | `auth` | contacts (offline directory, P-05 open) | WORKFLOW_OPERATION | SECURITY_SENSITIVE | n/a | NO_CONSUMER_YET | Bulk copy of every contact/organization: now requires user.dir (was open to any agent). | KEEP |
| `GET /sync/tasks` | R | `auth` | all (offline sync) | FIELD_OPERATION | READ_ONLY | n/a | REQUIRED_BY_DESIGNED_CONSUMER | Bounded by ticket/task visibility. | KEEP |
| `GET /sync/tickets` | R | `auth` | all (offline sync) | FIELD_OPERATION | READ_ONLY | n/a | REQUIRED_BY_DESIGNED_CONSUMER | Bounded by ticket/task visibility. | KEEP |
| `GET /sync/users` | R | `auth` | contacts (offline directory, P-05 open) | WORKFLOW_OPERATION | SECURITY_SENSITIVE | n/a | NO_CONSUMER_YET | Bulk copy of every contact/organization: now requires user.dir (was open to any agent). | KEEP |
| `GET /sync/visible-ticket-ids` | R | `auth` | all (offline sync) | FIELD_OPERATION | READ_ONLY | n/a | REQUIRED_BY_DESIGNED_CONSUMER | Bounded by ticket/task visibility. | KEEP |
| `GET /tasks` | R | `auth` | tickets (delegated work) | FIELD_OPERATION | READ_ONLY | n/a | REQUIRED_BY_DESIGNED_CONSUMER | task.view (SCP visibility). | KEEP |
| `GET /tasks/{id}` | R | `task.view` | tickets (delegated work) | FIELD_OPERATION | READ_ONLY | n/a | REQUIRED_BY_DESIGNED_CONSUMER | task.view (SCP visibility). | KEEP |
| `PUT /tasks/{id}` | W | `task.edit` | tickets (delegated work) | WORKFLOW_OPERATION | REVERSIBLE_UPDATE | yes (base value) | REQUIRED_BY_DESIGNED_CONSUMER | Base value required; alert opt-in. | KEEP |
| `POST /tasks/{id}/assignment` | W | `task.assign` | tickets (delegated work) | WORKFLOW_OPERATION | REVERSIBLE_UPDATE | yes (base value) | REQUIRED_BY_DESIGNED_CONSUMER | Base value required; alert opt-in. | KEEP |
| `POST /tasks/{id}/notes` | W | `task.view` | tickets (delegated work) | FIELD_OPERATION | APPEND_ONLY | n/a | REQUIRED_BY_DESIGNED_CONSUMER | Task thread is internal; PDF rule applies. | KEEP |
| `PATCH /tasks/{id}/notes/{entry}` | W | `task.view` | tickets (delegated work) | FIELD_OPERATION | REVERSIBLE_UPDATE | yes | REQUIRED_BY_DESIGNED_CONSUMER | Same edit rule as ticket notes. | KEEP |
| `POST /tasks/{id}/replies` | W | `task.reply` | tickets (delegated work) | FIELD_OPERATION | APPEND_ONLY | n/a | REQUIRED_BY_DESIGNED_CONSUMER | Task thread is internal; PDF rule applies. | KEEP |
| `POST /tasks/{id}/status` | W | `task.view` | tickets (delegated work) | WORKFLOW_OPERATION | REVERSIBLE_UPDATE | yes (base value) | REQUIRED_BY_DESIGNED_CONSUMER | Base value required; alert opt-in. | KEEP |
| `GET /tasks/{id}/thread` | R | `task.view` | tickets (delegated work) | FIELD_OPERATION | READ_ONLY | n/a | REQUIRED_BY_DESIGNED_CONSUMER | task.view (SCP visibility). | KEEP |
| `POST /tasks/{id}/transfer` | W | `task.transfer` | tickets (delegated work) | WORKFLOW_OPERATION | REVERSIBLE_UPDATE | yes (base value) | REQUIRED_BY_DESIGNED_CONSUMER | Base value required; alert opt-in. | KEEP |
| `GET /teams` | R | `auth` | tickets (assign/transfer/refer) | SYSTEM_ADMINISTRATION | READ_ONLY | n/a | REQUIRED_BY_DESIGNED_CONSUMER | Colleague names and e-mails inside the agent's visible departments (SCP visibility); login names removed from the payload in the hardening. | KEEP |
| `GET /teams/{id}/members` | R | `auth` | tickets (assign/transfer/refer) | SYSTEM_ADMINISTRATION | READ_ONLY | n/a | REQUIRED_BY_DESIGNED_CONSUMER | Colleague names and e-mails inside the agent's visible departments (SCP visibility); login names removed from the payload in the hardening. | KEEP |
| `GET /tickets` | R | `auth` | tickets | FIELD_OPERATION | READ_ONLY | n/a | REQUIRED_BY_DESIGNED_CONSUMER | Bounded by ticket visibility (dept/assigned/referral); paging scrapes only what the agent already sees in the SCP. | KEEP |
| `POST /tickets` | W | `anydept.ticket.create` | tickets, service_orders, quotes | FIELD_OPERATION | HIGH_IMPACT_UPDATE | partial (no delete; close) | REQUIRED_BY_DESIGNED_CONSUMER | Spam tickets; autoresponse mail. notify default false, staff alerts off, mail hourly budget; `fields` is now whitelisted (mass assignment fixed: dept/assignee/status/SLA/due/autoresponse cannot be forged). The ticket's department (explicit, topic or default) must be one the agent really has access to (403 department_not_accessible); cid:/data:/file.php?key= in free text are rejected (422). | KEEP |
| `GET /tickets/lookup` | R | `auth` | tickets | FIELD_OPERATION | READ_ONLY | n/a | REQUIRED_BY_DESIGNED_CONSUMER | Bounded by ticket visibility (dept/assigned/referral); paging scrapes only what the agent already sees in the SCP. | KEEP |
| `GET /tickets/{id}` | R | `ticket.view` | tickets | FIELD_OPERATION | READ_ONLY | n/a | REQUIRED_BY_DESIGNED_CONSUMER | ticket.view (Policy) — the SCP visibility rule; 403 otherwise. | KEEP |
| `GET /tickets/{id}/actions` | R | `ticket.view` | tickets | FIELD_OPERATION | READ_ONLY | n/a | REQUIRED_BY_DESIGNED_CONSUMER | ticket.view (Policy) — the SCP visibility rule; 403 otherwise. | KEEP |
| `GET /tickets/{id}/activity` | R | `ticket.view` | tickets | FIELD_OPERATION | READ_ONLY | n/a | REQUIRED_BY_DESIGNED_CONSUMER | ticket.view (Policy) — the SCP visibility rule; 403 otherwise. | KEEP |
| `POST /tickets/{id}/answered` | W | `ticket.markanswered` | tickets | FIELD_OPERATION | REVERSIBLE_UPDATE | yes | REQUIRED_BY_CORE_WORKFLOW | Flag toggle; no external effect. | KEEP |
| `DELETE /tickets/{id}/assignment` | W | `ticket.release_or_manager` | tickets (release) | FIELD_OPERATION | REVERSIBLE_UPDATE | yes (assign again) | REQUIRED_BY_CORE_WORKFLOW | Verb DELETE but nothing is deleted: it releases the assignment and logs `released`. | KEEP |
| `POST /tickets/{id}/assignment` | W | `ticket.assign` | tickets | FIELD_OPERATION | REVERSIBLE_UPDATE | yes | REQUIRED_BY_CORE_WORKFLOW | Reassignment noise; alert is opt-in (default false); base value required. | KEEP |
| `POST /tickets/{id}/claim` | W | `ticket.assign` | tickets | FIELD_OPERATION | REVERSIBLE_UPDATE | yes | REQUIRED_BY_CORE_WORKFLOW | Reassignment noise; alert is opt-in (default false); base value required. | KEEP |
| `GET /tickets/{id}/collaborators` | R | `ticket.view` | tickets | FIELD_OPERATION | READ_ONLY | n/a | REQUIRED_BY_DESIGNED_CONSUMER | ticket.view (Policy) — the SCP visibility rule; 403 otherwise. | KEEP |
| `POST /tickets/{id}/collaborators` | W | `ticket.edit` | tickets (CC management) | FIELD_OPERATION | HIGH_IMPACT_UPDATE | partial (deactivate, entry stays) | REQUIRED_BY_DESIGNED_CONSUMER | A stolen token can add an attacker address as CC so every later customer reply reaches it (also portal read access). Needs ticket.edit; a NEW contact needs user.create; event `collab` is visible in the thread. Residual: contact ids are usable without directory access. | KEEP |
| `PATCH /tickets/{id}/collaborators/{uid}` | W | `ticket.edit` | tickets (CC management) | FIELD_OPERATION | REVERSIBLE_UPDATE | yes (toggle) | REQUIRED_BY_DESIGNED_CONSUMER | Re-activating a CC makes later public replies reach that contact; visible in the thread events. (DELETE removed: deactivation is the reversible path.) | KEEP |
| `GET /tickets/{id}/documents` | R | `ticket.view` | tickets | FIELD_OPERATION | READ_ONLY | n/a | REQUIRED_BY_DESIGNED_CONSUMER | ticket.view (Policy) — the SCP visibility rule; 403 otherwise. | KEEP |
| `GET /tickets/{id}/fields` | R | `ticket.view` | tickets | FIELD_OPERATION | READ_ONLY | n/a | REQUIRED_BY_DESIGNED_CONSUMER | ticket.view (Policy) — the SCP visibility rule; 403 otherwise. | KEEP |
| `PATCH /tickets/{id}/fields/{name}` | W | `ticket.edit` | tickets, service_orders | FIELD_OPERATION | REVERSIBLE_UPDATE | yes (base value) | REQUIRED_BY_DESIGNED_CONSUMER | Field edits carry base; conflicting writes are 409; edits are logged as thread events. | KEEP |
| `PUT /tickets/{id}/forms` | W | `ticket.edit` | tickets, service_orders | FIELD_OPERATION | REVERSIBLE_UPDATE | yes (base value) | REQUIRED_BY_DESIGNED_CONSUMER | Field edits carry base; conflicting writes are 409; edits are logged as thread events. | KEEP |
| `GET /tickets/{id}/missing-fields` | R | `ticket.view` | tickets | FIELD_OPERATION | READ_ONLY | n/a | REQUIRED_BY_DESIGNED_CONSUMER | ticket.view (Policy) — the SCP visibility rule; 403 otherwise. | KEEP |
| `POST /tickets/{id}/notes` | W | `ticket.view` | tickets, service_orders, pdf_signer | FIELD_OPERATION | APPEND_ONLY | n/a (append-only) | REQUIRED_BY_DESIGNED_CONSUMER | Internal only; alert default false; files must be the agent's own uploads; PDF needs explanatory text. | KEEP |
| `PATCH /tickets/{id}/notes/{entry}` | W | `ticket.view` | tickets | FIELD_OPERATION | REVERSIBLE_UPDATE | yes (old version kept hidden) | REQUIRED_BY_DESIGNED_CONSUMER | Internal notes only; edit = new linked entry, old one hidden and kept; never e-mails. | KEEP |
| `POST /tickets/{id}/notes/{entry}/files` | W | `ticket.view` | tickets, service_orders, pdf_signer | FIELD_OPERATION | APPEND_ONLY | n/a (append-only) | REQUIRED_BY_DESIGNED_CONSUMER | Internal only; alert default false; files must be the agent's own uploads; PDF needs explanatory text. | KEEP |
| `GET /tickets/{id}/participants` | R | `ticket.view` | tickets | FIELD_OPERATION | READ_ONLY | n/a | REQUIRED_BY_DESIGNED_CONSUMER | ticket.view (Policy) — the SCP visibility rule; 403 otherwise. | KEEP |
| `GET /tickets/{id}/pdf` | R | `ticket.view` | tickets, pdf_signer | FIELD_OPERATION | READ_ONLY | n/a | USEFUL_BUT_NOT_REQUIRED | Whole ticket (optionally with internal notes/events) in one file: exfiltration unit; CPU heavy. Same ACL as ticket.view + hourly budget. | KEEP |
| `GET /tickets/{id}/recipients` | R | `ticket.view` | tickets | FIELD_OPERATION | READ_ONLY | n/a | REQUIRED_BY_DESIGNED_CONSUMER | ticket.view (Policy) — the SCP visibility rule; 403 otherwise. | KEEP |
| `POST /tickets/{id}/referrals` | W | `ticket.assign` | tickets | WORKFLOW_OPERATION | APPEND_ONLY | partial | REQUIRED_BY_CORE_WORKFLOW | Grants another agent/team/dept visibility of the ticket; append-only, logged. | KEEP |
| `GET /tickets/{id}/related` | R | `ticket.view` | tickets | FIELD_OPERATION | READ_ONLY | n/a | REQUIRED_BY_DESIGNED_CONSUMER | ticket.view (Policy) — the SCP visibility rule; 403 otherwise. | KEEP |
| `POST /tickets/{id}/replies` | W | `ticket.reply` | tickets | FIELD_OPERATION | HIGH_IMPACT_UPDATE | no (e-mail already sent) | REQUIRED_BY_DESIGNED_CONSUMER | External e-mail. `notify` is REQUIRED (no default), claim and signature are explicit, `cc` accepts existing contacts only, PDF needs text, hourly budget (limit_mail_per_hour). | KEEP |
| `GET /tickets/{id}/sla` | R | `ticket.view` | tickets | FIELD_OPERATION | READ_ONLY | n/a | REQUIRED_BY_DESIGNED_CONSUMER | ticket.view (Policy) — the SCP visibility rule; 403 otherwise. | KEEP |
| `POST /tickets/{id}/sla` | W | `ticket.edit` | tickets (SLA operations) | WORKFLOW_OPERATION | HIGH_IMPACT_UPDATE | partial (`clear_overdue`/`disable` drop deadlines) | REQUIRED_BY_CORE_WORKFLOW | `disable` and `clear_overdue` could hide an SLA breach: since PC-S2 (MSOLIS 2026-09-28) they need the department manager (or an administrator) on top of ticket.edit; `restart`, `extend`, `enable` stay with ticket.edit. Every operation leaves an internal SLA note plus the core events. | KEEP |
| `POST /tickets/{id}/status` | W | `ticket.view` | tickets | FIELD_OPERATION | REVERSIBLE_UPDATE | yes (base value) | REQUIRED_BY_CORE_WORKFLOW | Mass close/reopen with a token; every change checks base and ticket.close/create; no e-mail by itself. | KEEP |
| `GET /tickets/{id}/targets` | R | `ticket.view` | tickets | FIELD_OPERATION | READ_ONLY | n/a | REQUIRED_BY_DESIGNED_CONSUMER | ticket.view (Policy) — the SCP visibility rule; 403 otherwise. | KEEP |
| `GET /tickets/{id}/tasks` | R | `ticket.view` | tickets | FIELD_OPERATION | READ_ONLY | n/a | REQUIRED_BY_DESIGNED_CONSUMER | ticket.view (Policy) — the SCP visibility rule; 403 otherwise. | KEEP |
| `POST /tickets/{id}/tasks` | W | `ticket.task_create` | tickets (delegated work) | WORKFLOW_OPERATION | APPEND_ONLY | partial (close) | REQUIRED_BY_DESIGNED_CONSUMER | Creates internal work items; alert default false. | KEEP |
| `POST /tickets/{id}/transfer` | W | `ticket.transfer` | tickets | WORKFLOW_OPERATION | HIGH_IMPACT_UPDATE | partial (needs access in the target dept) | REQUIRED_BY_CORE_WORKFLOW | Can move a ticket out of everyone's sight; requires ticket.transfer; alert opt-in; base value required. | KEEP |
| `GET /topics` | R | `auth` | tickets, contacts, service_orders | SYSTEM_ADMINISTRATION | READ_ONLY | n/a | REQUIRED_BY_DESIGNED_CONSUMER | Catalog/form definitions; no business data. ETag/304. | KEEP |
| `GET /topics/{id}/forms` | R | `auth` | tickets, contacts, service_orders | SYSTEM_ADMINISTRATION | READ_ONLY | n/a | REQUIRED_BY_DESIGNED_CONSUMER | Catalog/form definitions; no business data. ETag/304. | KEEP |
| `GET /users` | R | `auth` | contacts, tickets (contact lookup) | WORKFLOW_OPERATION | SECURITY_SENSITIVE | n/a | REQUIRED_BY_DESIGNED_CONSUMER | Directory reads: `user.dir` agents browse/page; the rest get autocomplete-sized (10), unpaged, min 3 chars, hourly lookup budget. | KEEP |
| `POST /users` | W | `global.user.create` | contacts | WORKFLOW_OPERATION | APPEND_ONLY | partial (no delete) | REQUIRED_BY_DESIGNED_CONSUMER | Creates a portal identity; 409 candidates before creating; needs user.create. | KEEP |
| `GET /users/{id}` | R | `user.load` | contacts | WORKFLOW_OPERATION | SECURITY_SENSITIVE | n/a | REQUIRED_BY_DESIGNED_CONSUMER | Reading one contact needs user.dir OR the contact is on a ticket the agent can see OR the agent created it (403 otherwise; id enumeration closed). Notes are append-only. | KEEP |
| `PATCH /users/{id}` | W | `user.edit` | contacts | WORKFLOW_OPERATION | REVERSIBLE_UPDATE | yes (base value; old value in the event) | REQUIRED_BY_DESIGNED_CONSUMER | Name, phone and custom fields only, with user.edit + base value. The e-mail address is NOT editable (PC-S3, MSOLIS 2026-09-28): typed 422 `not_editable`. | KEEP |
| `GET /users/{id}/fields` | R | `user.load` | contacts | WORKFLOW_OPERATION | SECURITY_SENSITIVE | n/a | REQUIRED_BY_DESIGNED_CONSUMER | Reading one contact needs user.dir OR the contact is on a ticket the agent can see OR the agent created it (403 otherwise; id enumeration closed). Notes are append-only. | KEEP |
| `GET /users/{id}/notes` | R | `user.load` | contacts | WORKFLOW_OPERATION | SECURITY_SENSITIVE | n/a | REQUIRED_BY_DESIGNED_CONSUMER | Reading one contact needs user.dir OR the contact is on a ticket the agent can see OR the agent created it (403 otherwise; id enumeration closed). Notes are append-only. | KEEP |
| `POST /users/{id}/notes` | R | `user.load` | contacts | WORKFLOW_OPERATION | SECURITY_SENSITIVE | n/a | REQUIRED_BY_DESIGNED_CONSUMER | Reading one contact needs user.dir OR the contact is on a ticket the agent can see OR the agent created it (403 otherwise; id enumeration closed). Notes are append-only. | KEEP |
| `PUT /users/{id}/organization` | W | `user.edit` | contacts | WORKFLOW_OPERATION | SECURITY_SENSITIVE | yes (base value) | REQUIRED_BY_DESIGNED_CONSUMER | Organization membership drives org-shared ticket visibility in the portal; needs user.edit + base. | KEEP |
| `GET /users/{id}/tickets` | R | `user.load` | contacts | WORKFLOW_OPERATION | SECURITY_SENSITIVE | n/a | REQUIRED_BY_DESIGNED_CONSUMER | Reading one contact needs user.dir OR the contact is on a ticket the agent can see OR the agent created it (403 otherwise; id enumeration closed). Notes are append-only. | KEEP |
