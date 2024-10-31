<?php
/**
 * Bridge api communicate with Beeketing
 *
 * @since      1.0.0
 * @author     Beeketing
 */

namespace BKPersonalizedRecommendationSDK\Api;


use bandwidthThrottle\tokenBucket\Rate;
use bandwidthThrottle\tokenBucket\storage\FileStorage;
use bandwidthThrottle\tokenBucket\storage\StorageException;
use bandwidthThrottle\tokenBucket\TokenBucket;
use BKPersonalizedRecommendationSDK\Data\Api;
use BKPersonalizedRecommendationSDK\Data\Constant;
use BKPersonalizedRecommendationSDK\Data\Setting;
use BKPersonalizedRecommendationSDK\Manager\CartManager;
use BKPersonalizedRecommendationSDK\Manager\CollectionManager;
use BKPersonalizedRecommendationSDK\Manager\CollectManager;
use BKPersonalizedRecommendationSDK\Libraries\Helper;
use BKPersonalizedRecommendationSDK\Libraries\SettingHelper;
use BKPersonalizedRecommendationSDK\Manager\CustomerManager;
use BKPersonalizedRecommendationSDK\Manager\OrderManager;
use BKPersonalizedRecommendationSDK\Manager\ProductManager;
use BKPersonalizedRecommendationSDK\Manager\VariantManager;
use Buzz\Browser;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Buzz\Client\Curl;

class BridgeApi
{
    const VALIDATE_HEADER_ACCESS_TOKEN = 'X-Beeketing-Access-Token';
    const VALIDATE_HEADER_CLIENT_KEY = 'X-Beeketing-Client-Key';
    const VALIDATE_HEADER_API_KEY = 'X-Beeketing-Key';
    const REQUEST_TIMEOUT = 20;

    private $browser;
    private $setting_helper;
    private $collect_manager;
    private $collection_manager;
    private $product_manager;
    private $customer_manager;
    private $order_manager;
    private $variant_manager;
    private $cart_manager;

    public function __construct( $app_setting_key )
    {
        // Init handle request
        if ( Helper::is_woocommerce_active() ) {
            add_action( 'woocommerce_cart_loaded_from_session', array( $this, 'handle_request' ) );
        } else {
            add_action( 'wp_loaded', array( $this, 'handle_request') );
        }

        $this->setting_helper = new SettingHelper();
        $this->setting_helper->set_app_setting_key( $app_setting_key );

        // Set http client
        $client = null;
        if ( function_exists( 'curl_version' ) ) {
            $client = new Curl();
        }
        $this->browser = new Browser( $client );
        $this->browser->getClient()->setTimeout( self::REQUEST_TIMEOUT );

        // Init cart token
        Helper::init_cart_token();

        // Managers
        $this->collect_manager = new CollectManager();
        $this->collection_manager = new CollectionManager();
        $this->product_manager = new ProductManager();
        $this->customer_manager = new CustomerManager();
        $this->order_manager = new OrderManager();
        $this->variant_manager = new VariantManager();
        $this->cart_manager = new CartManager();
    }

    /**
     * Response error
     *
     * @param $message
     * @param bool $status
     * @param array $headers
     */
    private function response_error( $message, $status = false, $headers = array() )
    {
        $this->response( array(
            'errors' => $message,
        ), $status, $headers );
        exit;
    }

    /**
     * Api response
     *
     * @param array $result
     * @param bool $status
     * @param array $headers
     */
    private function response( $result = array(), $status = false, $headers = array() )
    {
        $response = new Response();

        // Set status
        if ( $status ) {
            $response->setStatusCode( $status );
        }

        // Set headers
        foreach ( $headers as $key => $header ) {
            $response->headers->set( $key, $header );
        }

        $response->headers->set( 'Content-Type', 'application/json' );
        $response->setContent( json_encode( $result ) );

        $response->send();
        exit;
    }

    /**
     * Handle api request
     */
    public function handle_request()
    {
        $this->handle_beeketing_request();
        $this->handle_api_request();
    }

    /**
     * Check api rate limit
     *
     * @param $filename
     * @since 3.0.6
     */
    private function check_rate_limit( $filename )
    {
        // Api rate limit
        if ( Helper::is_support_api_rate_limit() ) {
            $tokens = $this->setting_helper->get_settings( Setting::SETTING_API_RATE_LIMIT );
            if ( !$tokens ) {
                $tokens = Constant::API_RATE_LIMIT;
                $this->setting_helper->update_settings( Setting::SETTING_API_RATE_LIMIT, $tokens );
            }

            $storage = new FileStorage(__DIR__ . '/' . $filename . '.bucket');
            $rate = new Rate($tokens, Rate::MINUTE);
            try {
                $bucket = new TokenBucket($tokens, $rate, $storage);
                $seconds = 0;
                if (!$bucket->consume(1, $seconds)) {
                    $this->response_error(
                        'Too many requests',
                        Response::HTTP_TOO_MANY_REQUESTS,
                        array('X-Beeketing-Retry-After' => ceil($seconds))
                    );
                }
            } catch (StorageException $e) {
                $storage->setMicrotime( microtime( true ) );
            }
        }
    }

    /**
     * Handle beeketing request
     */
    private function handle_beeketing_request()
    {
        $request = Request::createFromGlobals();
        $header_api_key = $request->headers->get( BridgeApi::VALIDATE_HEADER_API_KEY );
        if (
            $header_api_key &&
            $header_api_key == $this->setting_helper->get_settings( Setting::SETTING_API_KEY )
        ) {
            $this->process_request( $request, 'beeketing' );
        }
    }

    /**
     * Handle api request
     */
    private function handle_api_request()
    {
        $request = Request::createFromGlobals();

        // Validate request
        $header_access_token = $request->headers->get( self::VALIDATE_HEADER_ACCESS_TOKEN );

        // Get app setting key by access token
        global $wpdb;
        $app_setting_key = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT option_name FROM $wpdb->options WHERE option_value LIKE %s",
                "%\"" . $header_access_token . "\"%"
            )
        );
        if ( $app_setting_key ) {
            $this->setting_helper->set_app_setting_key($app_setting_key);
        }

        // Process if valid
        if (
            $header_access_token &&
            $header_access_token == $this->setting_helper->get_settings( Setting::SETTING_ACCESS_TOKEN )
        ) {
            $this->check_rate_limit( 'api' );
            $this->process_request( $request );
        }
    }

    /**
     * Process request
     *
     * @param Request $request
     * @param string $suffix
     */
    private function process_request(Request $request, $suffix = null)
    {
        // Validate request
        $resource = $request->get( 'resource' );
        if ( !$resource ) {
            $this->response_error( 'Resource unrecognized' );
        }

        // Get handle method by resource and request method
        $resource_list[] = $request->getMethod();
        $resource_list[] = $resource;

        // Insert method suffix
        if ( $suffix ) {
            $resource_list[] = $suffix;
        }

        // Get handle method by resource and request method
        $method = strtolower( implode( '_', $resource_list ) );

        if (method_exists($this, $method)) {
            $this->{$method}($request);
        } else {
            // Method not found
            $this->response_error( 'Method not allowed' );
        }

        exit;
    }

    /**
     * Get cart
     *
     * @param Request $request
     * @resource cart
     */
    protected function get_cart_beeketing( Request $request )
    {
        // If woocommerce is active
        if ( Helper::is_woocommerce_active() ) {
            $cart = $this->cart_manager->get_cart();
        } else {
            $cart = array();
        }


        $this->response( array (
            'cart' => $cart,
        ) );
    }

    /**
     * Add cart
     *
     * @param Request $request
     * @resource cart
     */
    protected function post_cart_beeketing( Request $request )
    {
        $type = $request->get( 'type' );
        $product_id = $request->get( 'product_id' );
        $variant_id = $request->get( 'variant_id' );
        $quantity = $request->get( 'quantity' );
        $attributes = $request->get( 'attributes', array() );

        // Get return key
        if ( $type == 'multi' ) {
            $key = 'cart';
        } else {
            $key = 'item';
        }

        // If woocommerce isn't active
        if ( !Helper::is_woocommerce_active() ) {
            $this->response( array(
                $key => array(),
            ) );
        }

        if ( $type == 'multi' ) { // Add multi
            if ( is_array( $product_id ) ) {
                foreach ( $product_id as $index => $id ) {
                    $vid = isset( $variant_id[$index] ) ? $variant_id[$index] : null;
                    $qty = isset( $quantity[$index] ) ? $quantity[$index] : 1;
                    $attr = $attributes && isset( $attributes[$index] ) ? $attributes[$index] : array();

                    $this->cart_manager->add_cart( $id, $vid, $qty, $attr );
                }
            }

            $result = $this->cart_manager->get_cart();
        } else { // Add single
            $result = $this->cart_manager->add_cart( $product_id, $variant_id, $quantity, $attributes );
        }

        $this->response( array(
            $key => $result,
        ) );
    }

    /**
     * Get shop info
     *
     * @param Request $request
     * @resource shop
     */
    protected function put_cart_beeketing( Request $request )
    {
        // If woocommerce isn't active
        if ( !Helper::is_woocommerce_active() ) {
            $this->response( array(
                'cart' => array(),
            ) );
        }

        $id = $request->get( 'id' );
        foreach ( $id as $itemId => $quantity ) {
            $this->cart_manager->update_cart( $itemId, $quantity );
        }

        $result = $this->cart_manager->get_cart();
        $this->response(array(
            'cart' => $result,
        ));
    }

    /**
     * Post install app
     *
     * @param Request $request
     * @resource install_app
     */
    protected function post_install_app_beeketing( Request $request ) {
        $api_key = $request->headers->get( BridgeApi::VALIDATE_HEADER_API_KEY );
        $content = json_decode( $request->getContent(), true );
        if ( !isset( $content['app'] ) ) {
            $this->response_error( 'Data is not valid' );
        }

        $app_code = $content['app'];
        $app_key = isset($content['app_key']) ? $content['app_key'] : null;
        $setting_keys = SettingHelper::setting_keys();
        if ( isset( $setting_keys[$app_code] ) ) {
            $this->setting_helper->switch_settings( $app_code );
        } elseif ( $app_key ) {
            $this->setting_helper->set_app_setting_key( Helper::generate_app_setting_key( $app_key ) );
        } else {
            $this->response_error( 'App setting key is not valid' );
        }

        $token = Helper::generate_access_token();
        $this->setting_helper->update_settings( Setting::SETTING_ACCESS_TOKEN, $token );
        $this->setting_helper->update_settings( Setting::SETTING_API_KEY, $api_key );

        $this->response( array(
            'setting' => $this->setting_helper->get_settings(),
        ) );
    }

    /**
     * Get shop info
     *
     * @param Request $request
     * @resource shop
     * @return array(
     *  "domain": "beeketing.com",
     *  "absolute_path": "https://beeketing.com",
     *  "currency": "USD",
     *  "currency_format": "${{amount}}"
     * )
     */
    protected function get_shop( Request $request )
    {
        // Set shop domain from response data
        $result['domain'] = Helper::beeketing_get_shop_domain();
        $result['absolute_path'] = get_site_url();
        $result['currency'] = Helper::get_currency();
        $result['currency_format'] = Helper::get_currency_format();
        $result['id'] = '';

        $this->response( array(
            'shop' => $result,
        ) );
    }

    /**
     * Get option
     *
     * @param Request $request
     * @resource setting
     */
    protected function get_setting( Request $request ) {
        $this->response( array(
            'setting' => $this->setting_helper->get_settings(),
        ) );
    }

    /**
     * Put option
     *
     * @param Request $request
     * @resource setting
     */
    protected function put_setting( Request $request )
    {
        // Validate
        if ( !$request->getContent() ) {
            $this->response_error( 'Setting data is not valid' );
        }

        $content = json_decode( $request->getContent(), true );
        if ( !isset( $content['setting'] ) ) {
            $this->response_error( 'Setting data is not valid' );
        }

        // Update setting
        foreach ($content['setting'] as $setting => $value) {
            $this->setting_helper->update_settings( $setting, $value );
        }

        $this->response( array(
            'setting' => $this->setting_helper->get_settings(),
        ) );
    }

    /**
     * Put option
     *
     * @param Request $request
     * @resource option
     * @deprecated
     */
    protected function put_option( Request $request )
    {
        // Validate
        if ( !$request->getContent() ) {
            $this->response_error( 'Option data is not valid' );
        }

        $content = json_decode( $request->getContent(), true );
        if ( !isset( $content['option'] ) ) {
            $this->response_error( 'Option data is not valid' );
        }

        // Update setting
        foreach ($content['option'] as $setting => $value) {
            $this->setting_helper->update_settings( $setting, $value );
        }

        $this->response( array(
            'option' => $this->setting_helper->get_settings(),
        ) );
    }

    /**
     * Get collections count
     *
     * @param Request $request
     * @resource collections_count
     */
    protected function get_collections_count( Request $request ) {
        // If woocommerce isn't active
        if ( !Helper::is_woocommerce_active() ) {
            $this->response( array(
                'count' => 0,
            ) );
        }

        $result = $this->collection_manager->get_collections_count();

        if ( is_wp_error( $result ) ) {
            $this->response_error( $result->get_error_message() );
        }

        $this->response( array(
            'count' => $result,
        ) );
    }

    /**
     * Get collections
     *
     * @param Request $request
     * @resource collections
     */
    protected function get_collections( Request $request )
    {
        $resource_id = $request->get( 'resource_id' );
        $key = $resource_id ? 'collection' : 'collections';

        // If woocommerce isn't active
        if ( !Helper::is_woocommerce_active() ) {
            $this->response( array(
                $key => array(),
            ) );
        }

        $title = $request->get( 'title' );
        $limit = $request->get( 'limit', Api::ITEM_PER_PAGE );
        $page = $request->get( 'page', Api::PAGE );

        if ( $resource_id ) { // Collection
            $result = $this->collection_manager->get_collection_by_id( $resource_id );

        } else { // All collections
            $result = $this->collection_manager->get_collections( $title, $page, $limit );

        }

        // Error
        if ( is_wp_error( $result ) ) {
            $this->response_error( $result->get_error_message() );
        }

        // Result
        $this->response( array(
            $key => $result,
        ) );
    }

    /**
     * Get collects count
     *
     * @param Request $request
     * @resource collects_count
     */
    protected function get_collects_count( Request $request )
    {
        // If woocommerce isn't active
        if ( !Helper::is_woocommerce_active() ) {
            $this->response( array(
                'count' => 0,
            ) );
        }

        $collection_id = $request->get( 'collection_id' );
        $product_id = $request->get( 'product_id' );

        if ( $collection_id ) {
            $count = $this->collect_manager->get_collects_count_by_collection_id( $collection_id );
        } elseif ( $product_id ) {
            $count = $this->collect_manager->get_collects_count_by_product_id( $product_id );
        } else {
            $count = $this->collect_manager->get_collects_count();
        }

        $this->response( array(
            'count' => $count,
        ) );
    }

    /**
     * Get collects
     *
     * @param Request $request
     * @resource collects
     */
    protected function get_collects( Request $request )
    {
        // If woocommerce isn't active
        if ( !Helper::is_woocommerce_active() ) {
            $this->response( array(
                'collects' => array(),
            ) );
        }

        $limit = $request->get( 'limit', Api::ITEM_PER_PAGE );
        $page = $request->get( 'page', Api::PAGE );
        $collection_id = $request->get( 'collection_id' );
        $product_id = $request->get( 'product_id' );

        if ( $collection_id ) {
            $collects = $this->collect_manager->get_collects_by_collection_id( $collection_id, $page, $limit );
        } elseif ( $product_id ) {
            $collects = $this->collect_manager->get_collects_by_product_id( $product_id, $page, $limit );
        } else {
            $collects = $this->collect_manager->get_collects( $page, $limit );
        }

        $this->response( array(
            'collects' => $collects,
        ) );
    }

    /**
     * Get products count
     *
     * @param Request $request
     * @resource products_count
     */
    protected function get_products_count( Request $request ) {
        // If woocommerce isn't active
        if ( !Helper::is_woocommerce_active() ) {
            $this->response( array(
                'count' => 0,
            ) );
        }

        $result = $this->product_manager->get_products_count();

        if ( is_wp_error( $result ) ) {
            $this->response_error( $result->get_error_message() );
        }

        $this->response( array(
            'count' => $result,
        ) );
    }

    /**
     * Get products
     *
     * @param Request $request
     * @resource products
     */
    protected function get_products( Request $request )
    {
        $resource_id = $request->get( 'resource_id' );
        $key = $resource_id ? 'product' : 'products';

        // If woocommerce isn't active
        if ( !Helper::is_woocommerce_active() ) {
            $this->response( array(
                $key => array(),
            ) );
        }

        $limit = $request->get( 'limit', Api::ITEM_PER_PAGE );
        $page = $request->get( 'page', Api::PAGE );
        $title = $request->get( 'title' );

        if ( $resource_id ) { // Product
            $result = $this->product_manager->get_product_by_id( $resource_id );

        } else { // All products
            $result = $this->product_manager->get_products( $title, $page, $limit );

        }

        // Error
        if ( is_wp_error( $result ) ) {
            $this->response_error( $result->get_error_message() );
        }

        // Result
        $this->response( array(
            $key => $result,
        ) );
    }

    /**
     * Update products
     *
     * @param Request $request
     * @resource products
     */
    protected function put_products( Request $request )
    {
        $resource_id = $request->get( 'resource_id' );

        $content = json_decode( $request->getContent(), true );
        if ( !$resource_id || !isset( $content['product'] ) ) {
            $this->response_error( 'Data is not valid' );
        }

        // If woocommerce isn't active
        if ( !Helper::is_woocommerce_active() ) {
            $this->response( array(
                'product' => array(),
            ) );
        }

        // Update
        $result = $this->product_manager->update_product( $resource_id, $content['product'] );

        // Error
        if ( is_wp_error( $result ) ) {
            $this->response_error( $result->get_error_message() );
        }

        // Result
        $this->response( array(
            'product' => $result,
        ) );
    }

    /**
     * Get customers count
     *
     * @param Request $request
     * @resource customers_count
     */
    protected function get_customers_count( Request $request )
    {
        // If woocommerce isn't active
        if ( !Helper::is_woocommerce_active() ) {
            $this->response( array(
                'count' => 0,
            ) );
        }

        $count = $this->customer_manager->get_customers_count();

        $this->response( array(
            'count' => $count,
        ) );
    }

    /**
     * Get customers
     *
     * @param Request $request
     * @resource customers
     */
    protected function get_customers( Request $request )
    {
        $resource_id = $request->get( 'resource_id' );
        $key = $resource_id ? 'customer' : 'customers';

        // If woocommerce isn't active
        if ( !Helper::is_woocommerce_active() ) {
            $this->response( array(
                $key => array(),
            ) );
        }

        $limit = $request->get( 'limit', Api::ITEM_PER_PAGE );
        $page = $request->get( 'page', Api::PAGE );

        if ( $resource_id ) { // Customer
            $result = $this->customer_manager->get_customer_by_id( $resource_id );

        } else { // All customers
            $result = $this->customer_manager->get_customers( $page, $limit );

        }

        // Error
        if ( is_wp_error( $result ) ) {
            $this->response_error( $result->get_error_message() );
        }

        // Result
        $this->response( array(
            $key => $result,
        ) );
    }

    /**
     * Get orders count
     *
     * @param Request $request
     * @resource orders_count
     */
    protected function get_orders_count( Request $request )
    {
        // If woocommerce isn't active
        if ( !Helper::is_woocommerce_active() ) {
            $this->response( array(
                'count' => 0,
            ) );
        }

        $status = $request->get( 'status' );
        $count = $this->order_manager->get_orders_count( $status );

        $this->response( array(
            'count' => $count,
        ) );
    }

    /**
     * Get orders
     *
     * @param Request $request
     * @resource orders
     */
    protected function get_orders( Request $request )
    {
        $resource_id = $request->get( 'resource_id' );
        $key = $resource_id ? 'order' : 'orders';

        // If woocommerce isn't active
        if ( !Helper::is_woocommerce_active() ) {
            $this->response( array(
                $key => array(),
            ) );
        }

        $limit = $request->get( 'limit', Api::ITEM_PER_PAGE );
        $page = $request->get( 'page', Api::PAGE );
        $status = $request->get( 'status' );

        if ( $resource_id ) { // Order
            $result = $this->order_manager->get_order_by_id( $resource_id );

        } else { // All orders
            $result = $this->order_manager->get_orders( $status, $page, $limit );

        }

        // Error
        if ( is_wp_error( $result ) ) {
            $this->response_error( $result->get_error_message() );
        }

        // Result
        $this->response( array(
            $key => $result,
        ) );
    }

    /**
     * Get variants
     *
     * @param Request $request
     * @resource variants
     */
    protected function get_variants( Request $request )
    {
        $resource_id = $request->get( 'resource_id' );
        $key = $resource_id ? 'variant' : 'variants';

        // If woocommerce isn't active
        if ( !Helper::is_woocommerce_active() ) {
            $this->response( array(
                $key => array(),
            ) );
        }

        if ( $resource_id ) { // Variant
            $result = $this->variant_manager->get_variant_by_id( $resource_id );

            if ( is_wp_error( $result ) ) {
                $this->response_error( $result->get_error_message() );
            }

            $this->response( array(
                $key => $result,
            ) );

        }

        $this->response( array(
            $key => array(),
        ) );
    }

    /**
     * Put variants
     *
     * @param Request $request
     * @resource variants
     */
    protected function put_variants( Request $request )
    {
        $resource_id = $request->get( 'resource_id' );

        $content = json_decode( $request->getContent(), true );
        if ( !$resource_id || !isset( $content['variant'] ) ) {
            $this->response_error( 'Data is not valid' );
        }

        // If woocommerce isn't active
        if ( !Helper::is_woocommerce_active() ) {
            $this->response( array(
                'variant' => array(),
            ) );
        }

        // Update
        $result = $this->variant_manager->update_variant( $resource_id, $content['variant'] );

        // Error
        if ( is_wp_error( $result ) ) {
            $this->response_error( $result->get_error_message() );
        }

        // Result
        $this->response( array(
            'variant' => $result,
        ) );
    }

    /**
     * Post products variants
     *
     * @param Request $request
     * @resource products_variants
     */
    protected function post_products_variants( Request $request )
    {
        $product_id = $request->get( 'product_id' );

        $content = json_decode( $request->getContent(), true );
        if ( !$product_id || !isset( $content['variant'] ) ) {
            $this->response_error( 'Data is not valid' );
        }

        // If woocommerce isn't active
        if ( !Helper::is_woocommerce_active() ) {
            $this->response( array(
                'variant' => array(),
            ) );
        }

        // Create
        $product = $this->product_manager->get_product_by_id( $product_id );
        $result = $this->variant_manager->create_variant( $product, $content['variant'] );

        // Error
        if ( is_wp_error( $result ) ) {
            $this->response_error( $result->get_error_message() );
        }

        // Result
        $this->response( array(
            'variant' => $result,
        ) );
    }

    /**
     * Delete products variants
     *
     * @param Request $request
     * @resource products_variants
     */
    protected function delete_products_variants( Request $request )
    {
        $variant_id = $request->get( 'variant_id' );

        // Validate data
        if ( !$variant_id ) {
            $this->response_error( 'Data is not valid' );
        }

        // Delete
        $result = $this->variant_manager->delete_variant( $variant_id );

        // Error
        if ( is_wp_error( $result ) ) {
            $this->response_error( $result->get_error_message() );
        }

        // Result
        $this->response(array());
    }
}