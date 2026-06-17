<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class LAT_Settings {

    private static $instance = null;
    const OPTION_KEY = 'lat_settings';

    public static function instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action( 'admin_init', [ $this, 'register_settings' ] );
    }

    public function register_settings() {
        register_setting(
            'lat_settings_group',
            self::OPTION_KEY,
            [ 'sanitize_callback' => [ $this, 'sanitize_settings' ] ]
        );
    }

    public function sanitize_settings( $input ) {
        $clean = [];

        $clean['provider']        = sanitize_text_field( $input['provider'] ?? 'openrouter' );
        $clean['api_endpoint']    = esc_url_raw( trim( $input['api_endpoint'] ?? '' ) );
        $clean['api_key']         = sanitize_text_field( $input['api_key'] ?? '' );
        $clean['model']           = sanitize_text_field( $input['model'] ?? '' );
        $clean['batch_size']      = absint( $input['batch_size'] ?? 40 );
        $clean['max_retries']     = absint( $input['max_retries'] ?? 3 );
        $clean['system_prompt']   = sanitize_textarea_field( $input['system_prompt'] ?? '' );
        $clean['skip_translated'] = ! empty( $input['skip_translated'] ) ? 1 : 0;
        $clean['temperature']     = floatval( $input['temperature'] ?? 0.3 );

        // Clamp values
        $clean['batch_size']  = max( 5, min( 100, $clean['batch_size'] ) );
        $clean['max_retries'] = max( 0, min( 10,  $clean['max_retries'] ) );
        $clean['temperature'] = max( 0, min( 2,   $clean['temperature'] ) );

        return $clean;
    }

    public function get( $key = null, $default = null ) {
        $options = get_option( self::OPTION_KEY, [] );

        $defaults = [
            'provider'        => 'openrouter',
            'api_endpoint'    => 'https://openrouter.ai/api/v1',
            'api_key'         => '',
            'model'           => 'openai/gpt-4o-mini',
            'batch_size'      => 40,
            'max_retries'     => 3,
            'system_prompt'   => '',
            'skip_translated' => 1,
            'temperature'     => 0.3,
        ];

        $options = wp_parse_args( $options, $defaults );

        if ( $key !== null ) {
            return $options[ $key ] ?? $default;
        }

        return $options;
    }

    public static function default_system_prompt( $target_lang, $nplurals = 2 ) {
        return sprintf(
            'Translate JSON array items to %s. ' .
            'Rules: ' .
            '- Preserve HTML tags, spacing, and placeholders (%%s, %%d, {var}) exactly. ' .
            '- If an item is a plural array [singular, plural], translate it into exactly %d plural forms for %s as a nested JSON array. ' .
            '- Return ONLY a JSON array in the exact same order. No extra text, explanations, or markdown fences.',
            $target_lang,
            $nplurals,
            $target_lang
        );
    }
}
