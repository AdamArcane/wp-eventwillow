<?php
/** Run with wp eval-file on a disposable/local WordPress installation. No real API calls. */
if (!defined('WP_CLI') || !WP_CLI) exit;
if (get_option(EventWillow_Sync::STATE)) WP_CLI::error('Finish or cancel the current sync before testing.');
$options=['eventwillow_sync_settings','eventwillow_sync_key','eventwillow_sync_status','ew_public_fields'];
$saved=[];
foreach ($options as $o) $saved[$o]=get_option($o,null);
$assert=function($ok,$label){ if (!$ok) throw new RuntimeException($label); WP_CLI::log('PASS '.$label); };
$settings=['url'=>'https://example.org/api/eventwillow/v1','channels'=>['sync-test'],'interval'=>'hourly','enabled'=>false,'days'=>30,'adopt'=>false];
$channel=['id'=>'sync-test-channel','identifier'=>'sync-test','name'=>'Sync test channel','timezone'=>'UTC','show_subscribe_panel'=>true,'account_followers_count'=>12,'confirmed_email_subscribers_count'=>8];
$channels=[$channel]; $all_channels_mode=false; $requested_schedules=[];
$date=current_datetime()->format('Y-m-d');
$event=['id'=>'sync-test-event','key'=>'sync-test-event:'.$date,'occurrence_date'=>$date,'name'=>'Sync test event','timezone'=>'UTC',
    'starts_at'=>$date.'T20:00:00Z','ends_at'=>$date.'T21:00:00Z','channels'=>[$channel],
    'description'=>'**Plain fallback**','description_html'=>'<p><strong>Formatted Markdown</strong></p>',
    'custom_fields'=>['field_012345abcdef'=>['label'=>'Public test','values'=>['First']]]];
$mode='normal';
$http=function($pre,$args,$url) use (&$mode,&$event,&$channels,&$all_channels_mode,&$requested_schedules,$channel) {
    if (!str_starts_with($url,'https://example.org/api/eventwillow/v1/')) return $pre;
    if ($mode==='failure') return new WP_Error('test','Simulated network failure');
    parse_str(wp_parse_url($url,PHP_URL_QUERY)??'',$query);
    $events=str_contains($url,'/events?');
    if ($events) $requested_schedules[]=$query['schedule']??'';
    $data=$events?($mode==='empty'?[]:($all_channels_mode && ($query['schedule']??'')!=='sync-test'?[]:[$event])):$channels;
    $meta=['api_version'=>'1','generated_at'=>gmdate('c'),'total'=>count($data),'page'=>1,'last_page'=>1];
    if ($events) $meta+=['mode'=>'occurrences','from'=>$query['from'],'to'=>$query['to']];
    if ($mode==='incomplete' && $events) $meta['total']=2;
    return ['response'=>['code'=>200],'headers'=>[],'body'=>wp_json_encode(['data'=>$data,'meta'=>$meta])];
};
$last_run_steps=0;
$run=function() use (&$last_run_steps){
    EventWillow_Sync::start();
    $last_run_steps=0;
    for ($i=0;$i<30 && get_option(EventWillow_Sync::STATE);$i++) { EventWillow_Sync::step(); $last_run_steps++; }
    if (get_option(EventWillow_Sync::STATE)) throw new RuntimeException('Test job did not finish.');
    return get_option('eventwillow_sync_status');
};
$ids=[];
try {
    update_option('eventwillow_sync_settings',$settings,false); update_option('eventwillow_sync_key','test-key',false);
    add_filter('pre_http_request',$http,10,3);
    $r=$run(); $assert($r['status']==='success' && $r['counts']['created']===2,'complete snapshot creates channels and events');
    $importer=new EventWillow_Sync_Importer($settings);
    $ids=get_posts(['post_type'=>['ew_event','ew_channel'],'post_status'=>'any','fields'=>'ids','posts_per_page'=>-1,'meta_key'=>'_eventwillow_scope','meta_value'=>$importer->scope]);
    $event_id=0; $channel_id=0; foreach ($ids as $id) { if (get_post_type($id)==='ew_event') $event_id=$id; else $channel_id=$id; }
    $assert(get_post_meta($channel_id,'ew_show_subscribe_panel',true)==='1'
        && get_post_meta($channel_id,'ew_account_followers_count',true)==='12'
        && get_post_meta($channel_id,'ew_confirmed_email_subscribers_count',true)==='8','channel signup setting and separate audience counts reach native metadata');
    $field=eventwillow_sync_custom_key('field_012345abcdef','Public test');
    $assert(get_post_field('post_content',$event_id)==='<p><strong>Formatted Markdown</strong></p>','rendered Markdown is saved as native event post content');
    $assert(get_post_meta($event_id,$field,true)==='First','internal import projects individual public fields');
    $r=$run(); $assert($r['counts']['unchanged']===2 && $r['counts']['created']===0,'repeat import preserves IDs and skips unchanged posts');
    $event['custom_fields']=(object)[]; $run();
    $event['location']=['latitude'=>41.76,'longitude'=>-72.67,'geocode_status'=>'found'];
    $r=$run();
    $assert($r['status']==='success' && (float)get_post_meta($event_id,'ew_latitude',true)===41.76 && (float)get_post_meta($event_id,'ew_longitude',true)===-72.67,'coordinate pair reaches native event metadata');
    $r=$run(); $assert($r['counts']['unchanged']===2,'coordinates do not cause repeated writes');
    $event['location']['latitude']=91;
    $r=$run(); $assert($r['status']==='error' && (float)get_post_meta($event_id,'ew_latitude',true)===41.76 && get_post_status($event_id)==='publish','invalid coordinate snapshot does not write or withdraw events');
    $event['location']=['latitude'=>null,'longitude'=>null,'geocode_status'=>'pending'];
    $r=$run(); $assert($r['status']==='success' && get_post_meta($event_id,'ew_latitude',true)==='' && get_post_meta($event_id,'ew_longitude',true)==='' && get_post_meta($event_id,'ew_geocode_status',true)==='pending','pending changed address clears both old coordinates');
    $event['location']=['latitude'=>0,'longitude'=>20,'geocode_status'=>'approximate'];
    $r=$run(); $assert($r['status']==='success' && get_post_meta($event_id,'ew_latitude',true)==='0','valid equator coordinate is retained');
    $event['format']='online'; $run();
    $assert(get_post_meta($event_id,'ew_latitude',true)==='' && get_post_meta($event_id,'ew_geocode_status',true)==='online','online events never retain a physical search position');
    unset($event['location'],$event['format']);
    $r=$run(); $assert($r['status']==='success' && get_post_meta($event_id,'ew_latitude',true)==='','older API payloads remain compatible');
    $assert(!metadata_exists('post',$event_id,$field),'removed public fields are cleared');
    $mode='failure'; $r=$run(); $assert($r['status']==='error' && get_post_status($event_id)==='publish','failed fetch exhausts retries without withdrawing content');
    $mode='incomplete'; $r=$run(); $assert($r['status']==='error' && get_post_status($event_id)==='publish','incomplete pagination never triggers withdrawal');
    $future=wp_insert_post(['post_type'=>'ew_event','post_status'=>'publish','post_title'=>'Future sync test']); $ids[]=$future;
    update_post_meta($future,'_eventwillow_scope',$importer->scope); update_post_meta($future,'ew_source_key','future-test'); update_post_meta($future,'ew_occurrence_date',current_datetime()->modify('+100 days')->format('Y-m-d'));
    $mode='empty'; $r=$run(); $assert($r['status']==='success' && get_post_status($event_id)==='draft','complete empty snapshot drafts missing managed occurrences');
    $assert(get_post_status($future)==='publish','future records outside the window are retained');
    $channels[]=['id'=>'sync-test-channel-two','identifier'=>'sync-test-two','name'=>'Second sync test channel','timezone'=>'UTC'];
    $all_channels_mode=true; $mode='normal'; $requested_schedules=[];
    $settings['channels']=[]; update_option('eventwillow_sync_settings',$settings,false);
    $r=$run();
    $processed=array_sum($r['counts']);
    $assert($r['status']==='success' && $processed===3,'empty channel selection imports all channels and their events');
    $assert($last_run_steps===1,'small snapshot completes in one bounded continuation');
    $assert(array_values(array_unique($requested_schedules))===['sync-test','sync-test-two'],'empty channel selection requests events for every source channel');
    EventWillow_Sync::start(); $first=get_option(EventWillow_Sync::STATE); EventWillow_Sync::start();
    $assert(get_option(EventWillow_Sync::STATE)===$first,'overlapping starts retain the active job');
    EventWillow_Sync::stop(); $assert(!get_option(EventWillow_Sync::STATE) && !wp_next_scheduled(EventWillow_Sync::STEP),'cancellation removes the job and continuation');
    WP_CLI::success('Sync integration checks passed.');
} finally {
    remove_filter('pre_http_request',$http,10);
    EventWillow_Sync::stop();
    foreach ($ids as $id) wp_delete_post($id,true);
    // Catch partially created fixtures if an earlier assertion failed.
    foreach (get_posts(['post_type'=>['ew_event','ew_channel'],'post_status'=>['publish','draft'],'fields'=>'ids','posts_per_page'=>-1,'meta_key'=>'_eventwillow_source','meta_value'=>hash('sha256',$settings['url'])]) as $id) wp_delete_post($id,true);
    foreach ($saved as $o=>$v) { if ($v===null) delete_option($o); else update_option($o,$v,false); }
    EventWillow_Sync::schedule();
}
