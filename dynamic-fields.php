<?php
if(!defined('ABSPATH'))exit;
function eventwillow_sync_context_id($kind) {
    $id=get_the_ID();
    if(get_post_type($id)==='elementor_library'){
        $settings=get_post_meta($id,'_elementor_page_settings',true);
        $id=(int)($settings['preview_id']??0);
    }
    if($kind==='event')return get_post_type($id)==='ew_event' ? $id : 0;
    if(get_post_type($id)==='ew_channel')return $id;
    if(get_post_type($id)!=='ew_event')return 0;
    foreach((array)get_post_meta($id,'ew_channel_post_ids',true) as $related){
        if(get_post_type($related)==='ew_channel' && get_post_status($related)==='publish')return (int)$related;
    }
    return 0;
}
function eventwillow_sync_tag_definitions() {
    $defs=[];
    $add=function($kind,$name,$label,$key,$format='text',$native=false)use(&$defs){$defs['ew-'.$kind.'-'.$name]=compact('kind','label','key','format','native');};
    foreach(['event','channel'] as $kind){
        foreach(['title'=>'Title','content'=>'Description','excerpt'=>'Summary','permalink'=>'WordPress URL','image'=>'Image'] as $key=>$label)$add($kind,$key,$label,$key,$key==='image'?'image':($key==='permalink'?'url':($key==='content'?'html':'text')),true);
        foreach(['canonical_url'=>'Source URL','timezone'=>'Timezone','city'=>'City','state'=>'State','venue'=>'Venue name','address'=>'Street address','postal'=>'Postal code','country'=>'Country'] as $key=>$label)$add($kind,$key,$label,'ew_'.$key,$key==='canonical_url'?'url':'text');
    }
    foreach(['start_utc'=>'Start date/time','end_utc'=>'End date/time','occurrence_date'=>'Occurrence date','date_label'=>'Formatted date/time','price_label'=>'Price','registration_url'=>'Registration URL','ticket_url'=>'Ticket URL','follow_url'=>'Follow channel URL','recurrence'=>'Recurrence'] as $key=>$label)$add('event',$key,$label,'ew_'.$key,str_ends_with($key,'_url')?'url':'text');
    foreach(['start_utc','end_utc','occurrence_date','date_label'] as $key)$defs['ew-event-'.$key]['format']='date';
    foreach(['category'=>'Categories','location'=>'Locations','format'=>'Format','cost'=>'Cost','registration'=>'Registration type'] as $key=>$label)$add('event','taxonomy-'.$key,$label,'ew_'.$key,'terms');
    foreach(['website'=>'Website','channel_type'=>'Channel type','accent_color'=>'Accent color','email'=>'Public email','phone'=>'Public phone','facebook_url'=>'Facebook URL','instagram_url'=>'Instagram URL','youtube_url'=>'YouTube URL','tiktok_url'=>'TikTok URL','linkedin_url'=>'LinkedIn URL','x_url'=>'X URL','bluesky_url'=>'Bluesky URL'] as $key=>$label)$add('channel',$key,$label,'ew_'.$key,$key==='accent_color'?'color':(($key==='website'||str_ends_with($key,'_url'))?'url':'text'));
    $add('channel','header-image','Header image','ew_header_image_id','image');
    $add('channel','taxonomy-location','Locations','ew_location','terms');
    foreach(get_option('ew_public_fields',[]) as $key=>$field)$add('event','custom-'.substr(hash('sha256',$key),0,16),$field['label'],$key);
    return $defs;
}
trait EventWillow_Tag_Definition {
    protected function definition(){return $GLOBALS['eventwillow_tag_definitions'][static::DEFINITION];}
    public function get_name(){return static::DEFINITION;}
    public function get_title(){return $this->definition()['label'];}
    public function get_group(){return $this->definition()['kind']==='event'?'ew-events':'ew-channels';}
    protected function value(){
        $d=$this->definition();$id=eventwillow_sync_context_id($d['kind']);if(!$id)return '';
        if($d['native'])return match($d['key']){'title'=>get_post_field('post_title',$id),'content'=>get_post_field('post_content',$id),'excerpt'=>get_post_field('post_excerpt',$id),'permalink'=>get_permalink($id),'image'=>get_post_thumbnail_id($id),default=>''};
        if($d['format']==='terms'){$names=wp_get_object_terms($id,$d['key'],['fields'=>'names']);return is_wp_error($names)?'':implode(', ',$names);}
        return get_post_meta($id,$d['key'],true);
    }
}
class EventWillow_Text_Tag extends \Elementor\Core\DynamicTags\Tag {
    use EventWillow_Tag_Definition;
    public function get_categories(){return match($this->definition()['format']){'url'=>['url'],'color'=>['color','text'],default=>['text']};}
    public function render(){
        $value=(string)$this->value();
        echo $this->definition()['format']==='html'?wp_kses_post($value):($this->definition()['format']==='url'?$value:esc_html($value));
    }
}
class EventWillow_Image_Tag extends \Elementor\Core\DynamicTags\Data_Tag {
    use EventWillow_Tag_Definition;
    public function get_categories(){return ['image'];}
    protected function get_value(array $options=[]){$id=(int)$this->value();return ['id'=>$id,'url'=>$id?(wp_get_attachment_image_url($id,'full')?:''):''];}
}
class EventWillow_Date_Tag extends EventWillow_Text_Tag {
    protected function register_controls(){
        $this->add_control('date_format',[
            'label'=>'Format','type'=>\Elementor\Controls_Manager::SELECT,'default'=>'default',
            'options'=>[
                'default'=>'Default',
                'l, F j, Y g:i A'=>'Sunday, October 18, 2026 6:00 PM',
                'D, M j, Y g:i A T'=>'Sun, Oct 18, 2026 6:00 PM EDT',
                'F j, Y'=>'October 18, 2026',
                'M j, Y'=>'Oct 18, 2026',
                'Y-m-d'=>'2026-10-18',
                'm/d/Y'=>'10/18/2026',
                'd/m/Y'=>'18/10/2026',
                'g:i A'=>'6:00 PM',
                'H:i'=>'18:00',
                'c'=>'ISO 8601',
                'custom'=>'Custom',
            ],
            'description'=>'Displayed in the event’s schedule timezone.',
        ]);
        $this->add_control('custom_date_format',[
            'label'=>'Custom format','type'=>\Elementor\Controls_Manager::TEXT,
            'default'=>'M j, Y \\a\\t g:i A',
            'description'=>'WordPress/PHP date format. Example: M j, Y \\a\\t g:i A. Escape literal letters with a backslash.',
            'condition'=>['date_format'=>'custom'],
        ]);
    }
    public function render(){
        $id=eventwillow_sync_context_id('event');if(!$id)return;
        $key=$this->definition()['key'];
        $raw=(string)get_post_meta($id,$key==='ew_date_label'?'ew_start_utc':$key,true);
        if($raw==='')return;
        try{$zone=new \DateTimeZone(get_post_meta($id,'ew_timezone',true)?:wp_timezone_string());}
        catch(\Exception $e){$zone=wp_timezone();}
        if($key==='ew_occurrence_date'){
            $date=\DateTimeImmutable::createFromFormat('!Y-m-d',$raw,$zone);
            if(!$date||$date->format('Y-m-d')!==$raw)return;
        }else{
            if(!preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:?\d{2})$/',$raw))return;
            try{$date=new \DateTimeImmutable($raw);if(\DateTimeImmutable::getLastErrors()!==false)return;}
            catch(\Exception $e){return;}
        }
        $format=$this->get_settings('date_format')?:'default';
        if($format==='default'&&$key==='ew_date_label'){
            echo esc_html(get_post_meta($id,'ew_date_label',true));return;
        }
        if($format==='custom')$format=(string)$this->get_settings('custom_date_format');
        if($format==='default'||$format==='')$format=get_option('date_format','F j, Y').($key==='ew_occurrence_date'?'':' '.get_option('time_format','g:i a'));
        echo esc_html(wp_date($format,$date->getTimestamp(),$zone));
    }
}
function eventwillow_sync_register_named_tags($manager){
    $manager->register_group('ew-events',['title'=>eventwillow_sync_brand().' Events']);
    $manager->register_group('ew-channels',['title'=>eventwillow_sync_brand().' Channels']);
    $GLOBALS['eventwillow_tag_definitions']=eventwillow_sync_tag_definitions();
    foreach($GLOBALS['eventwillow_tag_definitions'] as $name=>$definition){
        // Elementor recreates tags by class name. Give every field an isolated class.
        // Only internally generated hexadecimal IDs and fixed PHP identifiers enter code;
        // field labels, keys, and source values remain data in the definition table.
        $hash=hash('sha256',$name);$class='EventWillow_Field_'.$hash;
        $base=match($definition['format']){'image'=>'EventWillow_Image_Tag','date'=>'EventWillow_Date_Tag',default=>'EventWillow_Text_Tag'};
        if(!class_exists($class,false))eval('class '.$class.' extends '.$base.' { const DEFINITION = '.var_export($name,true).'; }');
        $manager->register(new $class(['id'=>substr($hash,0,8),'settings'=>[]]));
    }
}
