# Plugins de osTicket en BestCare: ciclo de vida, instancias y despliegue

Guía operativa para quien escriba o despliegue un plugin en este repositorio (`mobile-api`, `ost-workflow` y los siguientes). Es el resumen de la experiencia real de producción; la versión canónica con el contexto completo vive en el vault de Obsidian (`02-Knowledge-Engineering/Projects/BestCare/osTicket API/Reference/osTicket Plugin Lifecycle and Operations Guide.md`). Si ambas difieren, manda el vault.

Última revisión: 2026-10-30 (tras el despliegue de `ost-API` `d573350` y la activación de Workflow API en producción).

## 1. Cómo carga osTicket un plugin (osTicket 1.17, `PluginManager::bootstrap`)
En cada petición, `include/class.plugin.php` recorre todas las filas de plugin y hace:
1. `init()` de **todos**, incluso los deshabilitados.
2. Solo si la fila del plugin está **Active**: `bootstrap()` de **cada instancia Enabled**.

Y `PluginInstance::bootstrap()` solo llama a `Plugin::bootstrap()` si `$plugin->getConfig($instance)` devuelve algo, es decir, **si el plugin declara `config_class`**. Consecuencias:

| | Plugin **sin** `config_class` (Mobile API) | Plugin **con** `config_class` (Workflow API) |
|---|---|---|
| Dónde registrar la ruta (`Signal::connect('api', …)`) | **`init()`** (el `bootstrap()` de la instancia nunca corre) | **`bootstrap()`** (con `init()` vacío) |
| ¿Se puede apagar la API desde el panel? | No: responde aunque el plugin esté Disabled | **Sí**: plugin Disabled o instancia Disabled → la ruta no existe |
| Pantalla «Add/Update Instance» | **En blanco** (no hay formulario que pintar; la instancia es decorativa) | Pestañas Instance y Config con los campos del plugin |
| Qué hay que hacer para que responda | Instalar el plugin | Plugin **Active** + **una instancia Enabled** con su configuración |

**Regla de diseño para un plugin nuevo:** si necesita configuración por instancia (secretos, límites) o un interruptor real, declarar `config_class`, registrar en `bootstrap()` y dejar `init()` vacío (Workflow). Si es un puente trivial sin configuración, registrar en `init()` (Mobile). **Nunca** registrar en `bootstrap()` un plugin sin `config_class`: la ruta no existirá jamás (fue el fallo de la v0.1 de mobile).

## 2. Cómo se ve y se comporta el panel (Admin → Manage → Plugins)
- **Installed Plugins** muestra el **nombre** del plugin (no el archivo `.phar`) y entre paréntesis el número de **instancias**. `(1)` **no** significa activo.
- **`(defunct — missing)`:** el archivo de `install_path` no existe en esa imagen (solo se muestra; no cambia la base de datos). Filas antiguas que apuntan a archivos que ya no están son inofensivas.
- **Add New Plugin** lista únicamente los PHAR **no instalados**; un plugin ya instalado no aparece ahí. No verlo ahí es lo normal.
- **Add New Instance:** el selector **Status viene en Disabled por defecto**. Una instancia creada sin tocarlo queda con `flags=0`: la lista dice `(1)` y la API responde como si no hubiera nada. Hay que ponerlo en **Enabled** al crearla (o editarla después).
- Los campos de configuración llevan nombres con hash en el HTML; un guardado con error (secreto de menos de 32 caracteres, campo no numérico…) **no cambia el estado** y muestra el error en rojo: comprobar que el Status siga Enabled al reabrir la instancia.
- Campos como «Token signing secret» son obligatorios en la práctica aunque el formulario deje guardar sin ellos: sin secreto el login responde `503 not_configured`.

## 3. Cómo llega la petición al plugin en producción
- BestCare **no enruta por nginx**: usa un PHP delgado por plugin, el patrón de `api/mobile.php`. `api/workflow.php?route=/ping` fija `PATH_INFO='/workflow/v1'.$route`, `SCRIPT_NAME='/api/http.php'`, quita `route` de `$_GET` y hace `require http.php`. Los clientes de Suite y de Staff lo usan cuando la URL base termina en `.php` (`?route=`).
- El `default.conf` de nginx va **horneado en la imagen base** (`dicomserver-php:debian-latest`, reconstruida el día 1 de cada mes a las 00:00 UTC o a mano), **no** en el despliegue de la aplicación. En producción solo enruta `tickets|tasks` por la regla específica; el resto de `api/*.php` lo sirve php-fpm por la regla genérica de `.php`. **Un cambio en `docker/default.conf` del repositorio no llega a producción con un despliegue.**
- **Riesgo conocido (R-C37):** con una regla `^/api/(…|mobile|workflow).*` sin delimitar y `try_files $uri`, nginx serviría `api/*.php` como texto (código fuente a la vista). La regla publicada en `ost-API` es así; no está activa en producción, pero no debe llegar a una imagen base.
- El despliegue de la aplicación copia el árbol del repositorio al artefacto (`manage.php deploy`); el archivo nuevo debe estar versionado en la rama que se despliega.

## 4. Respuestas HTTP y qué significan (sondas de solo lectura)
| Respuesta de `api/<plugin>.php?route=/ping` | Capa | Significado |
|---|---|---|
| **404 `text/html` «File not found.»** (igual que un `.php` inexistente) | php-fpm | el script no está en la imagen activa |
| **400 `text/html` «URL not supported»** | núcleo de osTicket | el script corre, pero el despachador no tiene la ruta: plugin **Disabled**, **sin instancia** o instancia **Disabled** (plugins con `config_class`) |
| **200 `application/json`** y `/config` sin token **401** `unauthorized` | plugin | operativo |
| **503 `text/html` de `awselb`** (en toda la raíz) | balanceador/ECS | el servicio no tiene destinos sanos: no es del plugin |
Un plugin habilitado no es una API funcional: la prueba es la respuesta HTTP. Una sonda de solo lectura de cuatro GET está en `bestcare_workflow/tool/prod_probe_workflow.sh`; el arnés por alcances (A público, B autenticado, C mutaciones con aprobación escrita) en `tool/prod_e2e.py`.

## 5. Alta de un plugin nuevo — receta con sandbox primero
1. **Construir** con PHP 8.0 (`php -dphar.readonly=0 make.php build <plugin>`; para Workflow, `prod-sandbox/build-artifact.sh`, que estampa el commit y corre `ci-check.sh`). **Un solo manifiesto por `id`** y ninguna otra copia de la misma clase en `include/plugins/`.
2. **Reproducir el estado de producción en el sandbox por el panel real**, no por la línea de comandos: `prod-sandbox/ui-plugin-flow.py` maneja los mismos formularios (`list`, `plugin-status`, `add-instance`, `set-instance-status`). Verificar: plugin Disabled y sin instancia → 400; plugin Active + instancia Enabled con su configuración → 200 y `/config` sin token 401. Crear la instancia **sin** cambiar el Status (Disabled) debe seguir dando 400: es la trampa que se quiere ver antes.
3. **Pruebas funcionales en el sandbox** con el arnés (A, B, C) y los simuladores en solo lectura (`prod_readonly_acceptance_test.dart`, con `BCW_PROFILE_URL`).
4. **Entrada HTTP:** añadir `api/<nombre>.php` (copia del patrón de `mobile.php`/`workflow.php`) y, para desarrollo y staging, su caso en `router.php`.
5. **Despliegue** solo con la lista la lista «Plugin Deploy Checklist» del vault y orden escrita que nombre el SHA: un único `workflow_dispatch`, margen de 10–15 minutos sin intervenir, humo con `curl` antes y después.
6. **En el panel de producción:** (a) si el plugin es nuevo, Add New Plugin → Install; (b) **plugin → Status Active → Save**; (c) **Add New Instance:** nombre, **Status Enabled**, pestaña Config con el secreto (≥ 32 caracteres, generado y guardado por el operador, nunca en notas ni en el repositorio) y el CIDR del balanceador en proxies de confianza; (d) comprobar `Active`, `Instances (1)`, `Enabled`; (e) sondas A.
7. Si el plugin ya está instalado y **no cambia de bytes**, no se vuelve a desplegar el PHAR: solo lo que haga falta (por ejemplo, el `api/<nombre>.php`).

## 6. Lo que NO está demostrado (no tomarlo como regla)
- **Nombre del PHAR:** ni «mismo nombre» ni «nombre y versión nuevos» están validados como práctica segura. En marzo de 2026 `mobile-api.phar` se sobrescribió con el mismo nombre al menos seis veces sin caída; el 17-abr fallaron dos variantes de v0.3 (mismo nombre con bytes nuevos, y nombre nuevo junto al viejo) y la causa de la primera nunca se confirmó. Lo que sí tumba el sitio es **tener dos archivos con el mismo `id` y la misma clase instalados a la vez** (PP-01).
- **Que el sandbox reproduzca ECS:** no lo hace (ni el balanceador, ni la imagen base, ni el reemplazo de tareas). Sirve para el ciclo de vida del plugin y las respuestas HTTP, no para la salud del servicio.

## 7. Incidentes y lecciones
- **17-abr-2026 (mobile-api v0.3):** dos intentos de subir v0.3 tumbaron la página; se revirtió; v0.3 nunca se desplegó.
- **27-oct-2026:** despliegues de `ost-workflow` y de `main` agotaron la estabilización de ECS; 503.
- **30-oct-2026:** despliegue de `ost-API` `4e7093c` y de `main`; 503 durante cinco reemplazos de tarea apilados en una hora; el «missing» que se vio entonces era la imagen de `main`, que no trae los PHAR. Se volvió a la normalidad con un despliegue único de `main` y, después, `ost-API` `d573350` (sin tocar los PHAR) con el plugin Active y una instancia Enabled. Causa del 503: no confirmada (hipótesis: reemplazos apilados); `deploy.yml` usa `timeout: -1`.
- **Lecciones:** (1) leer primero las notas canónicas del plugin y sus incidentes; (2) correlacionar horas de runs, revisiones de ECS y capturas antes de afirmar una causa; (3) **un solo despliegue** y esperar; (4) `main` es el upstream de seguridad de Douglas Yau: nunca se toca ni se despliega desde BestCare; el repositorio `coradoya/osTickets` es ajeno, y cada acción en él se enumera antes y después; (5) un 400 «URL not supported» no es un fallo del archivo: mirar plugin e instancia; (6) «Enabled» y `(1)` no prueban nada: prueba la respuesta HTTP.
