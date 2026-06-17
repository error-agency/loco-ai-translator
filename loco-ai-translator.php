<?php
/**
 * Plugin Name: Loco AI Translator
 * Plugin URI:  https://github.com/error-agency/loco-ai-translator
 * Description: AI-powered translation for Loco Translate using OpenRouter, Ollama and any OpenAI-compatible provider.
 * Version:     1.5.3
 * Author:      Err.or
 * Author URI:  https://error.bg
 * Text Domain: loco-ai-translator
 * License:     GPL-2.0+
 * Requires at least: 6.0
 * Requires PHP:      8.0
 * Tested up to:      6.9
 */

if (!defined('ABSPATH'))
    exit;

define('LAT_VERSION', '1.5.3');
define('LAT_PATH', plugin_dir_path(__FILE__));
define('LAT_URL', plugin_dir_url(__FILE__));
define('LAT_BASENAME', plugin_basename(__FILE__));

final class Loco_AI_Translator
{

    private static $instance = null;

    public static function instance()
    {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct()
    {
        $this->includes();
        $this->init_hooks();
    }

    private function includes()
    {
        require_once LAT_PATH . 'includes/class-lat-settings.php';
        require_once LAT_PATH . 'includes/class-lat-api-client.php';
        require_once LAT_PATH . 'includes/class-lat-po-handler.php';
        require_once LAT_PATH . 'includes/class-lat-ajax.php';
        require_once LAT_PATH . 'admin/class-lat-admin.php';
        require_once LAT_PATH . 'includes/plugin-update-checker/plugin-update-checker.php';
    }

    private function init_hooks()
    {
        add_action('init', [$this, 'load_textdomain']);
        add_action('plugins_loaded', [$this, 'check_requirements']);

        // Boot sub-modules
        LAT_Settings::instance();
        LAT_Ajax::instance();
        LAT_Admin::instance();

        // Boot update checker
        $this->init_update_checker();
    }

    /**
     * Initialize the plugin update checker.
     */
    private function init_update_checker()
    {
        if (class_exists('YahnisElsts\PluginUpdateChecker\v5\PucFactory')) {
            $update_checker = YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker(
                'https://github.com/error-agency/loco-ai-translator/',
                __FILE__,
                'loco-ai-translator'
            );
            $update_checker->setBranch('main');
        }
    }

    public function load_textdomain()
    {
        load_plugin_textdomain(
            'loco-ai-translator',
            false,
            dirname(LAT_BASENAME) . '/languages'
        );
    }

    public function check_requirements()
    {
        // 1. PHP Version
        if (version_compare(PHP_VERSION, '7.4', '<')) {
            add_action('admin_notices', function () {
                echo '<div class="notice notice-error"><p>';
                echo '<strong>Loco AI Translator:</strong> ';
                printf(esc_html__('This plugin requires PHP version 7.4 or higher. Your server is running version %s.', 'loco-ai-translator'), esc_html(PHP_VERSION));
                echo '</p></div>';
            });
        }

        // 2. WordPress Version
        global $wp_version;
        if (version_compare($wp_version, '6.0', '<')) {
            add_action('admin_notices', function () {
                echo '<div class="notice notice-error"><p>';
                echo '<strong>Loco AI Translator:</strong> ';
                printf(esc_html__('This plugin requires WordPress version 6.0 or higher. You are running version %s.', 'loco-ai-translator'), esc_html($GLOBALS['wp_version']));
                echo '</p></div>';
            });
        }

        // 3. Loco Translate
        if (!class_exists('Loco_data_Settings')) {
            add_action('admin_notices', function () {
                echo '<div class="notice notice-warning"><p>';
                echo '<strong>Loco AI Translator:</strong> ';
                esc_html_e('Loco Translate plugin is required for this plugin to work.', 'loco-ai-translator');
                echo '</p></div>';
            });
        }
    }
}

function lat_plugin()
{
    return Loco_AI_Translator::instance();
}
lat_plugin();
