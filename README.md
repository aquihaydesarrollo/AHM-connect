# AHM Connect — Requisitos y documentación completa

**Plugin:** AHM Connect  
**Versión:** 3.8.0  
**Namespace REST:** `ahm-connect/v1`  
**Autenticación:** cabecera `X-RMAI-Key`  
**Autor:** Aquí Hay Marketing · aquihaymarketing.es

---

## Índice

1. [Propósito](#1-propósito)
2. [Requisitos del sistema](#2-requisitos-del-sistema)
3. [Activación y seguridad](#3-activación-y-seguridad)
4. [Panel de administración](#4-panel-de-administración)
5. [Documentación técnica completa](#5-documentación-técnica-completa)

---

## 1. Propósito

API REST privada para gestionar desde herramientas externas (n8n, Make, scripts Python, agentes IA) el contenido y SEO de sitios WordPress con Rank Math, sin necesidad de acceso al escritorio de WordPress.

**Qué puede hacer:**
- Leer y escribir campos SEO de Rank Math en masa
- Actualizar `post_content` y `post_excerpt` en páginas de editor clásico
- Crear entradas, páginas y productos
- Auditar la calidad SEO de cada página contra los mismos checks de Rank Math
- Detectar errores 404, problemas en el sitemap, H1 duplicados o ausentes, y páginas indexadas que no deben estarlo
- Gestionar atributos y variaciones de productos WooCommerce
- Herramientas de mantenimiento (regenerar reglas de reescritura y purgar cachés sin acceso a wp-admin/SSH)
- Purga automática de Elementor y de la caché de página (LiteSpeed, WP Rocket...) tras cada escritura
- Conexión opcional con el panel AHM-Sites, activable/desactivable

**Qué no puede hacer:**
- Modificar el contenido de páginas Elementor vía `post_content` (protegido por diseño; usa `/post/{id}/meta` o `/post/{id}/elementor-section`)
- Acceder sin API Key válida
- Saltarse el rate limit por IP

### Power Tools (v3.8.0)

Bloque **no-RCE** que amplía lo que la IA puede hacer sin introducir ejecución de código arbitrario. Todo va con la misma API Key diaria, mismo rate limit y mismo log (las escrituras dejan traza de auditoría con el recurso afectado). **Deliberadamente NO se incluye** ejecutar PHP, escribir/editar/borrar ficheros, WP-CLI, instalar plugins/temas ni enlaces de login admin: con una key única autoactualizada en toda la flota, cualquiera de esas capacidades sería RCE en todas las webs.

| Grupo | Endpoints |
|-------|-----------|
| **Gutenberg** | `GET /gutenberg/blocks`, `POST /gutenberg/validate` (dry-run), `POST /gutenberg/post`, `PUT /gutenberg/post/{id}` — crea/edita posts con bloques validados con `parse_blocks`/`serialize_blocks`, sin `kses` que rompa atributos de bloques de terceros |
| **Skills** | `GET/POST /skills`, `GET/PUT/DELETE /skills/{id}` — playbooks Markdown en un CPT privado (`ahm_skill`); el listado devuelve nombre + descripción para que la IA elija; incluye una skill integrada "Cómo escribir skills" |
| **Design** | `GET/PUT/DELETE /design` — dirección de diseño global (paleta, tipografías, espaciados, tono, reglas). `GET/POST /design/elementor-kit` — colores/tipografías globales y CSS del **Kit clásico** de Elementor. `POST /design/elementor-regenerate-css` — regenera el CSS global |
| **Elementor clásico** | `POST /post/{id}/elementor-section` — inserta secciones (hero, features, pricing, FAQ…) en una posición dada, reutilizando la escritura segura con verificación de nodos y reversión; limpia el CSS del post |
| **Medios** | `POST /media/upload` — sube un medio por URL o base64 con `media_handle_sideload` (solo tipos de medio permitidos por WordPress; nada de plugins/temas ni ZIP ejecutable). Tope `RMAI_MEDIA_MAX_MB` (20 MB por defecto, configurable en `wp-config.php`) |
| **Ficheros (SOLO LECTURA)** | `GET /files/list`, `GET /files/tree`, `GET /files/read`, `GET /files/search` — explorar y leer bajo `ABSPATH` (rutas resueltas con `realpath()`); sin escribir, borrar ni pausar. **Nunca** enseña ni lee `wp-config*`, `.env*`, `.htpasswd`, `.htaccess`, `.user.ini`, claves, volcados SQL, logs, copias, comprimidos ni carpetas de backups (`updraft`, `ai1wm-backups`…), `.git` o `.ssh` |

**Grupos activables por web** (Ajustes → AHM Connect → Seguridad y límites, o filtro `rmai_route_groups`):

- **Power Tools** (Gutenberg, Skills, Design, Elementor clásico, subida de medios): **activo** por defecto.
- **Ficheros** (`/files/*`): **desactivado** por defecto. Es lo único que expone código y configuración de la web con solo la API Key, así que se enciende a propósito en las webs que lo necesiten.

```php
// mu-plugin o plugin envoltorio: forzar un grupo
add_filter( 'rmai_route_groups', fn( $g ) => array_merge( $g, [ 'files' => true ] ) );
```

---

## 2. Requisitos del sistema

| Requisito | Versión mínima |
|-----------|---------------|
| WordPress | 6.0 |
| PHP | 7.4 |
| Rank Math SEO | cualquier versión |
| WooCommerce | opcional (endpoints WC solo activos si está instalado) |
| Elementor | opcional (detección automática para proteger diseño) |

### Actualizaciones

Desde la 3.6.0 el plugin se actualiza solo: consulta `ahm-connect.json` de este
repositorio y ofrece **Actualizar** en Escritorio → Plugins, como cualquier
plugin de wordpress.org. Para forzar la comprobación sin esperar a la revisión
de cada 12 h, usa el enlace **"Buscar actualizaciones"** en la fila del plugin.

Las webs que estén en una versión anterior a la 3.6.0 no llevan ese mecanismo:
hay que subirles `dist/ahm-connect.zip` una vez a mano. Ver [RELEASING.md](RELEASING.md).

Para que una web **no** se actualice desde este repositorio (por ejemplo un
paquete que se actualiza a mano), define en `wp-config.php`:

```php
define( 'RMAI_DISABLE_UPDATES', true );
```

---

## 3. Activación y seguridad

### API Key
- Se genera automáticamente con `bin2hex(random_bytes(24))` al activar el plugin
- Se muestra en **Ajustes → AHM Connect**
- Se puede regenerar manualmente desde el panel
- **Se regenera automáticamente cada día a medianoche** vía WP Cron
- Debe enviarse en la cabecera HTTP: `X-RMAI-Key: <clave>`

### Rate limit
- 60 peticiones por minuto por IP por defecto, en ventana fija de 60 s
- Se cambia en el panel (1-1000) o fijándolo en `wp-config.php` con `define( 'RMAI_RATE_LIMIT', 120 );` (manda sobre el panel)
- Se puede desactivar desde el panel de ajustes
- Al pasarse se recibe HTTP 429 con `retry_after` (segundos hasta que se vacía la ventana)

### Whitelist de IPs
- Campo en ajustes para restringir acceso a IPs concretas
- Si está vacío, se admiten todas las IPs

### Log de accesos
- Registra las últimas 100 peticiones (fecha, método, ruta, estado HTTP, IP)
- Se puede desactivar y borrar desde el panel
- Solo visible para administradores de WordPress

---

## 4. Panel de administración

Acceso: **WordPress Admin → Ajustes → AHM Connect**

- **API Key:** clave actual, botón para regenerar
- **Info del sitio:** versiones de WordPress, PHP, Rank Math, WooCommerce
- **Ajustes:** rate limit on/off y peticiones por minuto, log de accesos on/off, whitelist de IPs, grupos Power Tools y Ficheros
- **Endpoints:** tabla de referencia de todas las rutas disponibles
- **Log de peticiones:** últimas 100 peticiones, con opción de borrar
- **AHM Sites:** toggle para vincular el sitio al panel AHM-Sites por código de un solo uso (opcional, independiente de la API Key general)

---

## 5. Documentación técnica completa

La referencia completa de endpoints (parámetros, bodies, respuestas), las reglas exactas de protección de Elementor, los checks de Rank Math replicados y los límites por operación viven en un documento interno, no en este repo público — solo hace falta para quien integra directamente contra la API, y ese nivel de detalle no aporta nada a quien solo visita el repositorio.

Si necesitas esa referencia, pídela al equipo de Aquí Hay Marketing.

---

*Última actualización: octubre 2026 · v3.8.0*
