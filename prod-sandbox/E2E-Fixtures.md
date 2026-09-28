# E2E fixtures: reconstruir las precondiciones de `e2e.py` desde cero

Infraestructura de pruebas, no una función del plugin. Sirve para que otra máquina (u otro agente) pueda reproducir la suite completa sin depender de un `/tmp` que ya no existe. **Solo sandbox: nunca producción.**

## Qué es efímero y qué está en Git

| Elemento | ¿En Git? | Dónde vive |
|---|---|---|
| `create-agent.php` (crea o re-clave un agente del sandbox) | sí | `prod-sandbox/` |
| `sql.sh` (ayudante de BD; **sin contraseña**: lee `SB_DB_PASS` de `credentials.env`) | sí | `prod-sandbox/` |
| `agent2.env`, `agent3.env` (usuario + contraseña generada) | **NO, nunca** | `~/development/ost-sandbox/e2e-fixtures/` (0700/0600, fuera del repositorio) o el directorio de `SCR` |
| `~/development/ost-sandbox/credentials.env` (admin del sandbox, contraseña de BD) | **NO, nunca** | fuera del repositorio |
| Tokens del plugin | **NO, nunca** | no se guardan; `e2e.py` inicia sesión en cada ejecución |
| `.claude/settings.local.json` | **NO, nunca** | local |

Las contraseñas de los agentes las **genera** `create-agent.php` (aleatorias, 22 caracteres) y solo las escribe en el `.env` local; no se imprimen ni se inventan. Un `.env` perdido se reconstruye con `--reset-password`.

## Qué necesita la suite

| Actor | Rol / alcance | Para qué |
|---|---|---|
| `ostadmin` (admin del sandbox; usuario y clave en `credentials.env`: `SB_ADMIN_USER`, `SB_ADMIN_PASS`) | administrador, con acceso a los departamentos 2 (Sales) y 3 (Maintenance) | casi todo; crea los datos del otro departamento |
| `agent2` | rol **3 «Limited Access»**, departamento primario **1 (Support)**, sin acceso extendido, no administrador | permisos negativos: tickets/tareas/archivos de otro departamento (incl. tareas **cerradas**), directorio, presupuestos |
| `agent3` | rol **2 «Expanded Access»**, departamento primario **1**, **no gerente** del departamento | PC-S2 (`disable`/`clear_overdue` del SLA solo gerente o administrador) |
| `agent2fa` | rol **3**, departamento **1**, con el **segundo factor por e-mail** de osTicket (`--2fa=email`) | 2FA A1: su login por la API debe ser `403 two_factor_required` |
| acceso a BD (`sql.sh`) | usuario `ost` del MySQL del sandbox (puerto 3307) | recuperación tras un corte de idempotencia, presupuestos por hora, comprobaciones de gerente |
| Mailpit (`127.0.0.1:8025`) | opcional | «correo por defecto apagado» (sin él, esa sección se omite) |

Datos base del sandbox que la suite da por hechos: departamentos 1 Support, 2 Sales y 3 Maintenance; tema 1; SLA y prioridades por defecto. El instalador del sandbox los crea.

## Procedimiento (sandbox nuevo → suite completa)

Desde la raíz del repositorio de plugins, con el sandbox en marcha (`prod-sandbox/prod-sandbox.sh start proposed`) y el phar de `ost-workflow` instalado (`plugin-admin.php <app> install …`):

```bash
PHP80=~/development/ost-prod/php80/bin/php          # el PHP del sandbox (8.0), no el del sistema
FIX=~/development/ost-sandbox/e2e-fixtures

# 1. carpeta local para las credenciales (fuera de Git)
mkdir -p "$FIX" && chmod 700 "$FIX"

# 2. crear los agentes de la suite y generar sus .env locales
$PHP80 prod-sandbox/create-agent.php agent2 3 --dept=1 --out="$FIX/agent2.env"   # Limited Access
$PHP80 prod-sandbox/create-agent.php agent3 2 --dept=1 --out="$FIX/agent3.env"   # Expanded Access, no gerente
$PHP80 prod-sandbox/create-agent.php agent2fa 3 --dept=1 --2fa=email --out="$FIX/agent2fa.env"   # con 2FA
#    (si ya existen y solo falta el .env: añadir --reset-password)

# 3. comprobar el fixture ANTES de correr la suite
prod-sandbox/sql.sh "select staff_id,username,dept_id,role_id,isactive,isadmin from ost_staff"
#    esperado: agent2 y agent2fa → role_id 3, agent3 → role_id 2, todos dept_id 1, isactive 1, isadmin 0
prod-sandbox/sql.sh "select namespace,\`key\`,value from ost_config where \`key\` in ('default_2fa','2fa-email')"
#    esperado: dos filas del namespace staff.<id> de agent2fa (default_2fa = 2fa-email y su configuración)
prod-sandbox/sql.sh "select 1"                                    # el ayudante de BD funciona
ls -l "$FIX"                                                     # agent2.env, agent3.env y agent2fa.env con permisos -rw-------

# 4. suite completa; E2E_STRICT=1 convierte en FALLO cualquier omisión salvo la conocida
E2E_STRICT=1 python3 prod-sandbox/e2e.py
```

Resultado esperado tras las decisiones finales de MSOLIS del 28-sep-2026: **0 fallidas y 1 omitida** (el número de aprobadas crece con la suite: ver la última línea `result:`) (`agent2 actions`: el ticket de esa comprobación no es visible para el agente limitado; es conocida y no un problema del fixture). Si `E2E_STRICT=1` muestra `FAIL … unexpected skip`, falta una precondición (agente, `.env`, BD o Mailpit). Sin `E2E_STRICT` esas omisiones pasarían en silencio: no lo uses para verificar un fixture.

Otras rutas, si no se usa el directorio por defecto: `SCR=<dir>` (con `agent2.env`, `agent3.env` y, opcional, un `sql.sh` propio) o las variables `AGENT2_USER/AGENT2_PASS`, `AGENT3_USER/AGENT3_PASS`.

## Notas

* `create-agent.php` se niega a correr si el directorio de osTicket no es de un `ost-sandbox`.
* Usar el PHP 8.0 del sandbox: con otro PHP el núcleo de osTicket 1.17 emite avisos de obsolescencia.
* `Staff::create()` se llama **sin argumentos** (como el panel): con argumentos, `passwd1`/`islocked` se tratan como columnas y la inserción falla (error 1054).
* La suite **no borra** sus datos: cada ejecución deja tickets, tareas, contactos y archivos (con valores únicos: el núcleo desduplica archivos por contenido). No hay que limpiar entre ejecuciones.
* `agent2fa` tiene el segundo factor **activo a propósito**: no sirve para probar nada más y no debe usarse para iniciar sesión por la API (ese es el comportamiento que se comprueba). Si su `.env` se pierde, `--reset-password --2fa=email` lo regenera.
