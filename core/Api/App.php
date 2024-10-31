<?php
/**
 * App api
 *
 * @since      1.0.0
 * @author     Beeketing
 *
 */

namespace Beeketing\PersonalizedRecommendation\Api;


use Beeketing\PersonalizedRecommendation\Data\Constant;
use BKPersonalizedRecommendationSDK\Api\CommonApi;
use BKPersonalizedRecommendationSDK\Data\AppCodes;
use BKPersonalizedRecommendationSDK\Libraries\SettingHelper;

class App extends CommonApi
{
    private $api_key;

    /**
     * App constructor.
     *
     * @param $api_key
     */
    public function __construct( $api_key )
    {
        $this->api_key = $api_key;
        $setting_helper = new SettingHelper();
        $setting_helper->set_app_setting_key( \BKPersonalizedRecommendationSDK\Data\AppSettingKeys::PERSONALIZEDRECOMMENDATION_KEY );
        $setting_helper->set_plugin_version( PERSONALIZEDRECOMMENDATION_VERSION );

        parent::__construct(
            $setting_helper,
            PERSONALIZEDRECOMMENDATION_PATH,
            PERSONALIZEDRECOMMENDATION_API,
            $api_key,
            AppCodes::PERSONALIZEDRECOMMENDATION,
            Constant::PLUGIN_ADMIN_URL
        );
    }

    /**
     * Get routers
     *
     * @return array
     */
    public function get_routers()
    {
        $result = $this->get( 'precommend/routers' );

        if ( $result && !isset( $result['errors'] ) ) {
            foreach ( $result as &$item ) {
                if ( strpos( $item, 'http' ) === false ) {
                    $end_point = PERSONALIZEDRECOMMENDATION_PATH;
                    if ( PERSONALIZEDRECOMMENDATION_ENVIRONMENT == 'local' ) {
                        $end_point = str_replace( '/app_dev.php', '', $end_point );
                    }
                    $item = $end_point . $item;
                }
            }

            return $result;
        }

        return array();
    }

    /**
     * Get api urls
     *
     * @return array
     */
    public function get_api_urls()
    {
        return array_merge( array(
            'app_data' => $this->get_url( 'precommend/data' ),
            'update_widget_status_url' => $this->get_url( 'precommend/widget_status/{type}/{status}' ),
        ), parent::get_api_urls() );
    }
}