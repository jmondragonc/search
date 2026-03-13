<?php
/**
 * Standalone AJAX search endpoint.
 *
 * URL: /wp-content/plugins/wc-meilisearch/ajax-search.php?q=mantequilla&cat=Tintos
 *
 * Returns JSON:
 * {
 *   "results": [ { id, name, price, image, url } ],
 *   "processingTimeMs": 2,
 *   "cached": false
 * }
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
// Security
// ---------------------------------------------------------------------------
if ( ! isset( $_GET['q'] ) && ! isset( $_GET['cat'] ) ) {
    http_response_code( 400 );
    echo json_encode( [ 'error' => 'Missing query or category parameter' ] );
    exit;
}

$query = isset( $_GET['q'] ) && is_string( $_GET['q'] ) ? trim( sanitize_text_field( wp_unslash( $_GET['q'] ) ) ) : '';
$cat   = isset( $_GET['cat'] ) && is_string( $_GET['cat'] ) ? trim( sanitize_text_field( wp_unslash( $_GET['cat'] ) ) ) : '';

if ( strlen( $query ) < 2 && empty( $cat ) ) {
    header( 'Content-Type: application/json; charset=utf-8' );
    echo json_encode( [ 'results' => [], 'processingTimeMs' => 0, 'cached' => false ] );
    exit;
}

// Simple rate-limit: max 1 request per 100ms per IP (via transient).
$ip_key   = 'wcm_rl_' . md5( $_SERVER['REMOTE_ADDR'] ?? '' );
$last_hit = get_transient( $ip_key );
if ( $last_hit && ( microtime( true ) - $last_hit ) < 0.1 ) {
    http_response_code( 429 );
    echo json_encode( [ 'error' => 'Too many requests' ] );
    exit;
}
set_transient( $ip_key, microtime( true ), 1 );

// ---------------------------------------------------------------------------
// Search
// ---------------------------------------------------------------------------
$limit = isset( $_GET['limit'] ) ? min( (int) $_GET['limit'], 100 ) : 8;
$options = [ 'limit' => $limit ];

if ( ! empty( $cat ) ) {
    $options['filter'] = [ "categories = '{$cat}'" ]; // Assuming exact category name match
}

// If query is empty but we have a category, we search for "" (all docs matching the filter)
$result = \WCMeilisearch\MeilisearchClient::instance()->search( $query, $options );

// ---------------------------------------------------------------------------
// Response
// ---------------------------------------------------------------------------
header( 'Content-Type: application/json; charset=utf-8' );
header( 'Cache-Control: no-store' );
header( 'X-WCM-Cached: ' . ( $result['cached'] ? '1' : '0' ) );

echo json_encode( [
    'results'          => array_values( $result['results'] ),
    'processingTimeMs' => $result['processingTimeMs'],
    'cached'           => $result['cached'],
] );
