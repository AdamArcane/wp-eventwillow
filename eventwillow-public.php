<?php
/**
 * Plugin Name: EventWillow Public Views
 * Description: API-backed EventWillow directories, calendars and Elementor templates.
 * Plugin URI: https://github.com/AdamArcane/wp-eventwillow
 * Update URI: https://files.arcanetechct.com/eventwillow-public/
 * Version: 0.1.5
 * Requires PHP: 8.0
 */
if (!defined('ABSPATH')) exit;
define('EWP_VERSION', '0.1.5');
define('EWP_PATH', __DIR__ . '/');
define('EWP_URL', plugin_dir_url(__FILE__));
if (!defined('EW_UPDATES_ENABLED')) define('EW_UPDATES_ENABLED', true);
if (!defined('EW_UPDATE_MANIFEST_URL')) define('EW_UPDATE_MANIFEST_URL', 'https://files.arcanetechct.com/eventwillow-public/info.json');
if (EW_UPDATES_ENABLED && EW_UPDATE_MANIFEST_URL) {
    require_once EWP_PATH . 'includes/class-simple-updater.php';
    new EWP_Updater(EW_UPDATE_MANIFEST_URL, __FILE__, EWP_VERSION);
}
require_once EWP_PATH . 'includes/api.php';
require_once EWP_PATH . 'includes/views.php';
require_once EWP_PATH . 'includes/plugin.php';
EWP_Plugin::boot();
register_activation_hook(__FILE__, ['EWP_Plugin', 'activate']);
register_deactivation_hook(__FILE__, 'flush_rewrite_rules');
