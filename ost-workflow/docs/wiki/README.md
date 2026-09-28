# Cómo se mantiene esta wiki

Esta carpeta (`ost-workflow/docs/wiki/`) es la documentación **pública y estable** del plugin. Sigue la misma convención que la wiki del proyecto (`DOCS_IMPACT` / `WIKI_IMPACT`).

- **Qué entra:** comportamiento acordado de la API, procedimientos **validados** (indicando el alcance de la validación), requisitos de despliegue y solución de problemas comprobados.
- **Qué no entra:** razonamiento de diseño, evidencia forense, decisiones en curso y rutas locales; eso vive en las notas internas del proyecto.
- **Dónde está el detalle por ruta:** [`../endpoints/`](../endpoints/) (cuerpo, respuesta, errores, funciones del núcleo con archivo y línea).
- **Índice de rutas:** [API-Reference.md](API-Reference.md) se genera a partir de la tabla de rutas real; regenérelo cuando cambien las rutas.
- **Cambios de contrato:** dentro de `v1` solo aditivos; un cambio incompatible abre `v2` y se documenta aparte.
- **Idioma:** español como base; las traducciones vendrán después.
