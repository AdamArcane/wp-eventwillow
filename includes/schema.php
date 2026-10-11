<?php
defined('ABSPATH') || exit;
function ew_lab_sanitize_json($value) {
    $decoded=json_decode($value);
    return json_last_error()===JSON_ERROR_NONE ? wp_json_encode($decoded, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : '';
}
function ew_lab_meta_keys($type) {
    $common=['ew_source_id','ew_source_key','ew_canonical_url','ew_timezone','ew_synced_at','ew_image_source','ew_identifier','ew_city','ew_state','ew_venue','ew_address','ew_postal','ew_country','ew_venue_source_id'];
    $event=['ew_start_utc','ew_end_utc','ew_occurrence_date','ew_date_label','ew_price_label','ew_registration_url','ew_ticket_url','ew_follow_url','ew_recurrence','ew_source_created_at'];
    $channel=['ew_website','ew_channel_type','ew_accent_color','ew_email','ew_phone','ew_header_image_url','ew_facebook_url','ew_instagram_url','ew_youtube_url','ew_tiktok_url','ew_linkedin_url','ew_x_url','ew_bluesky_url'];
    return array_merge($common,$type==='ew_lab_event'?$event:$channel);
}
function ew_lab_register_schema() {
    foreach (['ew_lab_event'=>['Events','Event','cpt-lab-events'], 'ew_lab_channel'=>['Channels','Channel','cpt-lab-channels']] as $type=>$labels) {
        register_post_type($type, ['labels'=>['name'=>$labels[0],'singular_name'=>$labels[1]],'public'=>true,'show_in_rest'=>true,'rest_base'=>$labels[2],'has_archive'=>$labels[2],'rewrite'=>['slug'=>$labels[2]],'supports'=>['title','editor','excerpt','thumbnail','custom-fields','revisions'],'menu_icon'=>'dashicons-calendar-alt']);
        foreach (ew_lab_meta_keys($type) as $key) {
            register_post_meta($type,$key,['single'=>true,'type'=>'string','show_in_rest'=>true,'sanitize_callback'=>(str_ends_with($key,'_url') || in_array($key,['ew_website','ew_image_source'],true)) ? 'esc_url_raw' : 'sanitize_text_field','auth_callback'=>fn($allowed,$key,$id)=>current_user_can('edit_post',$id)]);
        }
        foreach (array_merge(['ew_sub_schedules_json','ew_actions_json'],$type==='ew_lab_event'?['ew_custom_fields_json','ew_price_json']:['ew_social_links_json']) as $key) {
            register_post_meta($type,$key,['single'=>true,'type'=>'string','show_in_rest'=>true,'sanitize_callback'=>'ew_lab_sanitize_json','auth_callback'=>fn($allowed,$key,$id)=>current_user_can('edit_post',$id)]);
        }
        if($type==='ew_lab_channel')register_post_meta($type,'ew_header_image_id',['single'=>true,'type'=>'integer','show_in_rest'=>true,'sanitize_callback'=>'absint','auth_callback'=>fn($allowed,$key,$id)=>current_user_can('edit_post',$id)]);
        if($type==='ew_lab_event')register_post_meta($type,'ew_channel_post_ids',['single'=>true,'type'=>'array','show_in_rest'=>['schema'=>['type'=>'array','items'=>['type'=>'integer']]],'auth_callback'=>fn($allowed,$key,$id)=>current_user_can('edit_post',$id)]);
    }
    foreach (['location'=>'Locations','format'=>'Event formats','cost'=>'Event costs','registration'=>'Registration types'] as $key=>$label) {
        register_taxonomy('ew_lab_'.$key,$key==='location'?['ew_lab_event','ew_lab_channel']:['ew_lab_event'],['label'=>$label,'public'=>true,'show_in_rest'=>true,'hierarchical'=>false]);
    }
    register_taxonomy('ew_lab_category','ew_lab_event',['label'=>'Event categories','public'=>true,'show_in_rest'=>true,'hierarchical'=>true]);
}
add_action('init','ew_lab_register_schema');
// Elementor's normal Posts widget needs a query hook to sort by the event's start,
// since its native Date sort means WordPress publication date.
function eventwillow_sync_upcoming_query($query){
    $query->set('post_type','ew_lab_event');
    $query->set('meta_key','ew_start_utc');
    $query->set('orderby','meta_value');
    $query->set('order','ASC');
    $query->set('meta_query',[['key'=>'ew_end_utc','value'=>gmdate('c'),'compare'=>'>=','type'=>'CHAR']]);
}
add_action('elementor/query/ew_upcoming','eventwillow_sync_upcoming_query');
add_action('elementor/query/ew_lab_upcoming','eventwillow_sync_upcoming_query');

// Public field values arrive in the authenticated REST payload from the source API.
// Register learned keys for REST readback; native WordPress/Elementor reads post metadata.
function ew_lab_custom_key($source_key, $label) {
    $slug=substr(sanitize_title($label),0,60);
    return 'ew_custom_'.($slug ?: 'field').'_' . substr($source_key,6);
}
function ew_lab_register_custom_fields() {
    foreach (get_option('ew_lab_public_fields',[]) as $key=>$definition) {
        register_post_meta('ew_lab_event',$key,['single'=>true,'type'=>'string','show_in_rest'=>true,
            'description'=>$definition['label'],'sanitize_callback'=>'wp_strip_all_tags',
            'auth_callback'=>fn($allowed,$key,$id)=>current_user_can('edit_post',$id)]);
    }
    register_post_meta('ew_lab_event','ew_custom_fields_map_json',['single'=>true,'type'=>'string','show_in_rest'=>true,
        'sanitize_callback'=>'ew_lab_sanitize_json','auth_callback'=>fn($allowed,$key,$id)=>current_user_can('edit_post',$id)]);
    register_post_meta('ew_lab_event','ew_custom_fields_version',['single'=>true,'type'=>'integer','show_in_rest'=>true,
        'auth_callback'=>fn($allowed,$key,$id)=>current_user_can('edit_post',$id)]);
}
add_action('init','ew_lab_register_custom_fields',11);
/** Rebuild public custom fields after either REST or internal imports.
 * Call only when the import supplies ew_custom_fields_json; omission preserves fields.
 */
function eventwillow_sync_project_custom_fields($post_id) {
    $fields=json_decode(get_post_meta($post_id,'ew_custom_fields_json',true),true);
    if (!is_array($fields)) return;
    $registry=get_option('ew_lab_public_fields',[]);
    $before=$registry;$map=[];
    foreach ($fields as $source_key=>$field) {
        if (!preg_match('/^field_[a-f0-9]{12}$/D',(string)$source_key) || !is_array($field) || !is_string($field['label']??null) || !is_array($field['values']??null)) continue;
        $label=wp_strip_all_tags($field['label']);
        $key=ew_lab_custom_key($source_key,$label);
        $values=array_map(fn($value)=>wp_strip_all_tags((string)$value),array_filter($field['values'],'is_scalar'));
        $map[$source_key]=['key'=>$key,'label'=>$label];
        $registry[$key]=['source_key'=>$source_key,'label'=>$label];
        update_post_meta($post_id,$key,wp_slash(implode(', ',$values)));
    }
    // Clear previously synced fields when removed, made private, or renamed upstream.
    foreach (array_keys(get_post_meta($post_id)) as $key) {
        if (preg_match('/^ew_custom_.*_[a-f0-9]{12}$/D',$key) && !in_array($key,array_column($map,'key'),true)) delete_post_meta($post_id,$key);
    }
    update_post_meta($post_id,'ew_custom_fields_map_json',wp_slash(wp_json_encode($map,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)));
    update_post_meta($post_id,'ew_custom_fields_version',1);
    if($registry!==$before)update_option('ew_lab_public_fields',$registry,false);
}
add_action('rest_after_insert_ew_lab_event',function($post,$request){
    $meta=$request->get_param('meta');
    if (!is_array($meta) || !array_key_exists('ew_custom_fields_json',$meta)) return;
    eventwillow_sync_project_custom_fields($post->ID);
},10,2);
// Populate the native selector even while editing a template rather than an event post.
add_action('elementor/element/after_section_end',function($element,$section){
    if($element->get_name()!=='post-custom-field' || $section!=='settings')return;
    $control=$element->get_controls('key');
    $options=$control['options']??[''=>'Select...'];
    foreach(get_option('ew_lab_public_fields',[]) as $key=>$field)$options[$key]=eventwillow_sync_brand().': '.$field['label'];
    $element->update_control('key',['options'=>$options]);
},10,2);
