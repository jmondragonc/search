# WooCommerce + Meilisearch – Motor de búsqueda inteligente

Stack local en Docker (OrbStack/macOS):
**WordPress + WooCommerce → Meilisearch v1.6 + Redis 7 + Scraper panuts.com**

---

## Requisitos

| Herramienta | Versión mínima |
|-------------|---------------|
| OrbStack / Docker Desktop | latest |
| Docker Compose v2 | 2.20+ |
| (Opcional) Composer | 2.x – solo para desarrollo del plugin fuera de Docker |

---

## Estructura del proyecto

```
search/
├── docker-compose.yml
├── .env                          # credenciales (NO commitear)
├── wp-content/
│   └── plugins/
│       └── wc-meilisearch/
│           ├── wc-meilisearch.php
│           ├── composer.json
│           ├── ajax-search.php
│           ├── assets/
│           │   ├── autocomplete.js
│           │   └── admin.js
│           └── includes/
│               ├── class-meilisearch-client.php
│               ├── class-product-indexer.php
│               └── class-admin-page.php
├── scraper/
│   ├── Dockerfile
│   ├── requirements.txt
│   ├── scraper.py
│   └── output/                   # products.json generado aquí
└── scripts/
    ├── wp-setup.sh               # setup inicial de WP + WooCommerce
    ├── composer-install.sh       # instala vendor/ del plugin
    └── import-products.php       # importa products.json a WooCommerce
```

---

## Setup inicial (paso a paso)

### 1. Configurar variables de entorno

```bash
# El archivo .env ya existe con valores de desarrollo.
# Ajusta la master key de Meilisearch si quieres:
nano .env
```

### 2. Levantar el stack

```bash
docker compose up -d
```

Servicios expuestos:

| Servicio | URL local |
|----------|-----------|
| WordPress | http://localhost:8080 |
| Meilisearch | http://localhost:7700 |
| Adminer | http://localhost:8081 |

### 3. Instalar Composer dentro del contenedor

```bash
docker compose exec wordpress bash /scripts/composer-install.sh
```

Esto instala `meilisearch/meilisearch-php` y `predis/predis` en
`wp-content/plugins/wc-meilisearch/vendor/`.

### 4. Setup inicial de WordPress + WooCommerce

```bash
docker compose exec wpcli bash /scripts/wp-setup.sh
```

El script:
- Instala WordPress con las credenciales del `.env`
- Instala y activa WooCommerce
- Activa el plugin `wc-meilisearch`
- Configura los permalinks y opciones básicas

### 5. Scrapear productos de Panuts.com

```bash
docker compose --profile scraper run --rm scraper
```

Genera `scraper/output/products.json` con todos los productos encontrados.

### 6. Importar productos a WooCommerce

```bash
docker compose exec wpcli wp eval-file /scripts/import-products.php --allow-root
```

El script:
- Lee `products.json`
- Crea/actualiza productos en WooCommerce (idempotente)
- Descarga imágenes en `wp-content/uploads/panuts_import/images/`
- Al finalizar, lanza un reindex completo de Meilisearch

### 7. Verificar el índice

```bash
# Comprobar salud de Meilisearch
curl http://localhost:7700/health

# Ver estadísticas del índice
curl -H "Authorization: Bearer masterKeyForDevelopment1234567890" \
     http://localhost:7700/indexes/wc_products/stats

# Hacer una búsqueda de prueba
curl -H "Authorization: Bearer masterKeyForDevelopment1234567890" \
     "http://localhost:7700/indexes/wc_products/search?q=mantequilla"
```

---

## Uso del plugin

### Panel de administración

`WooCommerce → Meilisearch` en el menú de WordPress admin.

- **Probar Conexión** – verifica que WordPress puede hablar con Meilisearch
- **Reindexar Productos** – indexa en lotes con barra de progreso

### Autocompletado en el frontend

El JS se inyecta automáticamente en todos los campos `<input type="search">`
y `<input name="s">`. Empieza a buscar desde el 2do carácter con debounce de 150ms.

### Endpoint de búsqueda directo

```
GET /wp-content/plugins/wc-meilisearch/ajax-search.php?q=mantequilla&limit=8
```

Respuesta:
```json
{
  "results": [
    { "id": 42, "name": "Mantequilla de Maní", "price": 18500, "image": "...", "url": "..." }
  ],
  "processingTimeMs": 2,
  "cached": false
}
```

---

## Casos de prueba

Con los productos de Panuts importados, el buscador debe pasar:

```bash
BASE="http://localhost:8080/wp-content/plugins/wc-meilisearch/ajax-search.php"

# 1. Typo: mantequlla → Mantequilla
curl "$BASE?q=mantequlla"

# 2. Sin espacios: mantequillademaní → Mantequilla de Maní
curl "$BASE?q=mantequillademan%C3%AD"

# 3. Doble typo: mntqlla mni → Mantequilla Maní
curl "$BASE?q=mntqlla+mni"

# 4. Verificar caché Redis (segunda llamada debe tener "cached": true)
curl "$BASE?q=mantequilla"
curl "$BASE?q=mantequilla"
```

---

## Sincronización automática

| Evento WooCommerce | Acción en Meilisearch |
|--------------------|-----------------------|
| Producto creado / publicado | `addDocuments` |
| Producto editado | `addDocuments` (upsert) |
| Producto despublicado | `deleteDocument` |
| Producto eliminado | `deleteDocument` |

---

## Comandos útiles

```bash
# Ver logs del wordpress
docker compose logs -f wordpress

# Ver logs del meilisearch
docker compose logs -f meilisearch

# Entrar al contenedor de WordPress
docker compose exec wordpress bash

# Usar WP-CLI directamente
docker compose exec wpcli wp --allow-root plugin list

# Reindexar manualmente desde WP-CLI
docker compose exec wpcli wp eval '\WCMeilisearch\ProductIndexer::instance()->reindex_all();' --allow-root

# Limpiar Redis
docker compose exec redis redis-cli FLUSHDB

# Reiniciar solo Meilisearch
docker compose restart meilisearch
```

---

## Notas de seguridad

- El archivo `.env` contiene la master key – **no lo commitees a git público**
- En producción usa una **Search Key** (solo lectura) en el frontend, no la master key
- El `ajax-search.php` incluye rate-limiting básico por IP (1 req/100ms)

---

## Troubleshooting

### El plugin muestra "Run composer install"

```bash
docker compose exec wordpress bash /scripts/composer-install.sh
```

### Meilisearch no responde

```bash
docker compose logs meilisearch
# Si está caído:
docker compose restart meilisearch
```

### Los productos no aparecen en la búsqueda

1. Ir a `WooCommerce → Meilisearch → Reindexar Productos`
2. O vía CLI: `docker compose exec wpcli wp eval '\WCMeilisearch\ProductIndexer::instance()->reindex_all();' --allow-root`

### Adminer – conectarse a MySQL

- Servidor: `mysql`
- Usuario: `wordpress`
- Contraseña: `wordpress`
- Base de datos: `wordpress`
# search
