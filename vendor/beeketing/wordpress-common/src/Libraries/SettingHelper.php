<?php
/**
 * Created by PhpStorm.
 * User: tungquach
 * Date: 30/03/2017
 * Time: 11:35
 */

namespace BKPersonalizedRecommendationSDK\Libraries;


use BKPersonalizedRecommendationSDK\Data\AppCodes;
use BKPersonalizedRecommendationSDK\Data\AppSettingKeys;

class SettingHelper
{
    /**
     * @var string
     */
    private $app_setting_key;

    /**
     * @var array
     */
    private static $settings = array();

    /**
     * @var string
     */
    private $plugin_version;

    /**
     * @var SettingHelper
     */
    private static $instance = null;

    /**
     * Set singleton instance
     *
     * @param $instance
     * @return SettingHelper
     */
    public static function set_instance( $instance ) {
        self::$instance = $instance;
        // Return instance of class
        return self::$instance;
    }

    /**
     * Singleton instance
     *
     * @return SettingHelper
     */
    public static function get_instance() {
        // Check to see if an instance has already
        // been created
        if ( is_null( self::$instance ) ) {
            // If not, return a new instance
            self::$instance = new self();
            return self::$instance;
        } else {
            // If so, return the previously created
            // instance
            return self::$instance;
        }
    }

    /**
     * Get plugin version
     *
     * @return string
     */
    public function get_plugin_version()
    {
        return $this->plugin_version;
    }

    /**
     * Plugin version
     *
     * @param $plugin_version
     */
    public function set_plugin_version( $plugin_version )
    {
        $this->plugin_version = $plugin_version;
    }

    /**
     * Set app setting key
     *
     * @return string
     */
    public function get_app_setting_key()
    {
        return $this->app_setting_key;
    }

    /**
     * Set app setting key
     *
     * @param $app_setting_key
     */
    public function set_app_setting_key( $app_setting_key )
    {
        $this->app_setting_key = $app_setting_key;
    }

    /**
     * App setting keys
     *
     * @return array
     */
    public static function setting_keys()
    {
        return array(
          AppCodes::BETTERCOUPONBOX => AppSettingKeys::BETTERCOUPONBOX_KEY,
          AppCodes::BOOSTSALES => AppSettingKeys::BOOSTSALES_KEY,
          AppCodes::CHECKOUTBOOST => AppSettingKeys::CHECKOUTBOOST_KEY,
          AppCodes::HAPPYEMAIL => AppSettingKeys::HAPPYEMAIL_KEY,
          AppCodes::MAILBOT => AppSettingKeys::MAILBOT_KEY,
          AppCodes::PERSONALIZEDRECOMMENDATION => AppSettingKeys::PERSONALIZEDRECOMMENDATION_KEY,
          AppCodes::QUICKFACEBOOKCHAT => AppSettingKeys::QUICKFACEBOOKCHAT_KEY,
          AppCodes::SALESPOP => AppSettingKeys::SALESPOP_KEY,
          AppCodes::COUNTDOWNCART => AppSettingKeys::COUNTDOWNCART_KEY,
          AppCodes::MOBILEWEBBOOST => AppSettingKeys::MOBILEWEBBOOST_KEY,
        );
    }

    /**
     * Switch settings
     *
     * @param $app_code
     */
    public function switch_settings( $app_code )
    {
        $setting_keys = self::setting_keys();
        if ( isset( $setting_keys[$app_code] ) ) {
            $setting_key = $setting_keys[$app_code];
            $this->app_setting_key = $setting_key;
        }
    }

    /**
     * Get settings
     *
     * @param null $key
     * @param null $default
     * @return mixed
     */
    public function get_settings( $key = null, $default = null )
    {
        $settings = isset(self::$settings[$this->app_setting_key]) ? self::$settings[$this->app_setting_key] : array();
        if ( !$settings ) {
            $settings = get_option( $this->app_setting_key );
            $settings = $settings ? unserialize( $settings ) : array();
        }

        // Get setting by key
        if ( $key ) {
            if ( isset( $settings[$key] ) ) {
                return $settings[$key];
            }

            return $default;
        }

        return $settings;
    }

    /**
     * Update settings
     *
     * @param $key
     * @param $value
     * @return array|mixed
     */
    public function update_settings( $key, $value )
    {
        $settings = isset(self::$settings[$this->app_setting_key]) ? self::$settings[$this->app_setting_key] : array();
        if ( !$settings ) {
            $settings = $this->get_settings();
        }

        $settings[$key] = $value;
        self::$settings[$this->app_setting_key] = $settings;
        update_option( $this->app_setting_key, serialize( $settings ) );

        return self::$settings;
    }

    /**
     * Delete settings
     */
    public function delete_settings()
    {
        delete_option( $this->app_setting_key );
    }
}