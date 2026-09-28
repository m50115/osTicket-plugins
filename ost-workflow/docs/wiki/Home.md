# ost-workflow: wiki

`ost-workflow` es un plugin de **osTicket 1.17.2** que expone las capacidades del núcleo de osTicket como una API JSON tipada y versionada (`/workflow/v1`). Es el componente de servidor de la aplicación móvil BestCare Workflow, pero no depende de ella: cualquier cliente puede usarlo.

> Documentación base en español.

## Estado

| Aspecto | Estado |
|---|---|
| Versión | 0.1 (API `v1`) |
| Cobertura | 107 rutas: sesión, catálogos, tickets, hilo y archivos, contactos y organizaciones, tareas, reconciliación, sincronización e informes |
| Probado en | osTicket 1.17.2 con **PHP 8.0.30 + opcache + nginx** (reproduce la configuración de producción —PHP 8.0, opcache, nginx real— salvo las diferencias listadas en [Pruebas-y-Sandbox.md](Pruebas-y-Sandbox.md)) y con PHP 8.2 |
| No probado todavía | despliegue en contenedores/ECS reales, almacenamiento S3, correo y cron de producción, clientes móviles |
| Licencia | MIT (objetivo del proyecto) |

Cada página indica qué está validado y qué no.

## Índice

- [Vision-General.md](Vision-General.md): qué es, qué hace y qué no hace.
- [Instalacion-y-Actualizacion.md](Instalacion-y-Actualizacion.md): construir, instalar, actualizar y volver atrás (procedimiento validado en sandbox).
- [Requisitos-de-Despliegue.md](Requisitos-de-Despliegue.md): nginx, PHP, proxies y límites.
- [Convenciones.md](Convenciones.md): sobre de respuesta, autenticación, idempotencia, valores base, cursores, fechas y errores.
- [API-Reference.md](API-Reference.md): las 107 rutas con su permiso.
- [Sincronizacion-y-Trabajo-Offline.md](Sincronizacion-y-Trabajo-Offline.md): cómo un cliente offline debe usar la API.
- [Respuestas-y-Notas.md](Respuestas-y-Notas.md): respuesta pública frente a nota interna y edición de notas internas (versiones, errores, evidencia).
- [Seguridad.md](Seguridad.md): autenticación, permisos, límites y lo que no se expone.
- [Pruebas-y-Sandbox.md](Pruebas-y-Sandbox.md): cómo probar el plugin en un entorno parecido a producción.
- [Solucion-de-Problemas.md](Solucion-de-Problemas.md): síntomas comprobados y su causa.
- [README.md](README.md): cómo se mantiene esta wiki.

Referencia detallada por área (rutas, cuerpos, errores, funciones del núcleo con archivo y línea): carpeta [`../endpoints/`](../endpoints/).
