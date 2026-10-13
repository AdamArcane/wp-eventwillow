<?php
defined('ABSPATH') || exit;
function eventwillow_sync_sanitize_json($value) {
    $decoded=json_decode($value);
    return json_last_error()===JSON_ERROR_NONE ? wp_json_encode($decoded, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : '';
}
function eventwillow_sync_meta_keys($type) {
    $common=['ew_source_id','ew_source_key','ew_canonical_url','ew_timezone','ew_synced_at','ew_image_source','ew_identifier','ew_city','ew_state','ew_venue','ew_address','ew_postal','ew_country','ew_venue_source_id'];
    $event=['ew_start_utc','ew_end_utc','ew_occurrence_date','ew_date_label','ew_price_label','ew_registration_url','ew_ticket_url','ew_follow_url','ew_recurrence','ew_source_created_at'];
    $channel=['ew_website','ew_channel_type','ew_accent_color','ew_email','ew_phone','ew_header_image_url','ew_facebook_url','ew_instagram_url','ew_youtube_url','ew_tiktok_url','ew_linkedin_url','ew_x_url','ew_bluesky_url'];
    return array_merge($common,$type==='ew_event'?$event:$channel);
}
/** URL bases for the event and channel directories (also the archive slugs). */
function eventwillow_sync_default_slugs() {
    return ['event'=>'events','channel'=>'channels'];
}
function eventwillow_sync_clean_slug($raw) {
    return implode('/',array_filter(array_map('sanitize_title',explode('/',(string)$raw))));
}
function eventwillow_sync_slugs() {
    $slugs=eventwillow_sync_default_slugs();
    $saved=get_option('eventwillow_sync_slugs',[]);
    if (is_array($saved)) foreach ($slugs as $kind=>$default) {
        $clean=eventwillow_sync_clean_slug($saved[$kind]??'');
        if ($clean!=='') $slugs[$kind]=$clean;
    }
    // Two post types cannot share one base; fall back to the default for the channel.
    if ($slugs['event']===$slugs['channel']) $slugs['channel']=$slugs['event']==='channels'?'channel-directory':'channels';
    return $slugs;
}
function eventwillow_sync_register_schema() {
    $slugs=eventwillow_sync_slugs();
    $types=[
        'ew_event'=>['Events','Event','ew-events',$slugs['event'],'Event'],
        'ew_channel'=>['Channels','Channel','ew-channels',$slugs['channel'],'Channel'],
    ];
    foreach ($types as $type=>$def) {
        register_post_type($type, [
            'labels'=>['name'=>$def[0],'singular_name'=>$def[1],'add_new_item'=>'Add new '.strtolower($def[1]),'edit_item'=>'Edit '.strtolower($def[1]),'view_item'=>'View '.strtolower($def[1]),'search_items'=>'Search '.strtolower($def[0]),'all_items'=>'All '.strtolower($def[0])],
            'public'=>true,'publicly_queryable'=>true,'show_in_nav_menus'=>true,'show_in_rest'=>true,
            'rest_base'=>$def[2],'has_archive'=>$def[3],'rewrite'=>['slug'=>$def[3],'with_front'=>false],
            // 'elementor' lets Elementor edit individual records; Theme Builder templates work for any public type.
            'supports'=>['title','editor','excerpt','thumbnail','custom-fields','revisions','elementor'],
            'menu_icon'=>'dashicons-calendar-alt',
        ]);
        foreach (eventwillow_sync_meta_keys($type) as $key) {
            register_post_meta($type,$key,['single'=>true,'type'=>'string','show_in_rest'=>true,'sanitize_callback'=>(str_ends_with($key,'_url') || in_array($key,['ew_website','ew_image_source'],true)) ? 'esc_url_raw' : 'sanitize_text_field','auth_callback'=>fn($allowed,$key,$id)=>current_user_can('edit_post',$id)]);
        }
        foreach (array_merge(['ew_sub_schedules_json','ew_actions_json'],$type==='ew_event'?['ew_custom_fields_json','ew_price_json']:['ew_social_links_json']) as $key) {
            register_post_meta($type,$key,['single'=>true,'type'=>'string','show_in_rest'=>true,'sanitize_callback'=>'eventwillow_sync_sanitize_json','auth_callback'=>fn($allowed,$key,$id)=>current_user_can('edit_post',$id)]);
        }
        if($type==='ew_channel')register_post_meta($type,'ew_header_image_id',['single'=>true,'type'=>'integer','show_in_rest'=>true,'sanitize_callback'=>'absint','auth_callback'=>fn($allowed,$key,$id)=>current_user_can('edit_post',$id)]);
        if($type==='ew_event')register_post_meta($type,'ew_channel_post_ids',['single'=>true,'type'=>'array','show_in_rest'=>['schema'=>['type'=>'array','items'=>['type'=>'integer']]],'auth_callback'=>fn($allowed,$key,$id)=>current_user_can('edit_post',$id)]);
    }
    foreach (['location'=>'Locations','format'=>'Event formats','cost'=>'Event costs','registration'=>'Registration types'] as $key=>$label) {
        register_taxonomy('ew_'.$key,$key==='location'?['ew_event','ew_channel']:['ew_event'],['label'=>$label,'public'=>true,'show_in_rest'=>true,'hierarchical'=>false]);
    }
    register_taxonomy('ew_category','ew_event',['label'=>'Event categories','public'=>true,'show_in_rest'=>true,'hierarchical'=>true]);
}
add_action('init','eventwillow_sync_register_schema');

// Category images: stored as an attachment ID in term meta.
add_action('init',function(){
    register_term_meta('ew_category','ew_image_id',['single'=>true,'type'=>'integer','show_in_rest'=>true,'sanitize_callback'=>'absint','auth_callback'=>fn()=>current_user_can('manage_categories')]);
});
function eventwillow_sync_category_image_field($term=null){
    $id=$term?(int)get_term_meta($term->term_id,'ew_image_id',true):0;
    $preview=$id?wp_get_attachment_image($id,[80,80]):'';
    $field='<input type="hidden" name="ew_image_id" id="ew-image-id" value="'.esc_attr($id).'">'
        .'<div id="ew-image-preview" style="margin-bottom:8px">'.$preview.'</div>'
        .'<button type="button" class="button" id="ew-image-select">Select image</button> '
        .'<button type="button" class="button" id="ew-image-remove"'.($id?'':' style="display:none"').'>Remove image</button>';
    wp_nonce_field('ew_category_image','ew_category_image_nonce');
    if($term){echo '<tr class="form-field"><th scope="row"><label>Image</label></th><td>'.$field.'</td></tr>';}
    else{echo '<div class="form-field"><label>Image</label>'.$field.'</div>';}
}
add_action('ew_category_add_form_fields','eventwillow_sync_category_image_field');
add_action('ew_category_edit_form_fields','eventwillow_sync_category_image_field');
function eventwillow_sync_save_category_image($term_id){
    if(!isset($_POST['ew_category_image_nonce'])||!wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['ew_category_image_nonce'])),'ew_category_image')||!current_user_can('edit_term',$term_id))return;
    $id=absint($_POST['ew_image_id']??0);
    if($id&&!wp_attachment_is_image($id))$id=0;
    if($id)update_term_meta($term_id,'ew_image_id',$id);else delete_term_meta($term_id,'ew_image_id');
}
add_action('created_ew_category','eventwillow_sync_save_category_image');
add_action('edited_ew_category','eventwillow_sync_save_category_image');
add_action('admin_enqueue_scripts',function(){
    $screen=get_current_screen();
    if(!$screen||$screen->taxonomy!=='ew_category'||!in_array($screen->base,['edit-tags','term'],true))return;
    wp_enqueue_media();
    wp_add_inline_script('jquery-core',<<<'JS'
jQuery(function($){
    var frame;
    $(document).on('click','#ew-image-select',function(e){
        e.preventDefault();
        frame=frame||wp.media({title:'Category image',multiple:false,library:{type:'image'}});
        frame.off('select').on('select',function(){
            var a=frame.state().get('selection').first().toJSON();
            var url=(a.sizes&&a.sizes.thumbnail)?a.sizes.thumbnail.url:a.url;
            $('#ew-image-id').val(a.id);
            $('#ew-image-preview').html($('<img>',{src:url,width:80,height:80}));
            $('#ew-image-remove').show();
        });
        frame.open();
    });
    $(document).on('click','#ew-image-remove',function(e){
        e.preventDefault();
        $('#ew-image-id').val('');$('#ew-image-preview').empty();$(this).hide();
    });
    $(document).ajaxComplete(function(e,x,s){
        if(s.data&&String(s.data).indexOf('action=add-tag')>-1){$('#ew-image-id').val('');$('#ew-image-preview').empty();$('#ew-image-remove').hide();}
    });
});
JS);
});
add_filter('manage_edit-ew_category_columns',function($cols){$cb=array_slice($cols,0,1,true);return $cb+['image'=>'Image']+array_slice($cols,1,null,true);});
add_filter('manage_ew_category_custom_column',function($out,$col,$term_id){
    return $col==='image'?wp_get_attachment_image((int)get_term_meta($term_id,'ew_image_id',true),[40,40]):$out;
},10,3);
// Rewrite rules must be rebuilt after the slugs change; the flag survives the save redirect.
add_action('init',function(){
    if (get_option('eventwillow_sync_flush_rewrite')) {
        delete_option('eventwillow_sync_flush_rewrite');
        flush_rewrite_rules(false);
    }
},99);
// Directory pages: upcoming events by start time and channels alphabetically, so an
// Elementor Theme Builder archive template needs no custom query.
add_action('pre_get_posts',function($query){
    if (is_admin() || !$query->is_main_query()) return;
    if ($query->is_post_type_archive('ew_event')) {
        eventwillow_sync_upcoming_query($query);
    } elseif ($query->is_post_type_archive('ew_channel')) {
        $query->set('orderby','title');
        $query->set('order','ASC');
    }
});
// Links created under the former slugs keep working.
add_action('template_redirect',function(){
    global $wp;
    if (!is_404() || !preg_match('#^cpt-lab-(events|channels)(/.*)?$#D',(string)($wp->request??''),$m)) return;
    $slugs=eventwillow_sync_slugs();
    wp_safe_redirect(home_url('/'.$slugs[$m[1]==='events'?'event':'channel'].($m[2]??'/')),301);
    exit;
});
// Elementor's normal Posts widget needs a query hook to sort by the event's start,
// since its native Date sort means WordPress publication date.
function eventwillow_sync_upcoming_query($query){
    $query->set('post_type','ew_event');
    $query->set('meta_key','ew_start_utc');
    $query->set('orderby','meta_value');
    $query->set('order','ASC');
    $query->set('meta_query',[['key'=>'ew_end_utc','value'=>gmdate('c'),'compare'=>'>=','type'=>'CHAR']]);
}
add_action('elementor/query/ew_upcoming','eventwillow_sync_upcoming_query');

// Public field values arrive in the authenticated REST payload from the source API.
// Register learned keys for REST readback; native WordPress/Elementor reads post metadata.
function eventwillow_sync_custom_key($source_key, $label) {
    $slug=substr(sanitize_title($label),0,60);
    return 'ew_custom_'.($slug ?: 'field').'_' . substr($source_key,6);
}
function eventwillow_sync_register_custom_fields() {
    foreach (get_option('ew_public_fields',[]) as $key=>$definition) {
        register_post_meta('ew_event',$key,['single'=>true,'type'=>'string','show_in_rest'=>true,
            'description'=>$definition['label'],'sanitize_callback'=>'wp_strip_all_tags',
            'auth_callback'=>fn($allowed,$key,$id)=>current_user_can('edit_post',$id)]);
    }
    register_post_meta('ew_event','ew_custom_fields_map_json',['single'=>true,'type'=>'string','show_in_rest'=>true,
        'sanitize_callback'=>'eventwillow_sync_sanitize_json','auth_callback'=>fn($allowed,$key,$id)=>current_user_can('edit_post',$id)]);
    register_post_meta('ew_event','ew_custom_fields_version',['single'=>true,'type'=>'integer','show_in_rest'=>true,
        'auth_callback'=>fn($allowed,$key,$id)=>current_user_can('edit_post',$id)]);
}
add_action('init','eventwillow_sync_register_custom_fields',11);
/** Rebuild public custom fields after either REST or internal imports.
 * Call only when the import supplies ew_custom_fields_json; omission preserves fields.
 */
function eventwillow_sync_project_custom_fields($post_id) {
    $fields=json_decode(get_post_meta($post_id,'ew_custom_fields_json',true),true);
    if (!is_array($fields)) return;
    $registry=get_option('ew_public_fields',[]);
    $before=$registry;$map=[];
    foreach ($fields as $source_key=>$field) {
        if (!preg_match('/^field_[a-f0-9]{12}$/D',(string)$source_key) || !is_array($field) || !is_string($field['label']??null) || !is_array($field['values']??null)) continue;
        $label=wp_strip_all_tags($field['label']);
        $key=eventwillow_sync_custom_key($source_key,$label);
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
    if($registry!==$before)update_option('ew_public_fields',$registry,false);
}
add_action('rest_after_insert_ew_event',function($post,$request){
    $meta=$request->get_param('meta');
    if (!is_array($meta) || !array_key_exists('ew_custom_fields_json',$meta)) return;
    eventwillow_sync_project_custom_fields($post->ID);
},10,2);
// Populate the native selector even while editing a template rather than an event post.
add_action('elementor/element/after_section_end',function($element,$section){
    if($element->get_name()!=='post-custom-field' || $section!=='settings')return;
    $control=$element->get_controls('key');
    $options=$control['options']??[''=>'Select...'];
    foreach(get_option('ew_public_fields',[]) as $key=>$field)$options[$key]=eventwillow_sync_brand().': '.$field['label'];
    $element->update_control('key',['options'=>$options]);
},10,2);
