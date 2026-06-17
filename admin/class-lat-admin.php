<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class LAT_Admin {

    private static $instance = null;

    public static function instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action( 'admin_menu',             [ $this, 'register_menu' ] );
        add_action( 'admin_enqueue_scripts',  [ $this, 'enqueue_assets' ] );
        add_filter( 'plugin_action_links_' . LAT_BASENAME, [ $this, 'plugin_action_links' ] );
    }

    public function register_menu() {
        add_options_page(
            __( 'Loco AI Translator', 'loco-ai-translator' ),
            __( 'Loco AI Translator', 'loco-ai-translator' ),
            'manage_options',
            'loco-ai-translator',
            [ $this, 'render_settings_page' ]
        );
    }

    public function enqueue_assets( $hook ) {
        // Settings page
        if ( $hook === 'settings_page_loco-ai-translator' ) {
            wp_enqueue_style(
                'lat-admin',
                LAT_URL . 'assets/css/lat-admin.css',
                [],
                LAT_VERSION
            );
            wp_enqueue_script(
                'lat-admin',
                LAT_URL . 'assets/js/lat-admin.js',
                [ 'jquery' ],
                LAT_VERSION,
                true
            );
            wp_localize_script( 'lat-admin', 'latAdmin', $this->get_js_data() );
        }

        // Inject into Loco Translate editor pages.
        // We check both the WP hook name AND the raw ?page= query param because
        // Loco's hook names vary between versions and plugin/theme sub-pages.
        // strpos() used instead of str_starts_with() for PHP 7.4 compatibility.
        $current_page   = sanitize_text_field( wp_unslash( $_GET['page']   ?? '' ) );
        $current_action = sanitize_text_field( wp_unslash( $_GET['action'] ?? '' ) );

        // Hook names Loco registers (parent slug = loco-translate, various sub-slugs)
        $loco_hooks = [
            'loco-translate_page_loco-plugin',       // plugin file list + edit
            'loco-translate_page_loco-theme',        // theme file list + edit
            'loco-translate_page_loco-plugin-file-edit',
            'loco-translate_page_loco-theme-file-edit',
            'loco-plugin_page_loco-plugin-file-edit',
            'loco-theme_page_loco-theme-file-edit',
        ];

        $is_loco_editor = (
            in_array( $hook, $loco_hooks, true ) ||
            (
                strpos( $current_page, 'loco' ) !== false &&
                $current_action === 'file-edit'
            ) ||
            // Broad fallback: any loco- page that has ?path= (file editor URLs always have it)
            (
                strpos( $current_page, 'loco' ) !== false &&
                ! empty( $_GET['path'] )
            )
        );

        if ( $is_loco_editor ) {
            wp_enqueue_style(
                'lat-loco',
                LAT_URL . 'assets/css/lat-admin.css',
                [],
                LAT_VERSION
            );
            wp_enqueue_script(
                'lat-loco',
                LAT_URL . 'assets/js/lat-loco-editor.js',
                [ 'jquery' ],
                LAT_VERSION,
                true
            );
            wp_localize_script( 'lat-loco', 'latLoco', $this->get_loco_js_data() );
        }
    }

    private function get_js_data() {
        $settings = LAT_Settings::instance();
        return [
            'ajaxUrl'   => admin_url( 'admin-ajax.php' ),
            'nonce'     => wp_create_nonce( 'lat_nonce' ),
            'model'     => $settings->get( 'model' ),
            'provider'  => $settings->get( 'provider' ),
            'batchSize' => $settings->get( 'batch_size' ),
            'i18n'      => [
                'translating'  => __( 'Translating…', 'loco-ai-translator' ),
                'done'         => __( 'Done!', 'loco-ai-translator' ),
                'error'        => __( 'Error', 'loco-ai-translator' ),
                'noStrings'    => __( 'No untranslated strings found.', 'loco-ai-translator' ),
                'confirm'      => __( 'This will fill in all untranslated strings using AI. Continue?', 'loco-ai-translator' ),
                'btnTranslate' => __( '🤖 AI Translate', 'loco-ai-translator' ),
                'btnStop'      => __( '⏹ Stop', 'loco-ai-translator' ),
            ],
        ];
    }

    /**
     * Extended data object for the Loco editor page.
     * Passes the raw ?path= param to JS so the AJAX handler can resolve it
     * against all three Loco storage locations.
     */
    private function get_loco_js_data() {
        $base = $this->get_js_data();

        // Loco puts the .po file path in ?path= — it may be absolute or relative.
        // We pass it raw to JS; the server-side validate_po_path() will resolve it
        // against all candidate directories (Custom / Author / System locations).
        $raw_path = sanitize_text_field( wp_unslash( $_GET['path'] ?? '' ) );

        // Try to detect locale from the filename (e.g. devpulse-bg_BG.po → bg_BG)
        $detected_locale = '';
        if ( $raw_path ) {
            $basename = pathinfo( $raw_path, PATHINFO_FILENAME );
            if ( preg_match( '/[-_]([a-z]{2,3}_[A-Z]{2,3})$/', $basename, $m ) ) {
                $detected_locale = $m[1];
            }
        }

        $base['poPath']         = $raw_path;   // raw — resolved server-side
        $base['detectedLocale'] = $detected_locale;

        return $base;
    }

    public function render_settings_page() {
        if ( ! current_user_can( 'manage_options' ) ) return;
        require_once LAT_PATH . 'admin/views/settings-page.php';
    }

    public function plugin_action_links( $links ) {
        $settings_link = sprintf(
            '<a href="%s">%s</a>',
            admin_url( 'options-general.php?page=loco-ai-translator' ),
            __( 'Settings', 'loco-ai-translator' )
        );
        array_unshift( $links, $settings_link );
        return $links;
    }
}
