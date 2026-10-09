<?php
if (!defined('ABSPATH')) exit;
final class EWP_Plugin {
    public static ?array $route = null;
    public static function boot(): void {
        add_action('init', [self::class, 'routes']);
        add_filter('query_vars', fn($vars) => array_merge($vars, ['ewp_kind','ewp_id','ewp_slug']));
        add_action('template_redirect', [self::class, 'resolve']);
        add_filter('template_include', fn($file) => self::$route ? EWP_PATH . 'templates/page.php' : $file, 99);
        add_filter('pre_get_document_title', fn($title) => self::$route['item']['name'] ?? (self::$route['title'] ?? $title));
        add_action('wp_head', [self::class, 'metadata'], 1);
        add_action('wp_enqueue_scripts', function () {
            wp_register_style('ewp', EWP_URL.'assets/public.css', [], EWP_VERSION);
            wp_register_script('ewp', EWP_URL.'assets/public.js', [], EWP_VERSION, true);
        });
        add_action('init', function () {
            if (function_exists('ewd_shortcode')) return; // Never take over a still-active legacy adapter.
            add_shortcode('eventwillow_events', fn() => EWP_Views::listing('events', ['filters' => true]));
            add_shortcode('eventwillow_event_feed', function ($a) {
                $a=shortcode_atts(['type'=>'upcoming','limit'=>3,'title'=>'','directory_url'=>'/events/'],(array)$a);
                if (!in_array($a['type'],['upcoming','popular','new'],true)) $a['type']='upcoming';
                $a['limit']=max(1,min(12,(int)$a['limit']));
                $a['prefix']='ew_feed_'.$a['type'].'_';
                return EWP_Views::listing('events',$a);
            });
            add_shortcode('eventwillow_channels', fn() => EWP_Views::listing('channels', ['filters'=>true]));
        }, 100);
        add_action('admin_menu', fn() => add_options_page('EventWillow Public Views','EventWillow Public Views','manage_options','eventwillow-public',[self::class,'settingsPage']));
        add_action('admin_init', [self::class, 'registerSettings']);
        add_action('admin_post_ewp_clear', function () { current_user_can('manage_options') || wp_die('Forbidden'); check_admin_referer('ewp_clear'); EWP_API::clear(); wp_safe_redirect(admin_url('options-general.php?page=eventwillow-public')); exit; });
        add_action('elementor/init', function () { require_once EWP_PATH.'includes/elementor.php'; EWP_Elementor::boot(); });
    }
    public static function routes(): void {
        add_rewrite_rule('^events/([^/]+)/([^/]+)/?$', 'index.php?ewp_kind=event&ewp_id=$matches[1]&ewp_slug=$matches[2]', 'top');
        add_rewrite_rule('^channels/([^/]+)/([^/]+)/?$', 'index.php?ewp_kind=channel&ewp_id=$matches[1]&ewp_slug=$matches[2]', 'top');
        // Existing WordPress pages at these bases take precedence over the fallback archives.
        if (!get_page_by_path('events')) add_rewrite_rule('^events/?$', 'index.php?ewp_kind=events', 'top');
        if (!get_page_by_path('channels')) add_rewrite_rule('^channels/?$', 'index.php?ewp_kind=channels', 'top');
    }
    public static function activate(): void { self::routes(); flush_rewrite_rules(); }
    public static function noCache(): void { if (!defined('DONOTCACHEPAGE')) define('DONOTCACHEPAGE', true); if (!headers_sent()) nocache_headers(); }
    public static function resolve(): void {
        $kind = get_query_var('ewp_kind');
        if (!$kind) {
            $post = get_queried_object();
            if ($post instanceof WP_Post && (str_contains($post->post_content,'eventwillow_') || str_contains((string)get_post_meta($post->ID,'_elementor_data',true),'ewp-') || str_contains((string)get_post_meta($post->ID,'_elementor_data',true),'eventwillow_'))) self::noCache();
            return;
        }
        self::noCache();
        $id = sanitize_text_field(get_query_var('ewp_id'));
        self::$route = ['kind' => $kind, 'title' => $kind === 'channels' ? 'Channels' : 'Events'];
        global $wp_query; $wp_query->is_404 = false; $wp_query->is_home = false; $wp_query->is_archive = false; $wp_query->is_page = true; $wp_query->is_singular = true;
        self::virtualPost(self::$route['title'], home_url('/'.$kind.'/'));
        if (in_array($kind,['event','channel'],true)) {
            $query = [];
            if ($kind === 'event' && isset($_GET['date'])) $query['date'] = sanitize_text_field(wp_unslash($_GET['date']));
            $response = EWP_API::get(($kind === 'event' ? 'events/' : 'channels/').rawurlencode($id), $query);
            if (is_wp_error($response)) {
                self::$route['error'] = $response;
                status_header($response->get_error_code() === 'ewp_missing' ? 404 : 503);
                return;
            }
            self::$route['item'] = $response['data']; EWP_Views::$context = $response['data'];
            self::virtualPost($response['data']['name'], EWP_Views::url($response['data'], $kind));
            $slug = $response['data']['slug'] ?: 'view';
            if (get_query_var('ewp_slug') !== $slug) { wp_safe_redirect(EWP_Views::url($response['data'], $kind), 301); exit; }
        }
        status_header(200);
        remove_action('wp_head','rel_canonical');
    }
    private static function virtualPost(string $title, string $url): void {
        global $post, $wp_query;
        $post = new WP_Post((object)['ID'=>0,'post_author'=>0,'post_title'=>$title,'post_content'=>'','post_excerpt'=>'','post_status'=>'publish','post_name'=>'eventwillow-view','post_type'=>'page','post_parent'=>0,'comment_status'=>'closed','ping_status'=>'closed','filter'=>'raw','guid'=>$url]);
        $wp_query->post=$post; $wp_query->posts=[$post]; $wp_query->post_count=1; $wp_query->found_posts=1; $wp_query->queried_object=$post; $wp_query->queried_object_id=0;
    }
    public static function metadata(): void {
        if (isset(self::$route['error'])) { echo '<meta name="robots" content="noindex">'; return; }
        if (empty(self::$route['item'])) return;
        $item = self::$route['item'];
        echo '<link rel="canonical" href="'.esc_url($item['canonical_url']).'">' . "\n";
        echo '<meta name="description" content="'.esc_attr(wp_trim_words($item['summary'] ?: $item['description'], 35, '')).'">' . "\n";
        echo '<meta property="og:title" content="'.esc_attr($item['name']).'">' . "\n";
        echo '<meta property="og:url" content="'.esc_url(EWP_Views::url($item,self::$route['kind'])).'">' . "\n";
        if (!empty($item['image'])) echo '<meta property="og:image" content="'.esc_url($item['image']).'">' . "\n";
    }
    public static function registerSettings(): void {
        register_setting('ewp', 'ewp_settings', ['sanitize_callback' => function ($in) {
            $old = EWP_API::settings(); $out = [];
            $out['api_url'] = esc_url_raw(rtrim($in['api_url'] ?? '', '/'), ['http','https']);
            foreach (['event_template','channel_template','card_template','channel_card_template'] as $key) $out[$key] = absint($in[$key] ?? 0);
            foreach (['preview_event','preview_channel','unavailable'] as $key) $out[$key] = sanitize_text_field($in[$key] ?? $old[$key]);
            EWP_API::clear(); return $out;
        }]);
    }
    public static function templateOptions(): array {
        $options = [0 => 'Default layout'];
        foreach (get_posts(['post_type'=>'elementor_library','posts_per_page'=>-1,'post_status'=>'publish']) as $post) $options[$post->ID] = $post->post_title;
        return $options;
    }
    public static function settingsPage(): void {
        $s = EWP_API::settings();
        echo '<div class="wrap"><h1>EventWillow Public Views</h1><p>Content is managed in Laravel. These settings control the API connection and shared Elementor templates.</p>';
        if (function_exists('ewd_shortcode')) echo '<div class="notice notice-warning"><p>The legacy directory is active. Its shortcodes remain in control until you deactivate it.</p></div>';
        echo '<form action="options.php" method="post">'; settings_fields('ewp');
        echo '<table class="form-table"><tr><th>Public API URL</th><td><input class="regular-text" name="ewp_settings[api_url]" value="'.esc_attr($s['api_url']).'" placeholder="https://app.example.com/api/eventwillow/v1"></td></tr>';
        foreach (['event_template'=>'Event detail template','channel_template'=>'Channel detail template','card_template'=>'Event card template','channel_card_template'=>'Channel card template'] as $key=>$label) {
            echo '<tr><th>'.esc_html($label).'</th><td><select name="ewp_settings['.$key.']">';
            foreach (self::templateOptions() as $id=>$title) echo '<option value="'.esc_attr($id).'" '.selected($s[$key],$id,false).'>'.esc_html($title).'</option>';
            echo '</select></td></tr>';
        }
        foreach (['event'=>'events','channel'=>'channels'] as $kind=>$endpoint) {
            $r = EWP_API::get($endpoint,['per_page'=>100]);
            echo '<tr><th>'.ucfirst($kind).' template preview</th><td><select name="ewp_settings[preview_'.$kind.']"><option value="">First public record</option>';
            if (!is_wp_error($r)) foreach ($r['data'] as $item) { $id = $kind==='event' ? $item['id'] : $item['identifier']; echo '<option value="'.esc_attr($id).'" '.selected($s['preview_'.$kind],$id,false).'>'.esc_html($item['name']).'</option>'; }
            echo '</select></td></tr>';
        }
        echo '<tr><th>Unavailable message</th><td><input class="large-text" name="ewp_settings[unavailable]" value="'.esc_attr($s['unavailable']).'"></td></tr></table>';
        submit_button(); echo '</form><h2>Connection diagnostics</h2>';
        $check = EWP_API::get('channels',['per_page'=>1]);
        echo '<p>'.esc_html(is_wp_error($check) ? $check->get_error_message() : 'Connected to API v1. Public channels: '.$check['meta']['total'].'. Cached responses expire within two minutes.').'</p>';
        echo '<form action="'.esc_url(admin_url('admin-post.php')).'" method="post"><input type="hidden" name="action" value="ewp_clear">'; wp_nonce_field('ewp_clear'); submit_button('Clear API cache','secondary'); echo '</form></div>';
    }
}
