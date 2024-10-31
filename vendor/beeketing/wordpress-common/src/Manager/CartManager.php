<?php
/**
 * Created by PhpStorm.
 * User: tungquach
 * Date: 30/08/2017
 * Time: 09:34
 */

namespace BKPersonalizedRecommendationSDK\Manager;


use BKPersonalizedRecommendationSDK\Data\Constant;
use BKPersonalizedRecommendationSDK\Libraries\Helper;

class CartManager
{
    /**
     * CartManager constructor.
     */
    public function __construct()
    {
        add_filter('woocommerce_add_to_cart_fragments', array($this, 'filter_add_to_cart_fragment'), 10, 1);
    }

    /**
     * Add to cart fragment
     *
     * @since 2.0.0
     * @param $fragments
     * @return mixed
     */
    public function filter_add_to_cart_fragment( $fragments )
    {
        $fragments['beeketing_cart'] = $this->get_cart();
        return $fragments;
    }

    /**
     * Get cart
     *
     * @return array
     */
    public function get_cart()
    {
        // Init cart data
        global $woocommerce;
        $cart = $woocommerce->cart;
        $cart_items = $cart->get_cart();
        $cart_token = isset( $_COOKIE[Constant::CART_TOKEN_KEY] ) ? $_COOKIE[Constant::CART_TOKEN_KEY] : '';
        $result = array(
            'token' => $cart_token,
            'item_count' => count($cart_items),
            'total_price' => $cart->subtotal,
            'items' => array(),
        );

        // Traverse cart items
        foreach ($cart_items as $id => $item) {
            $result['items'][] = $this->format_item($id, $item);
        }

        return $result;
    }

    /**
     * Add cart
     *
     * @param $product_id
     * @param $variant_id
     * @param $quantity
     * @param $params
     * @return array
     */
    public function add_cart( $product_id, $variant_id, $quantity, $params )
    {
        global $woocommerce;
        $woocommerce->session->set_customer_session_cookie( true );
        $cart_item_key = $woocommerce->cart->add_to_cart( $product_id, $quantity, $variant_id, $params );

        $cart = $woocommerce->cart;
        $cart_items = $cart->get_cart();

        // Traverse cart items
        foreach ( $cart_items as $id => $item ) {
            if ( $cart_item_key == $id ) {
                return $this->format_item( $id, $item );
            }
        }

        return array();
    }

    /**
     * Update cart
     *
     * @param $item_id
     * @param $quantity
     * @return bool
     */
    public function update_cart( $item_id, $quantity )
    {
        global $woocommerce;
        $cart_item_key = sanitize_text_field( $item_id );
        if ( $cart_item = $woocommerce->cart->get_cart_item( $cart_item_key ) ) {
            $woocommerce->cart->set_quantity( $cart_item_key, $quantity );
            return true;
        }

        return false;
    }

    /**
     * Format item
     *
     * @param $id
     * @param $item
     * @return array
     */
    private function format_item( $id, $item )
    {
        if ( Helper::is_wc3() ) {
            $variation_id = $item['data']->get_id();
            $product = get_post( $item['product_id'] );
            $price = $item['data']->get_price();
            $sku = $item['data']->get_sku();
        } else {
            $variation_id = $item['data']->variation_id;
            $product = $item['data']->post;
            $price = $item['data']->price;
            $sku = $item['data']->sku;
        }

        $title = html_entity_decode( $product->post_title );
        $option1 = get_post_meta( $variation_id, '_beeketing_option1', true );
        $variant_title = $option1 ?: html_entity_decode( get_the_title( $variation_id ) );

        $image_data = wp_get_attachment_image_src( get_post_thumbnail_id( $product->ID ), 'thumbnail' );

        return array(
            'id' => $id,
            'variant_id' => (int)($item['variation_id'] ?: $item['product_id']),
            'variant_title' => $variant_title,
            'product_id' => (int)$item['product_id'],
            'title' => $variant_title,
            'product_title' => $title,
            'price' => (float)$price,
            'line_price' => (float)$item['line_total'],
            'quantity' => (int)$item['quantity'],
            'sku' => $sku,
            'handle' => ltrim( str_replace( get_site_url(), '', get_permalink( $product->ID ) ), '/' ),
            'image' => isset( $image_data[0] ) ? $image_data[0] : '',
            'url' => get_permalink( $product->ID ),
        );
    }
}