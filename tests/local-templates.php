<?php
if (!defined('WP_CLI') || !WP_CLI || !str_ends_with(parse_url(home_url(),PHP_URL_HOST),'.ddev.site')) exit;
global $ids;
$ids=get_option('ewp_local_templates',[]);
function ewp_seed_widget($name,$settings) { return ['id'=>substr(md5(wp_json_encode([$name,$settings])),0,7),'elType'=>'widget','widgetType'=>$name,'settings'=>$settings,'elements'=>[]]; }
function ewp_seed_container($children,$settings=[]) { return ['id'=>substr(md5(wp_json_encode([$children,$settings])),0,7),'elType'=>'container','settings'=>$settings+['content_width'=>'full','flex_direction'=>'column','gap'=>['unit'=>'px','size'=>16],'padding'=>['unit'=>'px','top'=>24,'right'=>24,'bottom'=>24,'left'=>24,'isLinked'=>true]],'elements'=>$children,'isInner'=>false]; }
function ewp_seed_document($key,$title,$type,$data) {
    global $ids;
    if (!empty($ids[$key]) && get_post($ids[$key])) return $ids[$key];
    $existing=get_posts(['post_type'=>$type==='page'?'page':'elementor_library','title'=>$title,'posts_per_page'=>1,'post_status'=>'publish']);
    $id=$existing ? $existing[0]->ID : wp_insert_post(['post_type'=>$type==='page'?'page':'elementor_library','post_title'=>$title,'post_status'=>'publish','post_name'=>$type==='page'?'eventwillow-preview':sanitize_title($title)]);
    update_post_meta($id,'_ewp_local_preview',1);
    update_post_meta($id,'_elementor_edit_mode','builder');update_post_meta($id,'_elementor_version',ELEMENTOR_VERSION);
    update_post_meta($id,'_elementor_data',wp_slash(wp_json_encode($data)));
    update_post_meta($id,'_elementor_template_type',$type);update_post_meta($id,'_wp_page_template','default');
    if ($type!=='page') wp_set_object_terms($id,$type,'elementor_library_type');
    $ids[$key]=$id; update_option('ewp_local_templates',$ids,false); return $id;
}
function ewp_seed_field($field,$tag='p',$kind='event',$size=16) { return ewp_seed_widget('ewp-field',['field'=>$field,'record_kind'=>$kind,'html_tag'=>$tag,'text_color'=>'#1d4134','typography_typography'=>'custom','typography_font_family'=>$tag==='h1'||$tag==='h3'?'Georgia':'Arial','typography_font_size'=>['unit'=>'px','size'=>$size]]); }
$tags=\Elementor\Plugin::$instance->dynamic_tags;
$text=$tags->tag_data_to_tag_text('ewtitle','ewp-text',['field'=>'name','record_kind'=>'event']);
$link=$tags->tag_data_to_tag_text('ewlink','ewp-text',['field'=>'local_url','record_kind'=>'event']);
$image=$tags->tag_data_to_tag_text('ewimage','ewp-image',['field'=>'image','record_kind'=>'event']);
$card=ewp_seed_document('card','EventWillow · Event card','section',[ewp_seed_container([
    ewp_seed_widget('image',['__dynamic__'=>['image'=>$image],'image_size'=>'full']),
    ewp_seed_widget('heading',['__dynamic__'=>['title'=>$text],'header_size'=>'h3','title_color'=>'#1d4134','typography_typography'=>'custom','typography_font_family'=>'Georgia','typography_font_size'=>['unit'=>'px','size'=>24]]),
    ewp_seed_field('date','p','event',13),ewp_seed_field('summary'),ewp_seed_field('location.city','p','event',13),
    ewp_seed_widget('button',['text'=>'View event →','__dynamic__'=>['link'=>$link],'button_background_color'=>'#1d4134','button_text_color'=>'#fff'])
],['background_background'=>'classic','background_color'=>'#fffcf5'])]);
$event=ewp_seed_document('event','EventWillow · Event details','section',[ewp_seed_container([
    ewp_seed_widget('image',['__dynamic__'=>['image'=>$image],'image_size'=>'full']),
    ewp_seed_field('name','h1','event',42),ewp_seed_field('date'),ewp_seed_field('location.venue'),
    ewp_seed_field('location.address'),ewp_seed_field('description'),ewp_seed_field('price_label'),
    ewp_seed_widget('ewp-actions',['record_kind'=>'event']),ewp_seed_widget('ewp-related-channels',[])
],['background_background'=>'classic','background_color'=>'#faf7ec'])]);
$channel=ewp_seed_document('channel','EventWillow · Channel details','section',[ewp_seed_container([
    ewp_seed_field('name','h1','channel',42),ewp_seed_field('description','p','channel'),
    ewp_seed_widget('ewp-actions',['record_kind'=>'channel']),
    ewp_seed_widget('ewp-channel-events',['title'=>'Upcoming gatherings','current_channel'=>'yes','filters'=>'yes','card_template'=>$card,'text_color'=>'#1d4134','surface'=>'#fffcf5','accent'=>'#1d4134'])
],['background_background'=>'classic','background_color'=>'#faf7ec'])]);
$eventData=get_post_meta($event,'_elementor_data',true);
if (!str_contains($eventData,'ewp-related-channels')) {
    $elements=json_decode($eventData,true);
    $elements[0]['elements'][]=ewp_seed_widget('ewp-related-channels',[]);
    update_post_meta($event,'_elementor_data',wp_slash(wp_json_encode($elements)));
}
$header=ewp_seed_document('header','EventWillow · Local preview header','header',[ewp_seed_container([
    ewp_seed_widget('heading',['title'=>'EventWillow','header_size'=>'h2','title_color'=>'#faf7ec','typography_typography'=>'custom','typography_font_family'=>'Georgia']),
    ewp_seed_widget('button',['text'=>'Browse events','link'=>['url'=>home_url('/events/')],'button_background_color'=>'#d5dfa5','button_text_color'=>'#1d4134']),
    ewp_seed_widget('button',['text'=>'Explore channels','link'=>['url'=>home_url('/channels/')],'button_background_color'=>'#d5dfa5','button_text_color'=>'#1d4134'])
],['flex_direction'=>'row','flex_wrap'=>'wrap','justify_content'=>'space-between','align_items'=>'center','background_background'=>'classic','background_color'=>'#1d4134'])]);
$footer=ewp_seed_document('footer','EventWillow · Local preview footer','footer',[ewp_seed_container([
    ewp_seed_widget('heading',['title'=>'EventWillow','header_size'=>'h3','title_color'=>'#1d4134','typography_typography'=>'custom','typography_font_family'=>'Georgia']),
    ewp_seed_widget('text-editor',['editor'=>'<p>A place for your events to grow. Local integration preview.</p>']),
],['background_background'=>'classic','background_color'=>'#faf7ec'])]);
$conditions=\ElementorPro\Modules\ThemeBuilder\Module::instance()->get_conditions_manager();
foreach ([$header,$footer] as $id) $conditions->save_conditions($id,[['type'=>'include','name'=>'general','sub_name'=>'ewp-public','sub_id'=>'']]);
$page=ewp_seed_document('page','EventWillow integration preview','page',[ewp_seed_container([
    ewp_seed_widget('heading',['title'=>'Your gatherings. All in one place.','header_size'=>'h1','title_color'=>'#1d4134','typography_typography'=>'custom','typography_font_family'=>'Georgia']),
    ewp_seed_widget('ewp-feed',['title'=>'Upcoming events','type'=>'upcoming','limit'=>3,'filters'=>'','card_template'=>$card,'accent'=>'#1d4134','surface'=>'#fffcf5']),
    ewp_seed_widget('ewp-feed',['title'=>'Newly created events','type'=>'new','limit'=>3,'filters'=>'','card_template'=>$card]),
    ewp_seed_widget('ewp-channels',['title'=>'Channels','filters'=>'yes']),
    ewp_seed_widget('ewp-calendar',['title'=>'Calendar','view'=>'calendar','filters'=>'yes']),
],['background_background'=>'classic','background_color'=>'#faf7ec'])]);
$pageData=get_post_meta($page,'_elementor_data',true);
if (!str_contains($pageData,'"widgetType":"ewp-search"')) {
    $elements=json_decode($pageData,true);
    array_splice($elements[0]['elements'],1,0,[ewp_seed_widget('ewp-search',['results_url'=>['url'=>home_url('/events/')],'filter_prefix'=>'ew_','label'=>'Find your next gathering','show_label'=>'yes','placeholder'=>'Search events, venues or cities','button_text'=>'Search'])]);
    update_post_meta($page,'_elementor_data',wp_slash(wp_json_encode($elements)));
}
update_option('ewp_local_templates',$ids,false);
$s=EWP_API::settings();foreach (['event_template'=>$event,'channel_template'=>$channel,'card_template'=>$card] as $key=>$value) if (!$s[$key]) $s[$key]=$value;
update_option('ewp_settings',$s); EWP_API::clear();
\Elementor\Plugin::$instance->files_manager->clear_cache();
WP_CLI::success('Created editable local templates and preview page.');
WP_CLI::log(wp_json_encode($ids));
