# Solución de problemas

Síntomas y su causa. Todos se reprodujeron en el sandbox, salvo el último, que proviene del historial de incidentes del plugin `mobile`.

| Síntoma | Causa | Solución |
|---|---|---|
| `/api/workflow/v1/...` responde **404 con HTML** del portal | nginx no envía `workflow` a `api/http.php` | añada `workflow` a la alternación ([Requisitos-de-Despliegue.md](Requisitos-de-Despliegue.md)) |
| `400` con HTML "URL not supported" | osTicket recibió la ruta pero el plugin no registró la suya: plugin o instancia **desactivados**, o el archivo del plugin no cargó | active el plugin y la instancia; revise el registro de errores de PHP |
| **413 con HTML** de nginx al subir un archivo | `client_max_body_size` (1 MB por defecto) | defina un límite mayor en nginx |
| `503 not_configured` en el login | la instancia no tiene el secreto de firma (≥ 32 caracteres) | configúrelo en la instancia |
| `401 unauthorized` con un token que antes servía | token revocado (`logout`), secreto rotado, agente desactivado o token caducado | iniciar sesión de nuevo |
| `429 rate_limited` | 5 intentos fallidos de ese usuario desde esa IP | esperar `Retry-After`; configure los proxies de confianza si todas las peticiones parecen venir de la misma IP |
| `400 idempotency_key_required` | escritura sin cabecera `Idempotency-Key` | envíe un UUID por operación |
| `410 file_expired` al publicar una nota con archivos | el archivo se subió hace más de un día sin adjuntarse y el núcleo lo limpió | sube el archivo otra vez |
| `409 attachment_missing` | la entrada se creó pero un adjunto no se pudo vincular | `POST /tickets/{id}/notes/{entry}/files` con los `missing_file_ids` |
| Un emoji desaparece del mensaje | la base de datos es `utf8mb3` (osTicket lo descarta) | revisa `effects.sanitized` y `/config` → `text`; usa `unsupported_chars:"reject"` si no quieres perderlo en silencio |
| `409 needs_review` | un intento anterior murió y no se puede probar lo que creó | verifique en el servidor (p. ej. `GET /match/ticket?marker=wf:<clave>`) |
| `409 conflict` al actualizar | el valor cambió desde que el cliente lo leyó | mostrar `details.current` y `details.last_change` |
| `409 conflict` con `details.current_entry_id` al editar una nota | la nota ya tiene una versión más nueva (otra edición) | releer la nota, mostrar la versión vigente y reaplicar sobre `current_entry_id` ([Respuestas-y-Notas.md](Respuestas-y-Notas.md)) |
| `422 not_editable_type` al editar | la entrada es una respuesta pública o un mensaje del cliente | solo las notas internas se editan; publique una respuesta nueva |
| `422` "'base' is required" | actualización sin valor base | envíe `base` (o `null` si estaba vacío) |
| Un ticket aparece dos veces en una lista | (corregido) el filtro de visibilidad duplicaba tickets con referencias | actualice al plugin actual |
| Horas desfasadas una hora | (corregido) la zona horaria de MySQL era ambigua (p. ej. `CST`) | actualice al plugin actual: comprueba la zona contra el reloj de MySQL; desde el 28-sep también compensa la escritura de `due_at` de tareas (alta y `PUT`), donde el núcleo aplicaba su zona supuesta (`America/Chicago`) y desfasaba una hora en meses de horario de verano |
| Crear una tarea devuelve `500` | (corregido) un formulario de tarea inválido (p. ej. sin `description`) lanzaba `TypeError` en `Ticketing::formErrors` | actualice al plugin actual: responde `422 validation_failed` con `field: description`; la descripción es obligatoria |
| Error fatal "Cannot declare class" en todo el sitio | dos phars o dos filas con las mismas clases | deje un solo phar `ost-workflow.phar` y una sola fila |

Para un `500`, use el `request_id` de la respuesta para localizar la línea en el registro de errores de PHP (`[ost-workflow] … request_id=…`).
