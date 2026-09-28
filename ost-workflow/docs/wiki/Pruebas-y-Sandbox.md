# Pruebas y sandbox

## Sandbox tipo producción (sin root ni Docker)
La carpeta `prod-sandbox/` del repositorio de plugins compila **PHP 8.0.30 (fpm + opcache) y nginx** desde el código fuente en el directorio del usuario y arranca:

```
cliente → proxy tipo balanceador (:8090, añade X-Forwarded-For)
        → nginx con el default.conf real de producción (:8091)
        → php-fpm 8.0.30 + opcache (:9080)
```

`prod-sandbox.sh start current|proposed` levanta la pila con la configuración de nginx **actual** de producción o con la **propuesta** (regla `workflow` y `client_max_body_size`). `restart-ecs` simula un contenedor nuevo (php-fpm y nginx recién iniciados, opcache frío). `plugin-admin.php` instala y desinstala el plugin con el `PluginManager` de osTicket. Además se usa Mailpit como servidor SMTP de captura (correo saliente) y el `cron.php` del núcleo.
Diferencias conocidas respecto a producción: sin `intl` (PHP 8.0 no compila contra la versión de ICU disponible), sin PCRE JIT (límite de macOS) y OpenSSL 1.1.1 compilado aparte (el de la imagen de producción).

## Qué se ha comprobado
| Comprobación | Resultado |
|---|---|
| El phar carga con el código de `lib/` dentro (PHP 8.0.30 + opcache + nginx) | correcto |
| Coexistencia con el phar real de `mobile` | panel, portal y ambos `ping` responden 200 |
| Reemplazar el phar activo (mismo `install_path`) con opcache caliente | sin caída; versión nueva tras ~2 s |
| Instancia o plugin desactivados | `/workflow/v1` deja de responder; panel y portal siguen |
| nginx actual: `/api/workflow/...` | 404 HTML (necesita la regla) |
| nginx actual: cuerpo de 2 MB | 413 HTML de nginx; con la regla propuesta el plugin responde JSON `too_large` |
| Bloqueo de login por usuario + IP real tras el proxy | correcto |
| Reintento con la misma `Idempotency-Key` | 0 duplicados, `Idempotent-Replayed` |
| Correo: `notify=none` / `notify=all` | 0 correos / correo al dueño y CC a colaboradores |
| Cursor compuesto de sincronización | una nota nueva se detecta aunque `ticket.updated` no cambie |
| Permisos con un agente de rol limitado | `403` con el permiso que falta |

## Pruebas manuales rápidas
```
curl $BASE/ping
curl -X POST $BASE/auth/login -d '{"username":"…","password":"…"}'
curl -H "Authorization: Bearer $TOKEN" "$BASE/tickets?state=open&limit=5"
curl -X POST -H "Authorization: Bearer $TOKEN" -H "Idempotency-Key: $(uuidgen)" \
     -d '{"subject":"Prueba","message":"…","user_id":1,"topic_id":1}' $BASE/tickets
```
