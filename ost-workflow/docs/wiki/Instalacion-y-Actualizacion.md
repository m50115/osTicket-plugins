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
2. Instale el plugin (`install_path` = `plugins/ost-workflow.phar`). Se crea **una sola fila** en la tabla de plugins y, al activarlo, la tabla `{prefijo}workflow_idempotency` (`CREATE TABLE IF NOT EXISTS`, repetible sin error).
3. Active el plugin y **cree y active una instancia** (el plugin es de instancia única). Configure en la instancia:

| Opción | Descripción |
|---|---|
| Token signing secret | Mínimo 32 caracteres aleatorios. **Obligatorio**: sin él, el login responde `503 not_configured`. Independiente de `SECRET_SALT`; rotarlo revoca todos los tokens. |
| Token lifetime (days) | Vida del token: **14 por defecto** (decisión de MSOLIS, 2026-09-28; línea base de guardarraíles). Una instancia que ya guardó otro valor (p. ej. 30) lo conserva hasta que se edite y guarde la configuración. |
| Límites por hora y agente (`limit_mail_per_hour` 100, `limit_uploads_per_hour` 200, `limit_pdf_per_hour` 60, `limit_lookups_per_hour` 300) | Presupuestos que acotan lo que haría un token robado (correo a clientes, subidas, PDF, búsquedas de contactos sin acceso al directorio). `0` desactiva. Se guardan en la tabla del plugin; una instancia ya existente usa los valores por omisión hasta que se guarde la configuración. |
| Default help topic / department | Valores por defecto para tickets nuevos. Vacío = el cliente debe enviarlos (422 si no). |
| Max attachments / max file bytes | Límites del plugin; el efectivo es el más estricto entre estos y los de osTicket. |
| Trusted proxy IPs / CIDRs | Proxies de confianza para leer `X-Forwarded-For` (bloqueo de login). Ponga las direcciones de su balanceador. |
| Enabled app modules, brand name/color | Los publica `GET /config`. |

4. Compruebe: `GET /workflow/v1/ping` → `200 {"data":{"status":"ok"}}` y, con un token, `GET /workflow/v1/config` (revise `plugin_version` y `build_sha`).

## 3. Activar y desactivar
Rutas solo existen con el plugin **activo y con una instancia activa** (validado): con la instancia desactivada, o el plugin desactivado, `/workflow/v1/*` deja de responder y el panel de agentes y el portal siguen funcionando.
Desactivar **no descarga** el archivo: osTicket incluye el archivo principal de cada plugin instalado en cada petición. Por eso **nunca** debe haber dos phars con la misma clase ni una segunda fila del mismo plugin.

## 4. Actualizar
Reemplace el phar **en el mismo `install_path`** (mismo nombre de archivo). Validado con el plugin activo y opcache caliente: el panel y el portal siguieron respondiendo, y tras la revalidación de opcache (unos 2 s con la configuración por defecto) se sirvió la versión nueva. En un despliegue por imagen, publique una imagen nueva con el mismo `install_path` y reinicie los contenedores.

No use la receta antigua "phar versionado junto al viejo + desactivar el viejo": causa un error fatal global (dos copias de la misma clase).

## 5. Volver atrás
Restaure el phar anterior en el mismo `install_path` (o la etiqueta de imagen anterior). Antes de cualquier cambio, respalde la tabla de plugins de osTicket.

## 6. Desinstalar
Desinstalar el plugin elimina su fila y su configuración; **conserva** la tabla `{prefijo}workflow_idempotency` (puede borrarse a mano si ya no se usa).
