<?php
/**
 * Created by PhpStorm.
 * User: tungquach
 * Date: 31/03/2017
 * Time: 16:06
 */

namespace BKPersonalizedRecommendationSDK\Manager;


use BKPersonalizedRecommendationSDK\Libraries\Helper;

class ImageManager
{
    /**
     * Get product images by product
     *
     * @param $product
     * @return array
     */
    public function get_product_images_by_product( $product )
    {
        $images = $attachment_ids = array();

        // Check wc version
        if ( Helper::is_wc3() ) {
            $product_id = $product->get_id();
            $gallery_image_ids = $product->get_gallery_image_ids();
        } else {
            $product_id = $product->id;
            $gallery_image_ids = $product->get_gallery_attachment_ids();
        }

        // Add featured image
        if ( has_post_thumbnail( $product_id ) ) {
            $attachment_ids[] = get_post_thumbnail_id( $product_id );
        }

        // Add gallery images
        $attachment_ids = array_merge( $attachment_ids, $gallery_image_ids );

        // Build image data
        foreach ( $attachment_ids as $position => $attachment_id ) {
            $attachment_post = get_post( $attachment_id );
            if ( is_null( $attachment_post ) ) {
                continue;
            }

            $attachment = wp_get_attachment_image_src( $attachment_id, 'medium' );
            if ( ! is_array( $attachment ) ) {
                continue;
            }

            $images[] = array(
                'id' => (int)$attachment_id,
                'src' => current( $attachment ),
            );
        }

        // Set a placeholder image if the product has no images set
        if ( empty( $images ) ) {
            $images[] = array(
                'id' => '',
                'src' => wc_placeholder_img_src(),
            );
        }

        return $images;
    }
}