<?php
/**
 * Plugin Name: Personalized Recommendation
 * Plugin URI: https://beeketing.com/personalized-recommendation?utm_channel=appstore&utm_medium=woolisting&utm_term=shortdesc&utm_fromapp=prec
 * Description: Add personalized product recommendation sliders on all pages of a WooCommerce store. Recommendations can be automatically generated based on customer shopping patterns and sales history of the store, or set up by your rules.
 * Version: 1.0.1
 * Author: Beeketing
 * Author URI: https://beeketing.com
 */

use Beeketing\PersonalizedRecommendation\Api\App;
use BKPersonalizedRecommendationSDK\Api\BridgeApi;
use Beeketing\PersonalizedRecommendation\Data\Constant;
use Beeketing\PersonalizedRecommendation\PageManager\AdminPage;
use BKPersonalizedRecommendationSDK\Data\Setting;
use BKPersonalizedRecommendationSDK\Libraries\Helper;
use BKPersonalizedRecommendationSDK\Libraries\SettingHelper;


if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

// Define plugin constants
define( 'PERSONALIZEDRECOMMENDATION_VERSION', '1.0.1' );
define( 'PERSONALIZEDRECOMMENDATION_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'PERSONALIZEDRECOMMENDATION_PLUGIN_DIRNAME', __FILE__ );

// Require plugin autoload
require_once( PERSONALIZEDRECOMMENDATION_PLUGIN_DIR . 'vendor/autoload.php' );

// Get environment
$env = Helper::get_local_file_contents( PERSONALIZEDRECOMMENDATION_PLUGIN_DIR . 'env' );
$env = trim( $env );

if ( !$env ) {
    throw new Exception( 'Can not get env' );
}

define( 'PERSONALIZEDRECOMMENDATION_ENVIRONMENT', $env );

if ( ! class_exists( 'PersonalizedRecommendation' ) ):

    class PersonalizedRecommendation {
        /**
         * @var AdminPage $admin_page;
         *
         * @since 1.0.0
         */
        private $admin_page;

        /**
         * @var App $api_app
         *
         * @since 1.0.0
         */
        private $api_app;

        /**
         * @var BridgeApi
         *
         * @since 1.0.0
         */
        private $bridge_api;

        /**
         * @var SettingHelper
         *
         * @since 1.0.0
         */
        private $setting_helper;

        /**
         * The single instance of the class
         *
         * @since 1.0.0
         */
        private static $_instance = null;

        /**
         * Get instance
         *
         * @return PersonalizedRecommendation
         * @since 1.0.0
         */
        public static function instance() {
            if ( is_null( self::$_instance ) ) {
                self::$_instance = new self();
            }

            return self::$_instance;
        }

        /**
         * Constructor
         *
         * @since 1.0.0
         */
        public function __construct()
        {
            $this->setting_helper = new SettingHelper();
            $this->setting_helper->set_app_setting_key( \BKPersonalizedRecommendationSDK\Data\AppSettingKeys::PERSONALIZEDRECOMMENDATION_KEY );

            $api_key = $this->setting_helper->get_settings( Setting::SETTING_API_KEY );

            // Init api app
            $this->api_app = new App( $api_key );

            // Bridge api
            $this->bridge_api = new BridgeApi( \BKPersonalizedRecommendationSDK\Data\AppSettingKeys::PERSONALIZEDRECOMMENDATION_KEY );

            // Plugin hooks
            $this->hooks();
        }

        /**
         * Hooks
         *
         * @since 1.0.0
         */
        private function hooks()
        {
            // Initialize plugin parts
            add_action( 'plugins_loaded', array( $this, 'init' ) );

            // Add the plugin page Settings and Docs links
            add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), array( $this, 'plugin_links' ) );

            // Plugin updates
            add_action( 'admin_init', array( $this, 'check_version' ) );

            // Plugin activation
            add_action( 'activated_plugin', array( $this, 'plugin_activation' ) );
        }

        /**
         * Init
         *
         * @since 1.0.0
         */
        public function init()
        {
            $this->ajax();

            if ( is_admin() ) {
                $this->admin_page = new AdminPage();
            }

            // Enqueue scripts
            add_action( 'admin_enqueue_scripts', array( $this, 'register_script' ) );

            // Enqueue styles
            add_action( 'admin_enqueue_scripts', array( $this, 'register_style' ) );
        }

        /**
         * Enqueue and localize js
         *
         * @since 1.0.0
         * @param $hook
         */
        public function register_script( $hook )
        {
            // Load only on plugin page
            if ($hook != 'toplevel_page_' . Constant::PLUGIN_ADMIN_URL) {
                return;
            }

            $app_name = PERSONALIZEDRECOMMENDATION_ENVIRONMENT == 'local' ? 'app' : 'app.min';

            // Enqueue script
            wp_register_script( 'beeketing_app_script', plugins_url( 'dist/js/' . $app_name . '.js', __FILE__ ) , array( 'jquery' ), null, false );
            wp_enqueue_script( 'beeketing_app_script' );

            $api_key = $this->setting_helper->get_settings( Setting::SETTING_API_KEY );
            $current_user = wp_get_current_user();
            $routers = $this->api_app->get_routers();

            $beeketing_email = false;
            if ( !$api_key ) {
                $beeketing_email = $this->api_app->get_user_email();
            }

            wp_localize_script( 'beeketing_app_script', 'beeketing_app_vars', array(
                'plugin_url' => plugins_url( '/', __FILE__ ),
                'routers' => $routers,
                'api_urls' => $this->api_app->get_api_urls(),
                'api_key' => $api_key,
                'user_display_name' => $current_user->display_name,
                'user_email' => $current_user->user_email,
                'site_url' => site_url(),
                'domain' => Helper::beeketing_get_shop_domain(),
                'beeketing_email' => $beeketing_email,
                'is_woocommerce_active' => Helper::is_woocommerce_active(),
                'woocommerce_plugin_url' => Helper::get_woocommerce_plugin_url(),
                'app_code' => \BKPersonalizedRecommendationSDK\Data\AppCodes::PERSONALIZEDRECOMMENDATION,
            ));
        }

        /**
         * Enqueue style
         *
         * @since 1.0.0
         * @param $hook
         */
        public function register_style( $hook )
        {
            // Load only on plugin page
            if ( $hook != 'toplevel_page_' . Constant::PLUGIN_ADMIN_URL ) {
                return;
            }

            wp_register_style( 'beeketing_app_style', plugins_url( 'dist/css/app.css', __FILE__ ), array(), null, 'all' );
            wp_enqueue_style( 'beeketing_app_style' );
        }

        /**
         * Ajax
         *
         * @since 1.0.0
         */
        public function ajax()
        {
            add_action( 'wp_ajax_personalizedrecommendation_verify_account_callback', array( $this, 'verify_account_callback' ) );
        }

        /**
         * Verify account callback
         *
         * @since 1.0.0
         */
        public function verify_account_callback() {
            $api_key = $this->api_app->register_shop();

            wp_send_json_success( array(
                'api_key' => $api_key,
            ) );
            wp_die();
        }

        /**
         * Plugin links
         *
         * @param $links
         * @return array
         * @since 1.0.0
         */
        public function plugin_links( $links )
        {
            $more_links = array();
            $more_links['settings'] = '<a href="' . admin_url( 'admin.php?page=' . Constant::PLUGIN_ADMIN_URL ) . '">' . __( 'Settings', 'beeketing' ) . '</a>';

            return array_merge( $more_links, $links );
        }

        /**
         * Check version
         *
         * @since 1.0.0
         */
        public function check_version()
        {
            // Update version number if its not the same
            if ( PERSONALIZEDRECOMMENDATION_VERSION != $this->setting_helper->get_settings( Setting::SETTING_PLUGIN_VERSION ) ) {
                $this->setting_helper->update_settings( Setting::SETTING_PLUGIN_VERSION, PERSONALIZEDRECOMMENDATION_VERSION );
            }
        }

        /**
         * Plugin activation
         *
         * @param $plugin
         * @since 1.0.0
         */
        public function plugin_activation( $plugin )
        {
            if ( $plugin == plugin_basename( __FILE__ ) ) {
                exit( wp_redirect( admin_url( 'admin.php?page=' . Constant::PLUGIN_ADMIN_URL ) ) );
            }
        }

        /**
         * Plugin uninstall
         *
         * @since 1.0.0
         */
        public function plugin_uninstall()
        {
            $this->api_app->uninstall_app();
            delete_option( \BKPersonalizedRecommendationSDK\Data\AppSettingKeys::PERSONALIZEDRECOMMENDATION_KEY );
        }
    }

    // Run plugin
    new PersonalizedRecommendation();

endif;