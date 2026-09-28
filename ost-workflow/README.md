# ost-workflow

Plugin de osTicket 1.17.2 (`ost:workflow`) que expone las capacidades del núcleo como una API JSON tipada y versionada bajo **`/workflow/v1`**: sesión con token revocable, catálogos, tickets, hilo y archivos, contactos y organizaciones, tareas, reconciliación, sincronización por deltas e informes. Es el componente de servidor de BestCare Workflow.

- **Wiki (estable):** [docs/wiki/Home.md](docs/wiki/Home.md) — instalación y actualización validadas en sandbox tipo producción, requisitos de despliegue (nginx), convenciones, referencia de las 101 rutas, guía offline, seguridad y solución de problemas.
- **OpenAPI:** [docs/openapi.json](docs/openapi.json) (generado de la tabla real de rutas).
- **Detalle por área** (cuerpos, errores, funciones del núcleo con archivo y línea): [docs/endpoints/](docs/endpoints/).
- **Sandbox tipo producción:** [`../prod-sandbox/`](../prod-sandbox/) (PHP 8.0.30 + opcache + nginx real + proxy tipo balanceador, sin root).

## Estructura
```
plugin.php          manifiesto (id ost:workflow)
workflow.php        archivo principal MÍNIMO: clase del plugin y ruta única /workflow/v1
config.php          configuración de la instancia (secreto de firma cifrado, límites, valores por defecto)
lib/OstWorkflow/    código (PSR-0, carga diferida): Pipeline, Router, Auth, Token, Idempotency, Policy, Emitter…
lib/OstWorkflow/Handlers/   un handler por área (Tickets, Threads, Files, Users, Orgs, Tasks, Sync…)
docs/               wiki y referencia por área
```

## Reglas que no se rompen
Prefijo `OstWorkflow\` y ninguna función global; sintaxis compatible con PHP 8.0; el archivo principal no tiene efectos; handlers que devuelven `[código, cuerpo]` (nunca `echo`/`exit`); un solo emisor de respuestas; el `$thisstaff` de osTicket se fija antes de llamar al núcleo; ningún estado en archivos; un solo phar (`ost-workflow.phar`) y una sola fila de plugin.

## Construir
```
php -dphar.readonly=0 make.php build ost-workflow      # con PHP 8.0
```
Antes de un artefacto de despliegue, estampe el commit en `lib/OstWorkflow/Build.php` (`const SHA`).
