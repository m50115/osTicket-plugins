# prod-sandbox

Entorno tipo producción para probar `ost-workflow` sin root ni Docker: PHP 8.0.30 (fpm + opcache) y nginx compilados en `~/development/ost-prod`, el `docker/default.conf` real del repositorio de osTicket y un proxy tipo balanceador delante. Comparte la aplicación y la base de datos de `~/development/ost-sandbox`.

| Archivo | Uso |
|---|---|
| `build-php80.sh` | Compila OpenSSL 1.1.1w, PHP 8.0.30 y nginx (una vez; tarda unos minutos). |
| `prod-sandbox.sh start [current\|proposed]` | `current` = nginx exactamente como en producción hoy; `proposed` = + ruta `/api/workflow` y `client_max_body_size`. También `stop`, `status`, `restart-ecs` (procesos nuevos, opcache frío), `logs`. Arranca Mailpit si existe. |
| `plugin-admin.php <app> install\|uninstall [ruta]` | Instala/desinstala el plugin con el `PluginManager` de osTicket. |
| `smoke.sh [BASE]` | 16 comprobaciones de humo (rutas, autenticación, idempotencia, conflicto, revocación). |

Puertos: 8090 (proxy) · 8091 (nginx del "contenedor") · 9080 (php-fpm) · 1025/8025 (Mailpit SMTP/UI).
Diferencias con producción: sin `intl`, sin PCRE JIT. El correo saliente de osTicket debe apuntar a `127.0.0.1:1025` (cuenta SMTP sin autenticación) para verlo en Mailpit.
Estas herramientas viven fuera de `ost-workflow/` a propósito: el repositorio de plugins no se despliega y el phar no debe incluirlas.
