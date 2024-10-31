<?php
/**
 * Plugin helper
 *
 * @since      1.0.0
 * @author     Beeketing
 *
 */

namespace BKPersonalizedRecommendationSDK\Libraries;


use BKPersonalizedRecommendationSDK\Data\Constant;

class Helper
{
    /**
     * Check if WooCommerce is active
     *
     * @return bool
     */
    public static function is_woocommerce_active()
    {
        if ( ! function_exists( 'is_plugin_active_for_network' ) ) {
            require_once( ABSPATH . 'wp-admin/includes/plugin.php' );
        }

        if ( ! in_array( 'woocommerce/woocommerce.php', apply_filters( 'active_plugins', get_option( 'active_plugins' ) ) ) ) {
            if ( ! is_plugin_active_for_network( 'woocommerce/woocommerce.php' ) ) {
                return false;
            }
        }

        return true;
    }

    /**
     * Get woocommerce plugin url
     *
     * @return string
     */
    public static function get_woocommerce_plugin_url()
    {
        return get_site_url() . '/wp-admin/plugin-install.php?tab=plugin-information&plugin=woocommerce';
    }

    /**
     * Get shop domain
     *
     * @return string
     */
    public static function beeketing_get_shop_domain()
    {
        $site_url = get_site_url();
        $url_parsed = parse_url( $site_url );
        $host = isset( $url_parsed['host'] ) ? $url_parsed['host'] : '';

        // Config www
        if ( isset( $_GET['www'] ) ) {
            if ( in_array( $_GET['www'], array(0, false) ) ) {
                $host = preg_replace('/^www\./', '', $host);
            } elseif ( !preg_match('/^www\./', $host) && in_array( $_GET['www'], array(1, true) ) ) {
                $host = 'www.' . $host;
            }
        }

        return $host;
    }

    /**
     * Get currency format
     *
     * @return string|null
     */
    public static function get_currency_format()
    {
        if ( self::is_woocommerce_active() ) {
            $currency_format = wc_price( 11.11 );
            $currency_format = html_entity_decode( $currency_format );
            $currency_format = preg_replace( '/[1]+[.,]{0,1}[1]+/', '{{amount}}', $currency_format, 1 );

            return $currency_format;
        }

        return null;
    }

    /**
     * Get currency
     *
     * @return string|null
     */
    public static function get_currency()
    {
        if ( self::is_woocommerce_active() ) {
            return get_woocommerce_currency();
        }

        return null;
    }

    /**
     * Get local file contents
     *
     * @param $file_path
     * @return string
     */
    public static function get_local_file_contents( $file_path ) {
        $contents = @file_get_contents( $file_path );
        if ( !$contents ) {
            ob_start();
            @include_once( $file_path );
            $contents = ob_get_clean();
        }

        return $contents;
    }

    /**
     * Is in current screen
     *
     * @param array $pages
     * @return bool
     */
    public static function is_in_current_screen( $pages = array() )
    {
        $screen = get_current_screen();

        if ( in_array( $screen->parent_base, $pages ) ) {
            return true;
        }

        return false;
    }

    /**
     * Is beeketing hidden name
     *
     * @param $name
     * @return bool
     */
    public static function is_beeketing_hidden_name( $name )
    {
        if ((bool) preg_match('/\(BK (\d+)\)/', $name, $matches)) {
            return true;
        }

        return false;
    }

    /**
     * Init cart token
     */
    public static function init_cart_token()
    {
        if ( ! isset( $_COOKIE[Constant::CART_TOKEN_KEY] ) ) {
            setcookie(
                Constant::CART_TOKEN_KEY,
                md5( uniqid( Constant::CART_TOKEN_KEY ) ),
                time() + Constant::COOKIE_CART_TOKEN_LIFE_TIME, '/'
            );
        }
    }

    /**
     * Is wc 3 version
     *
     * @return mixed
     */
    public static function is_wc3()
    {
        return version_compare( WOOCOMMERCE_VERSION, '3.0', '>=' );
    }

    /**
     * Generate access token
     *
     * @return string
     */
    public static function generate_access_token()
    {
        try {
            $string = random_bytes( 16 );
            $token = bin2hex( $string );
        } catch ( \Exception $e ) {
            $token = md5( uniqid( rand(), true ) );
        }

        return $token;
    }

    /**
     * High priority widget
     *
     * @param $name
     */
    public static function high_priority_widget( $name ) {
        // Globalize the metaboxes array, this holds all the widgets for wp-admin
        global $wp_meta_boxes;

        // Get the regular dashboard widgets array
        // (which has our new widget already but at the end)
        $normal_dashboard = $wp_meta_boxes['dashboard']['normal']['core'];

        // Backup and delete our new dashboard widget from the end of the array
        $example_widget_backup = array( $name => $normal_dashboard[$name] );
        unset( $normal_dashboard[$name] );

        // Merge the two arrays together so our widget is at the beginning
        $sorted_dashboard = array_merge( $example_widget_backup, $normal_dashboard );

        // Save the sorted array back into the original metaboxes
        $wp_meta_boxes['dashboard']['normal']['core'] = $sorted_dashboard;
    }

    /**
     * Format date
     *
     * @param $datetime
     * @return string
     */
    public static function format_date( $datetime )
    {
        try {
            if ( is_numeric( $datetime ) ) {
                $date = new \DateTime( '@{$datetime}' );
            } elseif ( is_string( $datetime ) ) {
                $date = new \DateTime( $datetime );
            } elseif ( $datetime instanceof \DateTime ) {
                $date = $datetime;
            } else {
                $date = new \DateTime();
            }
        } catch ( \Exception $e ) {
            $date = new \DateTime();
        }

        return $date->format('Y-m-d H:i:s');
    }

    /**
     * Generate access token
     *
     * @param $app_key
     * @return string
     */
    public static function generate_app_setting_key( $app_key )
    {
        return sprintf('beeketing_%s_settings', $app_key);
    }

    /**
     * Is support api rate limit
     *
     * @return mixed
     * @since 3.0.6
     */
    public static function is_support_api_rate_limit()
    {
        return version_compare( phpversion(), '5.5', '>=' ) && extension_loaded( 'bcmath' );
    }
}