# Bitácora de Desarrollo – WooCommerce + Meilisearch

Registro técnico de decisiones, fases, problemas encontrados y soluciones aplicadas durante el desarrollo del plugin `wc-meilisearch`.

---

## Visión general

Motor de búsqueda inteligente para WooCommerce corriendo en Docker (OrbStack/macOS). El stack completo es:

```
WordPress + WooCommerce → Plugin wc-meilisearch → Meilisearch v1.6 + Redis 7
```

El plugin sincroniza automáticamente el catálogo de WooCommerce con Meilisearch y expone un endpoint de búsqueda con autocomplete en el frontend. Un scraper Python obtiene los productos de panuts.com y un script WP-CLI los importa a WooCommerce.

---

## Fase 1 – Estructura del proyecto y stack Docker

### Qué se construyó

- `docker-compose.yml`: 6 servicios en red `search_net` (mysql, meilisearch, redis, wordpress, wpcli, adminer + scraper con profile opcional)
- `.env`: variables de entorno para MySQL, WordPress, Meilisearch y Redis
- Plugin `wc-meilisearch` con estructura completa:
  - `wc-meilisearch.php` – bootstrap del plugin
  - `includes/class-meilisearch-client.php` – cliente Meilisearch + cache Redis
  - `includes/class-product-indexer.php` – sincronización con WooCommerce hooks
  - `includes/class-admin-page.php` – panel de administración
  - `ajax-search.php` – endpoint HTTP standalone
  - `assets/autocomplete.js` – UI de búsqueda (vanilla JS)
  - `assets/admin.js` – barra de progreso de reindexado
- Scraper Python para panuts.com
- Scripts WP-CLI de setup e importación
- `README.md` con instrucciones completas

### Decisión: classmap vs PSR-4 en Composer

WordPress usa la convención `class-foo-bar.php` para nombrar archivos, que no es compatible con PSR-4 (que esperaría `FooBar.php`). Se usó **classmap** en lugar de PSR-4:

```json
"autoload": { "classmap": ["includes/"] }
```

Intentar PSR-4 causaba que todas las clases del plugin quedaran invisibles al autoloader.

---

## Fase 2 – Setup inicial y primeros errores

### Error: `/scripts/composer-install.sh` no encontrado en el contenedor `wordpress`

El volumen `/scripts` solo estaba montado en el contenedor `wpcli`, no en `wordpress`. El fix fue correr composer directamente inline dentro del contenedor:

```bash
docker compose exec wordpress bash -c 'curl -sS https://getcomposer.org/installer | php && \
  cd /var/www/html/wp-content/plugins/wc-meilisearch && \
  php /var/www/html/composer.phar install'
```

### Error: PSR-18 HTTP client no encontrado

El SDK de Meilisearch para PHP requiere un cliente PSR-18. Sin él lanzaba:

```
Psr18ClientDiscovery::find() NotFoundException
```

Fix: agregar `guzzlehttp/guzzle` como dependencia en `composer.json`:

```json
"guzzlehttp/guzzle": "^7.0"
```

### Error: robots.txt devuelve 403 → "disallow all"

El módulo `urllib.robotparser` de Python no envía User-Agent al hacer `read()`, por lo que el servidor devuelve 403. El spec de robots.txt trata 403 como "disallow all", bloqueando el scraper.

Fix en `scraper.py`: descargar robots.txt manualmente con `requests` (que sí envía UA) y parsear el contenido:

```python
def build_robot_parser(base_url: str) -> urllib.robotparser.RobotFileParser:
    rp = urllib.robotparser.RobotFileParser()
    rp.set_url(base_url + "/robots.txt")
    resp = requests.get(base_url + "/robots.txt", headers={"User-Agent": SCRAPER_UA}, timeout=10)
    rp.parse(resp.text.splitlines())
    return rp
```

### Error: WP-CLI atascado esperando la base de datos

El contenedor `wpcli` (imagen `wordpress:cli`) no tiene los archivos core de WordPress, solo `wp-content` montado. Fix: instalar WP-CLI directamente dentro del contenedor `wordpress` y correr el setup desde ahí.

---

## Fase 3 – Barra de búsqueda en el header (genérica para cualquier tema)

### Requerimiento

El input de búsqueda con todas las funcionalidades de Meilisearch debía estar visible en todas las páginas sin importar el tema de WordPress activo.

### Solución: hook `wp_body_open`

En lugar de modificar templates de ningún tema, se inyecta la barra mediante el hook `wp_body_open` con posicionamiento `fixed`:

```php
add_action( 'wp_body_open', 'wcm_render_header_searchbar' );

function wcm_render_header_searchbar(): void {
    ?>
    <div id="wcm-header-bar" style="position:fixed;top:0;left:0;right:0;z-index:99999;background:#1a1a1a;...">
      <input type="search" id="wcm-header-input" ...>
    </div>
    <style>body { padding-top: 56px !important; }</style>
    <?php
}
```

Funciona con Twenty Twenty-Five, Twenty Twenty-Four, WooCommerce Storefront y cualquier tema que soporte `wp_body_open`.

---

## Fase 4 – Tolerancia a typos avanzada (genérica)

### El problema

Meilisearch tiene un **límite a nivel de motor**: no aplica tolerancia a errores tipográficos en el **primer carácter** de cada palabra. Esto causa que búsquedas como "zanta julia" (z en lugar de s) o "santajuli" (palabra compuesta sin espacio) devuelvan 0 resultados.

### Primer intento (incorrecto): sinónimos hardcodeados

Se agregaron sinónimos específicos para la marca: `"zanta" → "santa"`, `"santajuli" → "santa julia"`, etc.

**Problema**: esto es específico a una marca y no funciona para ningún otro producto del catálogo. El requerimiento es que la solución sea **completamente genérica**.

### Solución genérica: tres capas de búsqueda

Se implementó una estrategia de búsqueda en tres capas, sin conocimiento hardcodeado del catálogo:

#### Capa 1 – Búsqueda estándar

Búsqueda normal en los campos `name`, `sku`, `description`, `categories`, `tags`, `name_alt`.

#### Capa 2 – Split de palabras compuestas

Para queries sin espacios como "santajullia", se generan todas las particiones posibles de la palabra y se prueban secuencialmente:

```
"santajullia" → "sant ajullia", "santa jullia", "santaj ullia", ...
```

Meilisearch con typo tolerance maneja "jullia" → "julia" internamente. El primer candidato que devuelve resultados gana.

**Regla importante**: ambas mitades deben tener al menos **4 caracteres** para evitar falsos positivos. "san tajullia" (3 chars en "san") generaba 30 hits falsos porque "san" coincide con demasiadas cosas con typo tolerance.

```php
for ( $i = 4; $i <= $len - 4; $i++ ) { ... }
```

#### Capa 3 – Strip del primer carácter en `name_alt`

Para compensar el límite del motor (no typo en char[0]), cada documento indexa un campo adicional `name_alt` = nombre con el primer carácter de cada palabra eliminado:

```
"Santa Julia Malbec" → name_alt: "anta ulia albec"
```

Cuando el query "zanta jul" llega:
1. Se elimina el primer char de cada palabra del query: "anta ul"
2. Se busca "anta ul" en el campo `name_alt`
3. Meilisearch encuentra "anta ulia albec" con typo tolerance normal

Esto funciona para **cualquier producto** del catálogo sin configuración adicional.

### Configuración de typo tolerance reducida

Para que palabras cortas también se beneficien:

```php
$index->updateTypoTolerance( [
    'enabled'             => true,
    'minWordSizeForTypos' => [
        'oneTypo'  => 3,   // antes: 4
        'twoTypos' => 7,   // antes: 8
    ],
] );
```

---

## Fase 5 – Error SDK multiSearch

### El problema

El método `multiSearch()` del SDK `meilisearch-php` v1.16.1 lanzaba:

```
Call to a member function toArray() on array
```

Incompatibilidad entre la versión del SDK instalada y el procesamiento interno del resultado de multi-search.

### Fix: búsqueda secuencial en lugar de multi-search

Se reemplazó `multiSearch()` del SDK por llamadas secuenciales a `raw_search()`. Dado que Meilisearch es local (latencia sub-milisegundo), el impacto en performance es despreciable:

```php
private function run_multi_search( array $candidates, array $options ): ?array {
    foreach ( $candidates as $candidate ) {
        $result = $this->raw_search( $candidate, $options );
        if ( ! empty( $result['results'] ) ) {
            return $result;
        }
    }
    return null;
}
```

---

## Resumen de casos de prueba verificados

Con el catálogo de panuts.com importado (206 productos, mayormente vinos):

| Query | Resultados esperados | Estado |
|-------|---------------------|--------|
| `santa julia` | Santa Julia Malbec * | ✅ |
| `santajuli` | Santa Julia Malbec * (split fallback) | ✅ |
| `santajullia` | Santa Julia Malbec * (split + typo) | ✅ |
| `zanta jul` | Santa Julia Malbec * (first-char-strip) | ✅ |
| `zuccardi` | Productos Zuccardi | ✅ |
| `malbec` | Productos Malbec | ✅ |

---

## Arquitectura del campo `name_alt`

```
Indexación (ProductIndexer::build_document):
  "Santa Julia Malbec Reserva"
      ↓ make_name_alt()
  "anta ulia albec eserva"      ← guardado en name_alt

Búsqueda (MeilisearchClient::try_first_char_strip):
  Query: "zanta jul"
      ↓ strip_first_chars()
  Alt query: "anta ul"
      ↓ raw_search( "anta ul", attributesToSearchOn: ["name_alt"] )
  → encuentra "anta ulia albec eserva" con typo tolerance
  → devuelve el producto correcto
```

---

## Fase 6 – Despliegue a producción (search.bttr.pe)

### Infraestructura

- **Servidor**: Ubuntu 24.04 LTS, VPS en 38.250.161.142
- **DNS**: search.bttr.pe → 38.250.161.142 (configurado en panel DNS antes del deploy)
- **SSL**: Let's Encrypt via certbot (expira 2026-05-22, auto-renovación habilitada)
- **Stack**: idéntico al desarrollo local, corriendo en Docker

### Problema: mysql:8.0 no compatible con la CPU del VPS

```
Fatal glibc error: CPU does not support x86-64-v2
```

MySQL 8.0 (tag `:latest`) desde cierta versión requiere instrucciones x86-64-v2 que el VPS no soporta. Fix: pinear a `mysql:8.0.32` que no tiene ese requisito.

### Arquitectura nginx (reverse proxy)

nginx en el host actúa como proxy hacia el contenedor WordPress:

```
Internet → nginx:80/443 → Docker:8080 (wc-wordpress)
```

```nginx
location / {
    proxy_pass         http://127.0.0.1:8080;
    proxy_http_version 1.1;
    proxy_set_header   Host              $host;
    proxy_set_header   X-Real-IP         $remote_addr;
    proxy_set_header   X-Forwarded-For   $proxy_add_x_forwarded_for;
    proxy_set_header   X-Forwarded-Proto $scheme;
    proxy_read_timeout 60s;
}
```

### WooCommerce "Coming Soon" activado por defecto

Las instalaciones nuevas de WooCommerce activan el modo "Coming Soon" automáticamente. Fix:

```bash
wp option update woocommerce_coming_soon 'no'
wp option update woocommerce_store_pages_only 'no'
```

### Firewall

```bash
ufw allow 22/tcp   # SSH
ufw allow 80/tcp   # HTTP
ufw allow 443/tcp  # HTTPS
ufw deny 7700      # Meilisearch (solo acceso interno)
ufw deny 8081      # Adminer (solo acceso interno)
```

---

## Fase 7 – Extracción de precios del scraper

### Problema: todos los precios aparecían como $0

panuts.com usa elementos `<bdi>` para los precios, no `.amount` como asumía el selector original.

**HTML real:**
```html
<bdi><span class="woocommerce-Price-currencySymbol">S/.</span>&nbsp;13.90</bdi>
```

**Fix 1 – Selector correcto:**
```python
def extract_price(sel: str) -> float:
    el = soup.select_one(sel)
    if not el:
        return 0.0
    bdi = el.select_one("bdi")
    text = bdi.get_text(strip=True) if bdi else el.get_text(strip=True)
    return parse_price(text)
```

**Fix 2 – Formato numérico inglés vs español:**

`parse_price` asumía formato español (`1.556,00`), pero panuts usa formato inglés (`1,556.00`). Fix: detectar cuál separador está más a la derecha.

```python
if "," in cleaned and "." in cleaned:
    if cleaned.rfind(".") > cleaned.rfind(","):
        cleaned = cleaned.replace(",", "")          # inglés: 1,556.00
    else:
        cleaned = cleaned.replace(".", "").replace(",", ".")  # español: 1.556,00
```

**Fix 3 – Punto residual del símbolo "S/.":**

El símbolo `S/.` tiene un punto que pasa el regex `[^\d,\.]`, generando `".13.90"` que falla en `float()`.

```python
cleaned = re.sub(r"[^\d,\.]", "", text)
cleaned = cleaned.strip(".")   # elimina punto inicial de "S/."
```

### Resultado final

977 productos importados con precios correctos. 0 productos con precio 0.

---

## Notas de operación

- **OPcache**: el contenedor WordPress usa `opcache.validate_timestamps=1`, detecta cambios de archivo automáticamente. `opcache_reset()` desde CLI no afecta el OPcache de Apache (procesos separados).
- **Redis cache TTL**: 300 segundos. Las búsquedas sin resultados no se cachean para no bloquear el fallback en futuras peticiones.
- **Invalidación de cache**: automática en cada `upsert_documents`, `delete_document` y `clear_index`.
- **Rate limiting**: 1 request cada 100ms por IP en `ajax-search.php`, implementado con WordPress transients.
- **Reindexado automático**: el plugin tiene hooks en `woocommerce_new_product`, `woocommerce_update_product`, `woocommerce_delete_product`. Cada edición de producto en WooCommerce actualiza Meilisearch automáticamente.
- **Reindexado masual**: desde el panel admin de WordPress → Meilisearch → "Reindexar todo". Solo necesario la primera vez o si el índice se corrompe.
