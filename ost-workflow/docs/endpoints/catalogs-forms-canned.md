# Catalogs, forms and canned responses (read-only)

Base: `/workflow/v1`. All routes are `GET`, need `Authorization: Bearer <token>`, policy `auth`
(unless noted). Success envelope `{"data": …, "meta": {…}}`; errors `{"error": {"code","message","field?","details?"}}`.
Boolean query flags accept only `0|1|true|false` (anything else → `422 validation_failed` with `field`).
Every list echoes the filters it applied in `meta.filters` (no hidden defaults). Timestamps are ISO-8601 UTC or `null`.
Handlers: `lib/OstWorkflow/Handlers/{Catalogs,Forms,Canned}.php`. None of these mutates anything, so none takes an `Idempotency-Key`.

## Catalogs (`Catalogs.php`)

| Route | Core method (osTickets/include) | Visibility |
|---|---|---|
| `GET /departments?include_disabled=0` | `Staff::getDepartmentNames` (class.staff.php:473), `Dept::lookup` | without `visibility.departments` only the agent's departments |
| `GET /departments/{id}/assignees` | `Dept::getAssignees(['staff'=>$staff])` (class.dept.php:278) | dept must be visible (403 otherwise); result filtered by `Staff::applyDeptVisibility` |
| `GET /staff?dept_id=&include_inactive=0` | `Staff::objects()` + `Staff::applyDeptVisibility` (class.staff.php:746) + `Staff::nsort` | `visibility.agents`; max 1000 rows |
| `GET /topics?include_disabled=0` | `Staff::getTopicNames` (class.staff.php:487), `Topic::lookup` | private topics of inaccessible depts hidden |
| `GET /statuses?state=open,closed&include_disabled=0` | `TicketStatusList::getStatuses` (class.list.php:973) | none |
| `GET /priorities` | `Priority::objects()` (class.priority.php) | none |
| `GET /slas` | `SLA::objects()` (class.sla.php) | none |
| `GET /teams` | `Team::objects()`, `Team::getNumMembers` (class.team.php:78) | none |
| `GET /teams/{id}/members` | `Team::getMembers` (class.team.php:82) | none |
| `GET /catalog/sources` | `Ticket::getSources` (class.ticket.php:4767) | none |

### Shapes

```jsonc
// GET /departments
{"data":[{"id":1,"name":"Support","full_name":"Support","parent_id":null,"is_active":true,"is_public":true,
          "manager_id":null,"sla_id":null,"members_only_assign":false}],
 "meta":{"count":1,"filters":{"include_disabled":false}}}
// GET /departments/1/assignees, GET /staff, GET /teams/{id}/members  (agent object)
{"id":2,"username":"agent2","name":"Agent Two","first_name":"Agent","last_name":"Two",
 "email":"agent2@example.com","dept_id":1,"is_active":true,"on_vacation":false}
// GET /topics
{"id":10,"name":"Report a Problem","full_name":"Report a Problem","parent_id":null,"dept_id":3,
 "priority_id":2,"sla_id":null,"status_id":null,"staff_id":null,"team_id":null,
 "is_public":true,"is_active":true,"sort":0,"updated":"2026-09-27T22:59:57Z"}
// GET /statuses   state ∈ open|closed|archived|deleted; reopenstatus null = "system default"
{"id":2,"name":"Resolved","state":"closed","sort":2,"is_enabled":true,"allowreopen":true,
 "reopenstatus":null,"description":"Resolved tickets","updated":null}
// GET /priorities   urgency: lower = more urgent (sorted by urgency)
{"id":4,"key":"emergency","name":"Emergency","color":"#FEE7E7","urgency":1,"is_public":true}
// GET /slas
{"id":1,"name":"Default SLA","grace_period_hours":18,"is_active":true,"schedule_id":null,"updated":"…Z"}
// GET /teams
{"id":1,"name":"Level I Support","is_enabled":true,"lead_id":null,"members_count":0,"updated":"…Z"}
// GET /catalog/sources
{"key":"Phone","label":"Phone"}
```

Errors: `404 not_found` (department/team id), `403 forbidden` (department not visible), `422 validation_failed`
(`dept_id` not an integer; unknown `state` → `details.allowed`; non-boolean flag).

Notes
- `id`s of ids-that-mean-"none" in osTicket (0) are returned as `null` (`parent_id`, `dept_id`, `sla_id`, …).
- `GET /statuses` lists every state (including `archived`/`deleted`); clients filter with `state=`. Status `updated` is
  usually `null` (osTicket stores `0000-00-00`).
- Topic/department names are localized by osTicket for the caller's language; `full_name` carries the `Parent / Child` path.
- `/staff` and `/departments/{id}/assignees` expose agent emails as the SCP does. No permission beyond `auth` is required
  to read them (the SCP shows the same lists to anyone able to assign/transfer).

## Forms (`Forms.php`)

| Route | Core method |
|---|---|
| `GET /forms/ticket` | `TicketForm::objects()` (class.dynamic_forms.php:530), `DynamicForm::getFields` (:72) |
| `GET /forms/user` | `UserForm::getUserForm` (class.dynamic_forms.php:487) |
| `GET /forms/organization` | `OrganizationForm::getDefaultForm` (class.organization.php:672) |
| `GET /topics/{id}/forms` | `Topic::getForms` (class.topic.php:174) — topic-disabled fields already removed |

```jsonc
{"data":{"id":2,"type":"T","title":"Ticket Details","instructions":"Please Describe Your Issue",
  "fields":[{"id":22,"name":"priority","label":"Priority Level","type":"priority","hint":null,"sort":3,
    "required_staff":false,"required_users":false,"visible_staff":true,"visible_users":false,
    "editable_staff":true,"editable_users":false,"has_data":true,
    "default":null,"configuration":{"prompt":"","default":""},
    "choices":[{"value":1,"label":"Low"},{"value":2,"label":"Normal"}]}]}}
// GET /topics/{id}/forms => {"data":{"topic":{…topic object…},"forms":[{form},…]}}
```

- `type` is the osTicket field type code (`text`, `memo`, `thread`, `phone`, `priority`, `choices`, `list-N`, `bool`, `datetime`, …).
  `choices` is present (max 500) for list-like types, else `null`. `configuration` keeps only scalar settings (size, length,
  validator, regex, placeholder, …). `required_*/visible_*/editable_*` are the per-audience flags; the app is staff-side,
  so it should use the `*_staff` ones. `has_data:false` = presentation-only field.
- The `thread` field (`message`) is the ticket body: its `configuration.size` is osTicket's attachment limit, not the plugin's
  (use `GET /config` `attachments` for the effective limit).
- `GET /topics/{id}/forms` includes the default "Ticket Details" form when the topic attaches it (all stock topics do).
  Contact data is asked with `GET /forms/user`.
- Errors: `404 not_found` (topic / missing form), `403 forbidden` (topic hidden from this agent).

## Canned responses (`Canned.php`)

| Route | Core method | Policy |
|---|---|---|
| `GET /canned?dept=&explicit=0` | `Canned::getCannedResponses` (class.canned.php:231) | `auth`; only enabled responses of the agent's departments + global ones |
| `GET /canned/{id}/render?ticket=<id>` | `Canned::getFormattedResponse` (class.canned.php:122) + `Ticket::replaceVars` (class.ticket.php:2412), as `ajax.tickets.php:520` | `canned.render` (handler policy): `ticket` required and accessible (`Ticket::checkStaffPerm`) |

```jsonc
// GET /canned?dept=1
{"data":[{"id":2,"title":"Sample (with variables)","dept_id":null}],
 "meta":{"count":1,"filters":{"dept":1,"explicit":false}}}   // dept_id null = global response
// GET /canned/2/render?ticket=1
{"data":{"id":2,"title":"…","dept_id":null,"ticket_id":1,
  "body":"Hi osTicket,\n<br>…Your ticket #379987 …",      // stored HTML, variables replaced for the ticket owner
  "body_text":"Hi osTicket,\n\n…",                          // plain text
  "files":[{"id":2,"name":"osTicket.txt","size":24,"type":"text/plain"}]}}
```

- `dept` without `explicit` = that department plus global responses; `explicit=1` = that department only.
- `canned.manage` is not needed to use a response.
- `files` is metadata only: the plugin does not serve canned attachments, and the SCP's session-based "attach canned files"
  (`$_SESSION[':cannedFiles']`) is not reproduced; attaching one to a reply is up to the Threads/Files handlers.
- Errors: `422 validation_failed` (`dept` not numeric; missing/invalid `ticket`), `404 not_found` (ticket, response missing or
  disabled), `403 forbidden` (ticket not accessible; response of a department the agent cannot use).

## Tested (sandbox osTicket 1.17.2, PHP 8.2)
Every route above with the admin agent (success) plus error paths (unknown ids, malformed params, hidden department for a
Limited-Access agent, missing `ticket`). Canned variable replacement verified against ticket #379987.
