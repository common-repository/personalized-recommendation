<?php
/**
 * App api
 *
 * @since      1.0.0
 * @author     Beeketing
 *
 */

namespace BKPersonalizedRecommendationSDK\Api;


use BKPersonalizedRecommendationSDK\Data\AppCodes;
use BKPersonalizedRecommendationSDK\Data\Constant;
use BKPersonalizedRecommendationSDK\Data\Setting;
use BKPersonalizedRecommendationSDK\Data\Webhook;
use BKPersonalizedRecommendationSDK\Libraries\Helper;
use BKPersonalizedRecommendationSDK\Libraries\QueryHelper;
use BKPersonalizedRecommendationSDK\Libraries\SettingHelper;
use BKPersonalizedRecommendationSDK\Manager\CollectionManager;
use BKPersonalizedRecommendationSDK\Manager\CustomerManager;
use BKPersonalizedRecommendationSDK\Manager\OrderManager;
use BKPersonalizedRecommendationSDK\Manager\ProductManager;

class CommonApi
{
    const METHOD_GET = 'GET';
    const METHOD_POST = 'POST';
    const METHOD_PUT = 'PUT';
    const METHOD_DELETE = 'DELETE';

    /**
     * @var string
     */
    private $beeketing_path;

    /**
     * @var string
     */
    private $beeketing_api;

    /**
     * @var string
     */
    private $api_key;

    /**
     * @var string
     */
    private $app_code;

    /**
     * @var string
     */
    private $plugin_page_id;

    /**
     * @var SettingHelper
     */
    protected $setting_helper;

    /**
     * App constructor.
     *
     * @param SettingHelper $settingHelper
     * @param $beeketing_path
     * @param $beeketing_api
     * @param $api_key
     * @param $app_code
     * @param $plugin_page_id
     * @throws \Exception
     */
    public function __construct( SettingHelper $settingHelper, $beeketing_path, $beeketing_api, $api_key, $app_code, $plugin_page_id )
    {
        if (!$beeketing_path || !$beeketing_api || !$plugin_page_id) {
            throw new \Exception( 'Failed to config api' );
        }

        $this->setting_helper = $settingHelper;
        $this->beeketing_path = $beeketing_path;
        $this->beeketing_api = $beeketing_api;
        $this->api_key = $api_key;
        $this->app_code = $app_code;
        $this->plugin_page_id = $plugin_page_id;

        // Add scripts
        add_action( 'wp_footer', array( $this, 'add_scripts' ) );

        /** Webhooks **/

        if ( Helper::is_woocommerce_active() ) {
            // Create, update, delete collection webhook
            add_action( 'created_term', array( $this, 'create_collection_webhook' ), 10, 3 );
            add_action( 'edited_term', array( $this, 'update_collection_webhook' ), 10, 3 );
            add_action( 'delete_term', array( $this, 'delete_collection_webhook' ), 10, 3 );

            // Create, update, delete customer webhook
            add_action( 'user_register', array( $this, 'create_customer_webhook' ), 10, 2 );
            add_action( 'profile_update', array( $this, 'update_customer_webhook' ), 10, 2 );
            add_action( 'delete_user', array( $this, 'delete_customer_webhook' ), 10, 2 );

            // Order webhook
            add_action( 'woocommerce_checkout_update_order_meta', array( $this, 'update_order_webhook' ), 10 );
            add_action( 'woocommerce_order_actions_end', array( $this, 'update_order_webhook' ), 10 );

            // Create, update, delete product webhook
            add_action( 'save_post', array( $this, 'update_product_webhook' ), 20 );
            add_action( 'trash_product', array( $this, 'delete_product_webhook' ), 10 );
            add_action( 'untrashed_post', array( $this, 'update_product_webhook' ), 10 );
        }
    }

    /**
     * Add snippet to store
     */
    public function add_scripts()
    {
        echo $this->get_snippet();
    }

    /**
     * Update product webhook
     *
     * @param $post_id
     * @return array|bool|mixed
     */
    public function update_product_webhook( $post_id )
    {
        // If its not a product / if its trashed
        if (
            'product' !== get_post_type( $post_id ) ||
            in_array( get_post_status( $post_id ), array( 'trash', 'auto-draft' ) )
        ) {
            return false;
        }

        // Check product id not in exclude product ids
        global $wpdb;
        $sql = QueryHelper::get_exclude_products_id();
        $exclude_products_id = $wpdb->get_col( $sql );
        if ( in_array( $post_id, $exclude_products_id ) ) {
            return false;
        }

        // Send webhook
        $product_manager = new ProductManager();
        $content = $product_manager->get_product_by_id( $post_id );
        return $this->send_request_webhook( Webhook::PRODUCT_UPDATE, $content );
    }

    /**
     * Delete product webhook
     *
     * @param $post_id
     */
    public function delete_product_webhook( $post_id )
    {
        // If its not a product
        if ( 'product' !== get_post_type( $post_id ) ) {
            return;
        }

        $this->send_request_webhook( Webhook::PRODUCT_DELETE, array( 'id' => $post_id ) );
    }

    /**
     * Update order webhook
     *
     * @param $order_id
     */
    public function update_order_webhook( $order_id )
    {
        // Validate order
        $order = wc_get_order( $order_id );
        if ( !$order || in_array( $order->get_status(), array( 'draft', 'trash', 'auto-draft' ) ) ) {
            return;
        }

        // Update and clear cart token
        if ( isset( $_COOKIE[Constant::CART_TOKEN_KEY] ) ) {
            update_post_meta( $order_id, Constant::CART_TOKEN_KEY, $_COOKIE[Constant::CART_TOKEN_KEY] );
            // Delete cart token
            setcookie( Constant::CART_TOKEN_KEY, null, time() - Constant::COOKIE_CART_TOKEN_LIFE_TIME + 1, '/' );
        }

        $order_manager = new OrderManager();
        $content = $order_manager->get_order_by_id( $order_id );
        $this->send_request_webhook( Webhook::ORDER_UPDATE, $content );
    }

    /**
     * Create customer webhook
     *
     * @param $user_id
     */
    public function create_customer_webhook( $user_id )
    {
        $customer_manager = new CustomerManager();
        $content = $customer_manager->get_customer_by_id( $user_id );
        $this->send_request_webhook( Webhook::CUSTOMER_CREATE, $content );
    }

    /**
     * Update customer webhook
     *
     * @param $user_id
     */
    public function update_customer_webhook( $user_id )
    {
        $customer_manager = new CustomerManager();
        $content = $customer_manager->get_customer_by_id( $user_id );
        $this->send_request_webhook( Webhook::CUSTOMER_UPDATE, $content );
    }

    /**
     * Delete customer webhook
     *
     * @param $user_id
     */
    public function delete_customer_webhook( $user_id )
    {
        $this->send_request_webhook( Webhook::CUSTOMER_DELETE, array( 'id' => $user_id ) );
    }

    /**
     * Create collection webhook
     *
     * @param $term_id
     * @param $tt_id
     * @param $taxonomy
     * @return array|bool|mixed
     */
    public function create_collection_webhook( $term_id, $tt_id, $taxonomy )
    {
        if ( $taxonomy != 'product_cat' ) {
            return false;
        }

        $collection_manager = new CollectionManager();
        $content = $collection_manager->get_collection_by_id( $term_id );
        return $this->send_request_webhook( Webhook::COLLECTION_CREATE, $content );
    }

    /**
     * Update collection webhook
     *
     * @param $term_id
     * @param $tt_id
     * @param $taxonomy
     * @return array|bool|mixed
     */
    public function update_collection_webhook( $term_id, $tt_id, $taxonomy )
    {
        if ( $taxonomy != 'product_cat' ) {
            return false;
        }

        $collection_manager = new CollectionManager();
        $content = $collection_manager->get_collection_by_id( $term_id );
        return $this->send_request_webhook( Webhook::COLLECTION_UPDATE, $content );
    }

    /**
     * Delete collection webhook
     * @param $term_id
     * @param $tt_id
     * @param $taxonomy
     * @return array|bool|mixed
     */
    public function delete_collection_webhook( $term_id, $tt_id, $taxonomy )
    {
        if ( $taxonomy != 'product_cat' ) {
            return false;
        }

        return $this->send_request_webhook( Webhook::COLLECTION_DELETE, array( 'id' => $term_id ) );
    }

    /**
     * Set setting helper
     *
     * @param SettingHelper $setting_helper
     */
    public function set_setting_helper( SettingHelper $setting_helper )
    {
        $this->setting_helper = $setting_helper;
    }

    /**
     * Get setting helper
     *
     * @return SettingHelper
     */
    public function get_setting_helper()
    {
        return $this->setting_helper;
    }

    /**
     * Set app code
     *
     * @param $app_code
     */
    public function set_app_code( $app_code )
    {
        $this->app_code = $app_code;
    }

    /**
     * Set api_key
     *
     * @param $api_key
     */
    public function set_api_key( $api_key )
    {
        $this->api_key = $api_key;
    }

    /**
     * Install app
     *
     * @return bool
     */
    public function install_app()
    {
        // If not api key
        if ( !$this->api_key ) {
            return false;
        }

        // Generate access token
        $token = $this->setting_helper->get_settings( Setting::SETTING_ACCESS_TOKEN );
        if ( !$token ) {
            $token = Helper::generate_access_token();
            $this->setting_helper->update_settings( Setting::SETTING_ACCESS_TOKEN, $token );
        }

        $this->setting_helper->update_settings( Setting::SETTING_API_KEY, $this->api_key );
        $this->setting_helper->update_settings( Setting::SETTING_SITE_URL, get_site_url() );

        $params = array(
            'app' => $this->app_code,
            'access_token' => $token,
        );

        $result = $this->post( 'wordpress/install_app', $params );
        if ( isset( $result['hit'], $result['shop_id'] ) && $result['shop_id'] ) { // Install successfully
            $this->setting_helper->update_settings( Setting::SETTING_SHOP_ID, $result['shop_id'] );

            // Delete beeketing apps data cookie
            $data_cookie_key = Constant::COOKIE_BEEKETING_APPS_DATA;
            $cookie_lifetime = Constant::COOKIE_BEEKETING_APPS_DATA_LIFETIME;
            setcookie( $data_cookie_key, null, time() - $cookie_lifetime, '/' );
            return true;
        } else { // Install fail
            $this->setting_helper->delete_settings();
        }

        return false;
    }

    /**
     * Register shop
     *
     * @return bool
     */
    public function register_shop()
    {
        // If not email
        if ( !isset( $_POST['api_key'] ) ) {
            return false;
        }

        $this->api_key = sanitize_text_field( $_POST['api_key'] );
        if ( $this->api_key ) {
            $result = $this->update_shop_info() && $this->install_app();

            // Install app success
            if ($result) {
                return $this->api_key;
            }
        }

        return false;
    }

    /**
     * Get login shop url
     *
     * @return string
     */
    public function get_login_shop_url()
    {
        $token = base64_encode( json_encode( array(
            'api_key' => $this->api_key,
            'app' => $this->app_code,
        ) ) );

        return $this->get_url( 'wordpress/login_shop', array(
            'token' => $token
        ), $this->beeketing_path );
    }

    /**
     * Get reset password url
     *
     * @return string
     */
    public function get_reset_password_url()
    {
        return $this->get_platform_url( 'resetting/request', array(
            'email' => '{email}',
        ) );
    }

    /**
     * Count more apps
     *
     * @return int
     */
    public function count_more_apps()
    {
        $result = $this->get( 'wordpress/more_apps/count' );
        if ( isset( $result['total'] ) ) {
            return $result['total'];
        }

        return 0;
    }

    /**
     * Get user email
     *
     * @return string|bool
     */
    public function get_user_email()
    {
        $this->api_key = md5(uniqid()); // Fake api key
        $domain = Helper::beeketing_get_shop_domain();
        $result = $this->get( 'wordpress/get_user_email', array(
            'domain' => $domain,
        ) );

        if ( isset( $result['email'] ) ) {
            return $result['email'];
        }

        return false;
    }

    /**
     * Get shop snippet
     *
     * @return string|bool
     */
    public function get_shop_snippet()
    {
        $result = $this->get( 'wordpress/shop_snippet' );

        if ( isset( $result['snippet'] ) ) {
            return $result['snippet'];
        }

        return false;
    }

    /**
     * Get sign in iframe url
     * @return string
     */
    public function get_sign_up_url()
    {
        $currentUser = wp_get_current_user();
        $email = $currentUser->user_email;
        $domain = Helper::beeketing_get_shop_domain();

        return $this->get_platform_url( 'registration/account', array(
            'display' => 'popup',
            'domain' => $domain,
            'platform' => Constant::PLATFORM,
            'email' => $email,
        ) );
    }

    /**
     * Update shop absolute path
     * @param array $params
     * @return bool
     */
    public function update_shop_info( $params = array() )
    {
        $params['absolute_path'] = get_site_url();
        $params['currency'] = Helper::get_currency();
        $params['currency_format'] = Helper::get_currency_format();
        $result = $this->put( 'shops', $params );
        if ( !isset( $result['errors'] ) ) {
            return true;
        }

        return false;
    }

    /**
     * Get sign in iframe url
     * @return string
     */
    public function get_sign_in_url()
    {
        $domain = Helper::beeketing_get_shop_domain();

        return $this->get_platform_url( 'sign-in', array(
            'display' => 'popup',
            'platform' => Constant::PLATFORM,
            'domain' => $domain,
        ) );
    }

    /**
     * Get platform url
     *
     * @param $path
     * @param array $params
     * @return string
     */
    public function get_platform_url( $path, $params = array() )
    {
        $url = $this->beeketing_path . '/' . $path;

        if ($params) {
            $url .= '?' . http_build_query($params, '', '&');
        }

        return $url;
    }

    /**
     * Get api urls
     *
     * @return array
     */
    public function get_api_urls()
    {
        return array(
            'login_shop' => $this->get_login_shop_url(),
            'verify_account' => $this->get_verify_account_url(),
            'reset_password' => $this->get_reset_password_url(),
            'sign_up' => $this->get_sign_up_url(),
            'sign_in' => $this->get_sign_in_url(),
            'shop' => $this->get_shop_url(),
            'more_apps_count' => $this->get_more_apps_count_url(),
            'update_app_active_status' => $this->get_update_app_status_url(),
        );
    }

    /**
     * Get update app active status url
     *
     * @return string
     */
    public function get_update_app_status_url()
    {
        return $this->get_url( 'wordpress/app_active_status/{app}/{status}' );
    }

    /**
     * Uninstall app
     *
     * @param bool $direct_disable
     * @return bool|mixed
     */
    public function uninstall_app( $direct_disable = false )
    {
        $result = $this->send_request_webhook( Webhook::UNINSTALL );

        if ( $result ) {
            // Disable app immediately
            if ( $direct_disable ) {
                $this->post( 'wordpress/disable_app_shop', array(
                    'app' => $this->app_code,
                ) );
            }

            $this->setting_helper->delete_settings();
        }

        return $result;
    }

    /**
     * Get verify account url
     *
     * @return string
     */
    public function get_verify_account_url()
    {
        return $this->get_url( 'wordpress/verify_account' );
    }

    /**
     * Get shop url
     *
     * @return string
     */
    public function get_shop_url()
    {
        return $this->get_url( 'shops/api/' . $this->api_key );
    }

    /**
     * Get more apps count url
     *
     * @return string
     */
    public function get_more_apps_count_url()
    {
        return $this->get_url( 'wordpress/more_apps/count' );
    }

    /**
     * Get endpoint
     *
     * @param null $url
     * @return string
     */
    private function get_endpoint( $url = null )
    {
        if ( !$url ) {
            $url = $this->beeketing_api;
        }

        return $url . '/rest-api/v1/';
    }

    /**
     * Send api request
     *
     * @param $type
     * @param $url
     * @param $content
     * @param array $headers
     * @return array|mixed
     */
    private function send_request( $type, $url, $content, $headers = array() )
    {
        if ( !$this->api_key ) {
            return array();
        }

        $headers = array_merge( array(
            'Content-Type' => 'application/json',
            'X-Beeketing-Key' => $this->api_key,
            'X-Beeketing-Plugin-Version' => 999, // Fixed value
        ), $headers );

        $url = $this->get_endpoint() . $url . '.json';

        // Json encode array content
        if ( $content ) {
            if ( $type == self::METHOD_GET ) {
                $url = add_query_arg( $content, $url );
            } else {
                $content = json_encode( $content );
            }
        }

        $args = array(
            'timeout' => 20,
            'redirection' => 5,
            'httpversion' => '1.0',
            'blocking' => true,
            'headers' => $headers,
            'body' => $content,
            'cookies' => array()
        );

        $api_response = array();
        switch ($type) {
            case self::METHOD_GET:
                $api_response = wp_remote_get( $url, $args);
                break;

            case self::METHOD_POST:
            case self::METHOD_PUT:
            case self::METHOD_DELETE:
                $args['method'] = $type;
                $api_response = wp_remote_post( $url, $args);
                break;
        }

        // Render response
        if ( $api_response && !is_wp_error( $api_response ) ) {
            return json_decode( wp_remote_retrieve_body( $api_response ), true );
        }

        return $this->response_error( $api_response->get_error_message() );
    }

    /**
     * Response error
     *
     * @param $message
     * @return array
     */
    private function response_error( $message )
    {
        return array(
            'errors' => $message,
        );
    }

    /**
     * Send request webhook
     *
     * @param $topic
     * @param $content
     * @param array $headers
     * @return array|mixed
     */
    private function send_request_webhook( $topic, $content = array(), $headers = array() )
    {
        $shopId = $this->setting_helper->get_settings( Setting::SETTING_SHOP_ID );
        if ( !$shopId ) {
            return false;
        }

        // If all apps plugin
        if ( $topic != Webhook::UNINSTALL && !$this->app_code ) {
            $this->app_code = AppCodes::BETTERCOUPONBOX;
        }

        $headers = array_merge( array(
            'Content-Type' => 'application/json',
            'X-Beeketing-Topic' => $topic,
            'X-Beeketing-Plugin-Version' => 999, // Fixed value
        ), $headers );

        // Json encode array content
        $content = json_encode( $content );

        $url = $this->beeketing_api . '/webhook/callback/' . Constant::PLATFORM . '/' .
            $this->app_code . '/' . $shopId;

        $args = array(
            'method' => 'POST',
            'timeout' => 45,
            'redirection' => 5,
            'httpversion' => '1.0',
            'blocking' => true,
            'headers' => $headers,
            'body' => $content,
            'cookies' => array()
        );

        $api_response = array();
        $continue = true;
        $triedTime = 0;

        // Retry request block
        while ( $continue ) {
            // Send request
            $api_response = wp_remote_post( $url, $args);

            // Render response
            if ( $api_response && !is_wp_error( $api_response ) ) {
                return json_decode( wp_remote_retrieve_body( $api_response ), true );
            }

            if ( $triedTime > 2 ) {
                $continue = false;
            }
        }

        return $api_response;
    }

    /**
     * Send get request
     *
     * @param $url
     * @param array $params
     * @return array|bool
     */
    protected function get( $url, $params = array() )
    {
        return $this->send_request( self::METHOD_GET, $url, $params );
    }

    /**
     * Send post request
     *
     * @param $url
     * @param array $params
     * @param array $headers
     * @return array|bool
     */
    protected function post( $url, $params = array(), $headers = array() )
    {
        return $this->send_request( self::METHOD_POST, $url, $params, $headers );
    }

    /**
     * Send put request
     *
     * @param $url
     * @param array $params
     * @param array $headers
     * @return array|bool
     */
    protected function put( $url, $params = array(), $headers = array() )
    {
        return $this->send_request( self::METHOD_PUT, $url, $params, $headers );
    }

    /**
     * Send delete request
     *
     * @param $url
     * @param array $param
     * @param array $headers
     * @return array|bool
     */
    protected function delete( $url, $param = array(), $headers = array() )
    {
        return $this->send_request( self::METHOD_DELETE, $url, $param, $headers );
    }

    /**
     * Get request url
     *
     * @param $path
     * @param array $params
     * @param null $endpoint
     * @param string $ext
     * @return string
     */
    protected function get_url( $path, $params = array(), $endpoint = null, $ext = '.json' )
    {
        $url = $this->get_endpoint( $endpoint ) . $path . $ext;

        if ( $params ) {
            $url .= '?' . http_build_query( $params, '', '&' );
        }

        return $url;
    }

    /**
     * Get oauth sign in url
     *
     * @return string
     */
    public function get_oauth_sign_in_url()
    {
        return $this->get_platform_url( 'oauth/wordpress/' . $this->app_code, array(
            'domain' => Helper::beeketing_get_shop_domain(),
            'absolute_path' => get_site_url(),
            'signature' => $this->setting_helper->get_settings( Setting::SETTING_API_KEY ),
            'timestamp' => time(),
        ) );
    }

    /**
     * Detect domain change
     *
     * @return bool
     */
    public function detect_domain_change()
    {
        $setting_site_url = $this->setting_helper->get_settings( Setting::SETTING_SITE_URL );
        $site_url = get_site_url();
        if ( $setting_site_url != $site_url ) {
            $params['absolute_path'] = $site_url;
            $result = $this->put( 'shops', $params );

            if ( !isset( $result['errors'] ) ) {
                $this->setting_helper->update_settings( Setting::SETTING_SITE_URL, $site_url );
                return true;
            }

            return false;
        }
    }

    /**
     * Install app
     *
     * @param array $params
     * @return bool
     */
    public function send_tracking_event( $params = array() )
    {
        $result = $this->post( 'shops/track', $params );
        if ( isset( $result['hit'] ) && $result['hit'] ) { // Install successfully
            return true;
        }

        return false;
    }

    /**
     * Get snippet
     *
     * @return string
     */
    public function get_snippet()
    {
        $snippet = $this->setting_helper->get_settings( Setting::SETTING_SNIPPET );

        // Get shop snippet from api
        if ( !$snippet ) {
            $snippet = $this->get_shop_snippet();
            if ( $snippet ) {
                $this->setting_helper->update_settings( Setting::SETTING_SNIPPET, $snippet );
            }
        }

        return $this->get_page_snippet() . html_entity_decode($snippet);
    }

    /**
     * Get page snippet
     *
     * @return string
     */
    private function get_page_snippet()
    {
        $data = array();

        if ( Helper::is_woocommerce_active() ) {
            global $woocommerce;
            // Wc, wp version
            $data['wc_version'] = $woocommerce->version;
            $data['wp_version'] = get_bloginfo( 'version' );
            $data['app_setting_key'] = $this->setting_helper->get_app_setting_key();
            $data['plugin_version'] = $this->setting_helper->get_plugin_version();

            // Page url
            $data['page_url'] = array(
                'home' => get_permalink( Helper::is_wc3() ? wc_get_page_id( 'shop' ) : woocommerce_get_page_id( 'shop' ) ),
                'cart' => Helper::is_wc3() ? wc_get_cart_url() : $woocommerce->cart->get_cart_url(),
                'checkout' => Helper::is_wc3() ? wc_get_checkout_url() : $woocommerce->cart->get_checkout_url(),
            );

            // Customer
            $current_user_id = get_current_user_id();
            if ( $current_user_id ) {
                $data['customer'] = array(
                    'id' => $current_user_id,
                );
            }

            // Page
            $data['page'] = array();
            if ( is_shop() ) {
                $data['page']['type'] = 'home';
            } elseif ( is_product_category() ) {
                $collection = get_queried_object();
                $collection_id     = $collection->term_id;
                $data['page']['type'] = 'collection';
                $data['page']['id'] = (int)$collection_id;
            } elseif ( is_product() ) {
                global $product;
                $data['page']['type'] = 'product';
                $data['page']['id'] = (int)$product->get_id();
            } elseif ( is_cart() ) {
                $data['page']['type'] = 'cart';
            } elseif ( is_wc_endpoint_url( 'order-received' ) ) {
                $data['page']['type'] = 'post_checkout';
            } elseif ( is_checkout() ) {
                $data['page']['type'] = 'checkout';
            }
        }

        // Convert to js snippet
        $data = json_encode( $data );
        $snippet = '<script>var _beeketing = JSON.parse(\'' . $data . '\');</script>';

        return $snippet;
    }
}