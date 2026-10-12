<?php
// wp eval-file tests/check-updater.php. No outbound HTTP or post mutations.
if (PHP_SAPI !== 'cli') exit;
$checks=0;
$assert=function($ok,$label)use(&$checks){if(!$ok)throw new RuntimeException($label);$checks++;};
$url='https://example.org/eventwillow-sync-test/info.json';
$key='eventwillow_sync_updater_'.md5($url);
$manifest=['slug'=>'eventwillow-sync','version'=>'0.1.1','download_url'=>'https://example.org/plugin.zip'];
$calls=0;$status=200;
$mock=function($pre,$args,$request)use($url,&$manifest,&$calls,&$status){
    if($request!==$url)return $pre;
    $calls++;
    return ['headers'=>[],'response'=>['code'=>$status],'body'=>wp_json_encode($manifest),'cookies'=>[]];
};
add_filter('pre_http_request',$mock,10,3);
$updater=new EventWillow_Sync_Updater($url,WP_PLUGIN_DIR.'/eventwillow-sync/eventwillow-sync.php','0.1.0');
try{
    delete_transient($key);
    $value=$updater->check_for_update((object)['response'=>[]]);
    $assert(($value->response['eventwillow-sync/eventwillow-sync.php']->new_version??'')==='0.1.1','New version reported');
    $info=$updater->plugin_info(false,'plugin_information',(object)['slug'=>'eventwillow-sync']);
    $assert($info->download_link==='https://example.org/plugin.zip','Plugin details');
    $assert($calls===1,'Successful manifest cached');
    $assert($updater->plugin_info('unchanged','plugin_information',(object)['slug'=>'another-plugin'])==='unchanged','Other plugins untouched');
    $manifest['version']='0.1.0';$updater->clear_cache_if_requested();
    $assert(empty($updater->check_for_update($value)->response),'Equal version clears stale notice');
    foreach([
        ['slug'=>'wrong','version'=>'0.1.1','download_url'=>'https://example.org/plugin.zip'],
        ['slug'=>'eventwillow-sync','version'=>'oops','download_url'=>'https://example.org/plugin.zip'],
        ['slug'=>'eventwillow-sync','version'=>'0.1.1','download_url'=>'http://example.org/plugin.zip'],
        ['slug'=>'eventwillow-sync','version'=>'0.1.1','download_url'=>'https://127.0.0.1/plugin.zip'],
    ] as $manifest){
        $updater->clear_cache_if_requested();
        $assert(empty($updater->check_for_update((object)['response'=>[]])->response),'Invalid manifest ignored');
    }
    $status=503;$updater->clear_cache_if_requested();$before=$calls;
    $updater->check_for_update((object)['response'=>[]]);$updater->check_for_update((object)['response'=>[]]);
    $assert($calls===$before+1,'Failure retry cache');
    $updater->clear_cache_after_update(null,['action'=>'update','type'=>'plugin']);
    $assert(get_transient($key)===false,'Upgrade clears cache');
    $assert(has_filter('pre_set_site_transient_update_plugins',[$updater,'check_for_update'])!==false,'CLI and cron registration');
    $assert(post_type_exists('ew_event')&&post_type_exists('ew_channel'),'Both CPTs available');
    $assert(!post_type_exists('ew_lab_event')&&!post_type_exists('ew_lab_channel'),'No legacy CPT names');
    $assert(get_post_type_object('ew_event')->labels->name==='Events','Permanent event labels');
    $assert(has_action('elementor/query/ew_upcoming')!==false,'Upcoming query hook');
    $assert(post_type_supports('ew_event','elementor')&&post_type_supports('ew_channel','elementor'),'Elementor editing support');
    $assert(get_post_type_object('ew_event')->has_archive===eventwillow_sync_slugs()['event'],'Event directory slug');
    $saved_slugs=get_option('eventwillow_sync_slugs',null);
    update_option('eventwillow_sync_slugs',['event'=>'Happenings','channel'=>'happenings']);
    $assert(eventwillow_sync_slugs()==['event'=>'happenings','channel'=>'channels'],'Slug sanitizing and collision fallback');
    if ($saved_slugs===null) delete_option('eventwillow_sync_slugs'); else update_option('eventwillow_sync_slugs',$saved_slugs);
    $assert(eventwillow_sync_rename_legacy(['n'=>'ew-lab-event-title','p'=>'ew_lab_event'])===['n'=>'ew-event-title','p'=>'ew_event'],'Legacy Elementor reference migration');
    echo "$checks release checks passed\n";
}finally{
    delete_transient($key);
    remove_filter('pre_http_request',$mock,10);
    remove_filter('pre_set_site_transient_update_plugins',[$updater,'check_for_update']);
    remove_filter('plugins_api',[$updater,'plugin_info'],20);
    remove_action('upgrader_process_complete',[$updater,'clear_cache_after_update'],10);
    remove_action('delete_site_transient_update_plugins',[$updater,'clear_cache_if_requested']);
}
