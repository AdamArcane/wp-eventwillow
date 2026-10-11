<?php
defined('ABSPATH') || exit;
final class EventWillow_Sync_Admin {
    public static function boot(): void {
        add_action('admin_menu',function(){ add_submenu_page('edit.php?post_type=ew_lab_event','EventWillow Sync','Sync','manage_options','eventwillow-sync',[self::class,'page']); });
        add_action('admin_post_eventwillow_sync',[self::class,'handle']);
    }
    public static function handle(): void {
        if (!current_user_can('manage_options')) wp_die('Access denied.',403);
        check_admin_referer('eventwillow_sync');
        $action=sanitize_key(wp_unslash($_POST['sync_action']??''));
        if ($action==='save') {
            $url=untrailingslashit(esc_url_raw(wp_unslash($_POST['url']??'')));
            $parts=wp_parse_url($url);
            if (!$url || ($parts['scheme']??'')!=='https' || !empty($parts['user']) || !empty($parts['pass']) || !empty($parts['query']) || !empty($parts['fragment']) || !EventWillow_Sync::valid_url($url)) wp_die('Enter a valid HTTPS API base URL without credentials or query parameters.');
            $channels=array_values(array_unique(array_filter(array_map('trim',explode(',',sanitize_text_field(wp_unslash($_POST['channels']??'')))))));
            if (count($channels)>20) wp_die('Select no more than 20 channel identifiers.');
            foreach ($channels as $c) if (!preg_match('/^[a-zA-Z0-9_-]+$/D',$c)) wp_die('Invalid channel identifier.');
            $interval=sanitize_key(wp_unslash($_POST['interval']??''));
            if (!in_array($interval,['eventwillow_15min','hourly','twicedaily','daily'],true)) wp_die('Invalid interval.');
            $key=trim((string)wp_unslash($_POST['site_key']??''));
            if (preg_match('/[\r\n]/',$key)) wp_die('Invalid site key.');
            $old=EventWillow_Sync::settings();
            if ($old['url'] && $old['url']!==$url && !$key && !defined('EVENTWILLOW_SYNC_SITE_KEY')) wp_die('Supply a new key when changing the source URL.');
            EventWillow_Sync::stop();
            if ($key && !defined('EVENTWILLOW_SYNC_SITE_KEY')) update_option('eventwillow_sync_key',$key,false);
            update_option('eventwillow_sync_settings',['url'=>$url,'channels'=>$channels,'interval'=>$interval,
                'enabled'=>!empty($_POST['enabled']),'days'=>min(90,max(1,absint($_POST['days']??30))),'adopt'=>!empty($_POST['adopt'])],false);
            EventWillow_Sync::schedule();
            EventWillow_Sync::status(['status'=>'ready','message'=>'Settings saved.']);
        } elseif ($action==='run') EventWillow_Sync::start();
        elseif ($action==='cancel') { EventWillow_Sync::stop(); EventWillow_Sync::status(['status'=>'cancelled','message'=>'Current run cancelled.']); EventWillow_Sync::schedule(); }
        wp_safe_redirect(admin_url('edit.php?post_type=ew_lab_event&page=eventwillow-sync')); exit;
    }
    public static function page(): void {
        if (!current_user_can('manage_options')) return;
        $s=EventWillow_Sync::settings(); $status=get_option('eventwillow_sync_status',[]);
        echo '<div class="wrap"><h1>EventWillow Sync</h1><p>Import public channels and events from the EventWillow public API. Imported fields follow the source; subsequent syncs replace local edits.</p>';
        echo '<h2>Connection and schedule</h2><form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';
        wp_nonce_field('eventwillow_sync');
        echo '<input type="hidden" name="action" value="eventwillow_sync"><input type="hidden" name="sync_action" value="save"><table class="form-table"><tbody>';
        self::input('url','API base URL',$s['url'],'url','For example: https://app.example.com/api/eventwillow/v1');
        self::input('site_key','Site key','','password',defined('EVENTWILLOW_SYNC_SITE_KEY')?'Managed by EVENTWILLOW_SYNC_SITE_KEY in wp-config.php.':(EventWillow_Sync::key()?'A key is saved. Leave blank to keep it.':'Use the public API site key.'));
        self::input('channels','Channel identifiers',implode(', ',$s['channels']),'text','Optional, comma-separated channel identifiers. Leave blank to sync all source channels.');
        self::input('days','Days ahead',(string)$s['days'],'number','Import the next 1–90 days. Events beyond this window are retained.');
        echo '<tr><th scope="row"><label for="interval">Frequency</label></th><td><select id="interval" name="interval">';
        foreach (['eventwillow_15min'=>'Every 15 minutes','hourly'=>'Hourly','twicedaily'=>'Twice daily','daily'=>'Daily'] as $value=>$label) echo '<option value="'.esc_attr($value).'" '.selected($s['interval'],$value,false).'>'.esc_html($label).'</option>';
        echo '</select></td></tr><tr><th scope="row">Automatic sync</th><td><label><input type="checkbox" name="enabled" value="1" '.checked($s['enabled'],true,false).'> Enable scheduled imports</label><p class="description">WordPress runs due jobs when the site receives traffic. Use a server cron for dependable timing.</p></td></tr>';
        echo '<tr><th scope="row">Existing imports</th><td><label><input type="checkbox" name="adopt" value="1" '.checked($s['adopt'],true,false).'> Adopt records from the previous external workflow</label><p class="description">Enable only when those records came from this source. Matching source IDs keep their WordPress URLs and template relationships. Disable the external workflow before enabling scheduled imports.</p></td></tr></tbody></table>';
        submit_button('Save settings'); echo '</form><h2>Sync status</h2><p role="status">'.esc_html(($status['status']??'Not configured').': '.($status['message']??'')).'</p>';
        $next=wp_next_scheduled(EventWillow_Sync::TICK);
        echo '<p>Last successful sync: '.esc_html(!empty($status['last_success'])?wp_date('M j, Y g:i a',$status['last_success']):'Never').'<br>Next scheduled sync: '.esc_html($next?wp_date('M j, Y g:i a',$next):'Disabled').'</p>';
        if (!empty($status['counts'])) { echo '<ul>'; foreach ($status['counts'] as $label=>$count) echo '<li>'.esc_html(ucfirst($label).': '.(int)$count).'</li>'; echo '</ul>'; }
        foreach (['run'=>'Sync now','cancel'=>'Cancel current run'] as $action=>$label) {
            echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'" style="display:inline-block;margin-right:12px">'; wp_nonce_field('eventwillow_sync');
            echo '<input type="hidden" name="action" value="eventwillow_sync"><input type="hidden" name="sync_action" value="'.esc_attr($action).'">'; submit_button($label,'secondary','submit',false); echo '</form>';
        }
        echo '<p>Missing source records are drafted only after a complete successful import. Existing images and content are preserved on deactivation.</p></div>';
    }
    private static function input(string $name,string $label,string $value,string $type,string $help): void {
        echo '<tr><th scope="row"><label for="'.esc_attr($name).'">'.esc_html($label).'</label></th><td><input class="regular-text" id="'.esc_attr($name).'" name="'.esc_attr($name).'" type="'.esc_attr($type).'" value="'.esc_attr($value).'" autocomplete="off"><p class="description">'.esc_html($help).'</p></td></tr>';
    }
}
