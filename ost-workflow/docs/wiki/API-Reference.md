# Referencia de la API

Índice de las **93 rutas** de `/workflow/v1`, generado a partir de la tabla de rutas real del plugin. El detalle de cada ruta (cuerpo, respuesta, errores, efectos) está en los documentos enlazados de cada sección. Las convenciones comunes están en [Convenciones.md](Convenciones.md).

La columna **Permiso** es el nombre de la política que el plugin aplica *antes* del handler (réplica de la matriz del panel de agentes de osTicket): `auth` = cualquier agente activo; `ticket.<x>` / `task.<x>` = permiso de rol en el departamento del objeto; `global.<x>` = permiso global del agente; `anydept.<x>` = permiso en al menos un departamento.

## Sesión y agente

Detalle: [Convenciones.md](Convenciones.md)

| Método | Ruta | Permiso |
|---|---|---|
| `GET` | `/workflow/v1/ping` | *(sin autenticación)* |
| `GET` | `/workflow/v1/config` | `auth` |
| `POST` | `/workflow/v1/auth/login` | *(sin autenticación)* |
| `GET` | `/workflow/v1/auth/verify` | `auth` |
| `POST` | `/workflow/v1/auth/logout` | `auth` |
| `GET` | `/workflow/v1/me` | `auth` |
| `GET` | `/workflow/v1/me/permissions` | `auth` |

## Catálogos, formularios y respuestas predefinidas

Detalle: [catalogs-forms-canned.md](../endpoints/catalogs-forms-canned.md)

| Método | Ruta | Permiso |
|---|---|---|
| `GET` | `/workflow/v1/departments` | `auth` |
| `GET` | `/workflow/v1/departments/{id}/assignees` | `auth` |
| `GET` | `/workflow/v1/staff` | `auth` |
| `GET` | `/workflow/v1/topics` | `auth` |
| `GET` | `/workflow/v1/statuses` | `auth` |
| `GET` | `/workflow/v1/priorities` | `auth` |
| `GET` | `/workflow/v1/slas` | `auth` |
| `GET` | `/workflow/v1/teams` | `auth` |
| `GET` | `/workflow/v1/teams/{id}/members` | `auth` |
| `GET` | `/workflow/v1/catalog/sources` | `auth` |
| `GET` | `/workflow/v1/forms/ticket` | `auth` |
| `GET` | `/workflow/v1/forms/user` | `auth` |
| `GET` | `/workflow/v1/forms/organization` | `auth` |
| `GET` | `/workflow/v1/topics/{id}/forms` | `auth` |
| `GET` | `/workflow/v1/canned` | `auth` |
| `GET` | `/workflow/v1/canned/{id}/render` | `canned.render` |

## Tickets

Detalle: [tickets.md](../endpoints/tickets.md)

| Método | Ruta | Permiso |
|---|---|---|
| `GET` | `/workflow/v1/tickets` | `auth` |
| `POST` | `/workflow/v1/tickets` | `anydept.ticket.create` |
| `GET` | `/workflow/v1/tickets/lookup` | `auth` |
| `GET` | `/workflow/v1/search` | `auth` |
| `GET` | `/workflow/v1/tickets/{id}` | `ticket.view` |
| `GET` | `/workflow/v1/tickets/{id}/missing-fields` | `ticket.view` |
| `GET` | `/workflow/v1/tickets/{id}/participants` | `ticket.view` |
| `GET` | `/workflow/v1/tickets/{id}/recipients` | `ticket.view` |
| `GET` | `/workflow/v1/tickets/{id}/fields` | `ticket.view` |
| `GET` | `/workflow/v1/tickets/{id}/related` | `ticket.view` |
| `GET` | `/workflow/v1/tickets/{id}/collaborators` | `ticket.view` |
| `POST` | `/workflow/v1/tickets/{id}/collaborators` | `ticket.edit` |
| `POST` | `/workflow/v1/tickets/{id}/status` | `ticket.view` |
| `POST` | `/workflow/v1/tickets/{id}/assignment` | `ticket.assign` |
| `DELETE` | `/workflow/v1/tickets/{id}/assignment` | `ticket.release_or_manager` |
| `POST` | `/workflow/v1/tickets/{id}/claim` | `ticket.assign` |
| `POST` | `/workflow/v1/tickets/{id}/transfer` | `ticket.transfer` |
| `POST` | `/workflow/v1/tickets/{id}/referrals` | `ticket.assign` |
| `PATCH` | `/workflow/v1/tickets/{id}/fields/{name}` | `ticket.edit` |
| `PUT` | `/workflow/v1/tickets/{id}/forms` | `ticket.edit` |
| `PUT` | `/workflow/v1/tickets/{id}/owner` | `ticket.edit` |
| `POST` | `/workflow/v1/tickets/{id}/answered` | `ticket.markanswered` |

## Hilo y archivos

Detalle: [threads-files.md](../endpoints/threads-files.md)

| Método | Ruta | Permiso |
|---|---|---|
| `GET` | `/workflow/v1/tickets/{id}/activity` | `ticket.view` |
| `POST` | `/workflow/v1/tickets/{id}/replies` | `ticket.reply` |
| `POST` | `/workflow/v1/tickets/{id}/notes` | `ticket.view` |
| `POST` | `/workflow/v1/tickets/{id}/notes/{entry}/files` | `ticket.view` |
| `POST` | `/workflow/v1/files` | `auth` |
| `GET` | `/workflow/v1/files/{hash}` | `auth` |

## Contactos, organizaciones y reconciliación

Detalle: [contacts-orgs-matching.md](../endpoints/contacts-orgs-matching.md)

| Método | Ruta | Permiso |
|---|---|---|
| `GET` | `/workflow/v1/users` | `auth` |
| `POST` | `/workflow/v1/users` | `global.user.create` |
| `GET` | `/workflow/v1/users/{id}` | `user.load` |
| `GET` | `/workflow/v1/users/{id}/tickets` | `user.load` |
| `GET` | `/workflow/v1/users/{id}/fields` | `user.load` |
| `PATCH` | `/workflow/v1/users/{id}` | `user.edit` |
| `PUT` | `/workflow/v1/users/{id}/organization` | `user.edit` |
| `GET` | `/workflow/v1/users/{id}/notes` | `user.load` |
| `POST` | `/workflow/v1/users/{id}/notes` | `user.load` |
| `GET` | `/workflow/v1/organizations` | `auth` |
| `POST` | `/workflow/v1/organizations` | `global.org.create` |
| `GET` | `/workflow/v1/organizations/{id}` | `org.load` |
| `GET` | `/workflow/v1/organizations/{id}/members` | `org.load` |
| `GET` | `/workflow/v1/organizations/{id}/tickets` | `org.load` |
| `GET` | `/workflow/v1/organizations/{id}/fields` | `org.load` |
| `POST` | `/workflow/v1/organizations/{id}/members` | `org.edit` |
| `DELETE` | `/workflow/v1/organizations/{id}/members/{uid}` | `org.edit` |
| `PUT` | `/workflow/v1/organizations/{id}` | `org.edit` |
| `PATCH` | `/workflow/v1/organizations/{id}/profile` | `org.edit` |
| `PATCH` | `/workflow/v1/organizations/{id}/extra` | `org.edit` |
| `GET` | `/workflow/v1/organizations/{id}/notes` | `org.load` |
| `POST` | `/workflow/v1/organizations/{id}/notes` | `org.load` |
| `GET` | `/workflow/v1/match/contact` | `auth` |
| `GET` | `/workflow/v1/match/organization` | `auth` |
| `GET` | `/workflow/v1/match/ticket` | `auth` |

## Tareas

Detalle: [tasks.md](../endpoints/tasks.md)

| Método | Ruta | Permiso |
|---|---|---|
| `GET` | `/workflow/v1/tickets/{id}/tasks` | `ticket.view` |
| `POST` | `/workflow/v1/tickets/{id}/tasks` | `ticket.task_create` |
| `GET` | `/workflow/v1/tasks` | `auth` |
| `GET` | `/workflow/v1/tasks/{id}` | `task.view` |
| `GET` | `/workflow/v1/tasks/{id}/thread` | `task.view` |
| `POST` | `/workflow/v1/tasks/{id}/notes` | `task.view` |
| `POST` | `/workflow/v1/tasks/{id}/replies` | `task.reply` |
| `POST` | `/workflow/v1/tasks/{id}/status` | `task.view` |
| `POST` | `/workflow/v1/tasks/{id}/assignment` | `task.assign` |
| `POST` | `/workflow/v1/tasks/{id}/transfer` | `task.transfer` |
| `PUT` | `/workflow/v1/tasks/{id}` | `task.edit` |

## Sincronización e informes

Detalle: [sync-reports.md](../endpoints/sync-reports.md)

| Método | Ruta | Permiso |
|---|---|---|
| `GET` | `/workflow/v1/sync/tickets` | `auth` |
| `GET` | `/workflow/v1/sync/visible-ticket-ids` | `auth` |
| `GET` | `/workflow/v1/sync/users` | `auth` |
| `GET` | `/workflow/v1/sync/organizations` | `auth` |
| `GET` | `/workflow/v1/sync/tasks` | `auth` |
| `GET` | `/workflow/v1/reports/support` | `auth` |

## Lo que la API no expone

Borrado de tickets, usuarios, organizaciones o tareas; fusionar o enlazar tickets; administración de osTicket (departamentos, temas, SLA, equipos, roles, formularios); `setDeptId`/`setStaffId`/`assignToStaff` directos; adquirir el bloqueo de escritorio de un ticket; un endpoint de lote o transaccional global (`/sync/batch`); la URL de descarga del núcleo. Motivo de cada exclusión: [Seguridad.md](Seguridad.md).
