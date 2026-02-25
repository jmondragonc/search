<?php
/**
 * Listens to WooCommerce product hooks and keeps the Meilisearch index in sync.
 *
 * @package WCMeilisearch
 */

namespace WCMeilisearch;

defined( 'ABSPATH' ) || exit;

class ProductIndexer {

    private static ?self $instance = null;

    /** Number of products per batch during bulk reindex. */
    private const BATCH_SIZE = 50;

    private function __construct() {
        $this->register_hooks();
    }

    public static function instance(): self {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    // ---------------------------------------------------------------------------
    // WooCommerce hooks
    // ---------------------------------------------------------------------------
    private function register_hooks(): void {
        // Index / update on save.
        add_action( 'woocommerce_new_product',    [ $this, 'sync_product' ] );
        add_action( 'woocommerce_update_product', [ $this, 'sync_product' ] );
        add_action( 'save_post_product',          [ $this, 'on_save_post' ], 10, 3 );

        // Remove from index on trash / delete.
        add_action( 'woocommerce_delete_product', [ $this, 'remove_product' ] );
        add_action( 'woocommerce_trash_product',  [ $this, 'remove_product' ] );
        add_action( 'before_delete_post',         [ $this, 'on_before_delete_post' ] );

        // AJAX handlers for admin bulk reindex.
        add_action( 'wp_ajax_wcm_reindex_batch', [ $this, 'ajax_reindex_batch' ] );
        add_action( 'wp_ajax_wcm_reindex_count', [ $this, 'ajax_reindex_count' ] );
    }

    // ---------------------------------------------------------------------------
    // Hook callbacks
    // ---------------------------------------------------------------------------

    /**
     * Called by woocommerce_new_product / woocommerce_update_product.
     */
    public function sync_product( int $product_id ): void {
        $product = wc_get_product( $product_id );
        if ( ! $product || $product->get_status() !== 'publish' ) {
            return;
        }
        MeilisearchClient::instance()->upsert_documents( [ $this->build_document( $product ) ] );
    }

    /**
     * Called by save_post_product – catches status transitions.
     */
    public function on_save_post( int $post_id, \WP_Post $post, bool $update ): void {
        if ( wp_is_post_revision( $post_id ) ) {
            return;
        }

        if ( $post->post_status === 'publish' ) {
            $this->sync_product( $post_id );
        } else {
            // Product was unpublished – remove from search.
            $this->remove_product( $post_id );
        }
    }

    /**
     * Remove product from index.
     */
    public function remove_product( int $product_id ): void {
        MeilisearchClient::instance()->delete_document( $product_id );
    }

    /**
     * Hook before a post is permanently deleted.
     */
    public function on_before_delete_post( int $post_id ): void {
        if ( get_post_type( $post_id ) === 'product' ) {
            $this->remove_product( $post_id );
        }
    }

    // ---------------------------------------------------------------------------
    // Document builder
    // ---------------------------------------------------------------------------

    /**
     * Transform a WC_Product into the document shape we store in Meilisearch.
     *
     * @param  \WC_Product $product
     * @return array
     */
    public function build_document( \WC_Product $product ): array {
        // Categories.
        $category_names = wp_list_pluck(
            get_the_terms( $product->get_id(), 'product_cat' ) ?: [],
            'name'
        );

        // Tags.
        $tag_names = wp_list_pluck(
            get_the_terms( $product->get_id(), 'product_tag' ) ?: [],
            'name'
        );

        // Primary image URL.
        $image_id  = $product->get_image_id();
        $image_url = $image_id ? wp_get_attachment_image_url( $image_id, 'woocommerce_thumbnail' ) : '';

        $name = $product->get_name();

        // Product attributes (marca, pais, region, tipo, varietal, volumen).
        $attrs = [];
        foreach ( $product->get_attributes() as $attr ) {
            $terms = $attr->get_terms();
            if ( $terms ) {
                $key          = str_replace( 'pa_', '', $attr->get_name() );
                $attrs[ $key ] = implode( ', ', wp_list_pluck( $terms, 'name' ) );
            }
        }

        // Priority: accessories/glassware go last (0), liquors/wines first (1).
        // Detected by category names that indicate non-drinkable products.
        $accessory_pattern = '/copa|decantador|cavas.copas|accesorio/i';
        $is_accessory      = false;
        foreach ( $category_names as $cat ) {
            if ( preg_match( $accessory_pattern, $cat ) ) {
                $is_accessory = true;
                break;
            }
        }

        return [
            'id'           => $product->get_id(),
            'name'         => $name,
            // Generic first-char-stripped variant used by the fuzzy fallback.
            // "Santa Julia" → "anta ulia", so searching "anta ulia" (derived
            // from the typo "zanta julia") still finds the right product.
            'name_alt'     => self::make_name_alt( $name ),
            'sku'          => $product->get_sku(),
            'description'  => wp_strip_all_tags( $product->get_short_description() ?: $product->get_description() ),
            'price'        => (float) $product->get_price(),
            'stock_status' => $product->get_stock_status(),
            'in_stock'     => $product->is_in_stock(),
            'categories'   => array_values( $category_names ),
            'tags'         => array_values( $tag_names ),
            'image'        => $image_url ?: '',
            'url'          => get_permalink( $product->get_id() ),
            // Attributes (empty string if not set, to keep document shape consistent).
            'attr_marca'      => $attrs['marca']    ?? '',
            'attr_pais'       => $attrs['pais']     ?? '',
            'attr_region'     => $attrs['region']   ?? '',
            'attr_tipo'       => $attrs['tipo']      ?? '',
            'attr_varietal'   => $attrs['varietal'] ?? '',
            'attr_volumen'    => $attrs['volumen']  ?? '',
            // 1 = liquor/wine (show first), 0 = accessory/glassware (show last).
            'product_priority' => $is_accessory ? 0 : 1,
        ];
    }

    // ---------------------------------------------------------------------------
    // Bulk reindex
    // ---------------------------------------------------------------------------

    /**
     * Reindex ALL published products, in batches of BATCH_SIZE.
     * Safe to call from cron or WP-CLI.
     */
    public function reindex_all(): void {
        MeilisearchClient::instance()->clear_index();

        $offset = 0;
        do {
            $ids = get_posts( [
                'post_type'      => 'product',
                'post_status'    => 'publish',
                'posts_per_page' => self::BATCH_SIZE,
                'offset'         => $offset,
                'fields'         => 'ids',
            ] );

            if ( empty( $ids ) ) {
                break;
            }

            $docs = [];
            foreach ( $ids as $id ) {
                $product = wc_get_product( $id );
                if ( $product ) {
                    $docs[] = $this->build_document( $product );
                }
            }

            if ( ! empty( $docs ) ) {
                MeilisearchClient::instance()->upsert_documents( $docs );
            }

            $offset += self::BATCH_SIZE;
        } while ( count( $ids ) === self::BATCH_SIZE );
    }

    // ---------------------------------------------------------------------------
    // AJAX: paged batch reindex (used by admin progress bar)
    // ---------------------------------------------------------------------------

    /**
     * Returns total number of published products for the progress bar.
     * Action: wcm_reindex_count
     */
    public function ajax_reindex_count(): void {
        check_ajax_referer( 'wcm_reindex', 'nonce' );
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_die( -1 );
        }

        $count = (int) wp_count_posts( 'product' )->publish;
        wp_send_json_success( [ 'total' => $count ] );
    }

    /**
     * Indexes one batch (BATCH_SIZE products at the given offset).
     * Action: wcm_reindex_batch
     */
    public function ajax_reindex_batch(): void {
        check_ajax_referer( 'wcm_reindex', 'nonce' );
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_die( -1 );
        }

        $offset = isset( $_POST['offset'] ) ? absint( $_POST['offset'] ) : 0;

        // Clear index only on first batch.
        if ( $offset === 0 ) {
            MeilisearchClient::instance()->clear_index();
        }

        $ids = get_posts( [
            'post_type'      => 'product',
            'post_status'    => 'publish',
            'posts_per_page' => self::BATCH_SIZE,
            'offset'         => $offset,
            'fields'         => 'ids',
        ] );

        $docs = [];
        foreach ( $ids as $id ) {
            $product = wc_get_product( $id );
            if ( $product ) {
                $docs[] = $this->build_document( $product );
            }
        }

        if ( ! empty( $docs ) ) {
            MeilisearchClient::instance()->upsert_documents( $docs );
        }

        wp_send_json_success( [
            'indexed'  => count( $docs ),
            'offset'   => $offset + self::BATCH_SIZE,
            'done'     => count( $ids ) < self::BATCH_SIZE,
        ] );
    }

    // ---------------------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------------------

    /**
     * Build a generic "first-char-stripped" variant of a product name.
     *
     * Removes the first character of every word that is longer than 2 chars.
     * This is used as a fallback search target to compensate for Meilisearch's
     * engine-level restriction that blocks typo tolerance on the first character.
     *
     * Examples:
     *   "Santa Julia Malbec"   → "anta ulia albec"
     *   "Zuccardi Q"           → "uccardi Q"
     *   "Fever-Tree Gin"       → "ever-Tree in"
     *
     * @param  string $name  Raw product name.
     * @return string
     */
    public static function make_name_alt( string $name ): string {
        $lower = mb_strtolower( $name, 'UTF-8' );
        $words = preg_split( '/\s+/u', $lower, -1, PREG_SPLIT_NO_EMPTY ) ?: [];

        $alt = array_map( function ( string $word ): string {
            return mb_strlen( $word, 'UTF-8' ) > 2
                ? mb_substr( $word, 1, null, 'UTF-8' )
                : $word;
        }, $words );

        return implode( ' ', $alt );
    }
}
