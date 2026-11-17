# Instalación y actualización

> **Alcance de la validación.** Los pasos de esta página se ejecutaron y comprobaron en un entorno que reproduce la producción de osTicket: PHP 8.0.30 con opcache, nginx con la configuración real de `docker/default.conf`, osTicket 1.17.2 y MySQL 8. **No** se han ejecutado todavía en contenedores ECS reales, ni pulsando los botones de la interfaz de administración (se usó el mismo `PluginManager` de osTicket por línea de comandos). Antes de producción, repetir en un staging equivalente.

## 1. Construir el phar
Con PHP 8.0 (la versión de producción):

```
php -dphar.readonly=0 make.php build ost-workflow
```

Genera `ost-workflow.phar` en el directorio actual. `make.php` puede imprimir avisos de composer sobre dependencias de otros plugins (requieren PHP ≥ 8.1); no afectan a este phar. El nombre **debe ser fijo**: `ost-workflow.phar`. El phar no se versiona en el repositorio de plugins (`*.phar` está ignorado).

Para un artefacto de despliegue use `prod-sandbox/build-artifact.sh`: estampa el commit en `build_sha` (comprobado: `GET /config` lo devuelve), ejecuta las guardias `ci-check.sh` y escribe `dist/ost-workflow.phar` con su `.sha256`. La identidad del artefacto es `build_sha` (y el sha256), no el número del manifiesto, que osTicket reescribe en cada carga.

## 2. Instalar
1. Copie el phar a `<osticket>/include/plugins/ost-workflow.phar`.
2. Instale el plugin (`install_path` = `plugins/ost-workflow.phar`). Se crea **una sola fila** en la tabla de plugins. **El plugin no crea ninguna tabla SQL propia** (ni al instalar ni al activar).
3. Active el plugin y **cree y active una instancia** (el plugin es de instancia única). Configure en la instancia:

| Opción | Descripción |
|---|---|
| Token signing secret | Mínimo 32 caracteres aleatorios. **Obligatorio**: sin él, el login responde `503 not_configured`. Independiente de `SECRET_SALT`; rotarlo invalida todos los tokens. |
| Token lifetime (days) | Vida del token: **14 por defecto** (decisión de MSOLIS, 2026-09-28; línea base de guardarraíles). Una instancia que ya guardó otro valor (p. ej. 30) lo conserva hasta que se edite y guarde la configuración. |
| Default help topic / department | Valores por defecto para tickets nuevos. Vacío = el cliente debe enviarlos (422 si no). |
| Max attachments / max file bytes | Límites del plugin; el efectivo es el más estricto entre estos y los de osTicket. |
| Trusted proxy IPs / CIDRs | Proxies de confianza para leer `X-Forwarded-For` (IP del cliente registrada en las entradas). Ponga las direcciones de su balanceador. |
| Enabled app modules, brand name/color | Los publica `GET /config`. |

4. Compruebe: `GET /workflow/v1/ping` → `200 {"data":{"status":"ok"}}` y, con un token, `GET /workflow/v1/config` (revise `plugin_version` y `build_sha`).

## 3. Activar y desactivar
Rutas solo existen con el plugin **activo y con una instancia activa** (validado): con la instancia desactivada, o el plugin desactivado, `/workflow/v1/*` deja de responder y el panel de agentes y el portal siguen funcionando.
Desactivar **no descarga** el archivo: osTicket incluye el archivo principal de cada plugin instalado en cada petición. Por eso **nunca** debe haber dos phars con la misma clase ni una segunda fila del mismo plugin.

## 4. Actualizar
Reemplace el phar **en el mismo `install_path`** (mismo nombre de archivo). Validado con el plugin activo y opcache caliente: el panel y el portal siguieron respondiendo, y tras la revalidación de opcache (unos 2 s con la configuración por defecto) se sirvió la versión nueva. En un despliegue por imagen, publique una imagen nueva con el mismo `install_path` y reinicie los contenedores.

**Mantenimientos (OW-REQ-70) sin tabla.** `enable()` ya no crea `workflow_maintenance`: el almacén es una Custom List nativa. Preparación (una vez, en el SCP): *Admin › Manage › Lists › Add New List* con un nombre como «BCW Scheduled Maintenance (ost-workflow)», sin propiedades ni ítems; anote su id y póngalo en el ajuste del plugin `maintenance_list_id`. `/maintenances` exige además `installed_equipment_list_id` (lista «Installed Equipment»); sin cualquiera de los dos responde `503 not_configured`. **Nunca** edite (botón *Save*), borre ni importe ítems de esa lista a mano: el plugin guarda ahí sus registros como ítems internos. Desde un phar con tabla, migre con `prod-sandbox/m8-migrate.py` (export con el phar viejo → import/verify con el nuevo; ver [maintenance.md](../endpoints/maintenance.md)). Tras reemplazar el phar, desactivar y activar el plugin una vez sigue siendo inocuo.

No use la receta antigua "phar versionado junto al viejo + desactivar el viejo": causa un error fatal global (dos copias de la misma clase).

## 5. Volver atrás
Restaure el phar anterior en el mismo `install_path` (o la etiqueta de imagen anterior). Antes de cualquier cambio, respalde la tabla de plugins de osTicket.

## 6. Desinstalar
Desinstalar el plugin elimina su fila y su configuración; **conserva** la tabla `{prefijo}workflow_idempotency` que dejó una versión anterior (el plugin ya no la crea ni la lee; puede borrarse a mano) y **conserva siempre** la lista nativa de mantenimientos (datos de negocio no reconstruibles; no la borre) y la tabla retirada `{prefijo}workflow_maintenance` si una versión anterior la creó (ya no se usa tras la migración; su eliminación es una decisión aparte). El almacén de contratos (OW-REQ-71, `workflow_contract*`) fue retirado del plugin: ya no se crea; las tablas que haya dejado una instalación anterior no se tocan al actualizar y se eliminan a mano.
