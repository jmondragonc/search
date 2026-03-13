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

define( 'WCM_VERSION',     '1.1.2' );
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
        /* Classic Mode: sin backdrop, el panel sale abajo del input */
        #wcm-lightbox-overlay.wcm-is-classic {
            background: transparent;
            backdrop-filter: none;
            -webkit-backdrop-filter: none;
            top: 65px;       /* justo debajo de la barra del header */
            bottom: auto;    /* no se extiende hasta el fondo */
            padding-top: 0;
            overflow: visible;
        }
        #wcm-lightbox-overlay.wcm-is-classic #wcm-lightbox-modal {
            border-radius: 0 0 16px 16px;
            transform: none !important;  /* sin animación de escala */
            box-shadow: 0 8px 30px rgba(0,0,0,0.15);
            max-height: 70vh;
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
        .wcm-tag::before {
            content: "";
            display: inline-block;
            width: 12px;
            height: 12px;
            background: url('<?php echo esc_url( WCM_PLUGIN_URL . '1f50d.svg' ); ?>') no-repeat center;
            background-size: contain;
            margin-right: 4px;
            vertical-align: middle;
            opacity: 0.6;
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
