<?php
if (!defined('ABSPATH')) exit;
final class EWP_Elementor {
    public static function fields(): array {
        return ['name'=>'Title','summary'=>'Summary','description'=>'Description','date'=>'Date and time','timezone'=>'Timezone','category'=>'Category','channel_name'=>'Channel name','channel_short_description'=>'Channel short description','channel_description'=>'Channel description','channel_accent_color'=>'Channel accent color','price_label'=>'Price',
            'location.venue'=>'Venue','location.address'=>'Address','location.city'=>'City','location.state'=>'State','location.country'=>'Country','location.postal'=>'Postal code','type'=>'Channel type',
            'local_url'=>'WordPress detail URL','channel_url'=>'WordPress channel URL','canonical_url'=>'Standalone URL','website'=>'Channel website','image'=>'Image URL','header_image'=>'Channel header image URL'];
    }
    public static function boot(): void {
        add_action('elementor/theme/register_conditions', function ($manager) { require_once EWP_PATH.'includes/condition.php'; $manager->get_condition('general')->register_sub_condition(new EWP_Public_Condition()); });
        add_action('elementor/elements/categories_registered', fn($manager) => $manager->add_category('eventwillow',['title'=>'EventWillow','icon'=>'eicon-calendar']));
        add_action('elementor/widgets/register', function ($manager) {
            foreach ([EWP_Feed_Widget::class,EWP_Directory_Widget::class,EWP_Channels_Widget::class,EWP_Channel_Events_Widget::class,EWP_Calendar_Widget::class,EWP_Search_Widget::class,EWP_Field_Widget::class,EWP_Card_Image_Widget::class,EWP_Card_Button_Widget::class,EWP_Actions_Widget::class,EWP_Details_Widget::class,EWP_Filters_Widget::class,EWP_Related_Channels_Widget::class] as $class) $manager->register(new $class());
        });
        add_action('elementor/dynamic_tags/register', function ($manager) {
            $manager->register_group('eventwillow',['title'=>'EventWillow']);
            $manager->register(new EWP_Text_Tag()); $manager->register(new EWP_Image_Tag());
            $manager->register(new EWP_Channel_Color_Tag());
        });
    }
    public static function dateFormat(array $settings): string {
        return ($settings['date_format'] ?? '') === 'custom' ? (string) ($settings['custom_date_format'] ?? '') : (string) ($settings['date_format'] ?? '');
    }
    public static function recordControls($element): void {
        $element->add_control('record_kind',['label'=>'Record type','type'=>\Elementor\Controls_Manager::SELECT,'default'=>'event','options'=>['event'=>'Event','channel'=>'Channel']]);
        $element->add_control('field',['label'=>'Field','type'=>\Elementor\Controls_Manager::SELECT,'default'=>'name','options'=>self::fields()]);
        $element->add_control('date_format',['label'=>'Date format','type'=>\Elementor\Controls_Manager::SELECT,'default'=>'','options'=>[''=>'Default (date, time and timezone)','F j, Y'=>'October 10, 2026','D, M j'=>'Sat, Oct 10','g:i a'=>'6:00 pm','M j · g:i a'=>'Oct 10 · 6:00 pm','Y-m-d'=>'2026-10-10','custom'=>'Custom'],'condition'=>['field'=>'date']]);
        $element->add_control('custom_date_format',['label'=>'Custom date format','type'=>\Elementor\Controls_Manager::TEXT,'placeholder'=>'F j, Y · g:i a','description'=>'Uses PHP date format characters and the event timezone. For example F j, Y or g:i a.','condition'=>['field'=>'date','date_format'=>'custom']]);
        $element->add_control('custom_key',['label'=>'Custom field path (optional)','type'=>\Elementor\Controls_Manager::TEXT,'description'=>'For example custom_fields.field_123456789abc.values. Only public API fields are available.']);
    }
}
abstract class EWP_Base_Widget extends \Elementor\Widget_Base {
    public function get_categories() { return ['eventwillow']; }
    public function get_icon() { return 'eicon-calendar'; }
    public function get_style_depends() { return ['ewp']; }
    public function get_script_depends() { return ['ewp']; }
    protected function is_dynamic_content(): bool { return true; }
    protected function styleControls(): void {
        $this->start_controls_section('style',['label'=>'Appearance','tab'=>\Elementor\Controls_Manager::TAB_STYLE]);
        $this->add_control('accent',['label'=>'Accent color','type'=>\Elementor\Controls_Manager::COLOR,'selectors'=>['{{WRAPPER}} .ewp'=>'--ewp-accent: {{VALUE}};']]);
        $this->add_control('text_color',['label'=>'Text color','type'=>\Elementor\Controls_Manager::COLOR,'selectors'=>['{{WRAPPER}}'=>'color: {{VALUE}};','{{WRAPPER}} .ewp'=>'--ewp-text: {{VALUE}};']]);
        $this->add_control('surface',['label'=>'Surface color','type'=>\Elementor\Controls_Manager::COLOR,'selectors'=>['{{WRAPPER}} .ewp'=>'--ewp-surface: {{VALUE}};']]);
        $this->add_control('panel_surface',['label'=>'Advanced panel background','type'=>\Elementor\Controls_Manager::COLOR,'selectors'=>['{{WRAPPER}} .ewp'=>'--ewp-panel-surface: {{VALUE}};']]);
        $this->add_control('panel_text',['label'=>'Advanced panel text','type'=>\Elementor\Controls_Manager::COLOR,'selectors'=>['{{WRAPPER}} .ewp'=>'--ewp-panel-text: {{VALUE}};']]);
        $this->add_group_control(\Elementor\Group_Control_Typography::get_type(),['name'=>'typography','selector'=>'{{WRAPPER}}, {{WRAPPER}} .ewp']);
        $this->add_responsive_control('columns',['label'=>'Grid columns','type'=>\Elementor\Controls_Manager::SELECT,'options'=>['1'=>'1','2'=>'2','3'=>'3','4'=>'4'],'selectors'=>['{{WRAPPER}} .ewp-grid'=>'grid-template-columns:repeat({{VALUE}},minmax(0,1fr));']]);
        $this->add_responsive_control('gap',['label'=>'Gap','type'=>\Elementor\Controls_Manager::SLIDER,'range'=>['px'=>['min'=>0,'max'=>80]],'selectors'=>['{{WRAPPER}} .ewp-results, {{WRAPPER}} .ewp-actions'=>'gap: {{SIZE}}{{UNIT}};']]);
        $this->add_responsive_control('padding',['label'=>'Card padding','type'=>\Elementor\Controls_Manager::DIMENSIONS,'size_units'=>['px','em','%'],'selectors'=>['{{WRAPPER}} .ewp-card-content'=>'padding: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};']]);
        $this->add_control('radius',['label'=>'Corner radius','type'=>\Elementor\Controls_Manager::SLIDER,'selectors'=>['{{WRAPPER}} .ewp-card, {{WRAPPER}} .ewp-button'=>'border-radius: {{SIZE}}{{UNIT}};']]);
        $this->add_responsive_control('image_height',['label'=>'Card image height','type'=>\Elementor\Controls_Manager::SLIDER,'range'=>['px'=>['min'=>50,'max'=>600]],'selectors'=>['{{WRAPPER}} .ewp-card img'=>'height: {{SIZE}}{{UNIT}};']]);
        $this->add_control('image_fit',['label'=>'Card image fit','type'=>\Elementor\Controls_Manager::SELECT,'default'=>'contain','options'=>['contain'=>'Show full flyer','cover'=>'Fill image area (crop)'],'selectors'=>['{{WRAPPER}} .ewp-card img'=>'object-fit: {{VALUE}};']]);
        $this->add_control('calendar_day_color',['label'=>'Calendar date color','type'=>\Elementor\Controls_Manager::COLOR,'selectors'=>['{{WRAPPER}} .ewp-calendar-day time'=>'color: {{VALUE}};']]);
        $this->end_controls_section();
    }
}
abstract class EWP_Listing_Widget extends EWP_Base_Widget {
    protected string $endpoint = 'events';
    protected function register_controls(): void {
        $this->start_controls_section('content',['label'=>'Content']);
        $this->add_control('title',['label'=>'Heading','type'=>\Elementor\Controls_Manager::TEXT]);
        $this->add_control('type',['label'=>'Feed','type'=>\Elementor\Controls_Manager::SELECT,'default'=>'','options'=>[''=>'Directory','upcoming'=>'Upcoming','popular'=>'Popular','new'=>'Newly created']]);
        $this->add_control('view',['label'=>'View','type'=>\Elementor\Controls_Manager::SELECT,'default'=>$this->get_name()==='ewp-calendar'?'calendar':'grid','options'=>['grid'=>'Grid','list'=>'List','calendar'=>'Calendar']]);
        $this->add_control('limit',['label'=>'Items per page','type'=>\Elementor\Controls_Manager::NUMBER,'min'=>1,'max'=>100,'default'=>$this->get_name()==='ewp-feed'?3:24]);
        $this->add_control('filters',['label'=>'Show filters','type'=>\Elementor\Controls_Manager::SWITCHER,'default'=>$this->get_name()==='ewp-feed'?'':'yes']);
        $this->add_control('channel',['label'=>'Channel identifier (optional)','type'=>\Elementor\Controls_Manager::TEXT,'description'=>'Use the standalone channel subdomain.']);
        $this->add_control('current_channel',['label'=>'Use current channel page','type'=>\Elementor\Controls_Manager::SWITCHER,'default'=>$this->get_name()==='ewp-channel-events'?'yes':'']);
        $this->add_control('card_template',['label'=>'Card template','type'=>\Elementor\Controls_Manager::SELECT,'default'=>0,'options'=>[-1=>'Built-in configurable card']+EWP_Plugin::templateOptions(),'description'=>'Use the built-in card controls below, or edit your reusable Elementor card template.']);
        $this->add_control('show_images',['label'=>'Show card images / flyers','type'=>\Elementor\Controls_Manager::SWITCHER,'default'=>'yes','description'=>'Uses the Laravel event flyer or channel image. Custom cards should use the EventWillow Image dynamic tag.']);
        foreach (['category'=>'Category','title'=>'Title','date'=>'Date and time','summary'=>'Summary','location'=>'Location','channels'=>'Channel links','price'=>'Price','button'=>'View button'] as $key=>$label) {
            $this->add_control('show_'.$key,['label'=>'Show '.$label,'type'=>\Elementor\Controls_Manager::SWITCHER,'default'=>'yes','description'=>'Applies to the built-in configurable card.']);
        }
        $this->add_control('button_text',['label'=>'View button text','type'=>\Elementor\Controls_Manager::TEXT,'default'=>'View details']);
        $this->add_control('filter_prefix',['label'=>'Filter URL prefix','type'=>\Elementor\Controls_Manager::TEXT,'description'=>'Optional: use ew_ to share filters with a separate Filters widget. Otherwise each widget has its own filters.']);
        $this->end_controls_section(); $this->styleControls(); $this->cardStyleControls();
    }
    protected function cardStyleControls(): void {
        $this->start_controls_section('card_style',['label'=>'Card layout and sections','tab'=>\Elementor\Controls_Manager::TAB_STYLE]);
        $this->add_responsive_control('image_position',['label'=>'Image position','type'=>\Elementor\Controls_Manager::SELECT,'default'=>'top','options'=>['top'=>'Top','bottom'=>'Bottom','left'=>'Left','right'=>'Right'],'selectors_dictionary'=>['top'=>'column; --ewp-image-width:100%','bottom'=>'column-reverse; --ewp-image-width:100%','left'=>'row; --ewp-image-width:35%','right'=>'row-reverse; --ewp-image-width:35%'],'selectors'=>['{{WRAPPER}} .ewp-card-default'=>'flex-direction: {{VALUE}};']]);
        $this->add_control('card_background',['label'=>'Card background','type'=>\Elementor\Controls_Manager::COLOR,'selectors'=>['{{WRAPPER}} .ewp-card'=>'background-color: {{VALUE}};']]);
        $this->add_control('content_background',['label'=>'Content background','type'=>\Elementor\Controls_Manager::COLOR,'selectors'=>['{{WRAPPER}} .ewp-card-content'=>'background-color: {{VALUE}};']]);
        $this->add_control('button_background',['label'=>'Button color','type'=>\Elementor\Controls_Manager::COLOR,'selectors'=>['{{WRAPPER}} .ewp-card-more'=>'background-color: {{VALUE}};']]);
        $this->add_control('button_color',['label'=>'Button text color','type'=>\Elementor\Controls_Manager::COLOR,'selectors'=>['{{WRAPPER}} .ewp-card-more'=>'color: {{VALUE}};']]);
        $this->add_control('button_hover_background',['label'=>'Button hover color','type'=>\Elementor\Controls_Manager::COLOR,'selectors'=>['{{WRAPPER}} .ewp-card-more:hover, {{WRAPPER}} .ewp-card-more:focus-visible'=>'background-color: {{VALUE}};']]);
        $this->add_responsive_control('section_gap',['label'=>'Gap between card sections','type'=>\Elementor\Controls_Manager::SLIDER,'range'=>['px'=>['min'=>0,'max'=>60]],'selectors'=>['{{WRAPPER}} .ewp-card-content'=>'gap: {{SIZE}}{{UNIT}};']]);
        $this->end_controls_section();
    }
    protected function render(): void {
        $s = $this->get_settings_for_display();
        if ($this->get_name()==='ewp-feed' && empty($s['type'])) $s['type']='upcoming';
        $s['prefix']=!empty($s['filter_prefix']) ? sanitize_key($s['filter_prefix']) : 'ew_'.$this->get_id().'_';
        $s['filters']=!empty($s['filters']); $s['current_channel']=!empty($s['current_channel']);
        $s['show_images']=!empty($s['show_images']);
        foreach (['category','title','date','summary','location','channels','price','button'] as $key) $s['show_'.$key]=!empty($s['show_'.$key]);
        $previous = EWP_Views::$context;
        try {
            if ($s['current_channel'] && !$previous) EWP_Views::$context=EWP_Views::preview('channel');
            echo EWP_Views::listing($this->endpoint,$s);
        } finally { EWP_Views::$context = $previous; }
    }
}
class EWP_Feed_Widget extends EWP_Listing_Widget { public function get_name(){return 'ewp-feed';} public function get_title(){return 'EventWillow Event Feed';} }
class EWP_Directory_Widget extends EWP_Listing_Widget { public function get_name(){return 'ewp-directory';} public function get_title(){return 'EventWillow Event Directory';} }
class EWP_Channels_Widget extends EWP_Listing_Widget { protected string $endpoint='channels'; public function get_name(){return 'ewp-channels';} public function get_title(){return 'EventWillow Channel Directory';} }
class EWP_Channel_Events_Widget extends EWP_Listing_Widget { public function get_name(){return 'ewp-channel-events';} public function get_title(){return 'EventWillow Channel Events';} }
class EWP_Calendar_Widget extends EWP_Listing_Widget { public function get_name(){return 'ewp-calendar';} public function get_title(){return 'EventWillow Calendar';} }
class EWP_Search_Widget extends EWP_Base_Widget {
    public function get_name(){return 'ewp-search';} public function get_title(){return 'EventWillow Search';}
    public function get_icon(){return 'eicon-search';}
    protected function register_controls(): void {
        $this->start_controls_section('content',['label'=>'Search']);
        $this->add_control('results_url',['label'=>'Results page','type'=>\Elementor\Controls_Manager::URL,'default'=>['url'=>home_url('/events/')],'description'=>'Choose the events or channels directory. Leave blank to search a listing on this page.']);
        $this->add_control('filter_prefix',['label'=>'Matching listing URL prefix','type'=>\Elementor\Controls_Manager::TEXT,'default'=>'ew_','description'=>'Match the directory widget’s Filter URL prefix. The standard /events/ and /channels/ pages use ew_.']);
        $this->add_control('label',['label'=>'Input label','type'=>\Elementor\Controls_Manager::TEXT,'default'=>'Search events']);
        $this->add_control('show_label',['label'=>'Show input label','type'=>\Elementor\Controls_Manager::SWITCHER,'default'=>'yes']);
        $this->add_control('placeholder',['label'=>'Placeholder','type'=>\Elementor\Controls_Manager::TEXT,'default'=>'Search by event, venue or city']);
        $this->add_control('button_text',['label'=>'Button text','type'=>\Elementor\Controls_Manager::TEXT,'default'=>'Search']);
        $this->end_controls_section();$this->styleControls();
    }
    protected function render(): void {
        $s=$this->get_settings_for_display();
        echo EWP_Views::search(sanitize_key($s['filter_prefix']) ?: 'ew_',[
            'url'=>$s['results_url']['url'] ?? '', 'label'=>$s['label'] ?: 'Search', 'show_label'=>!empty($s['show_label']),
            'placeholder'=>$s['placeholder'], 'button_text'=>$s['button_text'] ?: 'Search',
        ]);
    }
}
class EWP_Field_Widget extends EWP_Base_Widget {
    public function get_name(){return 'ewp-field';} public function get_title(){return 'EventWillow Field';}
    protected function register_controls(): void {
        $this->start_controls_section('content',['label'=>'Field']); EWP_Elementor::recordControls($this);
        $this->add_control('html_tag',['label'=>'HTML tag','type'=>\Elementor\Controls_Manager::SELECT,'default'=>'div','options'=>array_combine(['div','p','h1','h2','h3','h4','span'],['div','p','h1','h2','h3','h4','span'])]);
        $this->end_controls_section(); $this->styleControls();
    }
    protected function render(): void {
        $s=$this->get_settings_for_display(); $item=EWP_Views::preview($s['record_kind']);
        $tag=in_array($s['html_tag'],['div','p','h1','h2','h3','h4','span'],true)?$s['html_tag']:'div';
        echo '<'.$tag.' class="ewp-field">'.nl2br(esc_html(EWP_Views::value($s['custom_key'] ?: $s['field'],$item,EWP_Elementor::dateFormat($s)))).'</'.$tag.'>';
    }
}
class EWP_Card_Image_Widget extends EWP_Base_Widget {
    public function get_name(){return 'ewp-card-image';}public function get_title(){return 'EventWillow Card Image';}public function get_icon(){return 'eicon-image';}
    protected function register_controls(): void {
        $this->start_controls_section('content',['label'=>'Image']);
        $this->add_control('record_kind',['label'=>'Record type','type'=>\Elementor\Controls_Manager::SELECT,'default'=>'event','options'=>['event'=>'Event','channel'=>'Channel']]);
        $this->add_control('field',['label'=>'Image','type'=>\Elementor\Controls_Manager::SELECT,'default'=>'image','options'=>['image'=>'Main image / flyer','header_image'=>'Channel header']]);
        $this->add_control('link_image',['label'=>'Link to WordPress details','type'=>\Elementor\Controls_Manager::SWITCHER,'default'=>'yes']);
        $this->end_controls_section();
        $this->start_controls_section('image_style',['label'=>'Image','tab'=>\Elementor\Controls_Manager::TAB_STYLE]);
        $this->add_responsive_control('height',['label'=>'Height','type'=>\Elementor\Controls_Manager::SLIDER,'range'=>['px'=>['min'=>50,'max'=>800]],'selectors'=>['{{WRAPPER}} img'=>'height: {{SIZE}}{{UNIT}};']]);
        $this->add_control('fit',['label'=>'Image fit','type'=>\Elementor\Controls_Manager::SELECT,'default'=>'contain','options'=>['contain'=>'Full flyer','cover'=>'Crop to fill'],'selectors'=>['{{WRAPPER}} img'=>'object-fit: {{VALUE}};']]);
        $this->end_controls_section();
    }
    protected function render(): void {
        $s=$this->get_settings_for_display();$item=EWP_Views::preview($s['record_kind']);$url=EWP_Views::value($s['field'],$item);
        if (!$url) return;
        $image='<img class="ewp-component-image" loading="lazy" src="'.esc_url($url).'" alt="'.esc_attr($item['name'] ?? '').'">';
        echo !empty($s['link_image']) ? '<a href="'.esc_url(EWP_Views::value('local_url',$item)).'">'.$image.'</a>' : $image;
    }
}
class EWP_Card_Button_Widget extends EWP_Base_Widget {
    public function get_name(){return 'ewp-card-button';}public function get_title(){return 'EventWillow Card Button';}public function get_icon(){return 'eicon-button';}
    protected function register_controls(): void {
        $this->start_controls_section('content',['label'=>'Button']);
        $this->add_control('record_kind',['label'=>'Record type','type'=>\Elementor\Controls_Manager::SELECT,'default'=>'event','options'=>['event'=>'Event','channel'=>'Channel']]);
        $this->add_control('text',['label'=>'Button text','type'=>\Elementor\Controls_Manager::TEXT,'default'=>'View details']);
        $this->end_controls_section();$this->styleControls();
        $this->start_controls_section('button_style',['label'=>'Button colors','tab'=>\Elementor\Controls_Manager::TAB_STYLE]);
        $this->add_control('background',['label'=>'Background','type'=>\Elementor\Controls_Manager::COLOR,'selectors'=>['{{WRAPPER}} .ewp-button'=>'background: {{VALUE}};']]);
        $this->add_control('color',['label'=>'Text','type'=>\Elementor\Controls_Manager::COLOR,'selectors'=>['{{WRAPPER}} .ewp-button'=>'color: {{VALUE}};']]);
        $this->end_controls_section();
    }
    protected function render(): void {
        $s=$this->get_settings_for_display();$item=EWP_Views::preview($s['record_kind']);if (!$item) return;
        echo '<div class="ewp"><a class="ewp-button" href="'.esc_url(EWP_Views::value('local_url',$item)).'">'.esc_html($s['text']).'</a></div>';
    }
}
class EWP_Actions_Widget extends EWP_Base_Widget {
    public function get_name(){return 'ewp-actions';} public function get_title(){return 'EventWillow Actions';}
    protected function register_controls(): void {
        $this->start_controls_section('content',['label'=>'Actions']);
        $this->add_control('record_kind',['label'=>'Record type','type'=>\Elementor\Controls_Manager::SELECT,'default'=>'event','options'=>['event'=>'Event','channel'=>'Channel']]);
        $this->add_control('action_type',['label'=>'Action','type'=>\Elementor\Controls_Manager::SELECT,'default'=>'','options'=>[''=>'All available','view'=>'Standalone event','tickets'=>'Tickets','rsvp'=>'RSVP','external'=>'Registration','follow'=>'Follow','channel'=>'Standalone channel']]);
        foreach (['view'=>'Standalone event label','tickets'=>'Tickets label','rsvp'=>'RSVP label','external'=>'Registration label','follow'=>'Follow label','channel'=>'Standalone channel label'] as $type=>$label) {
            $this->add_control('label_'.$type,['label'=>$label,'type'=>\Elementor\Controls_Manager::TEXT,'default'=>'','description'=>'Leave blank to use the event or channel default.','condition'=>['action_type'=>['',$type]]]);
        }
        $this->end_controls_section(); $this->styleControls();
    }
    protected function render(): void {
        $s=$this->get_settings_for_display(); $item=EWP_Views::preview($s['record_kind']);
        if (!$item) return;
        if ($s['action_type']) $item['actions']=array_values(array_filter($item['actions'],fn($a)=>$a['type']===$s['action_type']));
        $labels=[];
        foreach (['view','tickets','rsvp','external','follow','channel'] as $type) $labels[$type]=(string)($s['label_'.$type] ?? '');
        echo '<div class="ewp">'.EWP_Views::actions($item,$labels).'</div>';
    }
}
class EWP_Details_Widget extends EWP_Base_Widget {
    public function get_name(){return 'ewp-details';} public function get_title(){return 'EventWillow Details';}
    protected function register_controls(): void {
        $this->start_controls_section('content',['label'=>'Details']);
        $this->add_control('record_kind',['label'=>'Record type','type'=>\Elementor\Controls_Manager::SELECT,'default'=>'event','options'=>['event'=>'Event','channel'=>'Channel']]);
        $this->end_controls_section(); $this->styleControls();
    }
    protected function render(): void { $s=$this->get_settings_for_display(); $item=EWP_Views::preview($s['record_kind']); if ($item) echo EWP_Views::detail($item,$s['record_kind']); }
}
class EWP_Filters_Widget extends EWP_Base_Widget {
    public function get_name(){return 'ewp-filters';} public function get_title(){return 'EventWillow Filters';}
    protected function register_controls(): void {
        $this->start_controls_section('content',['label'=>'Filters']);
        $this->add_control('filter_prefix',['label'=>'Matching directory URL prefix','type'=>\Elementor\Controls_Manager::TEXT,'default'=>'ew_']);
        $this->end_controls_section(); $this->styleControls();
    }
    protected function render(): void {
        $s=$this->get_settings_for_display();$prefix=sanitize_key($s['filter_prefix']) ?: 'ew_';$in=EWP_Views::input($prefix);unset($in['view']);
        $response=EWP_API::get('facets',$in);
        echo is_wp_error($response)?EWP_Views::message($response):'<div class="ewp">'.EWP_Views::filters($prefix,EWP_Views::input($prefix),$response['data'],'event').'</div>';
    }
}
class EWP_Text_Tag extends \Elementor\Core\DynamicTags\Tag {
    public function get_name(){return 'ewp-text';} public function get_title(){return 'EventWillow Field';} public function get_group(){return 'eventwillow';}
    public function get_categories(){return [\Elementor\Modules\DynamicTags\Module::TEXT_CATEGORY,\Elementor\Modules\DynamicTags\Module::URL_CATEGORY];}
    protected function register_controls(): void { EWP_Elementor::recordControls($this); }
    public function render(): void { $s=$this->get_settings(); echo esc_html(EWP_Views::value($s['custom_key'] ?: $s['field'],EWP_Views::preview($s['record_kind']),EWP_Elementor::dateFormat($s))); }
}
class EWP_Image_Tag extends \Elementor\Core\DynamicTags\Data_Tag {
    public function get_name(){return 'ewp-image';} public function get_title(){return 'EventWillow Image';} public function get_group(){return 'eventwillow';}
    public function get_categories(){return [\Elementor\Modules\DynamicTags\Module::IMAGE_CATEGORY];}
    protected function register_controls(): void {
        $this->add_control('record_kind',['label'=>'Record type','type'=>\Elementor\Controls_Manager::SELECT,'default'=>'event','options'=>['event'=>'Event','channel'=>'Channel']]);
        $this->add_control('field',['label'=>'Image','type'=>\Elementor\Controls_Manager::SELECT,'default'=>'image','options'=>['image'=>'Main image','header_image'=>'Channel header']]);
    }
    public function get_value(array $options=[]){
        $s=$this->get_settings();$url=EWP_Views::value($s['field'],EWP_Views::preview($s['record_kind']));
        // An empty dynamic CSS value is omitted by Elementor. Explicitly clear cached
        // background images with a transparent image, without affecting Image widgets.
        if ($url === '' && EWP_Views::$renderingRecordStyles) $url='data:image/svg+xml,%3Csvg xmlns=%22http://www.w3.org/2000/svg%22 width=%221%22 height=%221%22%3E%3C/svg%3E';
        return ['id'=>0,'url'=>$url];
    }
}

class EWP_Channel_Color_Tag extends \Elementor\Core\DynamicTags\Data_Tag {
    public function get_name(){return 'ewp-channel-color';}
    public function get_title(){return 'EventWillow Channel Accent Color';}
    public function get_group(){return 'eventwillow';}
    public function get_categories(){return [\Elementor\Modules\DynamicTags\Module::COLOR_CATEGORY];}
    protected function register_controls(): void {
        $this->add_control('record_kind',['label'=>'Record type','type'=>\Elementor\Controls_Manager::SELECT,'default'=>'channel','options'=>['event'=>'Event','channel'=>'Channel']]);
    }
    public function get_value(array $options=[]): string {
        return sanitize_hex_color(EWP_Views::value('channel_accent_color',EWP_Views::preview($this->get_settings('record_kind')))) ?: '';
    }
}

class EWP_Related_Channels_Widget extends EWP_Base_Widget {
    public function get_name(){return 'ewp-related-channels';} public function get_title(){return 'EventWillow Related Channels';}
    protected function register_controls(): void { $this->styleControls(); }
    protected function render(): void {
        $item=EWP_Views::$context ?? EWP_Views::preview('event');
        echo '<div class="ewp ewp-results ewp-grid">';
        foreach ($item['channels'] ?? [] as $channel) echo EWP_Views::card($channel,'channel');
        echo '</div>';
    }
}
