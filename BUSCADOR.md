# Buscador Meilisearch — Documentación Completa

> Documento de referencia para continuar el desarrollo en el repositorio principal (`Panuts-com/dev-panuts`, rama `396-mejoras-en-buscador-principal`).
> Última actualización: 2026-03-14

---

## 1. Visión general

El buscador reemplaza el buscador nativo de WooCommerce con **Meilisearch** como motor de búsqueda de texto completo, tolerante a errores tipográficos y con respuesta en tiempo real. Se expone como un plugin de WordPress (`wc-meilisearch`) que:

- Mantiene un índice sincronizado con el catálogo de WooCommerce.
- Ofrece un endpoint AJAX propio (`ajax-search.php`) para el autocompletado.
- Inyecta en el frontend un trigger de búsqueda + modal lightbox (sin tocar plantillas del tema).
- Incluye una página de administración en WP Admin para configurar conexiones y reindexar.

---

## 2. Infraestructura (servidor `search.bttr.pe`)

| Servicio      | Descripción                                                   |
|---------------|---------------------------------------------------------------|
| **Meilisearch** | Motor de búsqueda local. Corre en `http://localhost:7700`. Index: `wc_products`. |
| **Redis**     | Caché de resultados de búsqueda. TTL 5 minutos (300 s). Docker container `wc-redis`. |
| **WordPress + WooCommerce** | Instalación de prueba en `/var/www/search.bttr.pe`. |
| **Nginx**     | Proxy HTTPS. Certificado Let's Encrypt. |

### Variables de entorno / opciones de WP

| Opción WP (`get_option`)   | Variable de entorno fallback | Valor por defecto          |
|---------------------------|------------------------------|---------------------------|
| `wcm_meili_host`           | `MEILI_HOST`                 | `http://meilisearch:7700` |
| `wcm_meili_key`            | `MEILI_MASTER_KEY`           | *(vacío)*                 |
| `wcm_redis_host`           | `REDIS_HOST`                 | `redis`                   |
| `wcm_redis_port`           | `REDIS_PORT`                 | `6379`                    |

---

## 3. Estructura del plugin

```
wp-content/plugins/wc-meilisearch/
├── wc-meilisearch.php              # Bootstrap, hooks frontend, enqueue scripts
├── ajax-search.php                 # Endpoint AJAX directo (sin admin-ajax.php)
├── composer.json                   # Dependencias: meilisearch/meilisearch-php, predis/predis
├── includes/
│   ├── class-meilisearch-client.php   # Motor de búsqueda con capas de fallback
│   ├── class-product-indexer.php      # Sincronización WooCommerce → Meilisearch
│   └── class-admin-page.php           # Página WP Admin: configuración + reindex
├── assets/
│   ├── autocomplete.js             # UI del autocompletado (dropdown)
│   └── search-modal.js             # Lightbox modal + chips de categorías
└── templates/
    └── search-results.php          # Página de resultados completos (?s=...)
```

### Repositorio de desarrollo (sandbox)
- **GitHub:** `jmondragonc/search`
- **Branch principal:** `main`
- **Deploy:** push a `main` + `git pull` en el servidor.

### Repositorio principal (producción)
- **GitHub:** `Panuts-com/dev-panuts`
- **Branch activo:** `396-mejoras-en-buscador-principal` → PR abierto a `dev`
- **Archivos modificados en el PR:**
  - `wp-content/plugins/wc-meilisearch/includes/class-meilisearch-client.php`
  - `wp-content/plugins/wc-meilisearch/includes/class-product-indexer.php`
  - `wp-content/plugins/wc-meilisearch/includes/class-admin-page.php`
  - `wp-content/plugins/wc-meilisearch/wc-meilisearch.php`
  - `wp-content/themes/panuts/inc/capi.php`

---

## 4. Documento indexado en Meilisearch

Cada producto se indexa con la siguiente forma (generado en `ProductIndexer::build_document()`):

```json
{
  "id": 123,
  "name": "Santa Julia Malbec Del Mercado 750 ml.",
  "name_alt": "anta ulia albec el ercado 50 l.",
  "name_phonetic": "SNT JL MLBK TL MRKT 750 ML",
  "sku": "SJ-MAL-750",
  "description": "Vino tinto...",
  "price": 45.00,
  "stock_status": "instock",
  "in_stock": true,
  "categories": ["Tintos", "Vinos y Espumantes"],
  "tags": [],
  "image": "https://...",
  "url": "https://...",
  "attr_marca": "Santa Julia",
  "attr_pais": "Argentina",
  "attr_region": "Mendoza",
  "attr_tipo": "Tinto",
  "attr_varietal": "Malbec",
  "attr_volumen": "750 ml.",
  "product_priority": 1
}
```

### Campos especiales

| Campo           | Cómo se genera                                                                                   | Para qué sirve                          |
|-----------------|---------------------------------------------------------------------------------------------------|-----------------------------------------|
| `name_alt`      | Cada palabra > 2 chars: se elimina la primera letra. `"Santa Julia"` → `"anta ulia"`            | Fallback para errores en el primer carácter (Capas 3 y 5) |
| `name_phonetic` | `metaphone()` PHP en cada palabra. `"Johnnie Walker"` → `"JN WLKR"`                             | Fallback fonético (Capa 4)              |
| `product_priority` | `1` = vinos/licores, `0` = accesorios/copas (detectado por categoría con regex)               | Ranking: productos bebibles aparecen antes |

---

## 5. Configuración del índice Meilisearch

Configurado automáticamente en `MeilisearchClient::ensure_index()` al iniciar el plugin:

**Campos buscables** (en orden de relevancia):
```
name → sku → categories → tags → attr_marca → attr_pais → attr_region
→ attr_tipo → attr_varietal → name_alt → name_phonetic
```
> `name_alt` y `name_phonetic` van al final para que solo "ganen" en las búsquedas de fallback donde se les apunta explícitamente con `attributesToSearchOn`.

**Tolerancia a errores tipográficos:**
```
oneTypo:  3 chars mínimo  (activa 1 corrección)
twoTypos: 7 chars mínimo  (activa 2 correcciones)
```

**Ranking rules:**
```
words → typo → proximity → attribute → product_priority:desc → sort → exactness
```

**Campos filtrables:** `in_stock`, `categories`, `price`, `stock_status`, `attr_*`, `product_priority`
**Campos ordenables:** `price`, `name`, `product_priority`
**maxTotalHits:** 200

---

## 6. Estrategia de búsqueda — 5 capas

Todas las capas son genéricas (sin lógica hardcodeada de marcas/productos).

```
Query usuario
     │
     ▼
┌─────────────────────────────────────────────────────┐
│  Capa 1: Búsqueda estándar                          │
│  Campos: todos los searchableAttributes             │
│  Ej: "Santa Julia" → resultados directos            │
└─────────────────────────────────────────────────────┘
     │ 0 resultados
     ▼
┌─────────────────────────────────────────────────────┐
│  Capa 2: Split de palabras compuestas               │
│  Palabra ≥8 chars sin espacio → prueba todas las    │
│  particiones posibles (i=4 hasta len-4)             │
│  Ej: "santajulia" → "santa julia", "santaj ulia"…  │
└─────────────────────────────────────────────────────┘
     │ 0 resultados
     ▼
┌─────────────────────────────────────────────────────┐
│  Capa 3: Strip primer carácter → name_alt           │
│  Solo si la query tiene espacios (no compuestos).   │
│  Elimina el 1er char de cada palabra > 2 chars.     │
│  Ej: "zanta julia" → "anta ulia" → busca en name_alt│
│  Compensa el límite de Meilisearch: no aplica typo  │
│  al 1er carácter de una palabra.                    │
└─────────────────────────────────────────────────────┘
     │ 0 resultados
     ▼
┌─────────────────────────────────────────────────────┐
│  Capa 4: Matching fonético → name_phonetic          │
│  PHP metaphone() sobre cada palabra de la query.    │
│  Ej: "jhonny" → "JN" → busca "JN" en name_phonetic │
│  → encuentra "Johnnie Walker" (stored "JN WLKR")   │
│  También funciona: "joni" → "JN" → mismo resultado │
└─────────────────────────────────────────────────────┘
     │ 0 resultados
     ▼
┌─────────────────────────────────────────────────────┐
│  Capa 5: Compound-split + strip primer char         │
│  Combina Capas 2 y 3 para queries compuestos con    │
│  error en el 1er carácter.                          │
│  Palabra ≥7 chars → split (i=4 hasta len-3) →       │
│  strip primer char de cada parte → busca name_alt.  │
│  Ej: "zantajul" → split("zanta","jul") →            │
│       strip("anta","ul") → "anta ul" en name_alt   │
│  Meilisearch hace prefix en última palabra:         │
│       "ul*" → "ulia" → Santa Julia ✓               │
└─────────────────────────────────────────────────────┘
     │
     ▼
  Resultado final (con caché Redis 5 min)
```

### Por qué la Capa 3 excluye palabras compuestas (sin espacio)

Meilisearch tiene tolerancia a 2 typos para palabras ≥7 chars. Si el query es `"zantajul"` (compuesto), strippear el primer char da `"antajul"` — un token único que queda a 2 edits de `"antamilano"` (nombre de otro producto). Eso genera falsos positivos. Por eso los compuestos van a la Capa 5, que los divide antes de stripear.

---

## 7. Endpoint AJAX

**URL:** `GET /wp-content/plugins/wc-meilisearch/ajax-search.php`

| Parámetro | Tipo   | Descripción                               |
|-----------|--------|-------------------------------------------|
| `q`       | string | Término de búsqueda (mín. 2 chars)        |
| `cat`     | string | Filtrar por categoría exacta (opcional)   |
| `limit`   | int    | Máximo de resultados (por defecto 8, máx 100) |

**Respuesta:**
```json
{
  "results": [
    { "id": 123, "name": "...", "price": 45.0, "image": "...", "url": "...", "categories": [...], "stock_status": "instock" }
  ],
  "processingTimeMs": 2,
  "cached": false
}
```

**Rate limiting:** 1 request / 100ms por IP (vía WP transients).
**Seguridad:** `sanitize_text_field` + `wp_unslash` en todos los inputs.

---

## 8. Frontend — UI de búsqueda

### Modos de operación

El plugin soporta dos modos configurables desde WP Admin:

| Modo          | Opción `wcm_enable_lightbox` | Comportamiento                                               |
|---------------|------------------------------|--------------------------------------------------------------|
| **Lightbox**  | `yes` (por defecto)           | Backdrop oscuro con blur + modal centrado flotante           |
| **Clásico**   | `no`                          | Sin backdrop, el modal aparece directamente bajo el trigger   |

En ambos modos el HTML/CSS del modal es el mismo. Solo cambia la clase `wcm-is-classic` en el overlay.

### Chips de categorías

Las chips (Todos, Ofertas, Vinos, Tintos…) se cargan **dinámicamente** desde la API de WooCommerce. Solo se muestran categorías de tipo `product_cat` con al menos un producto. No están hardcodeadas. Se pueden filtrar resultados por cualquier categoría.

### Autocompletado (dropdown)

Archivo: `assets/autocomplete.js`

- Se activa a partir de 2 caracteres escritos.
- Debounce de 220ms para no disparar en cada tecla.
- Muestra imagen, nombre, precio y estado de stock.
- Botón "Ver todos los resultados" → redirige a la página de resultados completos.
- **Mobile:** `max-height: 180px` en pantallas ≤600px + `visualViewport` API para ajustar dinámicamente cuando abre el teclado del móvil (el botón siempre queda visible).

### Inyección en el tema

El plugin usa `wp_body_open` y `wp_footer` para inyectarse en cualquier tema sin modificar plantillas:

```php
add_action( 'wp_body_open', 'wcm_render_header_searchbar' );  // Trigger button
add_action( 'wp_footer',    'wcm_render_lightbox_modal' );    // Modal HTML
```

En el tema Panuts, el trigger bar se integra visualmente dentro del header existente.

---

## 9. Página de administración

**Ruta:** WP Admin → *WC Meilisearch*

| Sección              | Qué hace                                                                 |
|----------------------|--------------------------------------------------------------------------|
| Configuración        | Host Meilisearch, API Key, Host Redis, Puerto Redis, Modo (lightbox/clásico) |
| Reindexar productos  | Barra de progreso, indexa en lotes de 50 productos. Limpia el índice y reconstruye desde cero. **Necesario tras activar el plugin o añadir `name_phonetic`**. |
| Estado               | Muestra si Meilisearch y Redis están accesibles.                         |

---

## 10. Sincronización del índice

El índice se mantiene sincronizado automáticamente mediante hooks de WooCommerce:

| Evento                        | Acción                         |
|-------------------------------|--------------------------------|
| Producto publicado / guardado | Upsert del documento           |
| Producto a borrador / papelera| Eliminar del índice            |
| Producto eliminado            | Eliminar del índice            |

El reindex manual desde Admin es necesario en estos casos:
1. Primera activación del plugin.
2. Después de añadir un campo nuevo al documento (ej: `name_phonetic` se añadió en el PR actual → **reindexar en producción tras el deploy**).
3. Después de cambiar los `searchableAttributes` o `rankingRules`.

---

## 11. Caché Redis

- Clave: `meili_search_` + `md5(query + opciones)`
- TTL: 300 segundos (5 minutos)
- Solo se cachea si hay resultados (las queries sin resultado no se cachean).
- Se invalida completamente al hacer upsert o delete de un producto.
- Si Redis no está disponible, el plugin funciona sin caché (degradación silenciosa).

**Para limpiar manualmente:**
```bash
redis-cli FLUSHDB
```

---

## 12. Página de resultados completos

**URL:** `/?s=<query>` (o `/buscar/?s=<query>` según la configuración de WordPress)

Archivo: `templates/search-results.php`

Muestra la grilla completa de resultados con paginación. Se activa mediante el filtro `template_include` solo cuando la query tiene el parámetro `?s=`.

---

## 13. Deploy en producción (panuts)

### Pasos tras fusionar el PR a `dev`:

1. **Hacer deploy del código** (pull o CI/CD habitual del proyecto).
2. **Reindexar**: WP Admin → WC Meilisearch → "Reindexar todos los productos".
   - Esto es obligatorio porque el campo `name_phonetic` no existía en documentos indexados previamente.
3. **Verificar** en el buscador:
   - `"jhonny"` o `"joni"` → debe mostrar Johnnie Walker
   - `"zantajul"` → debe mostrar Santa Julia
   - `"santajulia"` → debe mostrar Santa Julia
   - `"santa julia malbec"` → debe mostrar Santa Julia Malbec (búsqueda normal sin fallback)

### Dependencias PHP requeridas en producción:
- `composer install` dentro del directorio del plugin (si no se hace en CI).
- PHP ≥8.0, extensión `mbstring` activa (usada en `mb_strlen`, `mb_substr`, `mb_strtolower`).
- Función `metaphone()` disponible (built-in en PHP, no requiere extensión extra).

---

## 14. Historial de decisiones técnicas relevantes

| Decisión | Razón |
|----------|-------|
| **No usar `admin-ajax.php`** para el AJAX | Latencia: `admin-ajax.php` carga todo WordPress + plugins. El endpoint directo `ajax-search.php` es ~5x más rápido. |
| **`name_alt` con primer carácter eliminado** | Meilisearch tiene un límite de motor: no aplica tolerancia a typos en el primer carácter de una palabra. `name_alt` es un workaround genérico que convierte ese typo en un typo "interior". |
| **Capa 3 excluye queries compuestos** | Queries como `"antajul"` (compuesto sin espacios) quedaban dentro de 2 edits de `"antamilano"`, generando falsos positivos. Los compuestos se derivan a Capa 5. |
| **Capa 5 usa `len-3` en vez de `len-4`** | Al hacer split de `"zantajul"` (8 chars), el rango `len-4=4` solo permite `i=4` → genera `"zant"+"ajul"`. Con `len-3=5`, también genera `i=5` → `"zanta"+"jul"` → strip → `"anta"+"ul"` → prefix `"ul*"` → `"ulia"` (Santa Julia). |
| **Prefix en última palabra de Meilisearch** | Meilisearch aplica búsqueda por prefijo automáticamente en el último token del query. Por eso `"ul"` (2 chars, sin typo tolerance) encuentra `"ulia"`. |
| **No usar `matchingStrategy: 'last'`** | Esa estrategia generó demasiados falsos positivos: al no encontrar el segundo token, caía a buscar solo el primero (`"anta"`) y encontraba cualquier producto con esa secuencia en `name_alt`. |
| **`product_priority` en ranking rules** | Sin él, copas y accesorios aparecían mezclados con vinos para búsquedas genéricas. Con la regla `product_priority:desc`, los vinos/licores siempre van primero. |

---

## 15. Lo que falta / mejoras pendientes

- [ ] **Búsqueda por varietal/maridaje en lenguaje natural**: "vino para carne" → no busca por contenido de descripción todavía (campo `description` desactivado en `searchableAttributes` por performance).
- [ ] **Sinónimos en Meilisearch**: para marcas con múltiples escrituras habituales que metaphone no captura bien (ej. marcas en idiomas no latinos).
- [ ] **Analytics de búsquedas**: loggear queries sin resultados para detectar gaps del catálogo.
- [ ] **Facets / filtros en resultados completos**: la página de resultados actualmente es una grilla simple sin filtros laterales por precio, varietal, país, etc.
- [ ] **Activar campo `description`** en `searchableAttributes` con peso bajo, para encontrar productos por características descriptivas.
