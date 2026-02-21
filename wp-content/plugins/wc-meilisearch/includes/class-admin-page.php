<?php
/**
 * Admin settings page under WooCommerce → Meilisearch.
 *
 * @package WCMeilisearch
 */

namespace WCMeilisearch;

defined( 'ABSPATH' ) || exit;

class AdminPage {

    private static ?self $instance = null;

    private function __construct() {
        add_action( 'admin_menu',             [ $this, 'register_menu' ] );
        add_action( 'admin_init',             [ $this, 'register_settings' ] );
        add_action( 'admin_enqueue_scripts',  [ $this, 'enqueue_admin_assets' ] );
        add_action( 'wp_ajax_wcm_test_connection', [ $this, 'ajax_test_connection' ] );
    }

    public static function instance(): self {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    // ---------------------------------------------------------------------------
    // Menu
    // ---------------------------------------------------------------------------

    public function register_menu(): void {
        add_submenu_page(
            'woocommerce',
            __( 'Meilisearch', 'wc-meilisearch' ),
            __( 'Meilisearch', 'wc-meilisearch' ),
            'manage_woocommerce',
            'wc-meilisearch',
            [ $this, 'render_page' ]
        );
    }

    // ---------------------------------------------------------------------------
    // Settings API
    // ---------------------------------------------------------------------------

    public function register_settings(): void {
        $fields = [
            'wcm_meili_host'  => __( 'Meilisearch Host', 'wc-meilisearch' ),
            'wcm_meili_key'   => __( 'Master Key', 'wc-meilisearch' ),
            'wcm_redis_host'  => __( 'Redis Host', 'wc-meilisearch' ),
            'wcm_redis_port'  => __( 'Redis Port', 'wc-meilisearch' ),
        ];

        foreach ( $fields as $option_name => $label ) {
            register_setting( 'wcm_settings', $option_name, [ 'sanitize_callback' => 'sanitize_text_field' ] );
        }
    }

    // ---------------------------------------------------------------------------
    // Assets
    // ---------------------------------------------------------------------------

    public function enqueue_admin_assets( string $hook ): void {
        if ( strpos( $hook, 'wc-meilisearch' ) === false ) {
            return;
        }

        wp_enqueue_script(
            'wcm-admin',
            WCM_PLUGIN_URL . 'assets/admin.js',
            [],
            WCM_VERSION,
            true
        );

        wp_localize_script( 'wcm-admin', 'wcmAdmin', [
            'ajaxUrl'      => admin_url( 'admin-ajax.php' ),
            'nonceReindex' => wp_create_nonce( 'wcm_reindex' ),
            'nonceTest'    => wp_create_nonce( 'wcm_test_connection' ),
            'i18n'         => [
                'reindexing'    => __( 'Indexing…', 'wc-meilisearch' ),
                'done'          => __( 'Done! All products indexed.', 'wc-meilisearch' ),
                'error'         => __( 'Error during indexing.', 'wc-meilisearch' ),
                'connected'     => __( 'Connected!', 'wc-meilisearch' ),
                'notConnected'  => __( 'Connection failed.', 'wc-meilisearch' ),
            ],
        ] );
    }

    // ---------------------------------------------------------------------------
    // AJAX: test connection
    // ---------------------------------------------------------------------------

    public function ajax_test_connection(): void {
        check_ajax_referer( 'wcm_test_connection', 'nonce' );
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_die( -1 );
        }

        $ok = MeilisearchClient::instance()->test_connection();
        if ( $ok ) {
            wp_send_json_success( [ 'message' => __( 'Connected to Meilisearch!', 'wc-meilisearch' ) ] );
        } else {
            wp_send_json_error( [ 'message' => __( 'Cannot connect to Meilisearch.', 'wc-meilisearch' ) ] );
        }
    }

    // ---------------------------------------------------------------------------
    // Page HTML
    // ---------------------------------------------------------------------------

    public function render_page(): void {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            return;
        }

        if ( isset( $_POST['wcm_save_settings'] ) && check_admin_referer( 'wcm_settings_nonce' ) ) {
            $this->save_settings();
            echo '<div class="notice notice-success is-dismissible"><p>'
                . esc_html__( 'Settings saved.', 'wc-meilisearch' )
                . '</p></div>';
        }

        $meili_host  = esc_attr( get_option( 'wcm_meili_host', getenv( 'MEILI_HOST' ) ?: 'http://meilisearch:7700' ) );
        $meili_key   = esc_attr( get_option( 'wcm_meili_key',  getenv( 'MEILI_MASTER_KEY' ) ?: '' ) );
        $redis_host  = esc_attr( get_option( 'wcm_redis_host', getenv( 'REDIS_HOST' ) ?: 'redis' ) );
        $redis_port  = esc_attr( get_option( 'wcm_redis_port', getenv( 'REDIS_PORT' ) ?: '6379' ) );
        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'WC Meilisearch', 'wc-meilisearch' ); ?></h1>

            <form method="post" action="">
                <?php wp_nonce_field( 'wcm_settings_nonce' ); ?>
                <input type="hidden" name="wcm_save_settings" value="1">

                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><label for="wcm_meili_host"><?php esc_html_e( 'Meilisearch Host', 'wc-meilisearch' ); ?></label></th>
                        <td><input type="url" id="wcm_meili_host" name="wcm_meili_host" value="<?php echo $meili_host; ?>" class="regular-text"></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="wcm_meili_key"><?php esc_html_e( 'Master / Search Key', 'wc-meilisearch' ); ?></label></th>
                        <td><input type="password" id="wcm_meili_key" name="wcm_meili_key" value="<?php echo $meili_key; ?>" class="regular-text" autocomplete="new-password"></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="wcm_redis_host"><?php esc_html_e( 'Redis Host', 'wc-meilisearch' ); ?></label></th>
                        <td><input type="text" id="wcm_redis_host" name="wcm_redis_host" value="<?php echo $redis_host; ?>" class="regular-text"></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="wcm_redis_port"><?php esc_html_e( 'Redis Port', 'wc-meilisearch' ); ?></label></th>
                        <td><input type="number" id="wcm_redis_port" name="wcm_redis_port" value="<?php echo $redis_port; ?>" class="small-text"></td>
                    </tr>
                </table>

                <?php submit_button( __( 'Save Settings', 'wc-meilisearch' ) ); ?>
            </form>

            <hr>

            <h2><?php esc_html_e( 'Conexión y herramientas', 'wc-meilisearch' ); ?></h2>
            <p>
                <button id="wcm-test-connection" class="button button-secondary">
                    <?php esc_html_e( 'Probar Conexión', 'wc-meilisearch' ); ?>
                </button>
                <span id="wcm-connection-status" style="margin-left:10px;font-weight:bold;"></span>
            </p>

            <hr>

            <h2><?php esc_html_e( 'Reindexar Productos', 'wc-meilisearch' ); ?></h2>
            <p><?php esc_html_e( 'Indexa todos los productos publicados en Meilisearch.', 'wc-meilisearch' ); ?></p>
            <p>
                <button id="wcm-start-reindex" class="button button-primary">
                    <?php esc_html_e( 'Reindexar Productos', 'wc-meilisearch' ); ?>
                </button>
            </p>

            <div id="wcm-progress-wrap" style="display:none; margin-top:16px;">
                <div style="background:#e0e0e0; border-radius:4px; overflow:hidden; height:20px; width:400px;">
                    <div id="wcm-progress-bar" style="background:#0073aa; height:100%; width:0%; transition:width .3s;"></div>
                </div>
                <p id="wcm-progress-text" style="margin-top:6px;"></p>
            </div>
        </div>
        <?php
    }

    // ---------------------------------------------------------------------------
    // Save helper
    // ---------------------------------------------------------------------------

    private function save_settings(): void {
        $fields = [ 'wcm_meili_host', 'wcm_meili_key', 'wcm_redis_host', 'wcm_redis_port' ];
        foreach ( $fields as $key ) {
            if ( isset( $_POST[ $key ] ) ) {
                update_option( $key, sanitize_text_field( wp_unslash( $_POST[ $key ] ) ) );
            }
        }
    }
}
