<?php
if (!defined('ABSPATH')) exit;
final class EWP_Views {
    public static ?array $context = null;
    public static bool $renderingRecordStyles = false;
    private static array $rendering = [];
    private static array $templateAssets = [];
    private static array $dynamicTemplateStyles = [];
    private static int $templateInstance = 0;
    public static function assets(): void { wp_enqueue_style('ewp'); wp_enqueue_script('ewp'); }
    public static function url(array $item, string $kind = 'event'): string {
        $base = $kind === 'channel' ? 'channels' : 'events';
        $url = home_url('/'.$base.'/'.rawurlencode($item['id']).'/'.rawurlencode($item['slug'] ?: 'view').'/');
        return $kind === 'event' && !empty($item['occurrence_date']) ? add_query_arg('date',$item['occurrence_date'],$url) : $url;
    }
    public static function message($error): string {
        $text = $error->get_error_code() === 'ewp_missing' ? 'This content is no longer available.' : EWP_API::settings()['unavailable'];
        return '<div class="ewp ewp-message" role="status">'.esc_html($text).'</div>';
    }
    public static function template(int $id, array $item): string {
        if (!$id || !class_exists('Elementor\\Plugin') || isset(self::$rendering[$id]) || get_post_type($id)!=='elementor_library' || get_post_status($id)!=='publish') return '';
        self::$rendering[$id] = true; $previous = self::$context; self::$context = $item;
        try {
            // Templates rendered outside the queried post need Elementor's full style pipeline,
            // including atomic local/global classes, not only the legacy post CSS file.
            if (!isset(self::$templateAssets[$id])) {
                do_action('elementor/post/render', $id);
                do_action('elementor/frontend/after_enqueue_post_styles');
                self::$templateAssets[$id] = true;
            }
            $html = \Elementor\Plugin::$instance->frontend->get_builder_content_for_display($id, true);
            $renderer = '\\Elementor\\Modules\\AtomicWidgets\\Styles\\Styles_Renderer';
            if (!class_exists($renderer)) return $html;
            if (!isset(self::$dynamicTemplateStyles[$id])) {
                $styles = [];
                $hasRecordTag = static function ($value) use (&$hasRecordTag): bool {
                    if (!is_array($value)) return false;
                    if (($value['$$type'] ?? '') === 'dynamic' && str_starts_with((string) ($value['value']['name'] ?? ''), 'ewp-')) return true;
                    foreach ($value as $child) if ($hasRecordTag($child)) return true;
                    return false;
                };
                $collect = static function (array $elements) use (&$collect, &$styles, $hasRecordTag): void {
                    foreach ($elements as $element) {
                        foreach ($element['styles'] ?? [] as $style) {
                            $style['variants'] = array_values(array_filter($style['variants'] ?? [], static fn ($variant) => $hasRecordTag($variant['props'] ?? [])));
                            if ($style['variants']) $styles[] = $style;
                        }
                        $collect($element['elements'] ?? []);
                    }
                };
                $collect(json_decode((string) get_post_meta($id, '_elementor_data', true), true) ?: []);
                self::$dynamicTemplateStyles[$id] = $styles;
            }
            if (!self::$dynamicTemplateStyles[$id]) return $html;
            // Shared Elementor CSS is cached per template; resolve record-dependent styles
            // separately so repeated cards cannot inherit the first event's background image.
            $instance = 'ewp-template-'.(++self::$templateInstance);
            $previousStyleMode = self::$renderingRecordStyles;
            self::$renderingRecordStyles = true;
            try { $css = $renderer::make(\Elementor\Plugin::$instance->breakpoints->get_breakpoints_config(), '[data-ewp-template-instance="'.$instance.'"] .elementor')->render(self::$dynamicTemplateStyles[$id]); }
            finally { self::$renderingRecordStyles = $previousStyleMode; }
            return '<div data-ewp-template-instance="'.$instance.'" style="display:contents"><style>'.str_ireplace('</style', '<\\/style', $css).'</style>'.$html.'</div>';
        }
        finally { self::$context = $previous; unset(self::$rendering[$id]); }
    }
    public static function preview(string $kind = 'event'): ?array {
        if (self::$context) {
            // Channel fields on an event use its public association, never an editor fixture.
            return $kind === 'channel' && !isset(self::$context['identifier'])
                ? (self::$context['channels'][0] ?? [])
                : self::$context;
        }
        if (!current_user_can('edit_posts')) return null;
        $s = EWP_API::settings(); $endpoint = $kind === 'event' ? 'events' : 'channels';
        $id = $s['preview_'.$kind];
        $response = EWP_API::get($id ? $endpoint.'/'.$id : $endpoint, $id ? [] : ['per_page'=>1]);
        return is_wp_error($response) ? null : ($id ? $response['data'] : ($response['data'][0] ?? null));
    }
    public static function value(string $field, ?array $item = null, string $dateFormat = ''): string {
        $item ??= self::$context ?? [];
        if (!$item) return '';
        $channelFields = ['channel_short_description'=>'summary','channel_description'=>'description','channel_accent_color'=>'accent_color','type'=>'type'];
        if (isset($channelFields[$field])) {
            $channel = isset($item['identifier']) ? $item : ($item['channels'][0] ?? []);
            $value = $channel[$channelFields[$field]] ?? '';
            return is_scalar($value) ? (string) $value : '';
        }
        if ($field === 'channel_url') {
            $channel = isset($item['identifier']) ? $item : ($item['channels'][0] ?? null);
            return is_array($channel) && !empty($channel['id']) ? self::url($channel, 'channel') : '';
        }
        if ($field === 'channel_name') {
            if (isset($item['identifier'])) return (string) ($item['name'] ?? '');
            return implode(', ', array_filter(array_map(static fn ($channel) => (string) ($channel['name'] ?? ''), $item['channels'] ?? []), static fn ($name) => $name !== ''));
        }
        if ($field === 'date') return !empty($item['local_starts_at']) ? (new DateTimeImmutable($item['local_starts_at']))->setTimezone(new DateTimeZone($item['timezone'] ?? 'UTC'))->format($dateFormat !== '' ? $dateFormat : 'D, M j, Y · g:i A T') : '';
        if ($field === 'price_label') { $p = $item['price'] ?? null; return $p ? ($p['free'] ? 'Free tickets' : ($p['from'] ? 'From ' : '').$p['currency'].' '.number_format((float)$p['min'],2)) : (($item['registration'] ?? '')==='rsvp' ? 'Free registration' : ''); }
        if ($field === 'local_url') return self::url($item,isset($item['occurrence_date'])?'event':'channel');
        $value = $item;
        foreach (explode('.',$field) as $part) $value = is_array($value) ? ($value[$part] ?? '') : '';
        return is_scalar($value) ? (string)$value : (is_array($value) && count(array_filter($value,'is_scalar'))===count($value) ? implode(', ', $value) : '');
    }
    public static function actions(?array $item = null, array $labels = []): string {
        $item ??= self::$context ?? [];
        $html = '<div class="ewp-actions">';
        foreach ($item['actions'] ?? [] as $action) {
            $label=trim((string)($labels[$action['type']] ?? ''));
            $html .= '<a class="ewp-button" href="'.esc_url($action['url']).'">'.esc_html($label !== '' ? $label : $action['label']).'</a>';
        }
        return $html.'</div>';
    }
    public static function card(array $item, string $kind, int $template = 0, bool $showImages = true, array $options = []): string {
        if (!$showImages) { $item['image']=''; $item['header_image']=''; }
        $template = $template ?: (int)EWP_API::settings()[$kind==='event'?'card_template':'channel_card_template'];
        $custom = $template < 0 ? '' : self::template($template,$item);
        if ($custom !== '') return '<article class="ewp-card ewp-card-custom">'.$custom.'</article>';
        $show=fn($key)=>!array_key_exists('show_'.$key,$options) || (bool)$options['show_'.$key];
        $url=self::url($item,$kind);$html='<article class="ewp-card ewp-card-default">';
        if (!empty($item['image'])) $html.='<a class="ewp-card-image" tabindex="-1" aria-hidden="true" href="'.esc_url($url).'"><img loading="lazy" src="'.esc_url($item['image']).'" alt=""></a>';
        $html.='<div class="ewp-card-content">';
        if ($show('category') && !empty($item['category'])) $html.='<div class="ewp-card-category"><span class="ewp-category">'.esc_html($item['category']).'</span></div>';
        if ($show('title')) $html.='<h3 class="ewp-card-title"><a href="'.esc_url($url).'">'.esc_html($item['name']).'</a></h3>';
        if ($show('date') && $kind==='event') $html.='<p class="ewp-date">'.esc_html(self::value('date',$item)).'</p>';
        if ($show('summary')) $html.='<p class="ewp-card-summary">'.esc_html(wp_trim_words($item['summary'] ?: $item['description'],30)).'</p>';
        $location=implode(' · ',array_filter([$item['location']['venue'] ?? '',$item['location']['city'] ?? '']));
        if ($show('location') && $location) $html.='<p class="ewp-location">'.esc_html($location).'</p>';
        if ($show('channels') && !empty($item['channels'])) {
            $html.='<div class="ewp-card-channels">';
            foreach ($item['channels'] as $channel) $html.='<a class="ewp-channel-link" href="'.esc_url(self::url($channel,'channel')).'">'.esc_html($channel['name']).'</a> ';
            $html.='</div>';
        }
        $price=self::value('price_label',$item);if ($show('price') && $price) $html.='<p class="ewp-card-price">'.esc_html($price).'</p>';
        if ($show('button')) $html.='<a class="ewp-card-more" aria-label="'.esc_attr('View '.$item['name']).'" href="'.esc_url($url).'">'.esc_html($options['button_text'] ?? ('View '.($kind==='event'?'event':'channel').' →')).'</a>';
        return $html.'</div></article>';
    }
    public static function input(string $prefix): array {
        $in = [];
        foreach (['q','country','state','city','category','venue','schedule','group','timezone','postal','period','from','to','sort','format','cost','registration','repeat','page','view'] as $key) {
            if (isset($_GET[$prefix.$key]) && is_scalar($_GET[$prefix.$key])) $in[$key] = sanitize_text_field(wp_unslash($_GET[$prefix.$key]));
        }
        foreach ($_GET as $key=>$value) if (preg_match('/^'.preg_quote($prefix,'/').'(field_[a-f0-9]{12})$/D',$key,$m) && is_scalar($value)) $in['custom'][$m[1]] = sanitize_text_field(wp_unslash($value));
        return $in;
    }
    public static function listing(string $endpoint = 'events', array $options = []): string {
        self::assets(); EWP_Plugin::noCache();
        $prefix = $options['prefix'] ?? 'ew_'; $in = self::input($prefix);
        $kind = $endpoint === 'channels' ? 'channel' : 'event';
        $view = $in['view'] ?? ($options['view'] ?? 'grid');
        if (!in_array($view,['grid','list','calendar'],true) || $kind==='channel' && $view==='calendar') $view='grid';
        $query = array_filter($in, fn($v) => $v !== ''); unset($query['view']);
        $query['per_page'] = max(1,min(100,(int)($options['limit'] ?? 24)));
        if (!empty($options['type'])) { $query['feed'] = $options['type']; $query['mode'] = 'series'; }
        if (!empty($options['channel'])) $endpoint = 'channels/'.sanitize_key($options['channel']).'/events';
        if (!empty($options['current_channel']) && !empty(self::$context['identifier'])) $endpoint = 'channels/'.sanitize_key(self::$context['identifier']).'/events';
        if ($kind==='channel') $query = array_intersect_key($query,array_flip(['q','country','state','city','page','per_page']));
        $month = null;
        if ($view==='calendar') {
            try { $month = new DateTimeImmutable(($in['from'] ?? gmdate('Y-m-01'))); } catch (Exception $e) { $month = new DateTimeImmutable('first day of this month'); }
            $month = $month->modify('first day of this month');
            $query['from'] = $month->format('Y-m-d'); $query['to'] = $month->modify('last day of this month')->format('Y-m-d');
            $query['mode']='occurrences'; $query['per_page']=100; $query['page']=1;
        }
        $response = EWP_API::get($endpoint,$query);
        if (is_wp_error($response)) return self::message($response);
        if ($month) for ($page=2; $page<=($response['meta']['last_page'] ?? 1); $page++) {
            $extra = EWP_API::get($endpoint,array_merge($query,['page'=>$page]));
            if (is_wp_error($extra)) return self::message($extra);
            $response['data'] = array_merge($response['data'],$extra['data']);
        }
        $html = '<section class="ewp ewp-directory" data-ewp-view="'.esc_attr($view).'">';
        if (!empty($options['title'])) $html .= '<h2>'.esc_html($options['title']).'</h2>';
        if (($options['type'] ?? '')==='popular') $html .= '<p>Most viewed over the last 30 days.</p>';
        if (!empty($options['filters'])) $html .= self::filters($prefix,$in,$response['meta'],$kind);
        if ($month) $html .= self::calendar($response['data'],$month,$prefix,$in);
        else {
            if (!$response['data']) $html .= '<p role="status">'.(!empty($options['type']) ? (($options['type']==='popular') ? 'Popular events will appear as people explore what’s on.' : 'More events are on the way. Check back soon.') : 'No '.($kind==='event'?'events':'channels').' match these filters.').'</p>';
            $html .= '<div class="ewp-results ewp-'.esc_attr($view).'">';
            foreach ($response['data'] as $item) $html .= self::card($item,$kind,(int)($options['card_template'] ?? 0),$options['show_images'] ?? true,$options);
            $html .= '</div>';
            if (empty($options['type']) && ($response['meta']['last_page'] ?? 1)>1) {
                $html .= '<nav class="ewp-pagination" aria-label="'.ucfirst($kind).' pages">';
                $page = $response['meta']['page'];
                if ($page>1) $html .= '<a href="'.esc_url(self::queryUrl([$prefix.'page'=>$page-1])).'">← Previous</a>';
                $html .= '<span>Page '.(int)$page.' of '.(int)$response['meta']['last_page'].'</span>';
                if ($page<$response['meta']['last_page']) $html .= '<a href="'.esc_url(self::queryUrl([$prefix.'page'=>$page+1])).'">Next →</a>';
                $html .= '</nav>';
            }
        }
        if (!empty($options['type'])) {
            $sort=['new'=>'newest','popular'=>'popular','upcoming'=>'soonest'][$options['type']] ?? 'soonest';
            $html .= '<a class="ewp-feed-link" href="'.esc_url(add_query_arg('ew_sort',$sort,$options['directory_url'] ?? home_url('/events/'))).'">Browse all events →</a>';
        }
        return $html.'</section>';
    }
    public static function search(string $prefix='ew_', array $options=[]): string {
        self::assets();EWP_Plugin::noCache();
        $url=$options['url'] ?? home_url('/events/');
        $current=get_pagenum_link(1,false);
        if (!$url) $url=$current;
        $query=[];parse_str(wp_parse_url($url,PHP_URL_QUERY) ?? '',$query);
        $same=wp_parse_url($url,PHP_URL_PATH)===wp_parse_url($current,PHP_URL_PATH) && (!wp_parse_url($url,PHP_URL_HOST) || wp_parse_url($url,PHP_URL_HOST)===wp_parse_url($current,PHP_URL_HOST));
        if ($same) $query=array_merge($query,wp_unslash($_GET));
        $label=$options['label'] ?? 'Search events';
        $input=self::input($prefix);$id=wp_unique_id('ewp-search-');
        $html='<div class="ewp"><form method="get" action="'.esc_url(strtok($url,'?')).'" class="ewp-search" role="search" aria-label="'.esc_attr($label).'">';
        foreach ($query as $key=>$value) if (is_scalar($value) && !in_array($key,[$prefix.'q',$prefix.'page'],true)) $html.='<input type="hidden" name="'.esc_attr($key).'" value="'.esc_attr($value).'">';
        $html.='<input type="hidden" name="'.esc_attr($prefix.'page').'" value="1"><label for="'.esc_attr($id).'"'.(empty($options['show_label']) ? ' class="ewp-sr-only"' : '').'>'.esc_html($label).'</label>';
        $html.='<div class="ewp-search-row"><input id="'.esc_attr($id).'" type="search" name="'.esc_attr($prefix.'q').'" value="'.esc_attr($input['q'] ?? ($query[$prefix.'q'] ?? '')).'" placeholder="'.esc_attr($options['placeholder'] ?? 'Search by event, venue or city').'"><button class="ewp-button" type="submit">'.esc_html($options['button_text'] ?? 'Search').'</button></div></form></div>';
        return $html;
    }
    private static function queryUrl(array $replace): string {
        return add_query_arg($replace, remove_query_arg(array_keys($replace)));
    }
    private static function select(string $prefix,string $key,string $label,array $values,array $in): string {
        $html = '<label>'.esc_html($label).'<select name="'.esc_attr($prefix.$key).'"><option value="">All</option>';
        foreach ($values as $value=>$title) $html .= '<option value="'.esc_attr($value).'" '.selected($in[$key] ?? '',$value,false).'>'.esc_html($title).'</option>';
        return $html.'</select></label>';
    }
    public static function filters(string $prefix,array $in,array $meta,string $kind): string {
        if ($kind==='channel') return self::channelFilters($prefix,$in,$meta,$kind);
        $id=wp_unique_id('ewp-advanced-');
        $html='<form method="get" class="ewp-filters ewp-filters-compact" aria-label="Filter events">';
        foreach ($_GET as $key=>$value) if (!str_starts_with($key,$prefix) && is_scalar($value)) $html.='<input type="hidden" name="'.esc_attr($key).'" value="'.esc_attr(wp_unslash($value)).'">';
        $html.='<input type="hidden" name="'.esc_attr($prefix.'page').'" value="1">';
        $html.=self::select($prefix,'cost','Cost',['free'=>'Free','paid'=>'Paid'],$in);
        // Keep Category visible even when the current catalogue has no categorized events.
        $categories=array_column($meta['facets']['category'] ?? [],'label','value');
        if (!empty($in['category']) && !isset($categories[$in['category']])) $categories[$in['category']]=$in['category'];
        $html.=self::select($prefix,'category','Category',$categories,$in);
        $html.=self::select($prefix,'period','Time frame',['today'=>'Today','week'=>'This week','later'=>'Later','upcoming'=>'Upcoming','weekend'=>'This weekend','month'=>'This month','custom'=>'Date range','past'=>'Past'],$in);
        $advancedKeys=['q','country','state','city','venue','schedule','group','timezone','postal','from','to','sort','format','registration','repeat','view'];
        $count=count(array_filter(array_intersect_key($in,array_flip($advancedKeys)),fn($value)=>$value!==''));
        $count+=count(array_filter($in['custom'] ?? [],fn($value)=>$value!==''));
        $html.='<div class="ewp-filter-actions"><button type="button" class="ewp-advanced-open" aria-haspopup="dialog" aria-controls="'.esc_attr($id).'">Advanced filters'.($count?' ('.$count.')':'').'</button><button type="button" class="ewp-reset" data-prefix="'.esc_attr($prefix).'">Clear filters</button><button type="submit" class="ewp-button">Apply filters</button></div>';
        $html.='<dialog id="'.esc_attr($id).'" class="ewp-advanced-panel" aria-labelledby="'.esc_attr($id).'-title"><div class="ewp-panel-header"><h2 id="'.esc_attr($id).'-title">Advanced filters</h2><button type="button" class="ewp-advanced-close" aria-label="Close advanced filters">Close</button></div><div class="ewp-panel-fields">';
        $html.='<label>Search<input type="search" name="'.esc_attr($prefix.'q').'" value="'.esc_attr($in['q'] ?? '').'"></label>';
        foreach (['country','state','city','venue','schedule','group','timezone'] as $key) {
            $values=array_column($meta['facets'][$key] ?? [],'label','value');
            if ($values) $html.=self::select($prefix,$key,$key==='group'?'Sub-schedule':ucfirst($key),$values,$in);
        }
        foreach (['from'=>'From','to'=>'Through'] as $key=>$label) $html.='<label>'.$label.'<input type="date" name="'.esc_attr($prefix.$key).'" value="'.esc_attr($in[$key] ?? '').'"></label>';
        $html.=self::select($prefix,'format','Format',['online'=>'Online','in-person'=>'In person'],$in);
        $html.=self::select($prefix,'registration','Registration',['tickets'=>'Tickets','rsvp'=>'RSVP','external'=>'External','none'=>'None'],$in);
        $html.=self::select($prefix,'repeat','Repeat',['single'=>'One-time','recurring'=>'Recurring'],$in);
        $html.='<label>Postal code<input name="'.esc_attr($prefix.'postal').'" value="'.esc_attr($in['postal'] ?? '').'"></label>';
        $html.=self::select($prefix,'sort','Sort',['soonest'=>'Soonest','latest'=>'Latest','name'=>'Name','newest'=>'Newly created','popular'=>'Popular'],$in);
        foreach ($meta['custom_fields'] ?? [] as $key=>$field) $html.=self::select($prefix,$key,$field['label'],array_combine($field['options'],$field['options']),[$key=>$in['custom'][$key] ?? '']);
        $html.=self::select($prefix,'view','View',['grid'=>'Grid','list'=>'List','calendar'=>'Calendar'],$in);
        return $html.'</div><div class="ewp-panel-actions"><button type="button" class="ewp-advanced-close">Back to events</button><button type="submit" class="ewp-button">Apply filters</button></div></dialog></form>';
    }
    private static function channelFilters(string $prefix,array $in,array $meta,string $kind): string {
        $html = '<form method="get" class="ewp-filters" aria-label="Filter '.($kind==='event'?'events':'channels').'">';
        // Preserve other widgets' independent filter state.
        foreach ($_GET as $key=>$value) if (!str_starts_with($key,$prefix) && is_scalar($value)) $html .= '<input type="hidden" name="'.esc_attr($key).'" value="'.esc_attr(wp_unslash($value)).'">';
        $html .= '<label>Search<input type="search" name="'.esc_attr($prefix.'q').'" value="'.esc_attr($in['q'] ?? '').'"></label>';
        $keys = $kind==='event' ? ['country','state','city','category','venue','schedule','group','timezone'] : ['country','state','city'];
        foreach ($keys as $key) {
            $values = array_column($meta['facets'][$key] ?? [],'label','value');
            if ($kind==='channel') { $html .= '<label>'.ucfirst($key).'<input name="'.esc_attr($prefix.$key).'" value="'.esc_attr($in[$key] ?? '').'"></label>'; }
            elseif ($values) $html .= self::select($prefix,$key,ucfirst($key),$values,$in);
        }
        if ($kind==='event') {
            $html .= self::select($prefix,'period','Dates',['upcoming'=>'Upcoming','today'=>'Today','weekend'=>'This weekend','month'=>'This month','custom'=>'Date range','past'=>'Past'],$in);
            foreach (['from'=>'From','to'=>'Through'] as $key=>$label) $html .= '<label>'.$label.'<input type="date" name="'.esc_attr($prefix.$key).'" value="'.esc_attr($in[$key] ?? '').'"></label>';
            $html .= self::select($prefix,'format','Format',['online'=>'Online','in-person'=>'In person'],$in);
            $html .= self::select($prefix,'cost','Cost',['free'=>'Free','paid'=>'Paid'],$in);
            $html .= self::select($prefix,'registration','Registration',['tickets'=>'Tickets','rsvp'=>'RSVP','external'=>'External','none'=>'None'],$in);
            $html .= self::select($prefix,'repeat','Repeat',['single'=>'One-time','recurring'=>'Recurring'],$in);
            $html .= '<label>Postal code<input name="'.esc_attr($prefix.'postal').'" value="'.esc_attr($in['postal'] ?? '').'"></label>';
            $html .= self::select($prefix,'sort','Sort',['soonest'=>'Soonest','latest'=>'Latest','name'=>'Name','newest'=>'Newly created','popular'=>'Popular'],$in);
            foreach ($meta['custom_fields'] ?? [] as $key=>$field) $html .= self::select($prefix,$key,$field['label'],array_combine($field['options'],$field['options']),[$key=>$in['custom'][$key] ?? '']);
        }
        $html .= self::select($prefix,'view','View',$kind==='event'?['grid'=>'Grid','list'=>'List','calendar'=>'Calendar']:['grid'=>'Grid','list'=>'List'],$in);
        $html .= '<button type="submit" class="ewp-button">Apply filters</button><button type="button" class="ewp-reset" data-prefix="'.esc_attr($prefix).'">Clear filters</button></form>';
        return $html;
    }
    private static function calendar(array $items,DateTimeImmutable $month,string $prefix,array $in): string {
        $html = '<div class="ewp-calendar"><nav aria-label="Calendar months"><a href="'.esc_url(self::queryUrl([$prefix.'from'=>$month->modify('-1 month')->format('Y-m-d'),$prefix.'to'=>$month->modify('-1 month')->modify('last day of this month')->format('Y-m-d'),$prefix.'view'=>'calendar',$prefix.'page'=>1])).'">← Previous month</a><h2>'.$month->format('F Y').'</h2><a href="'.esc_url(self::queryUrl([$prefix.'from'=>$month->modify('+1 month')->format('Y-m-d'),$prefix.'to'=>$month->modify('+1 month')->modify('last day of this month')->format('Y-m-d'),$prefix.'view'=>'calendar',$prefix.'page'=>1])).'">Next month →</a></nav>';
        $html .= '<div class="ewp-calendar-grid">';
        foreach (['Mon','Tue','Wed','Thu','Fri','Sat','Sun'] as $day) $html .= '<div class="ewp-weekday" aria-hidden="true">'.$day.'</div>';
        for ($i=1;$i<(int)$month->format('N');$i++) $html .= '<div class="ewp-calendar-blank" aria-hidden="true"></div>';
        $map=[]; foreach ($items as $item) {
            $start=(new DateTimeImmutable($item['local_starts_at']))->setTime(0,0);
            $end=(new DateTimeImmutable($item['ends_at']))->setTimezone(new DateTimeZone($item['timezone']))->setTime(0,0);
            $first=$start < $month ? $month : $start; $last=$end < $month->modify('last day of this month') ? $end : $month->modify('last day of this month');
            for ($cursor=$first;$cursor<=$last;$cursor=$cursor->modify('+1 day')) $map[$cursor->format('Y-m-d')][]=$item;
        }
        for ($day=1;$day<=(int)$month->format('t');$day++) {
            $date=$month->setDate((int)$month->format('Y'),(int)$month->format('m'),$day)->format('Y-m-d');
            $html .= '<section class="ewp-calendar-day" aria-label="'.esc_attr($date).'"><time datetime="'.$date.'">'.$day.'</time>';
            foreach ($map[$date] ?? [] as $item) $html .= '<a href="'.esc_url(self::url($item)).'">'.esc_html($item['name']).'<small>'.esc_html((new DateTimeImmutable($item['local_starts_at']))->setTimezone(new DateTimeZone($item['timezone'] ?? 'UTC'))->format('g:i A T')).'</small></a>';
            $html .= '</section>';
        }
        return $html.'</div></div>';
    }
    public static function detail(array $item,string $kind): string {
        self::assets();
        $html='<article class="ewp ewp-detail">';
        if (!empty($item['image'])) $html.='<img class="ewp-hero-image" src="'.esc_url($item['image']).'" alt="'.esc_attr($item['name']).'">';
        $html.='<div><h1>'.esc_html($item['name']).'</h1>';
        if ($kind==='event') $html.='<p>'.esc_html(self::value('date',$item)).'</p>';
        $html.='<p>'.esc_html(implode(' · ',array_filter([$item['location']['venue'] ?? '',$item['location']['city'] ?? '',$item['location']['state'] ?? '']))).'</p><div class="ewp-description">'.wpautop(esc_html($item['description'])).'</div>';
        if ($kind==='event') {
            foreach ($item['channels'] as $channel) $html.='<p><a href="'.esc_url(self::url($channel,'channel')).'">'.esc_html($channel['name']).'</a></p>';
            foreach ($item['custom_fields'] as $field) $html.='<p><strong>'.esc_html($field['label']).':</strong> '.esc_html(implode(', ',$field['values'])).'</p>';
            $html.='<p>'.esc_html(self::value('price_label',$item)).'</p>';
        }
        $html.=self::actions($item).'</div></article>';
        if ($kind==='channel') $html.=self::listing('events',['channel'=>$item['identifier'],'filters'=>true,'title'=>'Events']);
        return $html;
    }
}
