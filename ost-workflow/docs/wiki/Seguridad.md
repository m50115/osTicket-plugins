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
| Editar respuestas públicas y mensajes de clientes | la respuesta ya se envió por correo; se corrige con una respuesta nueva. **Sí** se pueden editar las **notas internas** (`PATCH …/notes/{entry}`, con los permisos del panel) |
| Adquirir el bloqueo del ticket | el lock de escritorio solo se lee |
| URL de descarga del núcleo | exige sesión del panel |
