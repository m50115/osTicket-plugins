# Visión general

## Qué es
Una **capa controlada** entre un cliente y osTicket. No es una colección de rutas sueltas: autentica al agente, fija el contexto del agente en el núcleo, aplica los permisos del panel de agentes, deduplica las escrituras y traduce el núcleo a contratos estables.

## Principios (cerrados)
1. **Se usa el núcleo, no SQL.** Toda escritura pasa por los métodos de dominio de osTicket (`Ticket::create`, `postReply`, `setStatus`, `assign`, `transfer`, …). Solo hay SQL directo en lecturas de agregación/sincronización y en la comparación-y-cambio del campo `extra` de organizaciones.
2. **Los permisos son los de osTicket.** El plugin no crea permisos propios: replica, antes de cada handler, lo que comprueba el panel de agentes (el núcleo casi nunca lo hace por sí mismo).
3. **Ninguna tabla SQL propia** (campaña M16: se retiró la tabla de idempotencia/revocación/límites). Ninguna tabla de negocio: el almacén de mantenimientos programados (OW-REQ-70) vive en una **Custom List nativa dedicada** (ajuste `maintenance_list_id`; antes, la tabla `workflow_maintenance` de la excepción D-22) y el almacén de contratos OW-REQ-71 fue retirado (los contratos viven como Tickets). El almacén de mantenimientos es durable: sin TTL ni purga, nada se borra, y entra en el respaldo normal de la base de osTicket.
4. **Reintentos por conciliación**: el plugin no guarda registro de repetición (`Idempotency-Key` se acepta pero no se exige ni se guarda); la cola M16 de la app concilia contra el estado oficial (práctica, no garantía absoluta de exactamente-una-vez). Toda actualización lleva el valor base que el cliente vio.
5. **Un fallo propio no tumba osTicket**: el archivo principal es mínimo, los handlers se cargan de forma diferida y todo error termina en JSON tipado.
6. **JSON siempre**, con un catálogo cerrado de errores. Fechas en UTC ISO-8601.

## Qué hace
Sesión con token revocable; perfil y permisos del agente (y edición del propio perfil, con disponibilidad «de vacaciones»); colas guardadas de osTicket; catálogos y formularios; tickets (listas con cursor, detalle, búsqueda, alta, estado, asignación, reclamo, transferencia, referencias, campos, dueño, colaboradores); hilo (actividad unificada, respuestas, notas y edición de notas internas con versiones, ver [Respuestas-y-Notas.md](Respuestas-y-Notas.md)) y archivos (subida, descarga con control de acceso y con rangos, miniaturas), documentos con identidad y versiones, y exportación del ticket a PDF; contactos y organizaciones con reconciliación; tareas; sincronización por deltas; informe de soporte.

## Qué no hace
Ver la lista en [API-Reference.md](API-Reference.md#lo-que-la-api-no-expone) y el motivo en [Seguridad.md](Seguridad.md).

## Relación con el plugin `mobile`
Comparten la mecánica probada de conexión (autenticación con los mismos backends del panel, señal `api` de osTicket, descarga con Bearer), pero `ost-workflow` es independiente: otro `id` (`ost:workflow`), otro prefijo de clases (`OstWorkflow\`) y otra ruta (`/workflow/v1`). Pueden estar activos a la vez (validado).
