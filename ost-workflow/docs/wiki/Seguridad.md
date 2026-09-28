# Seguridad

## Autenticación y tokens
Token firmado con HMAC-SHA256 con un **secreto propio** guardado cifrado en la configuración de la instancia (no `SECRET_SALT`). Se compara con `hash_equals`; el agente se relee en cada petición. Se revoca de forma individual o por agente (`logout`). Un secreto ausente o corto impide iniciar sesión (`503 not_configured`).

## Bloqueo de intentos de login
5 fallos por **usuario + IP real** bloquean ese par durante 30 minutos (`429` con `Retry-After`). La IP real se toma de `X-Forwarded-For` solo si la petición llega de un proxy configurado como de confianza. El estado es persistente (tabla del plugin), no depende del contenedor. Otro usuario o la misma cuenta desde otra IP no se ven afectados (comprobado).

## Permisos
Antes de cada handler se aplica la tabla de políticas (ver [API-Reference.md](API-Reference.md)): visibilidad de tickets con la misma regla del panel, permisos de rol por departamento (`ticket.reply`, `ticket.close`, `ticket.assign`…) y permisos globales (`user.edit`, `org.create`…). Los mensajes `403` nombran el permiso que falta. Un cambio de estado indicado en una nota (`note_status_id`) o respuesta obedece la misma regla que `POST /status` (el núcleo no la comprueba).

## Archivos
Tipo detectado con `finfo` (no por la cabecera del cliente), tamaño y número limitados por configuración, propiedad (`file_id` subido por el mismo agente), descarga con Bearer y control de acceso adjunto → entrada → hilo → ticket/tarea, con `ETag`, `nosniff` y nombre en UTF-8.

## Cabeceras y errores
`X-Content-Type-Options: nosniff`, `X-Frame-Options: DENY`, `Cache-Control: no-store` en todas las respuestas. Los errores internos no filtran datos; los mensajes del núcleo se transforman en errores por campo.

## Estado y registros
El plugin no escribe archivos en su directorio (el phar no admite escritura y los contenedores son efímeros). No se registran contraseñas, tokens ni datos personales.

## Lo que se decidió no exponer
| Capacidad | Motivo |
|---|---|
| Borrado de tickets, usuarios, organizaciones, tareas | irreversible, sin papelera |
| Fusionar o enlazar tickets | operación de escritorio, sin uso en campo |
| Administración de osTicket | solo lectura de catálogos |
| Cuentas de portal, contraseñas de agente, lista de correos vetados | administración |
| `setDeptId`, `setStaffId`, `assignToStaff` directos | escriben sin permisos, eventos ni alertas; se usan `transfer`/`assign` |
| Endpoint de lote o transaccional | el núcleo no es transaccional |
| Editar respuestas públicas y mensajes de clientes | la respuesta ya se envió por correo; se corrige con una respuesta nueva. **Sí** se pueden editar las **notas internas** (`PATCH …/notes/{entry}`; solo el autor, el gerente del departamento o quien tenga `thread.edit`; cada edición crea una versión nueva y oculta la anterior, sin borrarla; sin correo): ver [Respuestas-y-Notas.md](Respuestas-y-Notas.md) |
| Adquirir el bloqueo del ticket | el lock de escritorio solo se lee |
| URL de descarga del núcleo | exige sesión del panel |
| Quitar un colaborador (`DELETE …/collaborators/{uid}`) | se desactiva con `PATCH … {active:false}`: reversible y con rastro (retirada el 2026-09-28) |
| Alta y baja de miembros de una organización (`…/members`) | `PUT /users/{id}/organization` lo cubre, con valor base (retirada el 2026-09-28) |
| Login de agentes con 2FA (`POST /auth/login`) | el segundo factor de osTicket es una marca de la sesión del panel; la API no tiene sesión y con solo usuario y clave se obtenía token. **V1: `403 two_factor_required`**, sin emular el flujo (decisión de MSOLIS, 28-sep-2026). Reabre solo por requisito de la app → OW-REQ → diseño de un flujo con desafío |
| Contenido inline en cuerpos (`cid:`, `data:`, `file.php?key=`) | el núcleo lo convierte en adjuntos sin los controles de `POST /files` (tipo, tamaño, número, presupuesto) y `cid:` adjunta por clave archivos ajenos. **V1: `422 validation_failed`** (`inline_cid_not_supported` / `inline_data_not_supported`), en `text` y en `html` y en todo campo de texto libre (decisión de MSOLIS, 28-sep-2026). Reabre solo por requisito de Tickets → OW-REQ → diseño de propiedad de adjuntos inline |
| Editar el perfil propio (`PATCH /me`) | la firma llega por correo a los clientes y `on_vacation` frena las asignaciones; ningún módulo lo necesita (retirada el 2026-09-28) |

## Endurecimiento y línea base (2026-09-28)
La superficie es de **102 rutas**, cada una clasificada (impacto, reversibilidad, necesidad, riesgo de abuso) en [`../security/Route-Surface.md`](../security/Route-Surface.md); la clasificación es la única fuente editable ([`route-classification.json`](../security/route-classification.json)) y `ci-check.sh` falla si una ruta queda sin clasificar. Sin borrados duros en ningún handler (guardia en CI). Controles añadidos en esta revisión, cada uno con prueba en `e2e.py`:

* **`fields` de `POST /tickets`** acepta solo campos personalizados del tema; `deptId`, `staffId`, `statusId`, `slaId`, `duedate`, `autorespond`… dan `422` (antes se pasaban al núcleo y saltaban las comprobaciones de departamento/asignación y el correo).
* **Directorio de contactos y organizaciones:** con `user.dir` se navega, se pagina y se sincroniza (`/sync/users`, `/sync/organizations`); sin él, la búsqueda es del tamaño de un autocompletado (10 resultados, sin páginas, `q` ≥ 3) y un contacto/organización solo se lee si está en un ticket visible o lo creó el agente.
* **Presupuestos por hora y agente** (`429 rate_limited`, `details.bucket`): correo a clientes (`limit_mail_per_hour`, 100), subidas (`limit_uploads_per_hour`, 200), PDF de ticket (`limit_pdf_per_hour`, 60), búsquedas de contactos sin directorio (`limit_lookups_per_hour`, 300). `0` desactiva. Un 429 no se reproduce con la misma `Idempotency-Key`.
* **Correo por defecto apagado:** crear ticket, notas, asignación, transferencia, referencias y tareas no envían correo salvo `notify`/`alert` explícitos (comprobado contra Mailpit, con control positivo).
* **Descargas:** `GET /files/{hash}` aplica el mismo control de acceso completo, con `Range`, con `inline` y con miniatura; un archivo subido y aún sin adjuntar solo lo lee quien lo subió.
* El catálogo de agentes ya no publica los nombres de usuario (login).
* **Tareas cerradas de otro departamento (requalification, 28-sep):** el núcleo solo aplica el departamento a una tarea *abierta*; una cerrada era legible (detalle, hilo, archivo) y admitía notas de cualquier agente. Ahora `/tasks/{id}*` y los adjuntos de tareas usan la misma visibilidad que el listado (departamento, asignación o equipo), abierta o cerrada: `403` fuera de ella. Comprobado con un agente limitado contra una tarea cerrada del otro departamento (detalle, hilo, archivo, `Range`, nota y estado) y con un control positivo del propio departamento.

* **Decisiones finales de la requalification (MSOLIS, 28-sep-2026):**
  * **2FA (A1, cierra en falso):** `POST /auth/login` responde `403 two_factor_required` si el agente tiene un segundo factor en osTicket; se comprueba antes de `process()` (sin token, sin sesión, sin código por correo) y otra vez tras la autenticación. Reproducido antes: el panel rechazaba al agente y la API le daba token.
  * **`dept_id` (D1):** `POST /tickets` solo crea en un departamento con acceso real (el conjunto de `GET /departments`); vale igual para el departamento del tema o el predeterminado. `403 forbidden`, `details.reason = department_not_accessible`.
  * **`actor` (D2):** `last_change.actor` es `{type, id, name}`; el login del agente ya no sale en ninguna respuesta (H-5).
  * **`cid:` (D3) y `data:` (D4):** rechazados con `422`. Reproducido antes: un agente pasó de `403` a `200` sobre un archivo privado ajeno con `cid:<clave>`; `data:` creaba un adjunto con el MIME que declara el cliente. El fallo estaba en 9 rutas (creación de ticket, notas, comentarios de reclamo/estado/traspaso/campo, descripción y notas y estado de tarea), en las tres formas (`cid:`, `data:` y URL `file.php?key=`, que el núcleo convierte en `cid:`) y también con `body_format=text`, porque el núcleo compara sobre el cuerpo ya saneado. Una guardia común (`Threading::assertNoInlineContent`, llamada desde el pipeline y desde `bodyFromRequest`) las cubre todas. `body_format=html` sigue existiendo para el marcado admitido.

**Riesgo residual documentado:** un identificador de contacto (`user_id`) puede usarse al crear un ticket, cambiar el dueño, añadir colaboradores o poner `cc` sin acceso al directorio (igual que el panel); cada uso deja rastro visible en el ticket y la respuesta puede mostrar el nombre y el correo de ese contacto.

### Decisiones de MSOLIS sobre la revisión (2026-09-28)
* **PC-S1:** `PUT /tickets/{id}/owner` se retira de la superficie pública (mueve la conversación y el acceso al portal a otro contacto; sin consumidor aprobado). Se reabre solo por requisito de UX → gap demostrado → OW-REQ → revisión.
* **PC-S2:** `disable` y `clear_overdue` del SLA solo los hace el gerente del departamento (o un administrador); dejan la nota interna «SLA» y los eventos. `restart`, `extend` y `enable` no cambian.
* **PC-S3:** el correo de un contacto no se cambia por la API (`PATCH /users/{id}` conserva nombre, teléfono y campos personalizados).
* **PC-S4:** `sharing` y las banderas de colaboradores/asignación de `PATCH /organizations/{id}/profile` no se cambian por la API; `manager`, `domain` y `primary_contacts` siguen.
* **PC-S5:** vida del token de dispositivo de **14 días** y presupuestos por hora (100/200/60/300) como **guardarraíles iniciales**, configurables (`0` desactiva); se recalibrarán con uso real.
