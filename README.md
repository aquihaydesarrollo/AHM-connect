# AHM Connect — Requisitos y documentación completa

**Plugin:** AHM Connect  
**Versión:** 3.2.0  
**Namespace REST:** `rankmath-ai/v1`  
**Autenticación:** cabecera `X-RMAI-Key`  
**Autor:** Aquí Hay Marketing · aquihaymarketing.es

---

## Índice

1. [Propósito](#1-propósito)
2. [Requisitos del sistema](#2-requisitos-del-sistema)
3. [Activación y seguridad](#3-activación-y-seguridad)
4. [Reglas de escritura — Elementor](#4-reglas-de-escritura--elementor)
5. [Endpoints — Contenido y SEO](#5-endpoints--contenido-y-seo)
6. [Endpoints — Auditoría SEO /seo/*](#6-endpoints--auditoría-seo-seo)
7. [Checks de Rank Math replicados](#7-checks-de-rank-math-replicados)
8. [Endpoints — WooCommerce](#8-endpoints--woocommerce)
9. [Panel de administración](#9-panel-de-administración)
10. [Comportamientos automáticos](#10-comportamientos-automáticos)
11. [Límites y restricciones](#11-límites-y-restricciones)
12. [Campos SEO de Rank Math soportados](#12-campos-seo-de-rank-math-soportados)

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

## 4. Reglas de escritura — Elementor

> **Regla absoluta:** si una página está construida con Elementor, el plugin NUNCA modifica `post_content` ni `post_excerpt`. Los cambios de diseño en Elementor se hacen siempre manualmente desde el editor de Elementor.

### Detección
El plugin detecta páginas Elementor comprobando el meta `_elementor_edit_mode === 'builder'`.

### Comportamiento por endpoint

| Operación | Página Elementor | Página editor clásico |
|-----------|-----------------|----------------------|
| Leer SEO (`GET /post/{id}`) | ✅ permitido | ✅ permitido |
| Escribir SEO Rank Math (`PUT /post/{id}`) | ✅ permitido | ✅ permitido |
| Escribir `post_content` (`PUT /post/{id}/content`) | ❌ error 403 | ✅ permitido |
| Escribir `post_excerpt` (`PUT /post/{id}/content`) | ❌ error 403 | ✅ permitido |
| `POST /bulk-content` — item individual | ❌ skipped, devuelve `elementor_protected: true` | ✅ permitido |
| `GET /seo/post/{id}` (audit) | ✅ devuelve `elementor_page: true, content_editable: false` | ✅ devuelve `content_editable: true` |
| Aplicar sugerencias SEO (`POST /seo/apply/{id}`) | ✅ solo aplica campos Rank Math, nunca post_content | ✅ aplica campos Rank Math |

### Campos SEO siempre seguros (no afectan diseño Elementor)
`rank_math_title`, `rank_math_description`, `rank_math_focus_keyword`, `rank_math_canonical_url`, `rank_math_robots`, `rank_math_og_title`, `rank_math_og_description`, `rank_math_og_image_url`, `rank_math_twitter_title`, `rank_math_twitter_description`, `rank_math_twitter_card_type`, `rank_math_rich_snippet`, `rank_math_pillar_content`, `rank_math_breadcrumb_title`

---

## 5. Endpoints — Contenido y SEO

Base URL: `https://tudominio.com/wp-json/rankmath-ai/v1`

### `GET /posts`
Lista entradas con datos SEO básicos.

**Parámetros:**
| Parámetro | Default | Descripción |
|-----------|---------|-------------|
| `type` | `post` | Tipo de post (post, page, product, …) |
| `per_page` | `20` | Máximo 100 |
| `page` | `1` | Página de resultados |
| `search` | — | Filtro de búsqueda |
| `orderby` | `modified` | modified, date, title, ID, menu_order |

**Respuesta:** `total`, `total_pages`, `page`, `per_page`, `type`, `items[]` con id, title, slug, url, type, status, modified, seo (seo_title, seo_description, focus_keyword, score, rating).

---

### `GET /post/{id}`
Datos completos de una entrada: todo el SEO, `post_content`, `post_excerpt`.

---

### `PUT /post/{id}` o `PATCH /post/{id}`
Actualiza campos SEO de Rank Math.

**Body JSON:** cualquier combinación de campos del [mapa de campos](#12-campos-seo-de-rank-math-soportados).

**Respuesta:** `success`, `post_id`, `updated[]`, `ignored[]`, `seo{}`.

---

### `PUT /post/{id}/content` o `PATCH /post/{id}/content`
Actualiza `post_content` y/o `post_excerpt`.

**Bloqueado** si la página usa Elementor (error 403).

**Body JSON:**
```json
{ "post_content": "<p>Nuevo contenido HTML</p>", "post_excerpt": "Resumen" }
```

---

### `POST /bulk-update`
Actualiza SEO de hasta 50 entradas en una sola petición.

**Body JSON:**
```json
[
  { "id": 123, "fields": { "seo_title": "Título", "focus_keyword": "palabra clave" } },
  { "id": 456, "fields": { "seo_description": "Descripción meta" } }
]
```

---

### `POST /bulk-content`
Actualiza `post_content`, `post_excerpt` y/o `slug` de hasta 20 entradas.  
Los items de páginas Elementor se saltan automáticamente con `elementor_protected: true`.

**Body JSON:**
```json
[
  { "id": 123, "post_content": "<p>Contenido</p>", "slug": "nuevo-slug" },
  { "id": 456, "post_excerpt": "Resumen actualizado" }
]
```

---

### `POST /create-post`
Crea una nueva entrada, página o producto.

**Body JSON:**
```json
{
  "title": "Título obligatorio",
  "post_type": "post",
  "post_content": "<p>Contenido</p>",
  "post_excerpt": "Resumen",
  "status": "draft",
  "slug": "mi-slug",
  "seo_title": "Título SEO",
  "seo_description": "Meta descripción",
  "focus_keyword": "palabra clave"
}
```

---

### `GET /post/{id}/score`
Devuelve la puntuación SEO actual de Rank Math y la `focus_keyword`.

---

### `GET/POST /post/{id}/meta`
- **GET:** Devuelve metadatos del post (si `?keys=clave1,clave2` filtra por clave; sin filtro devuelve claves WooCommerce y nutricionales).
- **POST:** Escribe una clave de meta arbitraria. Body: `{ "key": "mi_clave", "value": "valor" }`.

---

### `POST /recalculate-scores`
Fuerza el recálculo del score de Rank Math en una lista de IDs.

**Body:** `{ "ids": [1, 2, 3] }` — Máximo 50 IDs.

---

### `GET /post-types`
Lista todos los tipos de contenido públicos del sitio.

---

### `GET /info`
Información general del sitio: nombre, URL, versiones de WordPress, Rank Math, WooCommerce, Elementor, PHP, tipos de contenido disponibles.

---

## 6. Endpoints — Auditoría SEO `/seo/*`

### `GET /seo`
Auditoría SEO paginada de todas las entradas/páginas/productos.

**Parámetros:**
| Parámetro | Default | Descripción |
|-----------|---------|-------------|
| `type` | `any` | Tipo de post, o `any` para todos |
| `per_page` | `20` | Máximo 50 |
| `page` | `1` | Paginación |
| `status` | `publish` | publish, draft, private, any |

**Cada item devuelve:**
- Datos básicos: `post_id`, `title`, `url`, `slug`, `type`, `status`
- `elementor_page` (bool), `content_editable` (bool)
- `noindex` (bool)
- Bloque `seo`: `seo_title`, `meta_description`, `focus_keyword`, `rank_math_score`, `estimated_score`, `rating`
- Bloque `content`: `word_count`, `char_count`
- Bloque `checks` con los 4 grupos de checks (ver sección 7)
- Bloque `summary`: `total`, `passed`, `errors`
- Array `suggestions` con acciones concretas para mejorar el score

---

### `GET /seo/post/{id}`
Auditoría completa individual. Devuelve la misma estructura que el ítem de `GET /seo` más el array `suggestions` detallado.

---

### `POST /seo/apply/{id}`
Aplica optimizaciones SEO automáticamente escribiendo en los metas de Rank Math.

**Sin body:** Aplica todas las sugerencias seguras generadas por el audit (solo campos Rank Math, nunca `post_content`).

**Con body manual:**
```json
{
  "rank_math_title": "Nuevo título SEO con palabra clave",
  "rank_math_description": "Meta descripción con palabra clave",
  "rank_math_focus_keyword": "palabra clave"
}
```

Campos permitidos: `rank_math_title`, `rank_math_description`, `rank_math_focus_keyword`, `rank_math_og_title`, `rank_math_og_description`, `rank_math_twitter_title`, `rank_math_twitter_description`.

---

### `GET /seo/sitemap`
Audita el sitemap XML del sitio (prueba `sitemap_index.xml`, `sitemap.xml` y `?sitemap=1`).

**Detecta URLs que no deberían estar en el sitemap:**
- Páginas de privacidad, aviso legal, cookies, RGPD, términos
- Páginas de cuenta, login, registro, carrito, checkout, pedidos
- Entradas de ejemplo (Hello World, página de muestra)

**Respuesta:** `sitemap_url`, `total_urls`, `ok_count`, `issues_count`, `issues[]` con url, slug, reason y action recomendada, `ok_urls[]`.

---

### `GET /seo/h1`
Revisión de etiquetas H1 en todas las páginas indexadas (excluye noindex).

**Parámetros:** `type`, `per_page` (máx. 100), `page`.

**Detecta:**
- Páginas sin H1 en el `post_content` (el título del post actúa como H1 en la plantilla — aviso informativo)
- Páginas con múltiples H1 (error — solo debe haber uno)

**Respuesta:** `total`, `total_pages`, `warnings`, `items[]` con id, title, url, type, h1s[], count, status (ok|warning), issues[].

---

### `GET /seo/noindex`
Detecta páginas sensibles o funcionales que están indexadas y deberían ser noindex.

**Verifica:**
- Páginas con slugs sensibles: privacidad, aviso-legal, cookies, RGPD, mi-cuenta, login, carrito, checkout, etc.
- Páginas funcionales de WooCommerce: carrito, checkout, mi cuenta (si WooCommerce está activo)

**Respuesta:**
- `issues_count`: número de problemas encontrados
- `should_be_noindexed[]`: páginas que están indexadas y no deberían — incluye `action` con instrucción exacta para corregirlo en Rank Math
- `correctly_noindexed[]`: páginas que ya tienen noindex correctamente

---

### `GET /seo/404`
Detecta páginas con error 404.

**Parámetros:**
| Parámetro | Default | Opciones |
|-----------|---------|---------|
| `source` | `auto` | `auto`, `rankmath`, `sitemap` |
| `limit` | `100` | Máximo 500 |

**Fuentes de datos:**
1. **`rankmath`:** Consulta la tabla `wp_rank_math_404_logs` del monitor 404 de Rank Math (requiere que esté activado en Rank Math → General → Monitor 404). Devuelve URL, path, número de hits y última vez vista.
2. **`sitemap`:** Si el monitor de Rank Math no está activo o `source=sitemap`, hace crawl del sitemap y comprueba cada URL con `HEAD`. Limitado a los primeros 5 sub-sitemaps, 100 URLs por sitemap.
3. **`auto`:** Intenta Rank Math primero, cae en crawl de sitemap si no está disponible.

---

## 7. Checks de Rank Math replicados

La auditoría replica exactamente los mismos checks que muestra Rank Math en el editor de WordPress.

### SEO Básico (5 checks)
| Check | Meta / Campo analizado | Peso |
|-------|------------------------|------|
| Keyword en título SEO | `rank_math_title` contiene `focus_keyword` | Alto |
| Keyword en meta descripción | `rank_math_description` contiene `focus_keyword` | Alto |
| Keyword al comienzo del contenido | `focus_keyword` en el primer 10% del texto (mín. 100 palabras) | Medio |
| Keyword en el contenido | `post_content` contiene `focus_keyword` | Alto |
| Longitud del contenido | Mínimo 250 caracteres | Medio |

### Adicional (5 checks)
| Check | Descripción | Peso |
|-------|-------------|------|
| Keyword en subencabezados | `focus_keyword` en al menos un H2, H3 o H4 | Medio |
| Keyword en alt de imagen | Al menos una `<img>` tiene `alt` con `focus_keyword` | Medio |
| Densidad de keyword | Entre 0.5% y 2.5% del total de palabras | Medio |
| Tiene enlaces | Al menos un `<a href>` en el contenido | Bajo |
| Keyword configurada | `rank_math_focus_keyword` no está vacío | Alto |

### Legibilidad del título (1 check)
| Check | Descripción | Peso |
|-------|-------------|------|
| Keyword cerca del inicio | `focus_keyword` en la primera mitad del título SEO | Medio |

### Legibilidad del contenido (2 checks)
| Check | Descripción | Peso |
|-------|-------------|------|
| Párrafos cortos | Ningún `<p>` supera las 120 palabras | Bajo |
| Tiene media | Al menos un `<img>`, `<video>`, `<iframe>` o `<figure>` | Bajo |

### Score estimado
Se calcula ponderando los checks: Alto = 3 puntos, Medio = 2, Bajo = 1. `score = (puntos_superados / puntos_totales) × 100`.

---

## 8. Endpoints — WooCommerce

> Todos los endpoints WooCommerce comprueban `class_exists('WooCommerce')` y devuelven error 400 si WooCommerce no está activo. El plugin funciona perfectamente en sitios sin WooCommerce.

### `POST /bulk-attributes`
Actualiza o fusiona atributos de producto (`_product_attributes`) en hasta 20 productos.

**Body:**
```json
[
  {
    "id": 123,
    "attributes": {
      "color": { "name": "Color", "value": "Rojo", "is_visible": 1 }
    }
  }
]
```

---

### `GET /product/{id}/variations`
Lista todas las variaciones de un producto con sus atributos, precio, SKU y campos EAN/GTIN.

**Campos EAN buscados:** `_ean`, `_gtin`, `ean`, `gtin`, `_barcode`, `barcode`, `_global_unique_id`, `_wpm_gtin_code`, `_yith_barcode`.

---

### `POST /bulk-variation-descriptions`
Actualiza la descripción (`post_excerpt`) de hasta 200 variaciones de producto.

**Body:**
```json
[
  { "id": 456, "description": "<p>Descripción de la variación</p>" }
]
```

---

### `POST /bulk-delete-variations`
Elimina hasta 100 variaciones de producto.

**Body:** `{ "ids": [456, 789, 123] }`

---

## 9. Panel de administración

Acceso: **WordPress Admin → Ajustes → AHM Connect**

### Secciones del panel
- **API Key:** muestra la clave actual (seleccionable con click), botón para regenerar manualmente
- **Info del sitio:** versiones de WordPress, PHP, Rank Math, WooCommerce, URL de la API base
- **Ajustes:** rate limit on/off, log de accesos on/off, whitelist de IPs
- **Endpoints:** tabla de referencia de todas las rutas disponibles
- **Log de peticiones:** tabla con las últimas 100 peticiones, botón para borrar

---

## 10. Comportamientos automáticos

### Auto-regeneración de API Key
- **Cuándo:** cada día a medianoche (hora del servidor WordPress)
- **Mecanismo:** WP Cron (`wp_schedule_event` con intervalo `daily`)
- **El evento cron se cancela** al desactivar el plugin
- **Implicación:** las integraciones externas deben obtener la nueva clave diariamente via `GET /info` o desde el panel de administración

### Recálculo de score Rank Math
- Se dispara automáticamente después de actualizar `post_content` o `post_excerpt`
- Usa `do_action('rank_math/head')` y `do_action('rank_math/analytics/recalculate_score')`
- Solo activo si Rank Math está instalado

### Renderizado HTML en atributos WooCommerce
- El filtro `woocommerce_attribute` permite HTML en atributos de texto (sin taxonomía)
- Solo se registra si WooCommerce está activo

### Corrección de cursiva en atributos adicionales
- Inyecta CSS en páginas de producto para evitar que el texto de atributos aparezca en cursiva
- Solo se ejecuta si `is_product()` devuelve true (requiere WooCommerce)

---

## 11. Límites y restricciones

| Operación | Límite |
|-----------|--------|
| Rate limit | 60 peticiones/minuto por IP (configurable) |
| `GET /posts` per_page | Máximo 100 |
| `GET /seo` per_page | Máximo 50 |
| `GET /seo/h1` per_page | Máximo 100 |
| `POST /bulk-update` | Máximo 50 entradas |
| `POST /bulk-content` | Máximo 20 entradas |
| `POST /bulk-attributes` | Máximo 20 productos |
| `POST /recalculate-scores` | Máximo 50 IDs |
| `POST /bulk-variation-descriptions` | Máximo 200 variaciones |
| `POST /bulk-delete-variations` | Máximo 100 variaciones |
| `GET /seo/404` limit | Máximo 500 |
| Log de peticiones | Últimas 100 entradas |

---

## 12. Campos SEO de Rank Math soportados

| Clave API | Meta WordPress | Descripción |
|-----------|---------------|-------------|
| `seo_title` | `rank_math_title` | Título SEO (variable o texto fijo) |
| `seo_description` | `rank_math_description` | Meta descripción |
| `focus_keyword` | `rank_math_focus_keyword` | Palabra clave objetivo |
| `canonical_url` | `rank_math_canonical_url` | URL canónica |
| `robots` | `rank_math_robots` | Array: noindex, nofollow, noarchive… |
| `og_title` | `rank_math_og_title` | Título Open Graph |
| `og_description` | `rank_math_og_description` | Descripción Open Graph |
| `og_image_url` | `rank_math_og_image_url` | URL imagen Open Graph |
| `twitter_title` | `rank_math_twitter_title` | Título Twitter/X |
| `twitter_description` | `rank_math_twitter_description` | Descripción Twitter/X |
| `twitter_card_type` | `rank_math_twitter_card_type` | Tipo de card Twitter |
| `schema_type` | `rank_math_rich_snippet` | Tipo de schema (Article, Product…) |
| `pillar_content` | `rank_math_pillar_content` | Marcar como contenido pilar (on/off) |
| `breadcrumb_title` | `rank_math_breadcrumb_title` | Título en breadcrumbs |

---

## Rating de score Rank Math

| Score | Rating |
|-------|--------|
| 80–100 | `good` |
| 51–79 | `ok` |
| 1–50 | `bad` |
| 0 | `unknown` |

---

*Última actualización: junio 2026 · v3.2.0*
