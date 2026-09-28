#!/usr/bin/env python3
"""
Generates ost-workflow/docs/security/Route-Surface.md from
  docs/openapi.json                        (generated from the real route table)
  docs/security/route-classification.json  (the reviewed classification; the ONLY hand-edited source)
Fails (exit 1) when a route has no classification or a classification names a route that no longer exists,
so the matrix cannot drift from the code:  python3 gen-route-matrix.py [--check]
"""
import json, os, sys, collections

ROOT = os.path.join(os.path.dirname(os.path.abspath(__file__)), "..", "ost-workflow", "docs")
oa = json.load(open(os.path.join(ROOT, "openapi.json")))
cl = json.load(open(os.path.join(ROOT, "security", "route-classification.json")))
routes, removed = cl["routes"], cl["removed_2026_09_28"]
ops = {}
for path, v in oa["paths"].items():
    for m, o in v.items():
        ops[m.upper() + " " + path] = o.get("x-policy", "auth")
missing = sorted(set(ops) - set(routes))
stale = sorted(set(routes) - set(ops))
if missing or stale:
    print("route-matrix: DRIFT. unclassified: %s; stale: %s" % (missing, stale))
    sys.exit(1)

def cnt(field):
    return collections.Counter(routes[k][field] for k in ops)

out = []
w = out.append
w("# Superficie de rutas de ost-workflow — matriz de clasificación\n")
w("> Generado por `prod-sandbox/gen-route-matrix.py` a partir de `docs/openapi.json` (tabla real de rutas) y `docs/security/route-classification.json` (única fuente editable). No editar a mano.\n")
w("**%d rutas.** Base congelada el 2026-09-28 (hardening). Decisiones, modelo de amenaza y evidencia: nota `2026-09-28-bestcare-workflow-ost-workflow-security-hardening` de la bóveda 02-KE.\n" % len(ops))
for title, field in (("Por impacto", "impact"), ("Por necesidad", "necessity"), ("Por categoría", "category"), ("Por recomendación", "recommendation")):
    w("## %s\n" % title)
    w("| Valor | Rutas |\n|---|---|")
    for k, n in sorted(cnt(field).items(), key=lambda x: -x[1]):
        w("| %s | %d |" % (k, n))
    w("")
w("## Rutas retiradas de la superficie pública (2026-09-28)\n")
w("| Ruta | Motivo | Reemplazo |\n|---|---|---|")
for r in removed:
    w("| `%s` | %s | %s |" % (r["route"], r["reason"], r["replacement"]))
w("")
w("Cada una tiene una prueba negativa en `e2e.py` (404/405).\n")
w("## Matriz\n")
w("Leyenda: R/W = lectura/escritura; *Permiso* = política que el plugin aplica antes del handler.\n")
w("| Endpoint | R/W | Permiso | Consumidor | Categoría | Impacto | Reversible | Necesidad | Riesgo de abuso | Recomendación |")
w("|---|---|---|---|---|---|---|---|---|---|")
for k in sorted(ops, key=lambda x: (x.split(" ", 1)[1], x.split(" ", 1)[0])):
    c = routes[k]
    w("| `%s` | %s | `%s` | %s | %s | %s | %s | %s | %s | %s |" % (
        k, c["rw"], ops[k], c["consumer"], c["category"], c["impact"], c["reversible"], c["necessity"], c["abuse"].replace("|", "/"), c["recommendation"]))
text = "\n".join(out) + "\n"
dest = os.path.join(ROOT, "security", "Route-Surface.md")
if "--check" in sys.argv:
    if not os.path.exists(dest) or open(dest).read() != text:
        print("route-matrix: Route-Surface.md is stale; run gen-route-matrix.py")
        sys.exit(1)
    print("route-matrix: OK (%d routes)" % len(ops))
else:
    open(dest, "w").write(text)
    print("route-matrix: wrote %s (%d routes)" % (dest, len(ops)))
