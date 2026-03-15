<?php
/**
 * Plugin Name:       WC Meilisearch
 * Plugin URI:        https://github.com/local/wc-meilisearch
 * Description:       Intelligent product search with Meilisearch, autocomplete, typo-tolerance and Redis caching.
 * Version:           1.0.0
 * Requires at least: 6.0
 * Requires PHP:      8.0
 * Author:            Local Dev
 * Text Domain:       wc-meilisearch
 * Domain Path:       /languages
 * WC requires at least: 7.0
 * WC tested up to:      8.5
 */

defined( 'ABSPATH' ) || exit;

define( 'WCM_VERSION',     '1.2.0' );
define( 'WCM_PLUGIN_FILE', __FILE__ );
define( 'WCM_PLUGIN_DIR',  plugin_dir_path( __FILE__ ) );
define( 'WCM_PLUGIN_URL',  plugin_dir_url( __FILE__ ) );

// ---------------------------------------------------------------------------
// Autoloader (Composer)
// ---------------------------------------------------------------------------
if ( file_exists( WCM_PLUGIN_DIR . 'vendor/autoload.php' ) ) {
    require_once WCM_PLUGIN_DIR . 'vendor/autoload.php';
} else {
    add_action( 'admin_notices', function () {
        echo '<div class="notice notice-error"><p><strong>WC Meilisearch:</strong> '
            . esc_html__( 'Run `composer install` inside the plugin directory.', 'wc-meilisearch' )
            . '</p></div>';
    } );
    return;
}

// ---------------------------------------------------------------------------
// Bootstrap
// ---------------------------------------------------------------------------
add_action( 'plugins_loaded', function () {
    if ( ! class_exists( 'WooCommerce' ) ) {
        add_action( 'admin_notices', function () {
            echo '<div class="notice notice-error"><p><strong>WC Meilisearch:</strong> '
                . esc_html__( 'WooCommerce must be active.', 'wc-meilisearch' )
                . '</p></div>';
        } );
        return;
    }

    require_once WCM_PLUGIN_DIR . 'includes/class-meilisearch-client.php';
    require_once WCM_PLUGIN_DIR . 'includes/class-product-indexer.php';
    require_once WCM_PLUGIN_DIR . 'includes/class-admin-page.php';

    // Boot singletons.
    \WCMeilisearch\MeilisearchClient::instance();
    \WCMeilisearch\ProductIndexer::instance();
    \WCMeilisearch\AdminPage::instance();

    // Frontend autocomplete script + search results page.
    add_action( 'wp_enqueue_scripts', 'wcm_enqueue_frontend' );
    add_action( 'wp_body_open',       'wcm_render_header_searchbar' );
    add_filter( 'template_include',   'wcm_search_template' );

    // Store active search term in a cookie so S&F Pro filter navigations
    // (which drop ?s= from the URL) can still recover the search context.
    add_action( 'template_redirect', 'wcm_manage_search_cookie' );

    // Inject Meilisearch IDs into S&F Pro filter queries via pre_get_posts
    // (priority 5, before S&F Pro at 10) so filter counts also reflect search.
    add_action( 'pre_get_posts', 'wcm_sfp_restrict_by_meilisearch', 5 );

    // Phonetic fallback for standard ?s= searches that MySQL can't handle.
    add_filter( 'posts_results', 'wcm_meilisearch_fallback', 10, 2 );
} );

/**
 * Render a search trigger in the header and the lightbox modal injected right after <body> opens.
 * Works with any theme – no template modifications needed.
 */
function wcm_render_header_searchbar(): void {
    $enable_lb = get_option( 'wcm_enable_lightbox', 'yes' );

    if ( 'yes' === $enable_lb ) {
        // --- MODO LIGHTBOX (MODERNO) ---
        ?>
        <!-- Trigger Button (Sticky Header or similar) -->
        <div id="wcm-header-bar">
            <div id="wcm-header-inner">
                <img src="<?php echo esc_url( WCM_PLUGIN_URL . '1f50d.svg' ); ?>" alt="Search" id="wcm-logo" width="20" height="20">
                <div id="wcm-header-trigger">
                    <span class="wcm-search-placeholder">Buscar vinos, categorías, ofertas...</span>
                </div>
            </div>
        </div>

        <!-- Lightbox Modal -->
        <div id="wcm-lightbox-overlay" style="display: none;">
            <div id="wcm-lightbox-modal">
                
                <!-- Modal Header -->
                <div class="wcm-modal-header">
                    <div class="wcm-search-input-wrapper">
                        <img src="<?php echo esc_url( WCM_PLUGIN_URL . '1f50d.svg' ); ?>" alt="Search" class="wcm-search-icon" width="20" height="20">
                        <form action="/" method="get" role="search" id="wcm-header-form" onsubmit="return false;">
                            <input
                                type="search"
                                id="wcm-header-input"
                                name="s"
                                placeholder="Buscar vinos, categorías, ofertas..."
                                autocomplete="off"
                                aria-label="Buscar productos"
                            >
                        </form>
                        <button id="wcm-modal-close">Cerrar</button>
                    </div>
                    
                    <!-- Chips (Filtros Rápidos) -->
                    <div class="wcm-chips-container">
                        <button class="wcm-chip active" data-filter="">
                            <span class="wcm-chip-icon">🍷</span> Todos
                        </button>
                        <?php
                        $top_categories = get_terms( [
                            'taxonomy'   => 'product_cat',
                            'hide_empty' => true,
                            'parent'     => 0,
                            'number'     => 6,
                            'orderby'    => 'count',
                            'order'      => 'DESC',
                        ] );

                        if ( ! is_wp_error( $top_categories ) && ! empty( $top_categories ) ) {
                            foreach ( $top_categories as $cat ) {
                                $icon = '🍷';
                                if ( stripos( $cat->name, 'espumante' ) !== false ) {
                                    $icon = '✨';
                                } elseif ( stripos( $cat->name, 'cerveza' ) !== false ) {
                                    $icon = '🍺';
                                } elseif ( stripos( $cat->name, 'licor' ) !== false || stripos( $cat->name, 'destilado' ) !== false ) {
                                    $icon = '🥃';
                                }
                                echo '<button class="wcm-chip" data-filter="' . esc_attr( $cat->name ) . '">';
                                echo '<span class="wcm-chip-icon">' . $icon . '</span> ' . esc_html( $cat->name );
                                echo '</button>';
                            }
                        }
                        ?>
                    </div>
                </div>

                <!-- Modal Content (Scrollable) -->
                <div class="wcm-modal-content">
                    
                    <!-- Initial View (Recents, Popular, Featured) -->
                    <div id="wcm-initial-view">
                        <!-- Búsquedas recientes -->
                        <div class="wcm-section" id="wcm-recent-searches-section" style="display: none;">
                            <div class="wcm-section-header">
                                <span class="wcm-section-title">🕒 Búsquedas recientes</span>
                                <button id="wcm-clear-recent">🗑️ Limpiar</button>
                            </div>
                            <div class="wcm-tags-container" id="wcm-recent-tags"></div>
                        </div>

                        <!-- Búsquedas populares -->
                        <div class="wcm-section">
                            <div class="wcm-section-header">
                                <span class="wcm-section-title">📈 Búsquedas populares</span>
                            </div>
                            <div class="wcm-tags-container">
                                <button class="wcm-tag" data-query="malbec">malbec</button>
                                <button class="wcm-tag" data-query="cabernet">cabernet</button>
                                <button class="wcm-tag" data-query="vinos tintos">vinos tintos</button>
                                <button class="wcm-tag" data-query="ofertas">ofertas</button>
                                <button class="wcm-tag" data-query="espumantes">espumantes</button>
                            </div>
                        </div>

                        <!-- Productos destacados -->
                        <div class="wcm-section" style="display:none;">
                            <div class="wcm-section-header">
                                <span class="wcm-section-title">✨ Productos destacados</span>
                            </div>
                            <div class="wcm-results-grid" id="wcm-featured-results">
                                <!-- Destacados se cargan por JS/AJAX inicial o hardcodeados temporalmente -->
                            </div>
                        </div>
                    </div>

                    <!-- Search Results View -->
                    <div id="wcm-results-view" style="display: none;">
                        <div class="wcm-results-header">
                            <span id="wcm-results-count">0 resultados</span>
                            <a href="#" id="wcm-view-all-link">Ver todos →</a>
                        </div>
                        <div class="wcm-results-grid" id="wcm-search-results">
                            <!-- Resultados inyectados por JS -->
                        </div>
                    </div>

                </div>
            </div>
        </div>
        <?php
    } else {
        // --- MODO CLÁSICO: misma UI que el lightbox, solo sin el backdrop oscuro ---
        ?>
        <!-- Trigger Button igual que el Lightbox -->
        <div id="wcm-header-bar">
            <div id="wcm-header-inner">
                <img src="<?php echo esc_url( WCM_PLUGIN_URL . '1f50d.svg' ); ?>" alt="Search" id="wcm-logo" width="20" height="20">
                <div id="wcm-header-trigger">
                    <span class="wcm-search-placeholder">Buscar vinos, categorías, ofertas...</span>
                </div>
            </div>
        </div>

        <!-- Mismo modal que el lightbox, con clase wcm-is-classic para quitar solo el backdrop -->
        <div id="wcm-lightbox-overlay" class="wcm-is-classic" style="display: none;">
            <div id="wcm-lightbox-modal">
                
                <!-- Modal Header -->
                <div class="wcm-modal-header">
                    <div class="wcm-search-input-wrapper">
                        <img src="<?php echo esc_url( WCM_PLUGIN_URL . '1f50d.svg' ); ?>" alt="Search" class="wcm-search-icon" width="20" height="20">
                        <form action="/" method="get" role="search" id="wcm-header-form" onsubmit="return false;">
                            <input
                                type="search"
                                id="wcm-header-input"
                                name="s"
                                placeholder="Buscar vinos, categorías, ofertas..."
                                autocomplete="off"
                                aria-label="Buscar productos"
                            >
                        </form>
                        <button id="wcm-modal-close">Cerrar</button>
                    </div>
                    
                    <!-- Chips (Filtros Rápidos) -->
                    <div class="wcm-chips-container">
                        <button class="wcm-chip active" data-filter="">
                            <span class="wcm-chip-icon">🍷</span> Todos
                        </button>
                        <?php
                        $top_categories_c = get_terms( [
                            'taxonomy'   => 'product_cat',
                            'hide_empty' => true,
                            'parent'     => 0,
                            'number'     => 6,
                            'orderby'    => 'count',
                            'order'      => 'DESC',
                        ] );
                        if ( ! is_wp_error( $top_categories_c ) && ! empty( $top_categories_c ) ) {
                            foreach ( $top_categories_c as $cat ) {
                                $icon = '🍷';
                                if ( stripos( $cat->name, 'espumante' ) !== false ) { $icon = '✨'; }
                                elseif ( stripos( $cat->name, 'cerveza' ) !== false ) { $icon = '🍺'; }
                                elseif ( stripos( $cat->name, 'licor' ) !== false || stripos( $cat->name, 'destilado' ) !== false ) { $icon = '🥃'; }
                                echo '<button class="wcm-chip" data-filter="' . esc_attr( $cat->name ) . '">';
                                echo '<span class="wcm-chip-icon">' . $icon . '</span> ' . esc_html( $cat->name );
                                echo '</button>';
                            }
                        }
                        ?>
                    </div>
                </div>

                <!-- Modal Content (Scrollable) -->
                <div class="wcm-modal-content">
                    
                    <!-- Initial View -->
                    <div id="wcm-initial-view">
                        <div class="wcm-section" id="wcm-recent-searches-section" style="display: none;">
                            <div class="wcm-section-header">
                                <span class="wcm-section-title">🕒 Búsquedas recientes</span>
                                <button id="wcm-clear-recent">🗑️ Limpiar</button>
                            </div>
                            <div class="wcm-tags-container" id="wcm-recent-tags"></div>
                        </div>
                        <div class="wcm-section">
                            <div class="wcm-section-header">
                                <span class="wcm-section-title">📈 Búsquedas populares</span>
                            </div>
                            <div class="wcm-tags-container">
                                <button class="wcm-tag" data-query="malbec">malbec</button>
                                <button class="wcm-tag" data-query="cabernet">cabernet</button>
                                <button class="wcm-tag" data-query="vinos tintos">vinos tintos</button>
                                <button class="wcm-tag" data-query="ofertas">ofertas</button>
                                <button class="wcm-tag" data-query="espumantes">espumantes</button>
                            </div>
                        </div>
                    </div>

                    <!-- Search Results View -->
                    <div id="wcm-results-view" style="display: none;">
                        <div class="wcm-results-header">
                            <span id="wcm-results-count">0 resultados</span>
                            <a href="#" id="wcm-view-all-link">Ver todos →</a>
                        </div>
                        <div class="wcm-results-grid" id="wcm-search-results"></div>
                    </div>

                </div>
            </div>
        </div>
        <?php
    }
    ?>

    <!-- Estilos del Lightbox -->
    <style>
        /* Trigger Bar */
        #wcm-header-bar {
            position: fixed;
            top: 0; left: 0; right: 0;
            z-index: 99990;
            background: #ffffff;
            padding: 10px 20px;
            box-shadow: 0 1px 4px rgba(0,0,0,0.05);
            display: flex;
            align-items: center;
            border-bottom: 1px solid #eee;
        }
        #wcm-header-inner {
            display: flex;
            align-items: center;
            gap: 15px;
            width: 100%;
            max-width: 1200px;
            margin: 0 auto;
        }
        #wcm-logo { font-size: 20px; flex-shrink: 0; cursor: pointer; }
        #wcm-header-trigger {
            flex: 1;
            padding: 12px 18px;
            font-size: 15px;
            background: #f5f5f5;
            border-radius: 24px;
            cursor: pointer;
            color: #888;
            transition: background 0.2s;
        }
        #wcm-header-trigger:hover {
            background: #eeeeee;
        }
        body { padding-top: 65px !important; }

        /* Lightbox Overlay */
        #wcm-lightbox-overlay {
            position: fixed;
            top: 0; left: 0; right: 0; bottom: 0;
            background: rgba(0, 0, 0, 0.6);
            backdrop-filter: blur(4px);
            -webkit-backdrop-filter: blur(4px);
            z-index: 99999;
            display: flex;
            justify-content: center;
            align-items: flex-start;
            padding-top: 60px;
            opacity: 0;
            transition: opacity 0.2s ease;
        }
        #wcm-lightbox-overlay.wcm-open {
            opacity: 1;
        }
        /* Classic Mode: sin backdrop oscuro, el resto igual */
        #wcm-lightbox-overlay.wcm-is-classic {
            background: transparent;
            backdrop-filter: none;
            -webkit-backdrop-filter: none;
        }

        /* Lightbox Modal */
        #wcm-lightbox-modal {
            width: 100%;
            max-width: 680px;
            background: #ffffff;
            border-radius: 16px;
            box-shadow: 0 10px 40px rgba(0,0,0,0.2);
            display: flex;
            flex-direction: column;
            max-height: 85vh;
            overflow: hidden;
            transform: translateY(10px) scale(0.98);
            transition: transform 0.2s ease;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
        }
        #wcm-lightbox-overlay.wcm-open #wcm-lightbox-modal {
            transform: translateY(0) scale(1);
        }

        /* Modal Header */
        .wcm-modal-header {
            padding: 20px 24px 16px 24px;
            border-bottom: 1px solid #f0f0f0;
        }
        .wcm-search-input-wrapper {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 16px;
        }
        .wcm-search-icon {
            font-size: 20px;
            color: #666;
            display: flex;
            align-items: center;
        }
        #wcm-header-form {
            flex: 1;
            margin: 0;
            display: flex;
            align-items: center;
        }
        #wcm-header-input {
            width: 100%;
            border: none;
            font-size: 18px;
            outline: none;
            color: #333;
            background: transparent;
            padding: 0;
            margin: 0;
            line-height: normal;
        }
        #wcm-header-input::placeholder {
            color: #a0a0a0;
        }
        #wcm-modal-close {
            background: none;
            border: none;
            color: #666;
            font-size: 15px;
            cursor: pointer;
            padding: 0 8px;
            display: flex;
            align-items: center;
        }
        #wcm-modal-close:hover {
            color: #000;
        }

        /* Chips Container */
        .wcm-chips-container {
            display: flex;
            gap: 10px;
            overflow-x: auto;
            scrollbar-width: none;
            -ms-overflow-style: none;
            padding-bottom: 4px;
        }
        .wcm-chips-container::-webkit-scrollbar {
            display: none;
        }
        .wcm-chip {
            background: #f5f5f5;
            border: 1px solid transparent;
            border-radius: 20px;
            padding: 8px 16px;
            font-size: 14px;
            font-weight: 500;
            color: #444;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 6px;
            white-space: nowrap;
            transition: all 0.2s;
        }
        .wcm-chip:hover {
            background: #eaeaea;
        }
        .wcm-chip.active {
            background: #8b1c31; /* Un color vino tinto típico */
            color: #fff;
        }
        .wcm-chip-icon {
            font-size: 14px;
        }

        /* Modal Content Scrollable Area */
        .wcm-modal-content {
            flex: 1;
            overflow-y: auto;
            padding: 20px 24px;
            background: #fafafa;
        }

        /* Sections */
        .wcm-section {
            margin-bottom: 28px;
        }
        .wcm-section-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 12px;
        }
        .wcm-section-title {
            font-weight: 600;
            font-size: 15px;
            color: #333;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        #wcm-clear-recent {
            background: none;
            border: none;
            color: #888;
            font-size: 13px;
            cursor: pointer;
        }
        #wcm-clear-recent:hover {
            color: #d32f2f;
        }

        /* Tags Container */
        .wcm-tags-container {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
        }
        .wcm-tag {
            background: #ffffff;
            border: 1px solid #e0e0e0;
            border-radius: 16px;
            padding: 6px 14px;
            font-size: 14px;
            color: #444;
            cursor: pointer;
            transition: border-color 0.2s;
        }
        .wcm-tag:hover {
            border-color: #8b1c31;
        }

        /* Results View */
        .wcm-results-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 16px;
        }
        #wcm-results-count {
            font-weight: 600;
            font-size: 15px;
            color: #333;
        }
        #wcm-view-all-link {
            color: #8b1c31;
            font-size: 14px;
            font-weight: 500;
            text-decoration: none;
        }
        #wcm-view-all-link:hover {
            text-decoration: underline;
        }

        /* Results Grid */
        .wcm-results-grid {
            display: flex;
            flex-direction: column;
            gap: 12px;
        }
        .wcm-product-card {
            display: flex;
            align-items: center;
            background: #fff;
            padding: 12px;
            border-radius: 12px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.04);
            text-decoration: none;
            color: inherit;
            transition: background 0.2s;
            border: 1px solid transparent;
        }
        .wcm-product-card:hover, .wcm-product-card.wcm-active {
            background: #fffafa;
            border-color: #ffe6e6;
        }
        .wcm-product-image-wrap {
            width: 60px;
            height: 60px;
            border-radius: 8px;
            background: #ffebeb;
            display: flex;
            justify-content: center;
            align-items: center;
            flex-shrink: 0;
            margin-right: 16px;
            overflow: hidden;
            position: relative;
        }
        .wcm-product-image-wrap img {
            width: 100%;
            height: 100%;
            object-fit: contain;
            mix-blend-mode: multiply;
        }
        .wcm-product-info {
            flex: 1;
            min-width: 0;
        }
        .wcm-product-title {
            font-weight: 500;
            font-size: 15px;
            color: #222;
            margin-bottom: 4px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .wcm-product-price-row {
            display: flex;
            align-items: baseline;
            gap: 8px;
            margin-bottom: 4px;
        }
        .wcm-product-price {
            font-weight: 700;
            font-size: 16px;
            color: #d32f2f;
        }
        .wcm-product-meta {
            font-size: 13px;
            color: #888;
            display: flex;
            align-items: center;
            gap: 6px;
        }
        .wcm-product-meta-dot {
            font-size: 10px;
            opacity: 0.5;
        }

        /* Modal Footer */
        .wcm-modal-footer {
            padding: 12px 24px;
            border-top: 1px solid #f0f0f0;
            background: #fff;
            display: flex;
            gap: 16px;
            justify-content: center;
        }
        .wcm-key-hint {
            font-size: 12px;
            color: #888;
            display: flex;
            align-items: center;
            gap: 4px;
        }
        kbd {
            background: #f5f5f5;
            border: 1px solid #ddd;
            border-radius: 4px;
            padding: 2px 6px;
            font-family: monospace;
            font-size: 11px;
            color: #555;
            box-shadow: 0 1px 1px rgba(0,0,0,0.1);
        }

        @media (max-width: 768px) {
            #wcm-lightbox-overlay {
                padding-top: 0;
            }
            #wcm-lightbox-modal {
                height: 100vh;
                max-height: 100vh;
                border-radius: 0;
                transform: translateY(100%);
            }
            #wcm-lightbox-overlay.wcm-open #wcm-lightbox-modal {
                transform: translateY(0);
            }
            .wcm-modal-footer {
                display: none; /* Hide hints on mobile */
            }
        }
    </style>
    <?php
}

/**
 * Enqueue the vanilla-JS autocomplete widget on every frontend page.
 * On search results pages, also enqueue the results grid script.
 */
function wcm_enqueue_frontend(): void {
    wp_enqueue_script(
        'wcm-autocomplete',
        WCM_PLUGIN_URL . 'assets/autocomplete.js',
        [],
        WCM_VERSION,
        true
    );

    wp_localize_script( 'wcm-autocomplete', 'wcmSearch', [
        'ajaxUrl'  => WCM_PLUGIN_URL . 'ajax-search.php',
        'nonce'    => wp_create_nonce( 'wcm_search' ),
        'minChars' => 2,
        'debounce' => 150,
    ] );

    if ( is_search() ) {
        wp_enqueue_script(
            'wcm-search-results',
            WCM_PLUGIN_URL . 'assets/search-results.js',
            [ 'wcm-autocomplete' ],
            WCM_VERSION,
            true
        );
    }
}

/**
 * Serve our custom search template instead of the theme's search.php.
 */
function wcm_search_template( string $template ): string {
    if ( is_search() ) {
        $custom = WCM_PLUGIN_DIR . 'templates/search-results.php';
        if ( file_exists( $custom ) ) {
            return $custom;
        }
    }
    return $template;
}

// ---------------------------------------------------------------------------
// S&F Pro search-context preservation
// ---------------------------------------------------------------------------

/**
 * Returns the active Meilisearch search term when in an S&F Pro filter context.
 * Reads from: (1) wcm_search cookie, (2) HTTP_REFERER ?s= param.
 */
function wcm_get_sfp_search_term(): string {
    if ( ! empty( $_COOKIE['wcm_search'] ) ) {
        return sanitize_text_field( $_COOKIE['wcm_search'] );
    }
    if ( ! empty( $_SERVER['HTTP_REFERER'] ) ) {
        $referer = wp_http_validate_url( sanitize_text_field( wp_unslash( $_SERVER['HTTP_REFERER'] ) ) );
        if ( $referer ) {
            $ref_parts = wp_parse_url( $referer );
            if ( ! empty( $ref_parts['query'] ) ) {
                parse_str( $ref_parts['query'], $ref_params );
                if ( ! empty( $ref_params['s'] ) && strlen( $ref_params['s'] ) >= 2 ) {
                    return sanitize_text_field( $ref_params['s'] );
                }
            }
        }
    }
    return '';
}

/**
 * Calls Meilisearch and returns matching product post IDs.
 * Results are cached per request to avoid duplicate API calls.
 *
 * @return int[]
 */
function wcm_get_cached_meilisearch_ids( string $s ): array {
    static $cache = [];
    if ( isset( $cache[ $s ] ) ) {
        return $cache[ $s ];
    }
    try {
        $client      = \WCMeilisearch\MeilisearchClient::instance();
        $result      = $client->search( $s, [ 'limit' => 200 ] );
        $ids         = array_column( $result['results'] ?? [], 'id' );
        $cache[ $s ] = array_values( array_filter( array_map( 'intval', $ids ) ) );
    } catch ( \Throwable $e ) {
        error_log( '[WCMeilisearch] search error: ' . $e->getMessage() );
        $cache[ $s ] = [];
    }
    return $cache[ $s ];
}

/**
 * Sets / clears the wcm_search cookie.
 *
 * - On a product search page (?s=…&post_type=product): stores the term (10 min).
 * - On any other page that is NOT an S&F Pro filter page: clears the cookie.
 */
function wcm_manage_search_cookie(): void {
    if ( headers_sent() ) {
        return;
    }

    $is_sfp = false;
    foreach ( array_keys( $_GET ) as $key ) {
        if ( str_starts_with( (string) $key, '_sft_' ) || str_starts_with( (string) $key, '_sfm_' ) ) {
            $is_sfp = true;
            break;
        }
    }

    if ( is_search() && isset( $_GET['s'], $_GET['post_type'] )
         && 'product' === sanitize_key( $_GET['post_type'] ) ) {
        $s = sanitize_text_field( wp_unslash( $_GET['s'] ) );
        if ( strlen( $s ) >= 2 ) {
            setcookie( 'wcm_search', $s, time() + 600, COOKIEPATH, COOKIE_DOMAIN, is_ssl(), true );
        }
    } elseif ( ! $is_sfp ) {
        setcookie( 'wcm_search', '', time() - 3600, COOKIEPATH, COOKIE_DOMAIN, is_ssl(), true );
    }
}

/**
 * Injects Meilisearch IDs into every WP_Query on S&F Pro filter pages
 * (pre_get_posts priority 5, before S&F Pro at 10).
 *
 * Affects ALL product queries — including S&F Pro count sub-queries — so
 * the filter option counts reflect the active search context.
 */
function wcm_sfp_restrict_by_meilisearch( WP_Query $query ): void {
    if ( is_admin() ) {
        return;
    }

    $is_sfp = false;
    foreach ( array_keys( $_GET ) as $key ) {
        if ( str_starts_with( (string) $key, '_sft_' ) || str_starts_with( (string) $key, '_sfm_' ) ) {
            $is_sfp = true;
            break;
        }
    }
    if ( ! $is_sfp ) {
        return;
    }

    $s = wcm_get_sfp_search_term();
    if ( strlen( $s ) < 2 ) {
        return;
    }

    $pt = $query->get( 'post_type' );
    if ( ! empty( $pt ) && $pt !== 'product' && ! in_array( 'product', (array) $pt, true ) ) {
        return;
    }

    $ids = wcm_get_cached_meilisearch_ids( $s );
    if ( empty( $ids ) ) {
        return;
    }

    $existing = $query->get( 'post__in' );
    if ( ! empty( $existing ) ) {
        $merged = array_values( array_intersect( (array) $existing, $ids ) );
        $query->set( 'post__in', $merged ?: [ -1 ] );
    } else {
        $query->set( 'post__in', $ids );
    }
}

/**
 * Meilisearch fallback via posts_results.
 *
 * Two modes:
 * 1. S&F Pro filter context: user applied a filter after a Meilisearch search.
 *    Detected via wcm_search cookie + _sft_*/_sfm_* params. Always activates
 *    and returns the intersection of Meilisearch hits and S&F Pro conditions.
 * 2. Standard phonetic fallback: MySQL returned 0 results for ?s=term.
 */
function wcm_meilisearch_fallback( array $posts, WP_Query $query ): array {
    if ( is_admin() || ! $query->is_main_query() ) {
        return $posts;
    }

    $s       = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
    $sfp_ctx = false;

    if ( strlen( $s ) < 2 ) {
        foreach ( array_keys( $_GET ) as $key ) {
            if ( str_starts_with( (string) $key, '_sft_' ) || str_starts_with( (string) $key, '_sfm_' ) ) {
                $sfp_ctx = true;
                break;
            }
        }
        if ( $sfp_ctx ) {
            $s = wcm_get_sfp_search_term();
        }
    }

    if ( strlen( $s ) < 2 ) {
        return $posts;
    }

    // Standard mode: only activate when MySQL returned nothing.
    // S&F Pro mode: always activate to override MySQL's unfiltered shop results.
    if ( ! $sfp_ctx && ! empty( $posts ) ) {
        return $posts;
    }

    $post_type = isset( $_GET['post_type'] ) ? sanitize_key( $_GET['post_type'] ) : $query->get( 'post_type' );
    if ( 'product' !== $post_type && ! in_array( 'product', (array) $post_type, true ) ) {
        return $posts;
    }

    try {
        $ids = wcm_get_cached_meilisearch_ids( $s );
        if ( empty( $ids ) ) {
            return $posts;
        }

        // Carry over S&F Pro tax/meta conditions so active filters are respected.
        $tax_query  = $query->get( 'tax_query' )  ?: [];
        $meta_query = $query->get( 'meta_query' ) ?: [];

        $alt = new WP_Query( [
            'post__in'            => $ids,
            'post_type'           => 'product',
            'post_status'         => 'publish',
            'posts_per_page'      => count( $ids ),
            'orderby'             => 'post__in',
            'ignore_sticky_posts' => true,
            'no_found_rows'       => false,
            'tax_query'           => $tax_query,
            'meta_query'          => $meta_query,
        ] );

        if ( ! empty( $alt->posts ) ) {
            $query->found_posts   = $alt->found_posts;
            $query->max_num_pages = $alt->max_num_pages;
            $query->post_count    = count( $alt->posts );
            return $alt->posts;
        }
    } catch ( \Throwable $e ) {
        error_log( '[WCMeilisearch] fallback error: ' . $e->getMessage() );
    }

    return $posts;
}

// ---------------------------------------------------------------------------
// Activation / deactivation hooks
// ---------------------------------------------------------------------------
register_activation_hook( __FILE__, function () {
    // Schedule daily full re-index.
    if ( ! wp_next_scheduled( 'wcm_scheduled_reindex' ) ) {
        wp_schedule_event( time(), 'daily', 'wcm_scheduled_reindex' );
    }
} );

register_deactivation_hook( __FILE__, function () {
    wp_clear_scheduled_hook( 'wcm_scheduled_reindex' );
} );

add_action( 'wcm_scheduled_reindex', function () {
    if ( class_exists( '\WCMeilisearch\ProductIndexer' ) ) {
        \WCMeilisearch\ProductIndexer::instance()->reindex_all();
    }
} );
