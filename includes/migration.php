<?php
defined('ABSPATH') || exit;
const EVENTWILLOW_SYNC_SCHEMA_VERSION = 2;
/** Replace the former "lab" identifiers inside Elementor data, preserving serialized structure. */
function eventwillow_sync_rename_legacy($value) {
    if (is_array($value)) { foreach ($value as $k=>$v) $value[$k]=eventwillow_sync_rename_legacy($v); return $value; }
    return is_string($value) ? strtr($value,['ew_lab_'=>'ew_','ew-lab-'=>'ew-']) : $value;
}
/** One-time rename of stored post types, taxonomies, options and Elementor references. */
function eventwillow_sync_migrate() {
    if ((int)get_option('eventwillow_sync_schema_version',0) >= EVENTWILLOW_SYNC_SCHEMA_VERSION) return;
    global $wpdb;
    foreach (['ew_lab_event'=>'ew_event','ew_lab_channel'=>'ew_channel'] as $old=>$new) {
        $wpdb->update($wpdb->posts,['post_type'=>$new],['post_type'=>$old]);
    }
    foreach (['location','category','format','cost','registration'] as $tax) {
        $wpdb->update($wpdb->term_taxonomy,['taxonomy'=>'ew_'.$tax],['taxonomy'=>'ew_lab_'.$tax]);
    }
    $fields=get_option('ew_lab_public_fields',null);
    if ($fields!==null) {
        if (get_option('ew_public_fields',null)===null) update_option('ew_public_fields',$fields,false);
        delete_option('ew_lab_public_fields');
    }
    $rows=$wpdb->get_results("SELECT meta_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key IN ('_elementor_data','_elementor_conditions','_elementor_page_settings','_elementor_template_type') AND (meta_value LIKE '%ew\\_lab\\_%' OR meta_value LIKE '%ew-lab-%')");
    foreach ($rows as $row) {
        $value=maybe_unserialize($row->meta_value);
        $updated=eventwillow_sync_rename_legacy($value);
        if ($updated!==$value) $wpdb->update($wpdb->postmeta,['meta_value'=>maybe_serialize($updated)],['meta_id'=>$row->meta_id]);
    }
    update_option('eventwillow_sync_schema_version',EVENTWILLOW_SYNC_SCHEMA_VERSION,true);
    update_option('eventwillow_sync_flush_rewrite',1,false);
    wp_cache_flush();
    // Theme Builder keeps its own copy of template conditions.
    if (class_exists('\ElementorPro\Modules\ThemeBuilder\Module')) {
        try { \ElementorPro\Modules\ThemeBuilder\Module::instance()->get_conditions_manager()->get_cache()->regenerate()->save(); }
        catch (\Throwable $e) { delete_option('elementor_pro_theme_builder_conditions'); }
    } else delete_option('elementor_pro_theme_builder_conditions');
}
// Must run before the post types are registered under their new names.
add_action('init','eventwillow_sync_migrate',1);
