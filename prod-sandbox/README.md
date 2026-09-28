# prod-sandbox

Entorno tipo producción para probar `ost-workflow` sin root ni Docker: PHP 8.0.30 (fpm + opcache) y nginx compilados en `~/development/ost-prod`, el `docker/default.conf` real del repositorio de osTicket y un proxy tipo balanceador delante. Comparte la aplicación y la base de datos de `~/development/ost-sandbox`.

| Archivo | Uso |
|---|---|
| `build-php80.sh` | Compila OpenSSL 1.1.1w, PHP 8.0.30 y nginx (una vez; tarda unos minutos). |
| `prod-sandbox.sh start [current\|proposed]` | `current` = nginx exactamente como en producción hoy; `proposed` = + ruta `/api/workflow` y `client_max_body_size`. También `stop`, `status`, `restart-ecs` (procesos nuevos, opcache frío), `logs`. Arranca Mailpit si existe. |
| `forcore-check.php <app> [lib]` | Comprobación de solo lectura de `Time::forCore` (90 verificaciones): identidad cuando la zona de BD del núcleo es la correcta, y compensación exacta —también en los días de cambio de horario y con tres zonas de agente— cuando el núcleo la deduce mal (RC-14). |
| `plugin-admin.php <app> install\|uninstall [ruta]` | Instala/desinstala el plugin con el `PluginManager` de osTicket. |
| `smoke.sh [BASE]` | 16 comprobaciones de humo (rutas, autenticación, idempotencia, conflicto, revocación). |
| `e2e.py [BASE]` | Suite de regresión (308 comprobaciones: 307 pasan, 1 omisión conocida) por HTTP; crea sus propios datos. Necesita los agentes `agent2` (Limited Access), `agent3` (Expanded Access, no gerente) y `agent2fa` (con 2FA) y acceso a la BD (`sql.sh`): sin ellos se omiten esos bloques; `E2E_STRICT=1` convierte cualquier omisión inesperada en fallo. **Reconstruir el fixture desde cero: [`E2E-Fixtures.md`](E2E-Fixtures.md)** (las credenciales son locales y nunca se versionan). Incluye la sección de hardening: superficie congelada (102 rutas), decisiones PC-S1…S5, 401 sin token en todas, rutas retiradas, asignación masiva, directorio de contactos, lecturas entre departamentos (archivos con y sin `Range`, documentos, PDF) y correo por defecto (Mailpit). |
| `create-agent.php <usuario> <role_id>` | Crea (o con `--reset-password` re-clave) un agente del sandbox y escribe su `.env` local (0600) con una contraseña generada; `--2fa=email` activa el segundo factor nativo del núcleo. Solo sandbox. |
| `sql.sh "<consulta>"` | Ayudante de BD del sandbox para `e2e.py`; sin secretos en el archivo (lee `credentials.env`). |
| `ci-check.sh [phar]` | Guardias estáticas (PHP 8.0, sin globales, sin exit, sin estado en archivos, un solo manifiesto; toda ruta de escritura declara su política; sin borrados duros en handlers; la clasificación de rutas cubre todo `openapi.json`). **Ningún commit de ost-workflow es una línea base válida si esto no pasa.** |
| `install-git-hooks.sh [--remove]` | Opcional: instala un `pre-commit` local que corre `ci-check.sh` cuando el commit toca `ost-workflow/` o `prod-sandbox/` (se salta con `--no-verify`; no destructivo). |
| `build-artifact.sh` | Artefacto con `build_sha` estampado en `dist/` (+ sha256 y build.json). |
| `gen-openapi.php` | Genera `ost-workflow/docs/openapi.json` desde la tabla real de rutas. |
| `gen-route-matrix.py [--check]` | Genera `ost-workflow/docs/security/Route-Surface.md` desde `openapi.json` + `docs/security/route-classification.json`; falla si una ruta no está clasificada. |

Puertos: 8090 (proxy) · 8091 (nginx del "contenedor") · 9080 (php-fpm) · 1025/8025 (Mailpit SMTP/UI).
Diferencias con producción: sin `intl`, sin PCRE JIT. El correo saliente de osTicket debe apuntar a `127.0.0.1:1025` (cuenta SMTP sin autenticación) para verlo en Mailpit.
Estas herramientas viven fuera de `ost-workflow/` a propósito: el repositorio de plugins no se despliega y el phar no debe incluirlas.
