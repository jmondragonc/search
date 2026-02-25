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

define( 'WCM_VERSION',     '1.0.2' );
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
 * Render a sticky search bar injected right after <body> opens.
 * Works with any theme – no template modifications needed.
 */
function wcm_render_header_searchbar(): void {
    ?>
    <div id="wcm-header-bar">
        <div id="wcm-header-inner">
            <span id="wcm-logo">🔍</span>
            <form action="/" method="get" role="search" id="wcm-header-form">
                <input
                    type="search"
                    id="wcm-header-input"
                    name="s"
                    placeholder="Buscar vinos, rones, cervezas…"
                    autocomplete="off"
                    aria-label="Buscar productos"
                >
            </form>
        </div>
    </div>
    <style>
        #wcm-header-bar {
            position: fixed;
            top: 0; left: 0; right: 0;
            z-index: 99999;
            background: #1a1a1a;
            padding: 10px 20px;
            box-shadow: 0 2px 8px rgba(0,0,0,.4);
            display: flex;
            align-items: center;
        }
        #wcm-header-inner {
            display: flex;
            align-items: center;
            gap: 10px;
            width: 100%;
            max-width: 700px;
            margin: 0 auto;
        }
        #wcm-logo { font-size: 20px; flex-shrink: 0; }
        #wcm-header-input {
            flex: 1;
            padding: 9px 14px;
            font-size: 15px;
            border: none;
            border-radius: 6px;
            outline: none;
            background: #2d2d2d;
            color: #f0f0f0;
        }
        #wcm-header-input::placeholder { color: #888; }
        /* Push page content below the bar */
        body { padding-top: 56px !important; }

        /* Dropdown inherits the dark theme for header bar inputs */
        #wcm-header-inner .wcm-dropdown {
            background: #2d2d2d;
            border-color: #444;
        }
        #wcm-header-inner .wcm-dropdown li { color: #eee; border-bottom-color: #3a3a3a; }
        #wcm-header-inner .wcm-dropdown li:hover,
        #wcm-header-inner .wcm-dropdown li.wcm-active { background: #3a3a3a; }
        #wcm-header-inner .wcm-dropdown .wcm-footer { color: #666; }
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
