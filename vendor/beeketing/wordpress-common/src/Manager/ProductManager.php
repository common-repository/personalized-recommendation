<?php
/**
 * Created by PhpStorm.
 * User: tungquach
 * Date: 31/03/2017
 * Time: 13:40
 */

namespace BKPersonalizedRecommendationSDK\Manager;


use BKPersonalizedRecommendationSDK\Data\Api;
use BKPersonalizedRecommendationSDK\Libraries\Helper;
use BKPersonalizedRecommendationSDK\Libraries\QueryHelper;

class ProductManager
{
    private $image_manager;
    private $variant_manager;
    private $wc_products = array();
    private $wc_product_images = array();
    private $wc_product_tags = array();

    public function __construct()
    {
        $this->image_manager = new ImageManager();
        $this->variant_manager = new VariantManager();
    }

    /**
     * Count products
     *
     * @return mixed
     */
    public function get_products_count()
    {
        global $wpdb;
        $count = $wpdb->get_var(
            $wpdb->prepare(
                "
                SELECT COUNT(ID)
                FROM $wpdb->posts
                WHERE post_type = %s
                  AND post_status = %s
                  AND post_password = %s
                  AND ID NOT IN ( " . QueryHelper::get_exclude_products_id() . " )
                ",
                "product",
                "publish",
                null
            )
        );

        return $count;
    }

    /**
     * Get product by id
     *
     * @param $id
     * @return array
     */
    public function get_product_by_id( $id )
    {
        global $wpdb;
        $result = $wpdb->get_row(
            $wpdb->prepare(
                "
                SELECT *
                FROM $wpdb->posts
                WHERE post_type = %s
                  AND post_status = %s
                  AND post_password = %s
                  AND ID = %d
                ",
                "product",
                "publish",
                null,
                $id
            )
        );

        $product = $result ? $this->format_product( $result ) : array();

        return $product;
    }

    /**
     * Get products
     *
     * @param $title
     * @param int $page
     * @param int $limit
     * @return array
     */
    public function get_products( $title = null, $page = Api::PAGE, $limit = Api::ITEM_PER_PAGE )
    {
        $offset = ( $page - 1 ) * $limit;

        global $wpdb;
        $result = $wpdb->get_results(
            $wpdb->prepare(
                "
                SELECT *
                FROM $wpdb->posts
                WHERE post_type = %s
                  AND post_status = %s
                  AND post_password = %s
                  AND post_title LIKE %s
                  AND ID NOT IN ( " . QueryHelper::get_exclude_products_id() . " )
                LIMIT %d
                OFFSET %d
                ",
                "product",
                "publish",
                null,
                "%" . $title . "%",
                $limit,
                $offset
            )
        );

        $products = $ids = array();
        // Traverse all result
        foreach ( $result as $item ) {
            $ids[] = $item->ID;
        }

        // Fill wc products
        $this->get_wc_products( $ids );

        // Fill product images
        $this->get_product_images( $ids );

        // Fill product tags
        $this->get_product_tags( $ids );

        // Traverse all result
        foreach ( $result as $item ) {
            $products[] = $this->format_product( $item );
        }

        return $products;
    }

    /**
     * Get product tags
     *
     * @param $posts_id
     */
    private function get_product_tags( $posts_id )
    {
        global $wpdb;

        // Get all images id
        $tag_result = $wpdb->get_results(
            "
            SELECT t.name, tr.object_id
            FROM $wpdb->terms t JOIN $wpdb->term_taxonomy tt ON t.term_id = tt.term_id
            JOIN $wpdb->term_relationships tr ON tr.term_taxonomy_id = tt.term_taxonomy_id
            WHERE tr.object_id IN (" . implode( ',', $posts_id ) . ") AND tt.taxonomy = 'product_tag'
            "
        );

        foreach ( $tag_result as $item ) {
            $this->wc_product_tags[$item->object_id][] = $item->name;
        }
    }

    /**
     * Get product images
     *
     * @param $posts_id
     */
    private function get_product_images( $posts_id )
    {
        global $wpdb;

        // Get all images id
        $image_result = $wpdb->get_results(
            "
            SELECT post_id, meta_key, meta_value
            FROM $wpdb->postmeta
            WHERE post_id IN (" . implode(',', $posts_id) . ") AND meta_key IN ('_thumbnail_id', '_product_image_gallery')
            "
        );

        $images_id = array();
        foreach ( $image_result as $item ) {
            if ( $item->meta_key == '_product_image_gallery' ) {
                $images_id = explode( ',', $item->meta_value );
                foreach ( $images_id as $item ) {
                    $images_id[] = $item;
                }
            } else {
                $images_id[] = $item->meta_value;
            }
        }

        $images_id = array_unique( $images_id );
        $result = $wpdb->get_results(
            "
            SELECT p.ID, p.post_parent, pm.meta_value
            FROM $wpdb->postmeta pm JOIN $wpdb->posts p ON pm.post_id = p.ID
            WHERE pm.meta_key = '_wp_attached_file'
              AND p.post_type = 'attachment'
              AND p.ID IN (" . implode( ',', $images_id ) . ")
            "
        );

        foreach ( $result as $item ) {
            // Get upload directory.
            $url = null;
            $file = $item->meta_value;
            if ( ( $uploads = wp_get_upload_dir() ) && false === $uploads['error'] ) {
                // Check that the upload base exists in the file location.
                if ( 0 === strpos( $file, $uploads['basedir'] ) ) {
                    // Replace file location with url location.
                    $url = str_replace($uploads['basedir'], $uploads['baseurl'], $file);
                } else {
                    // It's a newly-uploaded file, therefore $file is relative to the basedir.
                    $url = $uploads['baseurl'] . "/$file";
                }
            }

            // Ignore image
            if ( !$url ) {
                continue;
            }

            $this->wc_product_images[$item->post_parent][] = array(
                'id' => (int)$item->ID,
                'src' => $url,
            );
        }
    }

    /**
     * Get wc products
     *
     * @param $posts_id
     */
    private function get_wc_products( $posts_id )
    {
        if ( !Helper::is_wc3() ) {
            return;
        }

        $wc_products = wc_get_products( array(
            'include' => $posts_id,
            'limit' => -1,
        ) );

        foreach ( $wc_products as $wc_product ) {
            $product_id = $wc_product->get_id();
            $this->wc_products[$product_id] = $wc_product;
        }
    }

    /**
     * Format product
     *
     * @param $product
     * @return array
     */
    private function format_product( $product )
    {
        $post = $product;
        $product_id = $product->ID;
        $product = isset( $this->wc_products[$product_id] ) ? $this->wc_products[$product_id] :
            wc_get_product( $product_id );
        $tags = isset( $this->wc_product_tags[$product_id] ) ? $this->wc_product_tags[$product_id] :
            wp_get_post_terms( $product_id, 'product_tag', array( 'fields' => 'names' ) );
        $images = isset( $this->wc_product_images[$product_id] ) ? $this->wc_product_images[$product_id] :
            $this->image_manager->get_product_images_by_product( $product );

        // Get variants
        $variants = $this->variant_manager->get_variants_by_product( $product );

        return array(
            'id' => $product_id,
            'published_at' => $post->post_modified_gmt,
            'handle' => ltrim( str_replace( get_site_url(), '', $product->get_permalink() ), '/' ),
            'title' => $product->get_title(),
            'vendor' => '',
            'tags' => $tags ? implode( ', ', $tags ) : '',
            'description' => $post->post_excerpt,
            'images' => $images,
            'image' => isset($images[0]['src']) ? $images[0]['src'] : '',
            'variants' => $variants,
        );
    }

    /**
     * Update product
     *
     * @param $id
     * @param $content
     * @return array
     */
    public function update_product( $id, $content )
    {
        $tags = isset( $content['tags'] ) ? explode(',', $content['tags']) : array();

        $product_tags = array();
        foreach ( $tags as $tag ) {
            if ( $tag ) {
                $args = array(
                    'hide_empty' => false,
                    'fields' => 'ids',
                    'name' => $tag
                );

                $tag_ids = get_terms( 'product_tag', $args );

                if ( !$tag_ids ) {
                    $defaults = array(
                        'name' => $tag,
                        'slug' => sanitize_title( $tag ),
                    );

                    $insert = wp_insert_term( $defaults['name'], 'product_tag', $defaults );
                    $id = $insert['term_id'];
                    $product_tags[] = $id;

                } else {
                    $product_tags = array_merge( $product_tags, $tag_ids );

                }
            }
        }

        // Update tag
        if ( $product_tags ) {
            wp_set_object_terms($id, $product_tags, 'product_tag');
        }

        return $this->get_product_by_id( $id );
    }
}