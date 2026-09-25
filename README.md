# AHM Connect — Requisitos y documentación completa

**Plugin:** AHM Connect  
**Versión:** 3.7.0  
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
- Herramientas de mantenimiento (regenerar reglas de reescritura sin acceso a wp-admin/SSH)
- Conexión opcional con el panel AHM-Sites, activable/desactivable

**Qué no puede hacer:**
- Modificar el contenido de páginas Elementor (protegido por diseño)
- Acceder sin API Key válida
- Saltarse el rate limit por IP

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

---

## 3. Activación y seguridad

### API Key
- Se genera automáticamente con `bin2hex(random_bytes(24))` al activar el plugin
- Se muestra en **Ajustes → AHM Connect**
- Se puede regenerar manualmente desde el panel
- **Se regenera automáticamente cada día a medianoche** vía WP Cron
- Debe enviarse en la cabecera HTTP: `X-RMAI-Key: <clave>`

### Rate limit
- Máximo 60 peticiones por minuto por IP
- Se puede desactivar desde el panel de ajustes
- IPs bloqueadas reciben error HTTP 429

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
- **Ajustes:** rate limit on/off, log de accesos on/off, whitelist de IPs
- **Endpoints:** tabla de referencia de todas las rutas disponibles
- **Log de peticiones:** últimas 100 peticiones, con opción de borrar
- **AHM Sites:** toggle para vincular el sitio al panel AHM-Sites por código de un solo uso (opcional, independiente de la API Key general)

---

## 5. Documentación técnica completa

La referencia completa de endpoints (parámetros, bodies, respuestas), las reglas exactas de protección de Elementor, los checks de Rank Math replicados y los límites por operación viven en un documento interno, no en este repo público — solo hace falta para quien integra directamente contra la API, y ese nivel de detalle no aporta nada a quien solo visita el repositorio.

Si necesitas esa referencia, pídela al equipo de Aquí Hay Marketing.

---

*Última actualización: septiembre 2026 · v3.7.0*
