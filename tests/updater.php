<?php
if (!defined('WP_CLI') || !WP_CLI || !str_ends_with(parse_url(home_url(), PHP_URL_HOST), '.ddev.site')) exit;
require_once EWP_PATH . 'includes/class-simple-updater.php';
$url = 'https://files.arcanetechct.com/eventwillow-public/info.json';
$key = 'ewp_updater_' . md5($url);
$prior = get_site_transient($key);
$count = 0; $status = 200; $bad = false; $assertions = 0;
$manifest = ['name'=>'EventWillow Public Views','slug'=>'eventwillow-public','version'=>'0.1.4','download_url'=>'https://files.arcanetechct.com/eventwillow-public/eventwillow-public-0.1.4.zip','sections'=>['description'=>'Fixture']];
$assert = function ($condition, $message) use (&$assertions) { if (!$condition) throw new RuntimeException($message); $assertions++; };
$http = function ($pre, $args, $requestUrl) use ($url, &$count, &$status, &$bad, &$manifest, $assert) {
    if ($requestUrl !== $url) return $pre;
    $count++;
    $assert(empty($args['headers']['X-EventWillow-Site-Key']), 'Update request must not contain the app key');
    return ['response'=>['code'=>$status], 'headers'=>[], 'body'=>$bad ? '{invalid' : wp_json_encode($manifest), 'cookies'=>[]];
};
add_filter('pre_http_request', $http, 10, 3);
$updater = new EWP_Updater($url, EWP_PATH . 'eventwillow-public.php', '0.1.3');
$plugin = 'eventwillow-public/eventwillow-public.php';
$state = fn() => (object)['response'=>['other/plugin.php'=>(object)['new_version'=>'2.0']], 'no_update'=>[]];
try {
    delete_site_transient($key);
    $r = $updater->check_for_update($state());
    $assert($r->response[$plugin]->new_version === '0.1.4', 'Newer manifest offers an update');
    $assert($r->response[$plugin]->package === $manifest['download_url'], 'Update points to the versioned ZIP');
    $assert(isset($r->response['other/plugin.php']), 'Other plugins remain intact');
    $updater->check_for_update($state());
    $assert($count === 1, 'Successful checks reuse the manifest');
    $info = $updater->plugin_info(false, 'plugin_information', (object)['slug'=>'eventwillow-public']);
    $assert($info->download_link === $manifest['download_url'] && $info->sections['description'] === 'Fixture', 'Plugin details use the same manifest');
    $assert($updater->plugin_info('untouched', 'plugin_information', (object)['slug'=>'other']) === 'untouched', 'Unrelated plugin details remain intact');
    do_action('delete_site_transient_update_plugins');
    $updater->check_for_update($state());
    $assert($count === 2, 'Core forced checks invalidate the manifest cache');
    $manifest['version'] = '0.1.3'; delete_site_transient($key);
    $r = $updater->check_for_update($state());
    $assert(!isset($r->response[$plugin]) && isset($r->no_update[$plugin]), 'Installed version is marked current');
    $manifest['version'] = '0.1.2'; delete_site_transient($key);
    $assert(!isset($updater->check_for_update($state())->response[$plugin]), 'Older manifest cannot offer a downgrade');
    $manifest['version'] = '0.1.4'; $manifest['download_url'] = 'http://files.arcanetechct.com/plugin.zip'; delete_site_transient($key);
    $assert(!isset($updater->check_for_update($state())->response[$plugin]), 'Insecure packages are rejected');
    $manifest['download_url'] = 'https://files.arcanetechct.com/eventwillow-public/eventwillow-public-0.1.4.zip';
    $manifest['slug'] = 'wrong-plugin'; delete_site_transient($key);
    $assert(!isset($updater->check_for_update($state())->response[$plugin]), 'A different plugin manifest is rejected');
    $manifest['slug'] = 'eventwillow-public'; $status = 503; delete_site_transient($key);
    $updater->check_for_update($state()); $failedCount = $count;
    $updater->check_for_update($state());
    $assert($count === $failedCount, 'Failure cache prevents repeated requests');
    $updater->clear_cache_after_update(null, ['action'=>'update','type'=>'plugin']); $status = 200; $bad = true;
    $assert(!isset($updater->check_for_update($state())->response[$plugin]), 'Malformed JSON leaves the update screen usable');
    $assert($updater->check_for_update(false) === false, 'Empty core state remains unchanged');
    WP_CLI::success($assertions . ' updater assertions passed.');
} finally {
    remove_filter('pre_http_request', $http, 10);
    delete_site_transient($key);
    if ($prior !== false) set_site_transient($key, $prior, 6 * HOUR_IN_SECONDS);
}
