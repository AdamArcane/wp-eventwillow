<?php
if (!defined('WP_CLI') || !WP_CLI || !str_ends_with(parse_url(home_url(),PHP_URL_HOST),'.ddev.site')) exit;
$s=EWP_API::settings(); $s['api_url']='https://eventwillow.ddev.site/api/eventwillow/v1';
update_option('ewp_settings',$s); EWP_API::clear();
$r=EWP_API::get('channels',['per_page'=>1]);
if (is_wp_error($r)) { WP_CLI::error($r->get_error_message()); }
WP_CLI::success('Restricted API connection working; '.$r['meta']['total'].' public channels available.');
$manager=\Elementor\Plugin::$instance->widgets_manager;
$names=array_filter(array_keys($manager->get_widget_types()),fn($n)=>str_starts_with($n,'ewp-'));
WP_CLI::log('Registered widgets: '.implode(', ',$names));
WP_CLI::log('Elementor module: '.(class_exists('EWP_Elementor')?'loaded':'absent').'; base: '.(class_exists('Elementor\\Widget_Base')?'loaded':'absent').'; widget registration hook: '.did_action('elementor/widgets/register'));
