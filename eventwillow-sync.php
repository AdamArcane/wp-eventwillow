<?php
/**
 * Plugin Name: EventWillow Sync
 * Description: Native WordPress events, channels, taxonomies and Elementor fields for one-way source synchronization.
 * Version: 0.2.1
 * Requires at least: 6.5
 * Requires PHP: 8.0
 * Author: Arcane Tech Solutions
 * License: GPL-3.0-or-later
 * Text Domain: eventwillow-sync
 * Update URI: https://files.arcanetechct.com/eventwillow-sync/
 */
defined('ABSPATH') || exit;
// Also protects the compatibility loader while replacing the earlier plugin.
if (defined('EVENTWILLOW_SYNC_VERSION')) return;
define('EVENTWILLOW_SYNC_VERSION','0.2.1');
define('EVENTWILLOW_SYNC_FILE',__FILE__);
function eventwillow_sync_brand() {
    return (string) apply_filters('eventwillow_sync_brand','EventWillow');
}
require_once __DIR__.'/includes/migration.php';
require_once __DIR__.'/includes/schema.php';
require_once __DIR__.'/includes/class-sync-source.php';
require_once __DIR__.'/includes/class-sync-importer.php';
require_once __DIR__.'/includes/class-sync.php';
require_once __DIR__.'/includes/class-sync-admin.php';
EventWillow_Sync::boot();
EventWillow_Sync_Admin::boot();
add_action('elementor/dynamic_tags/register',function($manager){
    require_once __DIR__.'/dynamic-fields.php';
    eventwillow_sync_register_named_tags($manager);
});
require_once __DIR__.'/atomic-backgrounds.php';
require_once __DIR__.'/includes/class-updater.php';
$eventwillow_manifest = defined('EVENTWILLOW_SYNC_UPDATE_MANIFEST_URL')
    ? EVENTWILLOW_SYNC_UPDATE_MANIFEST_URL
    : 'https://files.arcanetechct.com/eventwillow-sync/info.json';
$eventwillow_manifest = apply_filters('eventwillow_sync_update_manifest_url',$eventwillow_manifest);
if ($eventwillow_manifest) new EventWillow_Sync_Updater($eventwillow_manifest,__FILE__,EVENTWILLOW_SYNC_VERSION);
unset($eventwillow_manifest);
register_activation_hook(__FILE__,function(){
    eventwillow_sync_migrate();
    eventwillow_sync_register_schema();
    eventwillow_sync_register_custom_fields();
    flush_rewrite_rules();
});
register_deactivation_hook(__FILE__,function(){ EventWillow_Sync::stop(); flush_rewrite_rules(); });
