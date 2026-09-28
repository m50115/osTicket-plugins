# Sincronización y trabajo offline

Guía para el cliente. El detalle de rutas está en [sync-reports](../endpoints/sync-reports.md).

## Escribir sin conexión: una operación = una petición
Cada operación de la cola del cliente es **una petición HTTP con su `Idempotency-Key`** (el `op_id`). No hay lotes ni endpoint transaccional: el núcleo de osTicket confirma cambios a mitad de una creación y envía correo de forma síncrona, así que no existe atomicidad de varios pasos. Las operaciones compuestas se modelan con dependencias en el cliente:

```
buscar/crear cliente → buscar/crear ticket → subir archivos ×N → nota (file_ids) → cambiar estado
```

Archivos primero y nota después: `POST /files` sube **un archivo por petición** (multipart, campo `file`) y devuelve `file_id`; la nota o respuesta los referencia con `file_ids` y el servidor comprueba que todos quedaron adjuntos antes de responder éxito.

## Qué hacer con cada respuesta
| Respuesta | Acción del cliente |
|---|---|
| 2xx, o `Idempotent-Replayed: true` | hecho; registrar el id del servidor |
| Timeout o corte tras enviar | reintentar **con la misma clave** |
| `409 in_progress` | reintentar tras `Retry-After` |
| `409 conflict`, `candidates`, `needs_review` | requiere decisión de una persona |
| `401` | pausar la cola hasta un nuevo login |
| `422 idempotency_key_reused` y otros 4xx deterministas | fallo definitivo; mostrar el error |
| 5xx, red | reintento con espera creciente |

## Reconciliación (evitar duplicados de clientes y tickets)
Antes de crear un contacto, organización o ticket cuyo alta se dudó (por ejemplo tras un corte), use `GET /match/contact`, `/match/organization` y `/match/ticket`. Clasificación: **safe** (el servidor lo garantiza: mismo correo, id del servidor, marcador `wf:<clave>`), **candidate** (una coincidencia plausible que confirma una persona), **ambiguous** (varias: decide una persona). No hay coincidencia difusa automática. Las altas de contactos y organizaciones responden `409 candidates` en vez de "buscar o crear" a ciegas.

## Lectura incremental
`ticket.updated` **no** es un cursor fiable: no lo mueven las notas internas ni las respuestas sucesivas (comprobado). Use `GET /sync/tickets`: un cursor compuesto que devuelve un ticket cuando cambia cualquiera de `updated`, la última entrada del hilo, el último evento o los formularios. Recorra todas las páginas de una pasada (`meta.cursor`) y guarde el `meta.sync_state` de la última página para la siguiente. Luego lea las entradas y los eventos **por id** con `GET /tickets/{id}/activity`. El delta nunca informa de tickets que dejaron de ser visibles: compare periódicamente con `GET /sync/visible-ticket-ids`.

Usuarios y organizaciones tienen ventanas de fechas (`/sync/users`, `/sync/organizations`) con solapamiento de 5 s; deduplique por `(id, updated)`.

## Conflictos
Lleve el valor base en cada actualización. Ante `409 conflict` el cliente muestra el valor actual, el autor y la hora del último cambio (`details.last_change`) y deja decidir a la persona.
