# Integración WooCommerce + Meilisearch
## Guía técnica para el developer

Documento de referencia completo para instalar, configurar e integrar el motor de búsqueda Meilisearch en un sitio WordPress + WooCommerce existente.

---

## Índice

1. [Visión general del sistema](#1-visión-general-del-sistema)
2. [Requisitos de servidor](#2-requisitos-de-servidor)
3. [Infraestructura Docker](#3-infraestructura-docker)
4. [Variables de entorno](#4-variables-de-entorno)
5. [Instalación del plugin](#5-instalación-del-plugin)
6. [Configuración de Meilisearch (índice)](#6-configuración-de-meilisearch-índice)
7. [Cómo funciona la búsqueda (3 capas)](#7-cómo-funciona-la-búsqueda-3-capas)
8. [Cambios en el frontend de WordPress](#8-cambios-en-el-frontend-de-wordpress)
9. [Panel de administración](#9-panel-de-administración)
10. [Caché con Redis](#10-caché-con-redis)
11. [Sincronización automática del catálogo](#11-sincronización-automática-del-catálogo)
12. [Configuración del servidor en producción](#12-configuración-del-servidor-en-producción)
13. [Pasos post-instalación](#13-pasos-post-instalación)
14. [Operación y mantenimiento](#14-operación-y-mantenimiento)
15. [Resolución de problemas conocidos](#15-resolución-de-problemas-conocidos)

---

## 1. Visión general del sistema

El stack completo funciona así:

```
Usuario → nginx (HTTPS) → WordPress + WooCommerce
                               ↓
                    Plugin wc-meilisearch
                         ↓           ↓
               Meilisearch v1.6    Redis 7
               (motor de búsqueda) (caché de queries)
```

**Qué hace el plugin:**
- Mantiene el catálogo de WooCommerce sincronizado con Meilisearch en tiempo real (hooks automáticos).
- Expone un endpoint HTTP de búsqueda (`ajax-search.php`) que devuelve JSON.
- Inyecta una barra de búsqueda fija en el header de WordPress (sin modificar ningún tema).
- Muestra un autocomplete con imágenes, precios y estado de stock mientras el usuario escribe.
- Provee una página de resultados completa cuando el usuario presiona Enter.
- Cache de resultados en Redis para reducir la carga en búsquedas repetidas.

---

## 2. Requisitos de servidor

### Software obligatorio

| Componente | Versión mínima | Notas |
|---|---|---|
| PHP | 8.0 | Con extensiones `mbstring`, `curl`, `json` |
| WordPress | 6.0 | |
| WooCommerce | 7.0 | |
| Meilisearch | 1.6 | Servidor propio o Docker |
| Redis | 7 | Opcional pero recomendado |
| Composer | 2.x | Para instalar dependencias PHP del plugin |

### Extensiones PHP requeridas

```
php-mbstring   # para manejo de strings UTF-8 (crítico para búsqueda con acentos)
php-curl       # Guzzle lo usa para comunicarse con Meilisearch
php-json       # serialización de documentos
```

### CPU del servidor

> **Importante:** Si se usa MySQL 8.0, el VPS/servidor debe soportar instrucciones **x86-64-v2**.
> Si no las soporta, fijar la imagen a `mysql:8.0.32` (no usar `:latest`).
> Error síntoma: `Fatal glibc error: CPU does not support x86-64-v2`

---

## 3. Infraestructura Docker

El proyecto incluye un `docker-compose.yml` con 6 servicios en la red `search_net`:

| Servicio | Imagen | Puerto expuesto | Descripción |
|---|---|---|---|
| `mysql` | `mysql:8.0.32` | interno | Base de datos WordPress |
| `meilisearch` | `getmeili/meilisearch:v1.6` | `7700` (solo interno en prod) | Motor de búsqueda |
| `redis` | `redis:7-alpine` | interno | Caché de queries |
| `wordpress` | `wordpress:latest` | `8080` | PHP + Apache |
| `wpcli` | `wordpress:cli` | — | WP-CLI para setup/scripts |
| `adminer` | `adminer:latest` | `8081` (solo interno en prod) | GUI de base de datos |

### Estructura de volúmenes

```
./wp-content/          → /var/www/html/wp-content    (código del sitio)
./scripts/             → /scripts                     (scripts WP-CLI)
./scraper/output/      → uploads/panuts_import/       (datos importados)
meili_data             → /meili_data                  (índice Meilisearch persistente)
redis_data             → /data                        (caché Redis persistente)
mysql_data             → /var/lib/mysql               (base de datos persistente)
```

### Levantar el stack

```bash
# Primera vez (o después de cambios)
docker compose up -d

# Ver logs de un servicio específico
docker compose logs -f wordpress
docker compose logs -f meilisearch

# Verificar que Meilisearch responde
curl http://localhost:7700/health
# Respuesta esperada: {"status":"available"}
```

---

## 4. Variables de entorno

Crear el archivo `.env` en la raíz del proyecto con las siguientes variables:

```env
# MySQL
MYSQL_ROOT_PASSWORD=<contraseña-root-segura>
MYSQL_DATABASE=wordpress
MYSQL_USER=wordpress
MYSQL_PASSWORD=<contraseña-db-segura>

# WordPress
WORDPRESS_TABLE_PREFIX=wp_
WP_ADMIN_USER=admin
WP_ADMIN_PASSWORD=<contraseña-admin-segura>
WP_ADMIN_EMAIL=admin@tudominio.com
WP_SITE_URL=https://tudominio.com
WP_SITE_TITLE=Nombre del sitio

# Meilisearch
MEILI_MASTER_KEY=<clave-maestra-minimo-16-chars>
MEILI_NO_ANALYTICS=true

# Redis
REDIS_PASSWORD=
```

> **Seguridad:** `MEILI_MASTER_KEY` debe tener al menos 16 caracteres. Esta clave es la que usa el plugin para autenticarse con Meilisearch. Nunca usar la misma en desarrollo y producción.

### Variables de entorno que lee el plugin directamente

El plugin PHP lee estas variables si no están configuradas desde el panel admin de WordPress:

```
MEILI_HOST        → URL de Meilisearch (ej: http://meilisearch:7700)
MEILI_MASTER_KEY  → Clave de autenticación
REDIS_HOST        → Hostname de Redis (ej: redis)
REDIS_PORT        → Puerto de Redis (ej: 6379)
```

En el `docker-compose.yml`, estas se pasan al contenedor `wordpress`:
```yaml
environment:
  MEILI_HOST: http://meilisearch:7700
  MEILI_MASTER_KEY: ${MEILI_MASTER_KEY}
  REDIS_HOST: redis
  REDIS_PORT: 6379
```

---

## 5. Instalación del plugin

### Estructura de archivos del plugin

```
wp-content/plugins/wc-meilisearch/
├── wc-meilisearch.php              # Bootstrap del plugin
├── ajax-search.php                 # Endpoint HTTP de búsqueda
├── composer.json                   # Dependencias PHP
├── vendor/                         # Dependencias instaladas por Composer
│   ├── meilisearch/meilisearch-php # SDK oficial de Meilisearch
│   ├── predis/predis               # Cliente Redis
│   └── guzzlehttp/guzzle           # HTTP client (requerido por el SDK)
├── includes/
│   ├── class-meilisearch-client.php  # Cliente + caché Redis + estrategia de búsqueda
│   ├── class-product-indexer.php     # Sincronización con WooCommerce
│   └── class-admin-page.php          # Panel de admin
└── assets/
    ├── autocomplete.js             # Widget de autocomplete (vanilla JS)
    ├── search-results.js           # Página de resultados completa
    └── admin.js                    # Barra de progreso de reindexado
```

### Instalar dependencias PHP (Composer)

Ejecutar **dentro del contenedor WordPress** (no en el host):

```bash
docker compose exec wordpress bash -c '
  curl -sS https://getcomposer.org/installer | php && \
  cd /var/www/html/wp-content/plugins/wc-meilisearch && \
  php /var/www/html/composer.phar install --no-dev --optimize-autoloader
'
```

> **Nota importante:** No correr `composer install` desde el host si PHP del host tiene versión diferente. Siempre dentro del contenedor para garantizar compatibilidad.

### Activar el plugin en WordPress

```bash
# Vía WP-CLI
docker compose exec wpcli wp plugin activate wc-meilisearch --path=/var/www/html

# O desde el panel: Plugins → WC Meilisearch → Activar
```

---

## 6. Configuración de Meilisearch (índice)

El plugin configura automáticamente el índice `wc_products` al activarse. Esta configuración se aplica cada vez que se instancia `MeilisearchClient`.

### Nombre del índice

```
wc_products
```

### Atributos de búsqueda (searchableAttributes)

Ordenados por peso de relevancia (primero = más importante):

```
name         → Nombre del producto (mayor peso)
sku          → Código SKU
categories   → Categorías del producto
tags         → Tags del producto
attr_marca   → Atributo "marca" de WooCommerce
attr_pais    → Atributo "pais"
attr_region  → Atributo "region"
attr_tipo    → Atributo "tipo"
attr_varietal → Atributo "varietal"
name_alt     → Nombre con primer carácter de cada palabra eliminado (menor peso, solo para fallback)
```

> `description` está **desactivado** por defecto (comentado en el código) para evitar falsos positivos por texto largo.

### Atributos filtrables (filterableAttributes)

```
in_stock, categories, price, stock_status,
attr_marca, attr_pais, attr_region, attr_tipo, attr_varietal, attr_volumen,
product_priority
```

### Atributos ordenables (sortableAttributes)

```
price, name, product_priority
```

### Reglas de ranking (rankingRules)

```
words → typo → proximity → attribute → product_priority:desc → sort → exactness
```

La regla `product_priority:desc` coloca primero los productos bebibles (vinos, licores, etc.) y al final los accesorios (copas, decantadores), que tienen `product_priority = 0`.

### Tolerancia a typos (typoTolerance)

```json
{
  "enabled": true,
  "minWordSizeForTypos": {
    "oneTypo": 3,
    "twoTypos": 7
  }
}
```

Los valores por defecto de Meilisearch son 5 y 9. Se reducen para que palabras cortas como "ron" también se beneficien de la tolerancia a errores.

### Documento indexado por producto

Cada producto de WooCommerce se almacena con esta estructura:

```json
{
  "id": 123,
  "name": "Santa Julia Malbec Reserva",
  "name_alt": "anta ulia albec eserva",
  "sku": "SJM-001",
  "description": "Vino tinto de Mendoza...",
  "price": 49.90,
  "stock_status": "instock",
  "in_stock": true,
  "categories": ["Vinos Tintos", "Malbec"],
  "tags": ["argentina", "mendoza"],
  "image": "https://sitio.com/wp-content/uploads/.../thumbnail.jpg",
  "url": "https://sitio.com/producto/santa-julia-malbec/",
  "attr_marca": "Santa Julia",
  "attr_pais": "Argentina",
  "attr_region": "Mendoza",
  "attr_tipo": "Tinto",
  "attr_varietal": "Malbec",
  "attr_volumen": "750ml",
  "product_priority": 1
}
```

> **Campo `name_alt`:** Se genera automáticamente a partir del nombre del producto, eliminando el primer carácter de cada palabra. Esto es parte del sistema de tolerancia a errores en el primer carácter (ver sección 7).

---

## 7. Cómo funciona la búsqueda (3 capas)

El motor implementa una estrategia en cascada para manejar typos avanzados, incluyendo errores en el primer carácter (limitación nativa de Meilisearch).

### Capa 1 – Búsqueda estándar

Búsqueda normal sobre todos los campos configurados con typo tolerance estándar de Meilisearch.

```
Query: "malbec mendoza" → resultados directos
```

### Capa 2 – Split de palabras compuestas

Si la Capa 1 no devuelve resultados y el query tiene palabras de 8+ caracteres sin espacios, se prueba dividirlas en todos los puntos posibles (con mínimo 4 chars en cada mitad):

```
Query: "santajulia" →
  prueba: "sant ajulia"
  prueba: "santa julia"  ← Meilisearch encuentra resultados ✓
  prueba: "santaj ulia"
  ...
```

Una vez que una división devuelve resultados, se detiene.

**Regla de mínimo 4 caracteres por mitad:** evita falsos positivos. "san tajulia" (solo 3 chars en "san") coincide con demasiados productos por typo tolerance.

### Capa 3 – Strip del primer carácter (name_alt)

Meilisearch tiene una limitación de motor: **no aplica typo tolerance al primer carácter** de cada palabra. Esto hace que "zanta julia" (z en lugar de s) no encuentre resultados.

**Solución:** En la indexación, cada producto guarda un campo extra `name_alt` con el primer carácter de cada palabra eliminado:

```
"Santa Julia Malbec" → name_alt: "anta ulia albec"
```

En la búsqueda, cuando las capas 1 y 2 fallan:

```
Query: "zanta jul"
  → strip primer char de cada palabra → "anta ul"
  → buscar "anta ul" en name_alt solamente
  → Meilisearch encuentra "anta ulia albec" con typo tolerance normal
  → devuelve el producto "Santa Julia Malbec" ✓
```

Esta técnica funciona para **cualquier marca o producto** del catálogo, sin configuración hardcodeada.

### Casos de prueba verificados

| Query del usuario | Resultado esperado | Capa que resuelve |
|---|---|---|
| `santa julia` | Santa Julia Malbec | Capa 1 |
| `santajuli` | Santa Julia Malbec | Capa 2 (split) |
| `santajullia` | Santa Julia Malbec | Capa 2 (split + typo) |
| `zanta jul` | Santa Julia Malbec | Capa 3 (name_alt) |
| `zuccardi` | Productos Zuccardi | Capa 1 |
| `malbek` | Productos Malbec | Capa 1 (typo) |

---

## 8. Cambios en el frontend de WordPress

### Barra de búsqueda fija en el header

El plugin inyecta una barra de búsqueda fija en la parte superior de **todas las páginas** del sitio, sin modificar ningún template del tema activo. Usa el hook `wp_body_open`:

```php
add_action( 'wp_body_open', 'wcm_render_header_searchbar' );
```

**Requisito del tema:** El tema debe llamar a `wp_body_open()` en su template. Los temas modernos de WordPress lo hacen por defecto (Twenty Twenty-Three, Twenty Twenty-Four, Twenty Twenty-Five, WooCommerce Storefront, etc.).

**Ajuste de padding:** El plugin agrega automáticamente `padding-top: 56px` al `<body>` para que el contenido no quede debajo de la barra.

### Estilos de la barra de búsqueda

Los estilos están inline en el plugin (no requieren un archivo CSS separado). Principales clases CSS:

```css
#wcm-header-bar     /* contenedor fijo, z-index 99999, fondo oscuro #1a1a1a */
#wcm-header-inner   /* inner container max-width 700px centrado */
#wcm-header-input   /* input de búsqueda, fondo #2d2d2d, texto #f0f0f0 */
```

**Para personalizar colores:** editar la función `wcm_render_header_searchbar()` en `wc-meilisearch.php`.

### Widget de autocomplete (autocomplete.js)

Se carga en **todas las páginas** del frontend. Se adjunta automáticamente a:
- `input[type="search"]`
- `input[name="s"]`
- `.search-field`

**Comportamiento:**
- Mínimo 2 caracteres para activar la búsqueda.
- Debounce de 150ms (no dispara en cada tecla, espera que el usuario pause).
- Muestra hasta 20 resultados en el dropdown con imagen, nombre y precio.
- Botón fijo "Ver todos los resultados" al pie del dropdown → redirige a la página de resultados.
- Navegación con flechas ↑↓ del teclado y Enter para ir al producto.
- En móvil: el dropdown ajusta su altura dinámicamente con `visualViewport` para que no quede tapado por el teclado virtual.
- Cancela requests anteriores si el usuario sigue escribiendo (AbortController).

**Formato de precio:** `S/. 49.90` (formato peruano con `Intl.NumberFormat('es-PE')`). Si se usa para otra moneda, editar la función `formatPrice()` en `autocomplete.js`.

### Página de resultados (search-results.js + template)

Cuando el usuario presiona Enter, se redirige a `/?s=query`. El plugin intercepta esta ruta con un template propio:

```php
add_filter( 'template_include', 'wcm_search_template' );
```

La página de resultados muestra una grilla de productos con hasta 96 resultados. El script `search-results.js` hace un fetch a `ajax-search.php` con `limit=96`.

### Endpoint de búsqueda

```
GET /wp-content/plugins/wc-meilisearch/ajax-search.php?q=<query>&limit=<n>
```

**Respuesta JSON:**
```json
{
  "results": [
    {
      "id": 123,
      "name": "Santa Julia Malbec",
      "price": 49.90,
      "image": "https://...",
      "url": "https://...",
      "categories": ["Vinos Tintos"],
      "stock_status": "instock"
    }
  ],
  "processingTimeMs": 2,
  "cached": false
}
```

**Rate limiting:** Máximo 1 request cada 100ms por IP. Responde 429 si se excede.

---

## 9. Panel de administración

Ubicación en WordPress: **WooCommerce → Meilisearch**

### Configuración disponible

| Campo | Descripción | Valor por defecto |
|---|---|---|
| Meilisearch Host | URL completa del servidor Meilisearch | `http://meilisearch:7700` |
| Master / Search Key | Clave de autenticación | (vacío, lee de env) |
| Redis Host | Hostname del servidor Redis | `redis` |
| Redis Port | Puerto de Redis | `6379` |

> Si los campos quedan vacíos, el plugin lee las variables de entorno del servidor.

### Herramientas disponibles

**Probar Conexión:** Hace un health check a Meilisearch y muestra si la conexión es exitosa.

**Reindexar Productos:** Borra el índice completo y lo reconstruye con todos los productos publicados. Muestra una barra de progreso. Procesa en batches de 50 productos.

---

## 10. Caché con Redis

### Comportamiento

- **TTL:** 300 segundos (5 minutos).
- **Clave de caché:** `meili_search_<md5(query + opciones)>`.
- **Solo se cachean queries con resultados.** Si una búsqueda no devuelve nada (para permitir que los fallbacks intenten en la próxima petición), no se guarda en caché.
- **Invalidación automática:** En cada `upsert_documents`, `delete_document` y `clear_index`, se borran todas las keys `meili_search_*`.

### Si Redis no está disponible

El plugin continúa funcionando sin caché. El error se registra en el log de PHP pero no interrumpe la búsqueda.

---

## 11. Sincronización automática del catálogo

El plugin escucha los siguientes hooks de WooCommerce para mantener el índice actualizado:

### Indexar / actualizar producto

```
woocommerce_new_product      → al crear un producto nuevo
woocommerce_update_product   → al editar y guardar un producto
save_post_product            → captura transiciones de estado (borrador → publicado, etc.)
```

Si el producto pasa a estado distinto de `publish`, se elimina del índice.

### Eliminar producto del índice

```
woocommerce_delete_product   → al eliminar permanentemente
woocommerce_trash_product    → al mover a la papelera
before_delete_post           → eliminación directa de post
```

### Reindexado programado

El plugin registra un cron de WordPress que ejecuta un reindexado completo una vez al día:

```php
wp_schedule_event( time(), 'daily', 'wcm_scheduled_reindex' );
```

Esto garantiza consistencia aunque algún hook falle.

---

## 12. Consideraciones para entorno de producción

Esta guía cubre el entorno local/desarrollo. Para producción, tener en cuenta:

- Usar un reverse proxy (nginx, Caddy, Traefik) para exponer WordPress en los puertos 80/443 y terminar SSL.
- **Nunca** exponer el puerto 7700 de Meilisearch a internet — solo debe ser accesible dentro de la red Docker.
- **Nunca** exponer el puerto 8081 de Adminer a internet.
- Configurar SSL con Let's Encrypt u otro proveedor de certificados.
- Asegurarse de que `MEILI_MASTER_KEY` en producción sea diferente al valor de desarrollo y tenga al menos 16 caracteres.
- Revisar la configuración del firewall del VPS para bloquear los puertos internos.

---

## 13. Pasos post-instalación

### 1. Instalar Composer dentro del contenedor

```bash
docker compose exec wordpress bash -c '
  curl -sS https://getcomposer.org/installer | php && \
  cd /var/www/html/wp-content/plugins/wc-meilisearch && \
  php /var/www/html/composer.phar install --no-dev --optimize-autoloader
'
```

### 2. Configurar WordPress y WooCommerce (WP-CLI)

```bash
# Instalar WordPress
docker compose exec wpcli wp core install \
  --url="${WP_SITE_URL}" \
  --title="${WP_SITE_TITLE}" \
  --admin_user="${WP_ADMIN_USER}" \
  --admin_password="${WP_ADMIN_PASSWORD}" \
  --admin_email="${WP_ADMIN_EMAIL}" \
  --path=/var/www/html

# Instalar y activar WooCommerce
docker compose exec wpcli wp plugin install woocommerce --activate --path=/var/www/html

# Activar el plugin de Meilisearch
docker compose exec wpcli wp plugin activate wc-meilisearch --path=/var/www/html

# Desactivar el modo "Coming Soon" de WooCommerce (activo por defecto en instalaciones nuevas)
docker compose exec wpcli wp option update woocommerce_coming_soon 'no' --path=/var/www/html
docker compose exec wpcli wp option update woocommerce_store_pages_only 'no' --path=/var/www/html
```

### 3. Realizar el primer reindexado

Desde el panel de WordPress: **WooCommerce → Meilisearch → Reindexar Productos**

O vía WP-CLI:

```bash
docker compose exec wpcli wp eval '\WCMeilisearch\ProductIndexer::instance()->reindex_all();' --path=/var/www/html
```

### 4. Verificar que el buscador funciona

```bash
curl "https://tudominio.com/wp-content/plugins/wc-meilisearch/ajax-search.php?q=malbec"
```

Respuesta esperada: JSON con `results` no vacío.

---

## 14. Operación y mantenimiento

### Reindexado manual

Solo necesario si:
- El índice de Meilisearch se corrompió o se borró accidentalmente.
- Se hicieron cambios masivos al catálogo sin pasar por WooCommerce (ej: importación directa a DB).
- Se modificó la configuración del índice (atributos de búsqueda, filtros, etc.).

```bash
# Desde el panel: WooCommerce → Meilisearch → Reindexar Productos
# O vía WP-CLI (ver arriba)
```

### Ver logs del plugin

```bash
# Errores de Meilisearch/Redis aparecen en el log de WordPress/PHP
docker compose logs wordpress | grep '\[WCMeilisearch\]'
```

### Verificar conexión con Meilisearch

```bash
# Health check directo
curl http://localhost:7700/health

# Desde el panel admin: WooCommerce → Meilisearch → Probar Conexión
```

### Monitorear el índice

```bash
# Ver estadísticas del índice
curl -H "Authorization: Bearer <MEILI_MASTER_KEY>" \
  http://localhost:7700/indexes/wc_products/stats
```

### Backup de datos

```bash
# Meilisearch: el volumen meili_data contiene todo el índice
docker run --rm -v search_meili_data:/data -v $(pwd):/backup \
  alpine tar czf /backup/meili_backup.tar.gz /data

# MySQL: dump estándar
docker compose exec mysql mysqldump -u root -p${MYSQL_ROOT_PASSWORD} wordpress > backup.sql
```

---

## 15. Resolución de problemas conocidos

### Plugin no aparece o da error al activar

**Causa:** Falta el directorio `vendor/` (Composer no se ha ejecutado).
**Fix:** Ejecutar `composer install` dentro del contenedor (ver sección 5).

### Búsquedas no devuelven resultados

**Verificar:**
1. Conexión a Meilisearch: `curl http://localhost:7700/health`
2. Que el índice tiene documentos: ver estadísticas (sección 14)
3. Realizar un reindexado desde el panel admin

### El dropdown de autocomplete no aparece

**Verificar:**
1. Que el script `autocomplete.js` se está cargando (F12 → Network)
2. Que `wcmSearch` está definido en el JS (F12 → Console: `console.log(wcmSearch)`)
3. Que `ajax-search.php` devuelve 200 (probar la URL directamente)

### La barra de búsqueda no aparece en el tema

**Causa:** El tema no llama a `wp_body_open()`.
**Fix:** Agregar `<?php wp_body_open(); ?>` justo después de la etiqueta `<body>` en el `header.php` del tema. O usar un tema que lo soporte nativamente.

### Error `Psr18ClientDiscovery NotFoundException`

**Causa:** Falta `guzzlehttp/guzzle` en el vendor.
**Fix:** Verificar que `composer.json` incluye `"guzzlehttp/guzzle": "^7.0"` y re-ejecutar `composer install`.

### `Call to a member function toArray() on array` en multiSearch

**Causa:** Incompatibilidad entre el SDK de Meilisearch PHP y la versión instalada.
**Fix ya aplicado:** El plugin usa búsquedas secuenciales en lugar de `multiSearch()`. Si aparece, verificar la versión del SDK en `composer.json` (`meilisearch/meilisearch-php: ^1.6`).

### MySQL no arranca en el VPS

**Causa:** La imagen `mysql:latest` requiere CPU x86-64-v2.
**Fix:** Usar `mysql:8.0.32` en `docker-compose.yml`.

### Precios aparecen como S/. 0.00

**Causa:** El scraper o la importación no está leyendo correctamente los precios de WooCommerce.
**Verificar:** Que los productos en WooCommerce tienen precio en el campo "Precio habitual" de WooCommerce (no en un metadato personalizado).

### Cache Redis no funciona

El plugin continúa funcionando sin Redis. Verificar:
1. `docker compose logs redis` para errores
2. Que el hostname `redis` es accesible desde el contenedor de WordPress
3. Que el puerto 6379 no está bloqueado entre contenedores

---

## Resumen de archivos clave para el developer

| Archivo | Qué modifica / para qué sirve |
|---|---|
| `wc-meilisearch.php` | Bootstrap, barra de búsqueda, enqueue de scripts |
| `includes/class-meilisearch-client.php` | Config del índice, estrategia de búsqueda en 3 capas, caché Redis |
| `includes/class-product-indexer.php` | Estructura del documento indexado, hooks de WooCommerce, reindexado |
| `includes/class-admin-page.php` | Panel de administración en WordPress |
| `ajax-search.php` | Endpoint HTTP de búsqueda (rate limiting, seguridad, respuesta JSON) |
| `assets/autocomplete.js` | Widget JS de autocomplete (UI, debounce, navegación teclado, mobile) |
| `assets/search-results.js` | Página de resultados completa |
| `composer.json` | Dependencias PHP |
| `docker-compose.yml` | Stack de servicios |
| `.env` | Variables de entorno (no commitear con valores reales) |
