#!/usr/bin/env php
<?php
/**
 * WP-CLI / standalone PHP script to import products.json into WooCommerce.
 *
 * Usage (via WP-CLI inside the wordpress container):
 *   wp eval-file /scripts/import-products.php --allow-root
 *
 * Or directly:
 *   php /scripts/import-products.php
 *
 * The script reads:
 *   /var/www/html/wp-content/uploads/panuts_import/products.json
 *
 * For each product it:
 *   1. Checks if a product with the same external_id meta already exists
 *      (idempotent – safe to re-run).
 *   2. Creates/updates the WooCommerce product.
 *   3. Downloads & attaches the first image.
 *   4. Syncs the product to Meilisearch (via the plugin, if active).
 */

defined( 'ABSPATH' ) || define( 'ABSPATH', '/var/www/html/' );

// Bootstrap WordPress when run standalone (not via WP-CLI).
if ( ! function_exists( 'add_action' ) ) {
    require_once ABSPATH . 'wp-load.php';
}

if ( ! class_exists( 'WooCommerce' ) ) {
    WP_CLI::error( 'WooCommerce not active.' );
    exit( 1 );
}

// ---------------------------------------------------------------------------
// Config
// ---------------------------------------------------------------------------
$import_dir  = WP_CONTENT_DIR . '/uploads/panuts_import';
$json_file   = $import_dir . '/products.json';
$image_dir   = $import_dir . '/images';
$batch_size  = 20;

if ( ! file_exists( $json_file ) ) {
    log_msg( "ERROR: $json_file not found. Run the scraper first." );
    exit( 1 );
}

$raw      = file_get_contents( $json_file );
$products = json_decode( $raw, true );

if ( ! is_array( $products ) ) {
    log_msg( 'ERROR: Could not parse products.json' );
    exit( 1 );
}

@mkdir( $image_dir, 0755, true );

log_msg( sprintf( 'Starting import of %d products…', count( $products ) ) );

$created = 0;
$updated = 0;
$failed  = 0;
$i       = 0;

foreach ( $products as $data ) {
    $i++;
    $name        = sanitize_text_field( $data['name'] ?? '' );
    $external_id = sanitize_text_field( $data['external_id'] ?? '' );

    if ( ! $name ) {
        log_msg( "  [$i] Skipped – empty name." );
        $failed++;
        continue;
    }

    log_msg( "  [$i] Importing: $name" );

    // Check for existing product by external_id meta.
    $existing_id = null;
    if ( $external_id ) {
        $existing = get_posts( [
            'post_type'  => 'product',
            'meta_key'   => '_panuts_external_id',
            'meta_value' => $external_id,
            'fields'     => 'ids',
            'numberposts' => 1,
        ] );
        $existing_id = $existing[0] ?? null;
    }

    // Build product.
    $product = $existing_id ? wc_get_product( $existing_id ) : new WC_Product_Simple();
    if ( ! $product ) {
        $product = new WC_Product_Simple();
    }

    $product->set_name( $name );
    $product->set_sku( sanitize_text_field( $data['sku'] ?? '' ) );
    $product->set_description( wp_kses_post( $data['description'] ?? '' ) );
    $product->set_short_description( wp_kses_post( $data['short_description'] ?? '' ) );
    $product->set_regular_price( (string) ( $data['regular_price'] ?? $data['price'] ?? 0 ) );

    if ( ! empty( $data['sale_price'] ) ) {
        $product->set_sale_price( (string) $data['sale_price'] );
    }

    $stock_status = in_array( $data['stock_status'] ?? 'instock', [ 'instock', 'outofstock' ], true )
        ? $data['stock_status']
        : 'instock';
    $product->set_stock_status( $stock_status );
    $product->set_manage_stock( false );
    $product->set_status( 'publish' );

    // Categories.
    if ( ! empty( $data['categories'] ) ) {
        $cat_ids = [];
        foreach ( (array) $data['categories'] as $cat_name ) {
            $cat_ids[] = ensure_product_cat( $cat_name );
        }
        $product->set_category_ids( array_filter( $cat_ids ) );
    }

    // Tags.
    if ( ! empty( $data['tags'] ) ) {
        $tag_ids = [];
        foreach ( (array) $data['tags'] as $tag_name ) {
            $tag_ids[] = ensure_product_tag( $tag_name );
        }
        $product->set_tag_ids( array_filter( $tag_ids ) );
    }

    // Save.
    try {
        $product_id = $product->save();
    } catch ( Exception $e ) {
        log_msg( "    ERROR saving product: " . $e->getMessage() );
        $failed++;
        continue;
    }

    if ( ! $product_id ) {
        log_msg( '    ERROR: save() returned no ID.' );
        $failed++;
        continue;
    }

    // External ID meta.
    if ( $external_id ) {
        update_post_meta( $product_id, '_panuts_external_id', $external_id );
        update_post_meta( $product_id, '_panuts_source_url', sanitize_url( $data['url'] ?? '' ) );
    }

    // Image.
    if ( ! empty( $data['images'][0] ) ) {
        $img_id = maybe_download_image( $data['images'][0], $image_dir, $product_id );
        if ( $img_id ) {
            $product->set_image_id( $img_id );
            $product->save();
        }
    }

    if ( $existing_id ) {
        $updated++;
        log_msg( "    Updated (ID: $product_id)" );
    } else {
        $created++;
        log_msg( "    Created (ID: $product_id)" );
    }

    // Periodic memory release.
    if ( $i % $batch_size === 0 ) {
        if ( function_exists( 'wp_cache_flush' ) ) {
            wp_cache_flush();
        }
    }
}

log_msg( '' );
log_msg( '=== Import complete ===' );
log_msg( "  Created : $created" );
log_msg( "  Updated : $updated" );
log_msg( "  Failed  : $failed" );
log_msg( '' );

// Trigger full Meilisearch reindex if the plugin is active.
if ( class_exists( '\WCMeilisearch\ProductIndexer' ) ) {
    log_msg( 'Reindexing all products in Meilisearch…' );
    \WCMeilisearch\ProductIndexer::instance()->reindex_all();
    log_msg( 'Meilisearch reindex done.' );
}

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

function ensure_product_cat( string $name ): ?int {
    $term = get_term_by( 'name', $name, 'product_cat' );
    if ( $term ) {
        return $term->term_id;
    }
    $result = wp_insert_term( $name, 'product_cat' );
    return is_wp_error( $result ) ? null : $result['term_id'];
}

function ensure_product_tag( string $name ): ?int {
    $term = get_term_by( 'name', $name, 'product_tag' );
    if ( $term ) {
        return $term->term_id;
    }
    $result = wp_insert_term( $name, 'product_tag' );
    return is_wp_error( $result ) ? null : $result['term_id'];
}

function maybe_download_image( string $url, string $local_dir, int $product_id ): ?int {
    if ( ! filter_var( $url, FILTER_VALIDATE_URL ) ) {
        return null;
    }

    $filename   = sanitize_file_name( basename( parse_url( $url, PHP_URL_PATH ) ) );
    $local_path = $local_dir . '/' . $filename;

    // Check if already attached.
    $existing = get_posts( [
        'post_type'  => 'attachment',
        'meta_key'   => '_panuts_source_url',
        'meta_value' => $url,
        'fields'     => 'ids',
        'numberposts' => 1,
    ] );

    if ( ! empty( $existing ) ) {
        return $existing[0];
    }

    // Download.
    $response = wp_remote_get( $url, [ 'timeout' => 30 ] );
    if ( is_wp_error( $response ) || wp_remote_retrieve_response_code( $response ) !== 200 ) {
        return null;
    }

    $body = wp_remote_retrieve_body( $response );
    file_put_contents( $local_path, $body );

    if ( ! file_exists( $local_path ) ) {
        return null;
    }

    // Determine MIME type.
    $filetype = wp_check_filetype( $filename, null );
    if ( ! $filetype['type'] ) {
        $filetype['type'] = 'image/jpeg';
        $filetype['ext']  = 'jpg';
    }

    // Move to uploads.
    $upload_dir  = wp_upload_dir();
    $dest_path   = $upload_dir['path'] . '/' . $filename;
    $dest_url    = $upload_dir['url'] . '/' . $filename;

    rename( $local_path, $dest_path );

    $attachment_id = wp_insert_attachment( [
        'post_mime_type' => $filetype['type'],
        'post_title'     => sanitize_file_name( pathinfo( $filename, PATHINFO_FILENAME ) ),
        'post_content'   => '',
        'post_status'    => 'inherit',
        'post_parent'    => $product_id,
    ], $dest_path, $product_id );

    if ( is_wp_error( $attachment_id ) ) {
        return null;
    }

    require_once ABSPATH . 'wp-admin/includes/image.php';
    $metadata = wp_generate_attachment_metadata( $attachment_id, $dest_path );
    wp_update_attachment_metadata( $attachment_id, $metadata );
    update_post_meta( $attachment_id, '_panuts_source_url', $url );

    return $attachment_id;
}

function log_msg( string $msg ): void {
    $line = '[' . date( 'H:i:s' ) . '] ' . $msg;
    if ( defined( 'WP_CLI' ) && WP_CLI ) {
        WP_CLI::log( $line );
    } else {
        echo $line . PHP_EOL;
    }
}
