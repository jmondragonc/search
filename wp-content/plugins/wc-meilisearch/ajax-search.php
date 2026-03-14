<?php
/**
 * Standalone AJAX search endpoint.
 *
 * URL: /wp-content/plugins/wc-meilisearch/ajax-search.php
 *
 * GET params:
 *   q          – search query (min 2 chars)
 *   cat        – single category filter (legacy, kept for autocomplete chips)
 *   cats       – comma-separated categories (OR logic, used by results page)
 *   price_min  – minimum price filter
 *   price_max  – maximum price filter
 *   stock      – "true" to return only in-stock products
 *   facets     – "1" to include facet distribution in response
 *   limit      – max results (default 8, max 100)
 *
 * Returns JSON: { results, processingTimeMs, cached, facets? }
 */

// Bootstrap WordPress without the admin layer.
$base = dirname( __DIR__, 3 ); // wp-content → wp root
if ( file_exists( $base . '/wp-load.php' ) ) {
    require_once $base . '/wp-load.php';
} else {
    http_response_code( 500 );
    echo json_encode( [ 'error' => 'WordPress not found' ] );
    exit;
}

// Autoloader.
if ( ! class_exists( '\WCMeilisearch\MeilisearchClient' ) ) {
    $autoload = __DIR__ . '/vendor/autoload.php';
    if ( file_exists( $autoload ) ) {
        require_once $autoload;
        require_once __DIR__ . '/includes/class-meilisearch-client.php';
    } else {
        http_response_code( 500 );
        echo json_encode( [ 'error' => 'Plugin vendor not installed' ] );
        exit;
    }
}

// ---------------------------------------------------------------------------
// Input sanitization
// ---------------------------------------------------------------------------
$query     = isset( $_GET['q'] )   && is_string( $_GET['q'] )   ? trim( sanitize_text_field( wp_unslash( $_GET['q'] ) ) )   : '';
$cat       = isset( $_GET['cat'] ) && is_string( $_GET['cat'] ) ? trim( sanitize_text_field( wp_unslash( $_GET['cat'] ) ) ) : '';
$cats_raw  = isset( $_GET['cats'] ) && is_string( $_GET['cats'] ) ? sanitize_text_field( wp_unslash( $_GET['cats'] ) ) : '';
$price_min = isset( $_GET['price_min'] ) && is_numeric( $_GET['price_min'] ) ? (float) $_GET['price_min'] : null;
$price_max = isset( $_GET['price_max'] ) && is_numeric( $_GET['price_max'] ) ? (float) $_GET['price_max'] : null;
$stock     = isset( $_GET['stock'] ) && $_GET['stock'] === 'true';
$with_facets = ! empty( $_GET['facets'] ) && $_GET['facets'] === '1';

// Require at least a query, a category, or a facets request.
if ( strlen( $query ) < 2 && empty( $cat ) && empty( $cats_raw ) && ! $with_facets ) {
    header( 'Content-Type: application/json; charset=utf-8' );
    echo json_encode( [ 'results' => [], 'processingTimeMs' => 0, 'cached' => false ] );
    exit;
}

// Rate-limit: max 1 request per 100ms per IP.
$ip_key   = 'wcm_rl_' . md5( $_SERVER['REMOTE_ADDR'] ?? '' );
$last_hit = get_transient( $ip_key );
if ( $last_hit && ( microtime( true ) - $last_hit ) < 0.1 ) {
    http_response_code( 429 );
    echo json_encode( [ 'error' => 'Too many requests' ] );
    exit;
}
set_transient( $ip_key, microtime( true ), 1 );

// ---------------------------------------------------------------------------
// Build filters
// ---------------------------------------------------------------------------
$filter_parts = [];

// Single category (legacy autocomplete chips).
if ( ! empty( $cat ) ) {
    $filter_parts[] = "categories = '" . str_replace( "'", "\\'", $cat ) . "'";
}

// Multiple categories with OR logic (results page sidebar).
if ( ! empty( $cats_raw ) ) {
    $cat_list = array_filter( array_map( 'trim', explode( ',', $cats_raw ) ) );
    if ( ! empty( $cat_list ) ) {
        $cat_conditions = array_map(
            fn( $c ) => "categories = '" . str_replace( "'", "\\'", $c ) . "'",
            $cat_list
        );
        $filter_parts[] = '(' . implode( ' OR ', $cat_conditions ) . ')';
    }
}

if ( $price_min !== null ) $filter_parts[] = "price >= {$price_min}";
if ( $price_max !== null ) $filter_parts[] = "price <= {$price_max}";
if ( $stock )              $filter_parts[] = 'in_stock = true';

// ---------------------------------------------------------------------------
// Search options
// ---------------------------------------------------------------------------
$limit   = isset( $_GET['limit'] ) ? min( (int) $_GET['limit'], 100 ) : 8;
$options = [ 'limit' => $limit ];

if ( ! empty( $filter_parts ) ) {
    $options['filter'] = implode( ' AND ', $filter_parts );
}

if ( $with_facets ) {
    $options['facets'] = [ 'categories', 'attr_tipo', 'attr_pais', 'in_stock' ];
}

$result = \WCMeilisearch\MeilisearchClient::instance()->search( $query, $options );

// ---------------------------------------------------------------------------
// Response
// ---------------------------------------------------------------------------
header( 'Content-Type: application/json; charset=utf-8' );
header( 'Cache-Control: no-store' );
header( 'X-WCM-Cached: ' . ( $result['cached'] ? '1' : '0' ) );

$response = [
    'results'          => array_values( $result['results'] ),
    'processingTimeMs' => $result['processingTimeMs'],
    'cached'           => $result['cached'],
];

if ( $with_facets && ! empty( $result['facets'] ) ) {
    $response['facets'] = $result['facets'];
}

echo json_encode( $response );
