<?php
/**
 * Plugin webhook topics
 *
 * @since      1.0.0
 * @author     Beeketing
 */

namespace BKPersonalizedRecommendationSDK\Data;


class Webhook
{
    const UNINSTALL = 'app/uninstalled';
    const ORDER_UPDATE = 'orders/updated';
    const PRODUCT_UPDATE = 'products/update';
    const PRODUCT_DELETE = 'products/delete';
    const COLLECTION_CREATE = 'collections/create';
    const COLLECTION_UPDATE = 'collections/update';
    const COLLECTION_DELETE = 'collections/delete';
    const CUSTOMER_CREATE = 'customers/create';
    const CUSTOMER_UPDATE = 'customers/update';
    const CUSTOMER_DELETE = 'customers/delete';
}