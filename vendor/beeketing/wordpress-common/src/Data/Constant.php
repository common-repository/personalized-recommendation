<?php
/**
 * Plugin constants
 *
 * @since      1.0.0
 * @author     Beeketing
 *
 */

namespace BKPersonalizedRecommendationSDK\Data;


class Constant
{
    const PLATFORM = 'wordpress';
    const COOKIE_CART_TOKEN_LIFE_TIME = 2592000;
    const CART_TOKEN_KEY = '_beeketing_cart_token';
    const API_RATE_LIMIT = 30;
    const COOKIE_BEEKETING_APPS_DATA = 'beeketing_apps_data';
    const COOKIE_BEEKETING_APPS_DATA_LIFETIME = 300;
}