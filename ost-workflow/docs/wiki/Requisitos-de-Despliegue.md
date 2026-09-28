# Requisitos de despliegue

Estos requisitos son de **infraestructura**: el plugin no puede resolverlos por sí mismo.

## Ruteo en nginx (obligatorio)
La configuración de nginx de la imagen de osTicket solo envía a `api/http.php` las rutas `^/api/(tickets|tasks|mobile)`. Sin cambios, `/api/workflow/v1/...` cae en el portal y responde `404` con HTML (comprobado). Añada `workflow` a la alternación:

```
location ~ ^/api/(?:tickets|tasks|mobile|workflow).*$ {
    try_files $uri $uri/ /api/http.php?$query_string;
}
```

Con esa línea la URL base es `https://<host>/api/workflow/v1`. **No despliegue un archivo `api/workflow.php`**: con esa expresión regular nginx sirve como archivo estático cualquier `api/workflow*.php` (código fuente visible). El plugin no necesita shim.

## Tamaño de cuerpo (obligatorio si hay adjuntos)
Sin `client_max_body_size`, nginx limita a 1 MB por petición y responde `413` con HTML antes de que el plugin vea la petición (comprobado con 2 MB). Defina un límite coherente con los adjuntos, p. ej. `client_max_body_size 8m;` (el plugin recibe una parte por petición y responde `413` en JSON cuando excede sus propios límites).

## PHP
- osTicket 1.17.2, PHP **8.0** (el código usa solo sintaxis compatible con 8.0). Extensiones: las de osTicket (`mysqli`, `gd`, `mbstring`, `openssl`, `zip`…). `intl` es opcional.
- opcache habilitado es la configuración de producción y la validada.

## Balanceador y proxies
Configure en la instancia los proxies de confianza (`Trusted proxy IPs / CIDRs`). El bloqueo de login se aplica por **usuario + IP real**; una IP compartida por el balanceador no bloquea a otros usuarios (comprobado).

## Convivencia
Puede coexistir con el plugin `mobile` (comprobado con el phar real de `mobile` activo). Cada plugin tiene su propia fila y su propio phar.
