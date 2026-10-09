<?php
if (!defined('WP_CLI') || !WP_CLI || !str_ends_with(parse_url(home_url(),PHP_URL_HOST),'.ddev.site')) exit;
require EWP_PATH.'tests/local-templates.php';
$page=ewp_seed_document('card_controls_page','EventWillow card customization preview','page',[ewp_seed_container([
    ewp_seed_widget('heading',['title'=>'Configurable directory cards','header_size'=>'h2']),
    ewp_seed_widget('ewp-feed',['type'=>'upcoming','limit'=>3,'card_template'=>-1,'show_images'=>'yes','show_title'=>'yes','show_category'=>'yes','show_date'=>'yes','show_summary'=>'','show_location'=>'yes','show_channels'=>'','show_price'=>'','show_button'=>'yes','button_text'=>'Explore event','image_position'=>'left','image_position_mobile'=>'top','card_background'=>'#faf7ec','content_background'=>'#faf7ec','button_background'=>'#1d4134','button_color'=>'#ffffff','section_gap'=>['unit'=>'px','size'=>10],'text_color'=>'#1d4134','image_height'=>['unit'=>'px','size'=>200],'image_fit'=>'contain']),
    ewp_seed_widget('heading',['title'=>'Reusable Elementor card template','header_size'=>'h2']),
    ewp_seed_widget('ewp-feed',['type'=>'upcoming','limit'=>3,'card_template'=>$ids['card'],'show_images'=>'yes'])
],['background_background'=>'classic','background_color'=>'#faf7ec'])]);
wp_update_post(['ID'=>$page,'post_name'=>'eventwillow-card-preview']);
\Elementor\Plugin::$instance->files_manager->clear_cache();
WP_CLI::success('Card customization preview: '.get_permalink($page));
