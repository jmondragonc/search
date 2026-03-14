<?php
/**
 * Singleton wrapper around the Meilisearch PHP SDK + optional Redis cache.
 *
 * Search strategy (three layers, all generic – no brand-specific logic):
 *
 * 1. Primary search on the standard fields (name, sku, description, …).
 * 2. Compound-word split fallback: "santajulia" → try "santa julia", etc.
 *    Uses Meilisearch multi-search to batch all split variants in one request.
 * 3. First-char-strip fallback: searches the `name_alt` field (which stores
 *    every product name with the first character of each word removed).
 *    Query "zanta jul" → "anta ul" → finds the document whose name_alt is
 *    "anta ulia albec" (Santa Julia Malbec). Works for any brand or product.
 *
 * @package WCMeilisearch
 */

namespace WCMeilisearch;

use Meilisearch\Client;
use Predis\Client as RedisClient;

defined( 'ABSPATH' ) || exit;

class MeilisearchClient {

    private static ?self $instance = null;

    private Client       $meili;
    private ?RedisClient $redis = null;

    public const INDEX_NAME = 'wc_products';

    private const CACHE_TTL = 300;

    // ---------------------------------------------------------------------------
    // Constructor
    // ---------------------------------------------------------------------------
    private function __construct() {
        $host       = get_option( 'wcm_meili_host', getenv( 'MEILI_HOST' ) ?: 'http://meilisearch:7700' );
        $master_key = get_option( 'wcm_meili_key',  getenv( 'MEILI_MASTER_KEY' ) ?: '' );

        $this->meili = new Client( $host, $master_key );

        $this->init_redis();
        $this->ensure_index();
    }

    public static function instance(): self {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    // ---------------------------------------------------------------------------
    // Redis
    // ---------------------------------------------------------------------------
    private function init_redis(): void {
        $host = get_option( 'wcm_redis_host', getenv( 'REDIS_HOST' ) ?: 'redis' );
        $port = (int) get_option( 'wcm_redis_port', getenv( 'REDIS_PORT' ) ?: 6379 );

        try {
            $this->redis = new RedisClient( [ 'scheme' => 'tcp', 'host' => $host, 'port' => $port ] );
            $this->redis->ping();
        } catch ( \Throwable $e ) {
            $this->redis = null;
            error_log( '[WCMeilisearch] Redis unavailable: ' . $e->getMessage() );
        }
    }

    // ---------------------------------------------------------------------------
    // Index bootstrap
    // ---------------------------------------------------------------------------
    private function ensure_index(): void {
        try {
            $index = $this->meili->index( self::INDEX_NAME );

            // name_alt is listed last → lowest relevance weight.
            // It only "wins" in the fallback search where we explicitly target it.
            // attr_* fields allow searching by brand, country, type, varietal, etc.
            $index->updateSearchableAttributes( [
                'name',
                'sku',
                // 'description',  // desactivado temporalmente – reactivar cuando se necesite
                'categories',
                'tags',
                'attr_marca',
                'attr_pais',
                'attr_region',
                'attr_tipo',
                'attr_varietal',
                'name_alt',
                'name_phonetic',
            ] );

            $index->updateFilterableAttributes( [
                'in_stock', 'categories', 'price', 'stock_status',
                'attr_marca', 'attr_pais', 'attr_region', 'attr_tipo', 'attr_varietal', 'attr_volumen',
                'product_priority',
            ] );
            $index->updateSortableAttributes( [ 'price', 'name', 'product_priority' ] );

            $index->updateRankingRules( [
                'words', 'typo', 'proximity', 'attribute',
                'product_priority:desc',  // liquors/wines (1) before accessories/glassware (0)
                'sort', 'exactness',
            ] );

            // Lower thresholds so shorter words also benefit from typo tolerance.
            $index->updateTypoTolerance( [
                'enabled'             => true,
                'minWordSizeForTypos' => [
                    'oneTypo'  => 3,
                    'twoTypos' => 7,
                ],
            ] );

            $index->updatePagination( [ 'maxTotalHits' => 200 ] );

        } catch ( \Throwable $e ) {
            error_log( '[WCMeilisearch] Index setup error: ' . $e->getMessage() );
        }
    }

    // ---------------------------------------------------------------------------
    // Public search API
    // ---------------------------------------------------------------------------

    /**
     * Run a search with automatic fallback strategies.
     *
     * All strategies are generic – they work for any product name or brand
     * without any hardcoded knowledge of the catalog.
     */
    public function search( string $query, array $options = [] ): array {
        $cache_key = 'meili_search_' . md5( $query . serialize( $options ) );

        // --- Cache read ---
        if ( $this->redis ) {
            try {
                $cached = $this->redis->get( $cache_key );
                if ( $cached ) {
                    $data           = json_decode( $cached, true );
                    $data['cached'] = true;
                    return $data;
                }
            } catch ( \Throwable $e ) {
                error_log( '[WCMeilisearch] Redis read error: ' . $e->getMessage() );
            }
        }

        // --- Layer 1: standard search ---
        $data = $this->raw_search( $query, $options );

        // --- Layer 2: compound-word split ---
        // "santajulia" → tries "santa julia", "santaj ulia", "san tajulia"…
        if ( empty( $data['results'] ) ) {
            $split = $this->try_compound_splits( $query, $options );
            if ( $split !== null ) {
                $data             = $split;
                $data['fallback'] = 'split';
            }
        }

        // --- Layer 3: first-char-strip on name_alt ---
        // "zanta jul" → strip first chars → "anta ul" → search in name_alt.
        // Compensates for Meilisearch's hard engine limit (no typo on char[0]).
        if ( empty( $data['results'] ) ) {
            $alt = $this->try_first_char_strip( $query, $options );
            if ( $alt !== null ) {
                $data             = $alt;
                $data['fallback'] = 'first_char_strip';
            }
        }

        // --- Layer 4: phonetic matching on name_phonetic ---
        // "jhonny" → metaphone → "JN" → matches "JN" in name_phonetic of Johnnie Walker.
        // Generic: works for any brand without hardcoding.
        if ( empty( $data['results'] ) ) {
            $alt = $this->try_phonetic( $query, $options );
            if ( $alt !== null ) {
                $data             = $alt;
                $data['fallback'] = 'phonetic';
            }
        }

        // --- Layer 5: compound-split THEN first-char-strip on name_alt ---
        // "zantajul" → split→("zanta","jul") → strip→("anta","ul") → name_alt.
        // Handles compound words where the first char is also wrong.
        // Uses Meilisearch prefix on the last (short) word, no matchingStrategy tricks.
        if ( empty( $data['results'] ) ) {
            $alt = $this->try_compound_split_then_strip( $query, $options );
            if ( $alt !== null ) {
                $data             = $alt;
                $data['fallback'] = 'compound_strip';
            }
        }




        $data['cached'] = false;

        // --- Cache write (only when we have results) ---
        if ( $this->redis && ! empty( $data['results'] ) ) {
            try {
                $this->redis->setex( $cache_key, self::CACHE_TTL, json_encode( $data ) );
            } catch ( \Throwable $e ) {
                error_log( '[WCMeilisearch] Redis write error: ' . $e->getMessage() );
            }
        }

        return $data;
    }

    // ---------------------------------------------------------------------------
    // Fallback strategies (private)
    // ---------------------------------------------------------------------------

    /**
     * Try splitting a compound (no-space) word at every position and run all
     * variants as a batched multi-search.
     *
     * Works for any language – no dictionary required.
     *
     * @return array|null  Search result array, or null if nothing found.
     */
    private function try_compound_splits( string $query, array $options ): ?array {
        $words      = preg_split( '/\s+/u', trim( $query ), -1, PREG_SPLIT_NO_EMPTY ) ?: [];
        $candidates = [];

        foreach ( $words as $word ) {
            $len = mb_strlen( $word, 'UTF-8' );
            if ( $len < 8 ) {
                continue;
            }
            for ( $i = 4; $i <= $len - 4; $i++ ) {
                $left  = mb_substr( $word, 0, $i, 'UTF-8' );
                $right = mb_substr( $word, $i, null, 'UTF-8' );
                // Replace this word with the two halves; keep other words intact.
                $other_words     = array_filter( $words, fn( $w ) => $w !== $word );
                $candidate_parts = array_merge( [ $left, $right ], array_values( $other_words ) );
                $candidates[]    = implode( ' ', $candidate_parts );
            }
        }

        if ( empty( $candidates ) ) {
            return null;
        }

        return $this->run_multi_search( $candidates, $options );
    }

    /**
     * Strip the first character of every word in the query and search in
     * the `name_alt` field (which contains every product name pre-processed
     * the same way).
     *
     * This is the generic fix for Meilisearch's "no typo on first char" limit.
     *
     * @return array|null
     */
    private function try_first_char_strip( string $query, array $options ): ?array {
        // Single compound words (no spaces) are better handled by Layer 4
        // (compound-split + strip), because stripping a compound word like
        // "zantajul" → "antajul" produces a single token that typo-matches
        // unrelated products (e.g. "antamilano" within 2 edits).
        if ( ! str_contains( $query, ' ' ) ) {
            return null;
        }

        $alt_query = $this->strip_first_chars( $query );

        // Skip if stripping changed nothing (e.g. all single-char words).
        if ( $alt_query === $query || $alt_query === '' ) {
            return null;
        }

        $alt_options = array_merge(
            $options,
            [ 'attributesToSearchOn' => [ 'name_alt' ] ]
        );

        $result = $this->raw_search( $alt_query, $alt_options );

        return empty( $result['results'] ) ? null : $result;
    }

    /**
     * Convert every query word to its metaphone code and search name_phonetic.
     * "jhonny walker" → "JN WLKR" → finds "Johnnie Walker" (stored as "JN WLKR").
     * "joni"          → "JN"      → finds "Johnnie Walker".
     */
    private function try_phonetic( string $query, array $options ): ?array {
        $words     = preg_split( '/\s+/u', mb_strtolower( trim( $query ), 'UTF-8' ), -1, PREG_SPLIT_NO_EMPTY ) ?: [];
        $phonetics = [];
        foreach ( $words as $word ) {
            $code = metaphone( $word );
            if ( $code !== '' ) {
                $phonetics[] = $code;
            }
        }

        $phonetic_query = implode( ' ', $phonetics );
        if ( $phonetic_query === '' ) {
            return null;
        }

        $alt_options = array_merge(
            $options,
            [ 'attributesToSearchOn' => [ 'name_phonetic' ] ]
        );

        $result = $this->raw_search( $phonetic_query, $alt_options );

        return empty( $result['results'] ) ? null : $result;
    }

    /**
     * Split each compound word at every position (range 4..len-3), strip the
     * first character of every resulting part, then search in name_alt.
     *
     * "zantajul" → split i=5 → "zanta"+"jul" → strip → "anta"+"ul"
     *            → name_alt search: "anta ul"
     *            → Meilisearch prefix-matches "ul*" → "ulia" → Santa Julia ✓
     *
     * Range len-3 (vs len-4 in Layer 2) captures short right-side tails (3 chars)
     * that work via Meilisearch's built-in last-word prefix matching.
     */
    private function try_compound_split_then_strip( string $query, array $options ): ?array {
        $words      = preg_split( '/\s+/u', trim( $query ), -1, PREG_SPLIT_NO_EMPTY ) ?: [];
        $candidates = [];

        foreach ( $words as $word ) {
            $len = mb_strlen( $word, 'UTF-8' );
            if ( $len < 7 ) {
                continue;
            }
            for ( $i = 4; $i <= $len - 3; $i++ ) {
                $left  = mb_substr( $word, 0, $i, 'UTF-8' );
                $right = mb_substr( $word, $i, null, 'UTF-8' );

                $other_words = array_filter( $words, fn( $w ) => $w !== $word );
                $parts       = array_merge( [ $left, $right ], array_values( $other_words ) );
                $candidates[] = implode( ' ', $parts );
            }
        }

        if ( empty( $candidates ) ) {
            return null;
        }

        $alt_options = array_merge(
            $options,
            [ 'attributesToSearchOn' => [ 'name_alt' ] ]
        );

        foreach ( $candidates as $candidate ) {
            $stripped = $this->strip_first_chars( $candidate );
            $result   = $this->raw_search( $stripped, $alt_options );
            if ( ! empty( $result['results'] ) ) {
                return $result;
            }
        }

        return null;
    }

    /**
     * Remove the first character of every word longer than 2 chars.
     * "zanta jul" → "anta ul"   |   "Santa Julia Malbec" → "anta ulia albec"
     */
    private function strip_first_chars( string $query ): string {
        $lower = mb_strtolower( trim( $query ), 'UTF-8' );
        $words = preg_split( '/\s+/u', $lower, -1, PREG_SPLIT_NO_EMPTY ) ?: [];

        $stripped = array_map( function ( string $w ): string {
            return mb_strlen( $w, 'UTF-8' ) > 2
                ? mb_substr( $w, 1, null, 'UTF-8' )
                : $w;
        }, $words );

        return implode( ' ', $stripped );
    }

    /**
     * Run candidate queries one by one, stopping at the first with results.
     *
     * Sequential calls are fine here: Meilisearch is local (sub-ms latency),
     * and the candidate list is typically small (≤ 8 items). Using the SDK's
     * multiSearch caused compatibility issues with the installed SDK version.
     */
    private function run_multi_search( array $candidates, array $options ): ?array {
        foreach ( $candidates as $candidate ) {
            $result = $this->raw_search( $candidate, $options );
            if ( ! empty( $result['results'] ) ) {
                return $result;
            }
        }
        return null;
    }

    // ---------------------------------------------------------------------------
    // Direct search (no cache, no fallback)
    // ---------------------------------------------------------------------------

    private function raw_search( string $query, array $options = [] ): array {
        try {
            $defaults = [
                'limit'                => 10,
                'attributesToRetrieve' => [ 'id', 'name', 'price', 'image', 'url', 'categories', 'stock_status', 'in_stock' ],
            ];

            $result = $this->meili
                ->index( self::INDEX_NAME )
                ->search( $query, array_merge( $defaults, $options ) );

            return [
                'results'          => $result->getHits(),
                'processingTimeMs' => $result->getProcessingTimeMs(),
                'cached'           => false,
                'facets'           => $result->getFacetDistribution() ?: [],
            ];
        } catch ( \Throwable $e ) {
            error_log( '[WCMeilisearch] Search error: ' . $e->getMessage() );
            return [ 'results' => [], 'processingTimeMs' => 0, 'cached' => false ];
        }
    }

    // ---------------------------------------------------------------------------
    // Index mutations
    // ---------------------------------------------------------------------------

    public function upsert_documents( array $documents ): void {
        try {
            $this->meili->index( self::INDEX_NAME )->addDocuments( $documents );
            $this->invalidate_cache();
        } catch ( \Throwable $e ) {
            error_log( '[WCMeilisearch] Upsert error: ' . $e->getMessage() );
        }
    }

    public function delete_document( int $id ): void {
        try {
            $this->meili->index( self::INDEX_NAME )->deleteDocument( $id );
            $this->invalidate_cache();
        } catch ( \Throwable $e ) {
            error_log( '[WCMeilisearch] Delete error: ' . $e->getMessage() );
        }
    }

    public function clear_index(): void {
        try {
            $this->meili->index( self::INDEX_NAME )->deleteAllDocuments();
            $this->invalidate_cache();
        } catch ( \Throwable $e ) {
            error_log( '[WCMeilisearch] Clear index error: ' . $e->getMessage() );
        }
    }

    public function invalidate_cache(): void {
        if ( ! $this->redis ) {
            return;
        }
        try {
            $keys = $this->redis->keys( 'meili_search_*' );
            if ( ! empty( $keys ) ) {
                $this->redis->del( $keys );
            }
        } catch ( \Throwable $e ) {
            error_log( '[WCMeilisearch] Cache invalidation error: ' . $e->getMessage() );
        }
    }

    public function test_connection(): bool {
        try {
            $health = $this->meili->health();
            return isset( $health['status'] ) && $health['status'] === 'available';
        } catch ( \Throwable $e ) {
            return false;
        }
    }

    public function raw_client(): Client {
        return $this->meili;
    }
}
