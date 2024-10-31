<?php
/**
 * Created by PhpStorm.
 * User: tungquach
 * Date: 24/08/2017
 * Time: 17:28
 */

namespace BKPersonalizedRecommendationSDK\Data;


final class AppSettingKeys
{
    const BETTERCOUPONBOX_KEY = 'beeketing_bettercouponbox_settings';
    const SALESPOP_KEY = 'beeketing_salespop_settings';
    const QUICKFACEBOOKCHAT_KEY = 'beeketing_quickfacebookchat_settings';
    const HAPPYEMAIL_KEY = 'beeketing_happyemail_settings';
    const PERSONALIZEDRECOMMENDATION_KEY = 'beeketing_personalizedrecommendation_settings';
    const CHECKOUTBOOST_KEY = 'beeketing_checkoutboost_settings';
    const BOOSTSALES_KEY = 'beeketing_boostsales_settings';
    const MAILBOT_KEY = 'beeketing_mailbot_settings';
    const COUNTDOWNCART_KEY = 'beeketing_countdowncart_settings';
    const MOBILEWEBBOOST_KEY = 'beeketing_mobilewebboost_settings';

    /**
     * Get all constants in class
     * @return array
     */
    public static function get_constants() {
        $oClass = new \ReflectionClass( __CLASS__ );
        $eventNames = array_values($oClass->getConstants());

        return $eventNames;
    }
}