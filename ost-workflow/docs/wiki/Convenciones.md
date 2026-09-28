# Convenciones de la API

Válidas para todas las rutas de `/workflow/v1`. Dentro de `v1` solo hay cambios aditivos (campos opcionales, rutas nuevas): el cliente **debe ignorar campos desconocidos**. Un cambio incompatible abriría `/workflow/v2` junto a `v1`.

## Sobre de respuesta
Éxito: `{"data": …, "meta": {…}}` (`meta` es opcional). Error: `{"error": {"code", "message", "field"?, "details"?}}`. Toda ruta, incluidas las desconocidas, responde JSON (`404`/`405` tipados); un fallo interno es `500 internal_error` con un `request_id` (sin detalles internos).

## Autenticación
`POST /auth/login {username, password}` usa los mismos backends que el panel de agentes (nativo, LDAP, OAuth2…) y devuelve un token firmado (`Authorization: Bearer <token>`). Cada petición vuelve a leer al agente: si se desactiva, pierde el acceso de inmediato. `POST /auth/logout` revoca el token actual; `{"all": true}` revoca todos los del agente. **Una cuenta con segundo factor (2FA) habilitado en osTicket no puede iniciar sesión por esta API** (V1, decisión de MSOLIS del 28-sep-2026): `403 two_factor_required`, sin token y antes de tocar los backends (tampoco se envía el código por correo). El 2FA de osTicket es una marca de la sesión del panel que la API no tiene; emularlo queda fuera de V1. Las cuentas sin 2FA inician sesión con normalidad; la API V1 no implementa segundo factor interactivo (ni desafío ni endpoint de código). Si la app necesita agentes con 2FA: requisito → OW-REQ → diseño explícito de un flujo con desafío. Habilitar y validar el 2FA en producción es un frente de *production-readiness* aparte, no un defecto del plugin.

## Idempotencia (escrituras)
Toda escritura (`POST`, `PUT`, `PATCH`, `DELETE`, salvo login/logout) exige `Idempotency-Key` (8–64 caracteres: letras, números y guion; un UUID sirve). Reintentar con la misma clave devuelve la respuesta original con la cabecera `Idempotent-Replayed: true` y **no repite el efecto**.

| Situación | Respuesta |
|---|---|
| Sin clave | `400 idempotency_key_required` |
| Misma clave, otro cuerpo o ruta | `422 idempotency_key_reused` |
| La misma operación sigue en curso | `409 in_progress` + `Retry-After` |
| Un intento anterior murió y no se puede probar qué creó | `409 needs_review` (un humano verifica en el servidor) |
| Error 5xx sin recurso creado | la clave se olvida (se puede reintentar) |
| Error 4xx determinista | se guarda y se repite |

Los tickets creados llevan el marcador `source_extra = wf:<clave>`: si el cliente no recibió la respuesta, el reintento adopta el ticket ya creado en lugar de duplicarlo. El registro se conserva 30 días.

## Valores base (actualizaciones)
Las actualizaciones de campos que pueden cambiar en paralelo (estado, asignación, transferencia, prioridad, tema, SLA, vencimiento, dueño, organización de un contacto, datos de contacto, perfil y `extra` de organizaciones) llevan **`base`**: el valor que el cliente vio (`null` = estaba vacío).

| Servidor | Resultado |
|---|---|
| ya es el valor deseado | `200` con `applied:false` (éxito idempotente) |
| igual a `base` | se aplica (`applied:true`) |
| distinto | `409 conflict` con `details.current`, `details.base` y `details.last_change` (`event`, `at`, `actor{type,id,name}` normalizado —nunca el login—, `staff_id`) |

No existe "la última escritura gana". Falta `base` → `422` (campo `base`).

## Paginación
Cursores opacos sobre `(orden, id)`; no hay `offset`. `meta.next_cursor` y `meta.has_more`. No se promete un total. Las listas exigen filtros explícitos: p. ej. `GET /tickets` requiere `state=open|closed|all` (un filtro ausente nunca se sustituye por un valor por defecto).

## Fechas
ISO-8601 en **UTC** (`2026-09-27T23:59:57Z`). El plugin normaliza las fechas del núcleo, que se guardan en la zona horaria de la base de datos, y comprueba esa zona contra el reloj de MySQL.

## Un PDF siempre lleva texto
Una nota o respuesta con un PDF debe explicarlo (qué es el anexo y qué esperar de él): el texto debe tener al menos `min_text_with_pdf` caracteres (opción de la instancia, 15 por defecto; `0` la desactiva; se publica en `GET /config`). Si no, `422` con `details.reason = "attachment_needs_text"`. Vale para notas y respuestas de tickets y tareas, para adjuntar a una nota existente y para editar una nota que ya lleva un PDF.

## SLA, vencimientos y overdue
Overdue es una **bandera** que activa el cron del núcleo en tickets abiertos vencidos; no es un estado y cambiar el estado no la quita. `GET /tickets/{id}/sla` muestra plan, vencimientos y acciones posibles; `POST /tickets/{id}/sla` permite `restart` (contar desde ahora), `extend`, `disable`, `enable` y `clear_overdue`, con `base` y una nota interna que deja rastro. `disable` y `clear_overdue` pueden ocultar un incumplimiento: solo los hace el **gerente del departamento** (o un administrador), además de `ticket.edit` (`403 department_manager_required`; `GET …/sla` no los ofrece a los demás). Un vencimiento manual (`duedate`) prevalece sobre el del SLA.

## Caché de catálogos
Las lecturas de catálogos y definiciones de formularios devuelven `ETag` y `Cache-Control: private, max-age=0, must-revalidate`; con `If-None-Match` igual responden `304` sin cuerpo (caché offline barata). El resto de rutas responde `no-store`.

## Texto y caracteres
Si la base de datos es `utf8mb3`, osTicket **descarta en silencio** los emoji y caracteres por encima de U+FFFF. `GET /config` indica `text.supplementary_characters_supported`; las escrituras de texto devuelven `effects.sanitized.removed_chars` y aceptan `unsupported_chars: "reject"` para rechazarlos con 422.

## Efectos secundarios explícitos
Las operaciones con efectos en el núcleo los piden explícitos: `notify` (`all`|`user`|`none`) en respuestas, `claim` (booleano), `alert` (booleano, por defecto `false`: asignaciones, transferencias y notas), `reopen` para asignar un ticket cerrado. La respuesta informa los efectos (`effects`: cambio de estado y de asignado).

## Catálogo de errores (cerrado)
| Código | HTTP | Cuándo |
|---|---|---|
| `unauthorized` | 401 | token ausente, inválido, revocado o cuenta inactiva |
| `forbidden` | 403 | falta un permiso (el mensaje lo nombra) o el departamento no es accesible (`details.reason = department_not_accessible`) |
| `two_factor_required` | 403 | la cuenta tiene segundo factor en osTicket: no puede iniciar sesión por la API |
| `not_found` | 404 | recurso o ruta inexistente |
| `method_not_allowed` | 405 | método incorrecto (`Allow`) |
| `validation_failed` | 422 | dato inválido (`field`) |
| `idempotency_key_required` | 400 | escritura sin clave |
| `idempotency_key_reused` | 422 | clave repetida con otra petición |
| `in_progress` / `needs_review` / `conflict` / `candidates` / `locked` / `not_closeable` | 409 | ver secciones anteriores; `candidates` incluye `details.classification` y `matches[]` |
| `unsupported_type` | 415 | tipo de archivo no permitido |
| `file_expired` | 410 | el archivo se subió pero el núcleo lo borró antes de adjuntarlo (vuelve a subirlo) |
| `attachment_missing` | 409 | la entrada se creó pero faltan adjuntos (`details.entry_id`, `missing_file_ids`) |
| `too_large` / `payload_too_large` | 413 | archivo o cuerpo demasiado grande |
| `rate_limited` | 429 | demasiados intentos de login, o presupuesto por hora agotado (`details.bucket`: `mail`, `upload`, `pdf`, `lookup`); `Retry-After`. Un 429 nunca se reproduce con la misma `Idempotency-Key` |
| `not_configured` | 503 | falta el secreto de firma en la instancia |
| `internal_error` | 500 | fallo inesperado (`details.request_id`) |
