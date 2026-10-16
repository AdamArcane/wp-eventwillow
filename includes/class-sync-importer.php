<?php
defined('ABSPATH') || exit;
/** Native WordPress projection of the saved n8n adapter's public fields. */
final class EventWillow_Sync_Importer {
    private array $settings;
    public string $source;
    public string $scope;
    public function __construct(array $settings) {
        $this->settings = $settings;
        $this->source = hash('sha256', rtrim($settings['url'], '/'));
        $channels = $settings['channels']; sort($channels);
        $this->scope = hash('sha256', $this->source . wp_json_encode($channels));
    }
    private function result($value) {
        if (is_wp_error($value)) throw new RuntimeException('WordPress could not save an imported record.');
        return $value;
    }
    private function image(string $url): int {
        if (!$url) return 0;
        $ids = get_posts(['post_type'=>'attachment','post_status'=>'inherit','fields'=>'ids','posts_per_page'=>1,'meta_key'=>'_eventwillow_image_url','meta_value'=>$url]);
        if ($ids) return (int)$ids[0];
        // Reuse images already imported by the external adapter.
        $posts = get_posts(['post_type'=>['ew_event','ew_channel'],'post_status'=>'any','posts_per_page'=>1,'meta_key'=>'ew_image_source','meta_value'=>$url]);
        if ($posts && ($id = get_post_thumbnail_id($posts[0]->ID)) && get_post($id)) return (int)$id;
        $headers=get_posts(['post_type'=>'ew_channel','post_status'=>'any','posts_per_page'=>1,'meta_key'=>'ew_header_image_url','meta_value'=>$url]);
        if ($headers && ($id=(int)get_post_meta($headers[0]->ID,'ew_header_image_id',true)) && get_post($id)) return $id;
        if (wp_parse_url($url, PHP_URL_SCHEME) !== 'https') throw new RuntimeException('Artwork must use HTTPS.');
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';
        $tmp = wp_tempnam('eventwillow-image');
        if (!$tmp) throw new RuntimeException('Unable to allocate an artwork temporary file.');
        try {
            // Never send the source key to an artwork host or follow redirects.
            $r = EventWillow_Sync::request($url, ['timeout'=>15,'redirection'=>0,'stream'=>true,'filename'=>$tmp,'limit_response_size'=>10*1024*1024]);
            if (is_wp_error($r) || wp_remote_retrieve_response_code($r) !== 200 || filesize($tmp) >= 10*1024*1024) throw new RuntimeException('Artwork download failed or exceeded 10 MB.');
            $mime = wp_get_image_mime($tmp);
            $ext = ['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp'][$mime] ?? null;
            if (!$ext) throw new RuntimeException('Artwork is not a supported PNG, JPEG or WebP image.');
            $id = (int)$this->result(media_handle_sideload(['name'=>'eventwillow-'.hash('sha256',$url).'.'.$ext,'tmp_name'=>$tmp], 0));
            update_post_meta($id, '_eventwillow_image_url', $url);
            return $id;
        } finally { if (file_exists($tmp)) unlink($tmp); }
    }
    public function upsert(array $item, string $kind, array $channel_map): array {
        EventWillow_Sync_Source::validate_location($item);
        $legacy = $kind . ':' . ($kind === 'channel' ? $item['id'] : $item['key']);
        $key = $this->source . ':' . $legacy;
        $type = 'ew_' . $kind;
        $ids = get_posts(['post_type'=>$type,'post_status'=>['publish','draft','pending','private','future','trash'],'fields'=>'ids','posts_per_page'=>2,'meta_key'=>'ew_source_key','meta_value'=>$key]);
        if (count($ids)>1) throw new RuntimeException('Duplicate target source identities; resolve them before syncing.');
        if (!$ids && $this->settings['adopt']) {
            $ids = get_posts(['post_type'=>$type,'post_status'=>['publish','draft'],'fields'=>'ids','posts_per_page'=>2,'meta_key'=>'ew_source_key','meta_value'=>$legacy]);
            if (count($ids)>1) throw new RuntimeException('Duplicate legacy source identities.');
            if ($ids && get_post_meta($ids[0], '_eventwillow_source', true) && get_post_meta($ids[0], '_eventwillow_source', true)!==$this->source) throw new RuntimeException('Legacy record belongs to another source.');
        }
        $id = (int)($ids[0] ?? 0);
        $location = $item['location'] ?? [];
        $meta = ['ew_source_id'=>$item['id'],'ew_source_key'=>$key,'ew_canonical_url'=>$item['canonical_url']??'',
            'ew_timezone'=>$item['timezone']??'','ew_identifier'=>$item['identifier']??$item['slug']??'',
            'ew_image_source'=>$item['image']??'', 'ew_sub_schedules_json'=>wp_json_encode($item['groups']??[]),
            'ew_actions_json'=>wp_json_encode($item['actions']??[])];
        foreach (['city','state','venue','address','postal','country'] as $field) $meta['ew_'.$field] = $location[$field]??'';
        $meta['ew_venue_source_id'] = $location['venue_id']??'';
        // An unresolved/removed location clears old coordinates, including after an address change.
        $located=isset($location['latitude'],$location['longitude']) && ($kind!=='event' || ($item['format']??'')!=='online');
        $meta['ew_latitude']=$located?(string)$location['latitude']:'';
        $meta['ew_longitude']=$located?(string)$location['longitude']:'';
        $meta['ew_geocode_status']=$kind==='event' && ($item['format']??'')==='online'?'online':($location['geocode_status']??'unavailable');
        if ($kind==='channel') {
            foreach (['email','phone'] as $field) $meta['ew_'.$field] = $item['contact'][$field]??'';
            $meta += ['ew_website'=>$item['website']??'','ew_channel_type'=>$item['type']??'','ew_accent_color'=>$item['accent_color']??'',
                'ew_header_image_url'=>$item['header_image']??'','ew_header_image_id'=>$this->image($item['header_image']??''),
                'ew_social_links_json'=>wp_json_encode($item['social_links']??[]),
                'ew_show_subscribe_panel'=>!empty($item['show_subscribe_panel'])?'1':'0',
                'ew_account_followers_count'=>(string)max(0,(int)($item['account_followers_count']??0)),
                'ew_confirmed_email_subscribers_count'=>(string)max(0,(int)($item['confirmed_email_subscribers_count']??0))];
            foreach (['facebook','instagram','youtube','tiktok','linkedin','x','bluesky'] as $platform) {
                $meta['ew_'.$platform.'_url']='';
                foreach ($item['social_links']??[] as $link) if (($link['platform']??'')===$platform || ($platform==='x' && ($link['platform']??'')==='twitter')) $meta['ew_'.$platform.'_url']=$link['url']??'';
            }
        } else {
            $related = [];
            foreach ($item['channels'] as $c) {
                if (empty($channel_map[$c['id']])) throw new RuntimeException('Channels must be imported before events.');
                $related[] = (int)$channel_map[$c['id']];
            }
            $price=$item['price']??null;
            $meta += ['ew_start_utc'=>$item['starts_at'],'ew_end_utc'=>$item['ends_at'],'ew_occurrence_date'=>$item['occurrence_date'],
                'ew_date_label'=>(new DateTimeImmutable($item['starts_at']))->setTimezone(new DateTimeZone($item['timezone']))->format('D, M j, Y · g:i A T'),
                'ew_price_label'=>$price ? (!empty($price['free'])?'Free tickets':(!empty($price['from'])?'From ':'').($price['currency']??'').' '.number_format((float)($price['min']??0),2,'.','')) : (($item['registration']??'')==='rsvp'?'Free registration':''),
                'ew_channel_post_ids'=>$related,'ew_registration_url'=>$item['registration_url']??'',
                'ew_recurrence'=>$item['recurrence']['label']??'','ew_source_created_at'=>$item['created_at']??'',
                'ew_custom_fields_json'=>wp_json_encode($item['custom_fields']??(object)[]),'ew_price_json'=>wp_json_encode($price),
                'ew_ticket_url'=>'','ew_follow_url'=>''];
            foreach ($item['actions']??[] as $a) {
                if (($a['type']??'')==='tickets') $meta['ew_ticket_url']=$a['url']??'';
                if (($a['type']??'')==='follow') $meta['ew_follow_url']=$a['url']??'';
            }
        }
        // Compare the same sanitized representation WordPress persists (not escaped JSON).
        foreach ($meta as $k=>$v) $meta[$k]=sanitize_meta($k,$v,'post',$type);
        $terms = ['ew_location'=>implode(', ',array_filter([$location['city']??'',$location['state']??'',$location['country']??'']))];
        if ($kind==='event') foreach (['category','format','cost','registration'] as $t) $terms['ew_'.$t]=$item[$t]??'';
        $tax = [];
        foreach ($terms as $taxonomy=>$name) {
            $tax[$taxonomy]=[];
            if ($name!=='') {
                $term=term_exists($name,$taxonomy);
                if (!$term) $term=$this->result(wp_insert_term($name,$taxonomy));
                $tax[$taxonomy]=[(int)(is_array($term)?$term['term_id']:$term)];
            }
        }
        $image = $this->image($item['image']??'');
        // EventWillow renders Markdown and sanitizes it at the source. Preserve that HTML in
        // WordPress so the native editor and Elementor's Post Content widget can render it.
        // Older API payloads still receive the previous plain-text fallback.
        $description_html=is_string($item['description_html']??null)?$item['description_html']:'';
        $post = ['post_type'=>$type,'post_status'=>'publish','post_title'=>sanitize_text_field($item['name']),
            'post_content'=>$description_html!==''?wp_kses_post($description_html):wpautop(esc_html($item['description']??'')),'post_excerpt'=>sanitize_textarea_field($item['summary']??'')];
        // Compare the actual projection, including local edits, instead of trusting a stale source hash.
        $same = $id && get_post($id);
        if ($same) {
            $old=get_post($id);
            foreach ($post as $k=>$v) if ($old->$k!==$v) $same=false;
            foreach ($meta as $k=>$v) if (get_post_meta($id,$k,true)!=(is_array($v)?$v:(string)$v)) $same=false;
            if ((int)get_post_thumbnail_id($id)!==$image) $same=false;
            foreach ($tax as $t=>$values) {
                $existing=$this->result(wp_get_object_terms($id,$t,['fields'=>'ids'])); sort($existing); sort($values);
                if ($existing!==$values) $same=false;
            }
        }
        if (!$same) {
            if ($id) $post['ID']=$id;
            $id=(int)$this->result(wp_insert_post(wp_slash($post),true));
            foreach ($meta as $k=>$v) update_post_meta($id,$k,wp_slash($v));
            foreach ($tax as $t=>$values) $this->result(wp_set_object_terms($id,$values,$t));
            if ($image) set_post_thumbnail($id,$image); else delete_post_thumbnail($id);
            update_post_meta($id,'ew_synced_at',gmdate('c'));
        }
        if ($kind==='event') eventwillow_sync_project_custom_fields($id);
        update_post_meta($id,'_eventwillow_source',$this->source);
        update_post_meta($id,'_eventwillow_scope',$this->scope);
        return ['id'=>$id,'key'=>$key,'result'=>$same?'unchanged':($ids?'updated':'created')];
    }
}
