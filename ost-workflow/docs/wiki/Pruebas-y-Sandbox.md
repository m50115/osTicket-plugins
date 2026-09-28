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
| Edición de notas internas | aplicada, `409` con versión antigua, `422` en respuesta pública, versión anterior oculta y nota de tarea: comprobadas por `e2e.py`; el resto de la semántica, sin comprobación ejecutable (ver [Respuestas-y-Notas.md](Respuestas-y-Notas.md)) |

**Cobertura por script.** Estas comprobaciones se realizaron en el sandbox. Tienen además un script en el repositorio (`e2e.py`): reintento con la misma clave, cursor compuesto de sincronización, permisos con un agente limitado y bloqueo de login. La coexistencia con `mobile`, el reemplazo del phar con opcache, la desactivación del plugin, el ruteo y el `413` de nginx y el correo (`notify`) se comprobaron a mano, sin script en el repositorio. `smoke.sh` no toca la edición de notas.

## Pruebas automáticas y guardias (carpeta `prod-sandbox/`)
| Herramienta | Qué hace |
|---|---|
| `e2e.py [BASE]` | Suite de regresión por HTTP (207 comprobaciones por ejecución completa, con el agente limitado, `SCR` y Mailpit disponibles; sin ellos se omiten las que los necesitan): rutas y sobre de respuesta, tokens y revocación, catálogos con ETag, contactos y organizaciones (candidatos, base, CAS), tickets (creación con reintento, estado, asignación, traspaso, campos, colaboradores), hilo y archivos (edición de notas, adjuntos, política de caracteres), tareas, sincronización compuesta, informes, permisos con un agente limitado, recuperación tras un corte de idempotencia y bloqueo de login por usuario + IP. Crea sus propios datos. |
| `smoke.sh [BASE]` | 16 comprobaciones rápidas. |
| `ci-check.sh [phar]` | Guardias estáticas: sintaxis PHP 8.0, sin funciones globales, sin `exit`/`die`, `echo` solo en el emisor, sin estado en archivos, un solo manifiesto `ost:workflow`, archivo principal mínimo, toda ruta de escritura con política explícita, sin borrados duros en handlers y clasificación de rutas al día; con un phar, comprueba su contenido. **Ningún commit de `ost-workflow` es una línea base válida si este script no pasa.** |
| `install-git-hooks.sh` | Opcional: `pre-commit` local que corre `ci-check.sh` cuando el commit toca `ost-workflow/` o `prod-sandbox/` (se salta con `--no-verify`; no destructivo). |
| `build-artifact.sh` | Estampa el commit en `build_sha`, construye con PHP 8.0 en una copia del repositorio y escribe `dist/ost-workflow.phar` + `.sha256` + `build.json`. Se niega a construir si falla `ci-check.sh`. |
| `gen-openapi.php` | Genera `docs/openapi.json` desde la tabla real de rutas. |
| `gen-route-matrix.py [--check]` | Genera `docs/security/Route-Surface.md` desde `openapi.json` y `docs/security/route-classification.json`; falla si una ruta no está clasificada. |

## Pruebas manuales rápidas
```
curl $BASE/ping
curl -X POST $BASE/auth/login -d '{"username":"…","password":"…"}'
curl -H "Authorization: Bearer $TOKEN" "$BASE/tickets?state=open&limit=5"
curl -X POST -H "Authorization: Bearer $TOKEN" -H "Idempotency-Key: $(uuidgen)" \
     -d '{"subject":"Prueba","message":"…","user_id":1,"topic_id":1}' $BASE/tickets
```
