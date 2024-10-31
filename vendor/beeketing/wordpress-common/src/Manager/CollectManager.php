<?php

/**
 * Created by PhpStorm.
 * User: tungquach
 * Date: 30/03/2017
 * Time: 18:50
 */
namespace BKPersonalizedRecommendationSDK\Manager;


use BKPersonalizedRecommendationSDK\Data\Api;
use BKPersonalizedRecommendationSDK\Libraries\QueryHelper;

class CollectManager
{
    /**
     * Count collects
     *
     * @return mixed
     */
    public function get_collects_count()
    {
        global $wpdb;
        $count = $wpdb->get_var(
            $wpdb->prepare(
                "
                SELECT COUNT(p.ID)
                FROM $wpdb->term_relationships tr 
                  JOIN $wpdb->term_taxonomy tt 
                    ON tr.term_taxonomy_id = tt.term_taxonomy_id
                  JOIN $wpdb->posts p 
                    ON tr.object_id = p.ID
                WHERE tt.taxonomy = %s 
                  AND p.post_type = %s
                  AND p.post_status = %s
                  AND p.post_password = %s
                  AND p.ID NOT IN ( " . QueryHelper::get_exclude_products_id() . " )
                ",
                "product_cat",
                "product",
                "publish",
                null
            )
        );

        return $count;
    }

    /**
     * Count collects by collection id
     *
     * @param $collection_id
     * @return mixed
     */
    public function get_collects_count_by_collection_id( $collection_id )
    {
        global $wpdb;
        $count = $wpdb->get_var(
            $wpdb->prepare(
                "
                SELECT COUNT(p.ID)
                FROM $wpdb->term_relationships tr 
                  JOIN $wpdb->term_taxonomy tt 
                    ON tr.term_taxonomy_id = tt.term_taxonomy_id
                  JOIN $wpdb->posts p 
                    ON tr.object_id = p.ID
                WHERE tt.taxonomy = %s 
                  AND p.post_type = %s
                  AND p.post_status = %s
                  AND p.post_password = %s
                  AND tr.term_taxonomy_id = %d
                  AND p.ID NOT IN ( " . QueryHelper::get_exclude_products_id() . " )
                ",
                "product_cat",
                "product",
                "publish",
                null,
                $collection_id
            )
        );

        return $count;
    }

    /**
     * Count collects by product id
     *
     * @param $product_id
     * @return mixed
     */
    public function get_collects_count_by_product_id( $product_id )
    {
        global $wpdb;
        $count = $wpdb->get_var(
            $wpdb->prepare(
                "
                SELECT COUNT(p.ID)
                FROM $wpdb->term_relationships tr 
                  JOIN $wpdb->term_taxonomy tt 
                    ON tr.term_taxonomy_id = tt.term_taxonomy_id
                  JOIN $wpdb->posts p 
                    ON tr.object_id = p.ID
                WHERE tt.taxonomy = %s 
                  AND p.post_type = %s
                  AND p.post_status = %s
                  AND p.post_password = %s
                  AND p.ID = %d
                  AND p.ID NOT IN ( " . QueryHelper::get_exclude_products_id() . " )
                ",
                "product_cat",
                "product",
                "publish",
                null,
                $product_id
            )
        );

        return $count;
    }

    /**
     * Get collects
     *
     * @param int $page
     * @param int $limit
     * @return array
     */
    public function get_collects( $page = Api::PAGE, $limit = Api::ITEM_PER_PAGE )
    {
        $offset = ( $page - 1 ) * $limit;

        global $wpdb;
        $result = $wpdb->get_results(
            $wpdb->prepare(
                "
                SELECT tr.object_id as product_id, tr.term_taxonomy_id as collection_id, tr.term_order as position
                FROM $wpdb->term_relationships tr 
                  JOIN $wpdb->term_taxonomy tt 
                    ON tr.term_taxonomy_id = tt.term_taxonomy_id
                  JOIN $wpdb->posts p 
                    ON tr.object_id = p.ID
                WHERE tt.taxonomy = %s 
                  AND p.post_type = %s
                  AND p.post_status = %s
                  AND p.post_password = %s
                  AND p.ID NOT IN ( " . QueryHelper::get_exclude_products_id() . " )
                LIMIT %d
                OFFSET %d
                ",
                "product_cat",
                "product",
                "publish",
                null,
                $limit,
                $offset
            )
        );

        $results = array();
        if ($result) {
            foreach ($result as $item) {
                $results[] = $this->format_collect($item);
            }
        }

        return $results;
    }

    /**
     * Get collects by collection id
     *
     * @param $collection_id
     * @param int $page
     * @param int $limit
     * @return array
     */
    public function get_collects_by_collection_id( $collection_id, $page = Api::PAGE, $limit = Api::ITEM_PER_PAGE )
    {
        $offset = ( $page - 1 ) * $limit;

        global $wpdb;
        $result = $wpdb->get_results(
            $wpdb->prepare(
                "
                SELECT tr.object_id as product_id, tr.term_taxonomy_id as collection_id, tr.term_order as position
                FROM $wpdb->term_relationships tr 
                  JOIN $wpdb->term_taxonomy tt 
                    ON tr.term_taxonomy_id = tt.term_taxonomy_id
                  JOIN $wpdb->posts p 
                    ON tr.object_id = p.ID
                WHERE tt.taxonomy = %s 
                  AND p.post_type = %s
                  AND p.post_status = %s
                  AND p.post_password = %s
                  AND tr.term_taxonomy_id = %d
                  AND p.ID NOT IN ( " . QueryHelper::get_exclude_products_id() . " )
                LIMIT %d
                OFFSET %d
                ",
                "product_cat",
                "product",
                "publish",
                null,
                $collection_id,
                $limit,
                $offset
            )
        );

        $results = array();
        if ($result) {
            foreach ($result as $item) {
                $results[] = $this->format_collect($item);
            }
        }

        return $results;
    }

    /**
     * Get collects by product id
     *
     * @param $product_id
     * @param int $page
     * @param int $limit
     * @return array
     */
    public function get_collects_by_product_id( $product_id,  $page = Api::PAGE, $limit = Api::ITEM_PER_PAGE )
    {
        $offset = ($page - 1) * $limit;

        global $wpdb;
        $result = $wpdb->get_results(
            $wpdb->prepare(
                "
                SELECT tr.object_id as product_id, tr.term_taxonomy_id as collection_id, tr.term_order as position
                FROM $wpdb->term_relationships tr 
                  JOIN $wpdb->term_taxonomy tt 
                    ON tr.term_taxonomy_id = tt.term_taxonomy_id
                  JOIN $wpdb->posts p 
                    ON tr.object_id = p.ID
                WHERE tt.taxonomy = %s 
                  AND p.post_type = %s
                  AND p.post_status = %s
                  AND p.post_password = %s
                  AND p.ID = %d
                  AND p.ID NOT IN ( " . QueryHelper::get_exclude_products_id() . " )
                LIMIT %d
                OFFSET %d
                ",
                "product_cat",
                "product",
                "publish",
                null,
                $product_id,
                $limit,
                $offset
            )
        );

        $results = array();
        if ($result) {
            foreach ($result as $item) {
                $results[] = $this->format_collect($item);
            }
        }

        return $results;
    }

    /**
     * Format collection
     *
     * @param $collect
     * @return array
     */
    public function format_collect( $collect )
    {
        return array(
            'id' => $collect->collection_id * 100000 + $collect->product_id,
            'collection_id' => (int)$collect->collection_id,
            'product_id' => (int)$collect->product_id,
            'position' => (int)$collect->position,
        );
    }
}