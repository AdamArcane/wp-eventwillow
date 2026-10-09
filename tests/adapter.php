<?php
if (!defined('WP_CLI') || !WP_CLI || !str_ends_with(parse_url(home_url(),PHP_URL_HOST),'.ddev.site')) exit;
$original=get_option('ewp_settings',[]);$s=EWP_API::settings();$s['api_url']='https://fixture.invalid/api/eventwillow/v1';update_option('ewp_settings',$s);EWP_API::clear();
$lastUrl='';$requests=0;$status=200;$age=0;$body=['data'=>[],'meta'=>['api_version'=>'1','generated_at'=>gmdate('c'),'max_age'=>120,'total'=>0]];
$assertions=0;
$assert=function($condition,$message)use(&$assertions){ if(!$condition)throw new RuntimeException($message);$assertions++; };
$intercept=function($pre,$args,$url)use(&$lastUrl,&$requests,&$status,&$body,&$age,$assert){
    if(!str_starts_with($url,'https://fixture.invalid/'))return $pre;
    $requests++;$lastUrl=$url;
    $assert(!empty($args['headers']['X-EventWillow-Site-Key']),'Server request must have integration key');
    $response=$body;$response['meta']['generated_at']=gmdate('c',time()-$age);
    return ['response'=>['code'=>$status,'message'=>'Fixture'],'headers'=>[],'body'=>wp_json_encode($response),'cookies'=>[]];
};
add_filter('pre_http_request',$intercept,10,3);
try {
    $a=EWP_API::get('events');$assert(!is_wp_error($a),'Fresh response accepted');
    EWP_API::get('events');$assert($requests===1,'Fresh response reused');
    EWP_API::clear();$status=503;$error=EWP_API::get('events');$assert(is_wp_error($error),'Unavailable API must not return expired data');
    $status=404;$error=EWP_API::get('events');$assert($error->get_error_code()==='ewp_missing','Withdrawal differs from connection failure');
    $status=200;$age=121;$error=EWP_API::get('events');$assert($error->get_error_code()==='ewp_expired','Expired remote response refused');
    $age=0;$body['meta']['api_version']='2';$error=EWP_API::get('events');$assert($error->get_error_code()==='ewp_version','Version mismatch refused');
    $item=['id'=>'abc','slug'=>'example','name'=>'<script>alert(1)</script>','summary'=>'<img src=x onerror=alert(1)>','description'=>'','occurrence_date'=>'2026-10-10','local_starts_at'=>'2026-10-10T18:00:00-04:00','location'=>[],'channels'=>[]];
    $html=EWP_Views::card($item,'event');$assert(!str_contains($html,'<script>'),'Card title is escaped');
    $assert(str_contains(EWP_Views::url($item),'date=2026-10-10'),'Occurrence survives local links');
    $registered=array_keys(\Elementor\Plugin::$instance->widgets_manager->get_widget_types());
    $assert(in_array('ewp-calendar',$registered,true),'Calendar widget registered');
    $assert(in_array('ewp-field',$registered,true),'Field widget registered');
    $assert(in_array('ewp-search',$registered,true),'Search widget registered');
    $assert(!array_key_exists('site_key',EWP_API::settings()),'Key is absent from plugin options');
    $body['meta']['api_version']='1';$body['meta']['last_page']=2;$body['meta']['page']=1;EWP_API::clear();
    $feed=do_shortcode('[eventwillow_event_feed type="popular" directory_url="/events/"]');
    $assert(str_contains($feed,'Most viewed over the last 30 days.'),'Popular feed keeps its analytics note');
    $assert(str_contains($feed,'ew_sort=popular'),'Browse link keeps the selected feed sort');
    $assert(!str_contains($feed,'ewp-pagination'),'Homepage feeds do not paginate inline');
    $item['image']='https://fixture.invalid/flyer.jpg';
    $assert(str_contains(EWP_Views::card($item,'event'),'fixture.invalid/flyer.jpg'),'Custom card renders the remote flyer as an image or background');
    $assert(!str_contains(EWP_Views::card($item,'event',0,false),'<img '),'Image switch also hides dynamic template images');
    $savedGet=$_GET;$_GET=['ew_city'=>'Brooklyn','ew_page'=>'4','peer_q'=>'moon'];
    try {
        $search=EWP_Views::search('ew_',['url'=>'','show_label'=>false]);
        $assert(str_contains($search,'name="ew_page" value="1"'),'Search resets pagination');
        $assert(str_contains($search,'name="ew_city" value="Brooklyn"') && str_contains($search,'name="peer_q" value="moon"'),'Search preserves current filters and other widgets');
        $assert(str_contains($search,'type="search"') && str_contains($search,'<label for='),'Search has an accessible label');
    } finally { $_GET=$savedGet; }
    $filter=EWP_Views::filters('ew_',[],['facets'=>[]],'event');
    $primary=explode('<dialog ',$filter)[0];
    $assert(str_contains($primary,'name="ew_category"'),'Category remains visible without facet values');
    $assert(str_contains($primary,'name="ew_cost"') && str_contains($primary,'name="ew_period"'),'Primary cost and time frame controls visible');
    $assert(!str_contains($primary,'type="search"') && !str_contains($primary,'name="ew_postal"'),'Extra fields live in advanced panel');
    $assert(str_contains($filter,'<dialog ') && str_contains($filter,'aria-haspopup="dialog"'),'Advanced filters have an accessible dialog');
    $assert(str_contains($primary,'name="ew_page" value="1"'),'Filtering resets pagination');
    $filter=EWP_Views::filters('peer_', ['city'=>'Brooklyn','category'=>'Arts'], ['facets'=>['category'=>[['value'=>'Arts','label'=>'Arts']]]], 'event');
    $assert(str_contains($filter,'Advanced filters (1)') && str_contains($filter,'value="Arts"  selected='),'Advanced state and selected category survive rendering');
    EWP_API::clear();EWP_API::get('events',['category'=>'Arts & Culture','q'=>'ritual + music','custom'=>['field_abcd'=>'A&B']]);
    parse_str(wp_parse_url($lastUrl,PHP_URL_QUERY),$sentQuery);
    $assert($sentQuery['category']==='Arts & Culture' && $sentQuery['q']==='ritual + music' && $sentQuery['custom']['field_abcd']==='A&B','API query encoding preserves punctuation and nested filters');
    $native=EWP_Views::card($item,'event',-1,true,['show_title'=>false,'show_summary'=>false,'show_date'=>false,'show_button'=>false]);
    $assert(str_contains($native,'ewp-card-default') && !str_contains($native,'ewp-card-title') && !str_contains($native,'ewp-card-summary') && !str_contains($native,'ewp-card-more'),'Built-in card section switches remove markup');
    $assert(in_array('ewp-card-image',$registered,true) && in_array('ewp-card-button',$registered,true),'Reusable image and button components registered');
    WP_CLI::success($assertions.' adapter assertions passed.');
} finally { remove_filter('pre_http_request',$intercept,10);update_option('ewp_settings',$original);EWP_API::clear(); }
