<?php
/**
 * Created by PhpStorm.
 * User: tungquach
 * Date: 01/04/2017
 * Time: 00:11
 */

namespace BKPersonalizedRecommendationSDK\Manager;


use BKPersonalizedRecommendationSDK\Data\Api;
use BKPersonalizedRecommendationSDK\Data\Constant;
use BKPersonalizedRecommendationSDK\Libraries\Helper;

class OrderManager
{
    private $customer_manager;
    private static $default_order_status = array( 'wc-pending', 'wc-processing', 'wc-on-hold' );

    /**
     * OrderManager constructor.
     */
    public function __construct()
    {
        $this->customer_manager = new CustomerManager();
    }

    /**
     * Get orders count
     *
     * @param null $status
     * @return int
     */
    public function get_orders_count( $status = null )
    {
        // Get order status
        if ( $status ) {
            if ( $status == 'any' ) {
                $order_status = array_keys( wc_get_order_statuses() );
            } else {
                $order_status = $status;
            }
        } else {
            $order_status = self::$default_order_status;
        }

        $args = array(
            'fields'      => 'ids',
            'post_type'   => 'shop_order',
            'post_status' => $order_status,
            'posts_per_page' => -1,
        );

        $orders = new \WP_Query( $args );

        if ( !is_wp_error( $orders ) ) {
            return $orders->post_count;
        }

        return 0;
    }

    /**
     * Get order by id
     *
     * @param $id
     * @return array
     */
    public function get_order_by_id( $id )
    {
        $order = wc_get_order( $id );

        if ( $order ) {
            return $this->format_order($order);
        }

        return array();
    }

    /**
     * Get orders
     *
     * @param null $status
     * @param int $page
     * @param int $limit
     * @return array
     */
    public function get_orders( $status = null, $page = Api::PAGE, $limit = Api::ITEM_PER_PAGE )
    {
        // Get order status
        if ( $status ) {
            if ( $status == 'any' ) {
                $order_status = array_keys( wc_get_order_statuses() );
            } else {
                $order_status = $status;
            }
        } else {
            $order_status = self::$default_order_status;
        }

        $args = array(
            'fields' => 'ids',
            'post_type' => 'shop_order',
            'post_status' => $order_status,
            'posts_per_page' => $limit,
            'offset' => ( $page - 1 ) * $limit,
        );

        $result = new \WP_Query( $args );

        // Traverse all terms
        $orders = array();
        if ( !is_wp_error( $result ) ) {
            if ( $result->have_posts() ) {
                // Get all order objects
                $wc_orders = wc_get_orders( array(
                    'include' => $result->posts,
                    'limit' => -1,
                ) );
                $wc_orders_object = array();
                foreach ( $wc_orders as $wc_order ) {
                    $order_id = Helper::is_wc3() ? $wc_order->get_id() : $wc_order->id;
                    $wc_orders_object[$order_id] = $wc_order;
                }

                // Format each order
                foreach ($result->posts as $id) {
                    $order = isset( $wc_orders_object[$id] ) ? $wc_orders_object[$id] : wc_get_order( $id );
                    $orders[] = $this->format_order( $order );
                }
            }
        }

        return $orders;
    }

    /**
     * Format order
     *
     * @param $order
     * @return array
     */
    private function format_order( $order )
    {
        $currency = $total_tax = $total_discount = '';
        if ( Helper::is_wc3() ) {
            $order_id = $order->get_id();
            $order_email = $order->get_billing_email();
            $order_date = $order->get_date_created();
            $order_date_modified = $order->get_date_modified();
            $currency = $order->get_currency();
            $total_tax = $order->get_total_tax();
            $total_discount = $order->get_discount_total();
        } else {
            $order_id = $order->id;
            $order_email = $order->billing_email;
            $order_date = $order->order_date;
            $order_date_modified = $order->modified_date;
        }

        $order_data = array(
            'id' => $order_id,
            'email' => $order_email,
            'financial_status' => $order->get_status(),
            'fulfillment_status' => '',
            'line_items' => array(),
            'cart_token' => get_post_meta( $order_id, Constant::CART_TOKEN_KEY, true ),
            'currency' => $currency,
            'name' => '',
            'total_tax' => $total_tax,
            'total_discounts' => $total_discount,
            'total_price' => $order->get_total(),
            'subtotal_price' => $order->get_subtotal(),
            'total_line_items_price' => $order->get_subtotal(),
            'processed_at' => Helper::format_date( $order_date ),
            'cancelled_at' => $order->get_status() == 'cancelled' ? Helper::format_date( $order_date_modified ) : '',
            'note_attributes' => array(),
            'source_name' => '',
        );

        // Add contact info
        if ( $order->get_user() ) {
            $contact = $this->customer_manager->get_customer_by_id( $order->get_user_id() );

            if ( $contact ) {
                $order_data['customer'] = $contact;
            }
        } else {
            if ( Helper::is_wc3() ) {
                $order_data['customer']['email'] = $order->get_billing_email();
                $order_data['customer']['first_name'] = $order->get_billing_first_name();
                $order_data['customer']['last_name'] = $order->get_billing_last_name();
                $order_data['customer']['address1'] = $order->get_billing_address_1();
                $order_data['customer']['address2'] = $order->get_billing_address_2();
                $order_data['customer']['city'] = $order->get_billing_city();
                $order_data['customer']['company'] = $order->get_billing_company();
                $order_data['customer']['province'] = $order->get_billing_state();
                $order_data['customer']['zip'] = $order->get_billing_postcode();
                $order_data['customer']['country'] = $order->get_billing_country();
            } else {
                $order_data['customer']['email'] = $order->billing_email;
                $order_data['customer']['first_name'] = $order->billing_first_name;
                $order_data['customer']['last_name'] = $order->billing_last_name;
                $order_data['customer']['address1'] = $order->billing_address_1;
                $order_data['customer']['address2'] = $order->billing_address_2;
                $order_data['customer']['city'] = $order->billing_city;
                $order_data['customer']['company'] = $order->billing_company;
                $order_data['customer']['province'] = $order->billing_state;
                $order_data['customer']['zip'] = $order->billing_postcode;
                $order_data['customer']['country'] = $order->billing_country;
            }

            $order_data['customer']['country_code'] = '';
            $order_data['customer']['signed_up_at'] = Helper::format_date( $order_date );
            $order_data['customer']['accepts_marketing'] = true;
            $order_data['customer']['verified_email'] = false;
            $order_data['customer']['orders_count'] = 1;
            $order_data['customer']['total_spent'] = $order->get_total();
        }

        // Add line items
        foreach ( $order->get_items() as $item_id => $item ) {
            if ( Helper::is_wc3() ) {
                $product = $item->get_product();
                $variant_id = $item->get_variation_id();
                $product_id = $item->get_product_id();
                $tax = $item->get_total_tax();
            } else {
                $product = $order->get_product_from_item( $item );
                $variant_id = isset( $product->variation_id ) ? $product->variation_id : null;
                $product_id = $product->id;
                $tax = $item['line_tax'];
            }

            $product_sku = null;
            // Check if the product exists.
            if ( is_object( $product ) ) {
                $product_sku = $product->get_sku();
            }

            $order_data['line_items'][] = array(
                'id' => $item_id,
                'title' => $item['name'],
                'price' => wc_format_decimal( $order->get_item_total( $item, false, false ), 2 ),
                'sku' => $product_sku,
                'requires_shipping' => '',
                'taxable' => $tax,
                'product_id' => $product_id,
                'variant_id' => $variant_id ?: $product_id,
                'vendor' => '',
                'name' => $product ? $product->get_title() : '',
                'fulfillable_quantity' => wc_stock_amount( $item['qty'] ),
                'fulfillment_service' => '',
                'fulfillment_status' => '',
            );
        }

        return $order_data;
    }
}