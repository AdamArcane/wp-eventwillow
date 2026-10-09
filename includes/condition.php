<?php
if (!defined('ABSPATH')) exit;
class EWP_Public_Condition extends \ElementorPro\Modules\ThemeBuilder\Conditions\Condition_Base {
    public static function get_type(){return 'general';}
    public function get_name(){return 'ewp-public';}
    public function get_label(){return 'EventWillow public views';}
    public function check($args){
        if (get_query_var('ewp_kind')) return true;
        $post=get_queried_object();
        return $post instanceof WP_Post && (str_contains($post->post_content,'eventwillow_') || str_contains((string)get_post_meta($post->ID,'_elementor_data',true),'ewp-'));
    }
}
