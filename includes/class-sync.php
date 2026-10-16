<?php
defined('ABSPATH') || exit;
final class EventWillow_Sync {
    const TICK = 'eventwillow_sync_tick';
    const STEP = 'eventwillow_sync_step';
    const STATE = 'eventwillow_sync_state';
    private const STEP_TIME_BUDGET = 15;
    public static function settings(): array {
        return wp_parse_args(get_option('eventwillow_sync_settings', []), ['url'=>'','channels'=>[],'interval'=>'hourly','enabled'=>false,'days'=>30,'adopt'=>false]);
    }
    public static function key(): string {
        return defined('EVENTWILLOW_SYNC_SITE_KEY') ? (string)EVENTWILLOW_SYNC_SITE_KEY : (string)get_option('eventwillow_sync_key','');
    }
    /** Explicit server-side exception for a private development/source host only. */
    public static function trusted_host($external, $host) {
        return $external || (defined('EVENTWILLOW_SYNC_TRUSTED_PRIVATE_HOST') && strtolower($host)===strtolower(EVENTWILLOW_SYNC_TRUSTED_PRIVATE_HOST));
    }
    public static function request(string $url, array $args) {
        add_filter('http_request_host_is_external',[self::class,'trusted_host'],10,2);
        try { return wp_safe_remote_get($url,$args); }
        finally { remove_filter('http_request_host_is_external',[self::class,'trusted_host'],10); }
    }
    public static function post(string $url, array $args) {
        add_filter('http_request_host_is_external',[self::class,'trusted_host'],10,2);
        try { return wp_safe_remote_post($url,$args); }
        finally { remove_filter('http_request_host_is_external',[self::class,'trusted_host'],10); }
    }
    public static function valid_url(string $url) {
        add_filter('http_request_host_is_external',[self::class,'trusted_host'],10,2);
        try { return wp_http_validate_url($url); }
        finally { remove_filter('http_request_host_is_external',[self::class,'trusted_host'],10); }
    }
    public static function boot(): void {
        add_filter('cron_schedules', function($s){ $s['eventwillow_15min']=['interval'=>900,'display'=>'Every 15 minutes']; return $s; });
        add_action('init',[self::class,'schedule']);
        add_action(self::TICK,[self::class,'start']);
        add_action(self::STEP,[self::class,'step']);
    }
    public static function schedule(): void {
        $s=self::settings();
        if (!$s['enabled']) { wp_clear_scheduled_hook(self::TICK); return; }
        if (wp_get_schedule(self::TICK)!==$s['interval']) {
            wp_clear_scheduled_hook(self::TICK);
            wp_schedule_event(time()+60,$s['interval'],self::TICK);
        }
        // Recover a lost continuation or a terminated PHP worker on the next site visit.
        if (get_option(self::STATE) && !wp_next_scheduled(self::STEP)) wp_schedule_single_event(time()+10,self::STEP);
    }
    private static function locked(callable $callback): void {
        global $wpdb;
        $name='ew_sync_'.md5(DB_NAME.':'.$wpdb->prefix);
        if ((int)$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 0)',$name))!==1) return;
        try { $callback(); } finally { $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)',$name)); }
    }
    public static function start(): void {
        self::locked(function(){
            if (get_option(self::STATE)) {
                if (!wp_next_scheduled(self::STEP)) wp_schedule_single_event(time()+10,self::STEP);
                return;
            }
            $s=self::settings();
            if (!$s['url'] || !self::key()) {
                self::status(['status'=>'error','message'=>'Configure the source URL and site key first.']); return;
            }
            $now=current_datetime();
            $state=['started'=>time(),'config'=>hash('sha256',wp_json_encode($s)),'phase'=>'channels','page'=>1,'channel_index'=>0,
                'from'=>$now->format('Y-m-d'),'to'=>$now->modify('+'.$s['days'].' days')->format('Y-m-d'),
                'channels'=>[],'schedules'=>[],'events'=>[],'seen'=>[],'map'=>[],'index'=>0,'attempts'=>0,
                'counts'=>['created'=>0,'updated'=>0,'unchanged'=>0,'withdrawn'=>0]];
            update_option(self::STATE,$state,false);
            self::status(['status'=>'queued','message'=>'Sync queued.','started'=>$state['started']]);
            wp_schedule_single_event(time()+1,self::STEP);
        });
    }
    public static function status(array $data): void {
        update_option('eventwillow_sync_status',array_merge(get_option('eventwillow_sync_status',[]),$data),false);
    }
    public static function stop(): void {
        self::locked(function(){ delete_option(self::STATE); wp_clear_scheduled_hook(self::STEP); });
        wp_clear_scheduled_hook(self::TICK);
    }
    public static function step(): void {
        self::locked(function(){
            $state=get_option(self::STATE);
            if (!$state) return;
            $s=self::settings();
            $execution_limit=(int)ini_get('max_execution_time');
            $budget=$execution_limit>0?min(self::STEP_TIME_BUDGET,max(1,$execution_limit-5)):self::STEP_TIME_BUDGET;
            $deadline=microtime(true)+$budget;
            do {
                try {
                    if ($state['config']!==hash('sha256',wp_json_encode($s))) throw new RuntimeException('Settings changed. Start a new sync.');
                    if (time()-$state['started']>3600) throw new RuntimeException('Sync exceeded one hour. Start a new snapshot.');
                    $importer=new EventWillow_Sync_Importer($s);
                    if (in_array($state['phase'],['channels','events'],true)) {
                        $next=$state;
                        self::fetch($next,$s);
                        $state=$next;
                    }
                    elseif ($state['phase']==='import_channels' || $state['phase']==='import_events') {
                        $kind=$state['phase']==='import_channels'?'channel':'event';
                        $items=$state[$kind==='channel'?'channels':'events'];
                        if ($state['index']<count($items)) {
                            $item=$items[$state['index']];
                            $r=$importer->upsert($item,$kind,$state['map']);
                            $state['counts'][$r['result']]++; $state['seen'][]=$r['key'];
                            if ($kind==='channel') $state['map'][$item['id']]=$r['id'];
                            $state['index']++;
                        } else {
                            $state['phase']=$kind==='channel'?'import_events':'withdraw'; $state['index']=0;
                            if ($kind==='event') $state['candidates']=get_posts(['post_type'=>['ew_event','ew_channel'],'post_status'=>'publish','fields'=>'ids','posts_per_page'=>-1,'meta_key'=>'_eventwillow_scope','meta_value'=>$importer->scope]);
                        }
                    } elseif ($state['phase']==='withdraw') {
                        if ($state['index']<count($state['candidates'])) {
                            $id=$state['candidates'][$state['index']];
                            $key=get_post_meta($id,'ew_source_key',true);
                            $date=get_post_meta($id,'ew_occurrence_date',true);
                            // A rolling window is not evidence that a future occurrence was deleted.
                            $in_window=get_post_type($id)==='ew_channel' || ($date && $date<=$state['to']);
                            if ($in_window && !in_array($key,$state['seen'],true)) {
                                $result=wp_update_post(['ID'=>$id,'post_status'=>'draft'],true);
                                if (is_wp_error($result)) throw new RuntimeException('Could not draft a withdrawn record.');
                                $state['counts']['withdrawn']++;
                            }
                            $state['index']++;
                        }
                        if ($state['index']>=count($state['candidates'])) {
                            self::status(['status'=>'success','message'=>'Sync completed.','last_success'=>time(),'counts'=>$state['counts']]);
                            delete_option(self::STATE); wp_clear_scheduled_hook(self::STEP); return;
                        }
                    }
                    $state['attempts']=0;
                    update_option(self::STATE,$state,false);
                    self::status(['status'=>'running','message'=>'Processing '.str_replace('_',' ',$state['phase']).'.','counts'=>$state['counts']]);
                } catch (Throwable $e) {
                    $state['attempts']++;
                    // Only our bounded diagnostics are shown; never raw HTTP bodies or credentials.
                    $message=$e instanceof RuntimeException ? $e->getMessage() : 'Invalid source data or an import error. Check the source and retry.';
                    if ($state['attempts']>=3 || time()-$state['started']>3600 || $state['config']!==hash('sha256',wp_json_encode($s))) {
                        self::status(['status'=>'error','message'=>$message,'counts'=>$state['counts']]);
                        delete_option(self::STATE); wp_clear_scheduled_hook(self::STEP); return;
                    }
                    self::status(['status'=>'retrying','message'=>$message.' Retrying shortly.','counts'=>$state['counts']]);
                    update_option(self::STATE,$state,false);
                    if (!wp_next_scheduled(self::STEP)) wp_schedule_single_event(time()+60*$state['attempts'],self::STEP);
                    return;
                }
            } while (microtime(true)<$deadline);
            if (!wp_next_scheduled(self::STEP)) wp_schedule_single_event(time()+5,self::STEP);
        });
    }
    private static function fetch(array &$state, array $s): void {
        $resource=$state['phase'];
        $query=['page'=>$state['page'],'per_page'=>100];
        if ($resource==='events') $query+=['schedule'=>$state['schedules'][$state['channel_index']],'mode'=>'occurrences','from'=>$state['from'],'to'=>$state['to']];
        $body=EventWillow_Sync_Source::page($s,$resource,$query);
        $meta=$body['meta'];
        if ($state['page']===1) $state['pagination']=['total'=>$meta['total'],'last_page'=>$meta['last_page'],'received'=>0,'keys'=>[]];
        if ($state['pagination']['total']!==$meta['total'] || $state['pagination']['last_page']!==$meta['last_page']) throw new RuntimeException('Source changed during pagination. Retry with a fresh snapshot.');
        foreach ($body['data'] as $item) {
            $key=$resource==='channels'?($item['id']??''):($item['key']??'');
            if (!$key || isset($state['pagination']['keys'][$key])) throw new RuntimeException('Duplicate or missing source identity.');
            $state['pagination']['keys'][$key]=true;
        }
        $state['pagination']['received']+=count($body['data']);
        $state[$resource]=array_merge($state[$resource],$body['data']);
        if ($state['page']<$meta['last_page']) { $state['page']++; return; }
        if ($state['pagination']['received']!==$meta['total']) throw new RuntimeException('Incomplete source snapshot. No withdrawal will run.');
        $state['page']=1;
        if ($resource==='channels') {
            $state['schedules']=$s['channels'] ?: array_values(array_unique(array_column($state['channels'],'identifier')));
            if (!$state['schedules']) {
                EventWillow_Sync_Source::validate($state['channels'],$state['events'],$s);
                $state['phase']='import_channels';
            } else $state['phase']='events';
            return;
        }
        $state['channel_index']++;
        if ($state['channel_index']<count($state['schedules'])) return;
        $events=[];
        foreach ($state['events'] as $e) {
            if (isset($events[$e['key']]) && $events[$e['key']]!==$e) throw new RuntimeException('Occurrence changed between channel snapshots.');
            $events[$e['key']]=$e;
        }
        $state['events']=array_values($events);
        EventWillow_Sync_Source::validate($state['channels'],$state['events'],$s);
        $needed=[];
        foreach ($state['events'] as $e) foreach ($e['channels'] as $c) $needed[$c['id']]=true;
        if ($s['channels']) $state['channels']=array_values(array_filter($state['channels'],fn($c)=>isset($needed[$c['id']]) || in_array($c['identifier'],$s['channels'],true)));
        $state['phase']='import_channels';
    }
}
