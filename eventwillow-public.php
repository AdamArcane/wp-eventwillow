<?php
/**
 * Plugin Name: EventWillow Public Views
 * Description: API-backed EventWillow directories, calendars and Elementor templates.
 * Plugin URI: https://github.com/AdamArcane/wp-eventwillow
 * Version: 0.1.3
 * Requires PHP: 8.0
 */
if (!defined('ABSPATH')) exit;
define('EWP_VERSION', '0.1.3');
define('EWP_PATH', __DIR__ . '/');
define('EWP_URL', plugin_dir_url(__FILE__));
require_once EWP_PATH . 'includes/api.php';
require_once EWP_PATH . 'includes/views.php';
require_once EWP_PATH . 'includes/plugin.php';
EWP_Plugin::boot();
register_activation_hook(__FILE__, ['EWP_Plugin', 'activate']);
register_deactivation_hook(__FILE__, 'flush_rewrite_rules');
