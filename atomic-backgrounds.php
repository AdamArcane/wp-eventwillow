<?php
if(!defined('ABSPATH'))exit;

// The Atomic editor resolves native image tags through its own preview path.
// Honor the lab's configured event/channel preview there as well as on PHP render.
add_action('elementor/dynamic_tags/register',function($manager){
    if(!class_exists(\ElementorPro\Modules\DynamicTags\Tags\Post_Featured_Image::class))return;
    if(!class_exists('EW_Lab_Context_Featured_Image',false)){
        class EW_Lab_Context_Featured_Image extends \ElementorPro\Modules\DynamicTags\Tags\Post_Featured_Image {
            public function get_value(array $options=[]){
                $id=ew_lab_context_id('event')?:ew_lab_context_id('channel');
                if(!$id)return parent::get_value($options);
                $thumbnail=get_post_thumbnail_id($id);
                return $thumbnail?['id'=>$thumbnail,'url'=>wp_get_attachment_image_url($thumbnail,'full')?:'']:$this->get_settings('fallback');
            }
        }
    }
    $manager->register(new EW_Lab_Context_Featured_Image());
},20);

function ew_lab_has_dynamic_prop($value){
    if(!is_array($value))return false;
    if(($value['$$type']??'')==='dynamic')return true;
    foreach($value as $child)if(ew_lab_has_dynamic_prop($child))return true;
    return false;
}

function ew_lab_atomic_local_styles($element){
    $styles=$element->get_data('styles');if($styles)return $styles;
    // Loop Builder raw data omits styles. Resolve the current document instead
    // of relying on fixture IDs or scanning all site templates.
    $document=\Elementor\Plugin::$instance->documents->get_current();
    if(!$document)return [];
    $data=$document->get_elements_data();
    $find=function($elements)use(&$find,$element){
        foreach($elements as $data){
            if(($data['id']??'')===$element->get_id())return $data['styles']??[];
            $styles=$find($data['elements']??[]);
            if($styles)return $styles;
        }
        return [];
    };
    return $find($data);
}

// Shared Atomic CSS is generated in document context, outside the loop's post.
// Resolve background variants during element rendering, when the item is known.
// Reuse Elementor's resolver and renderer so sizes, layers, states and breakpoints
// keep their native meaning. Never put occurrence-specific URLs in shared CSS.
add_action('elementor/frontend/before_render',function($element){
    if(!class_exists(\Elementor\Modules\AtomicWidgets\Styles\Styles_Renderer::class))return;
    $localStyles=ew_lab_atomic_local_styles($element);
    if(empty($localStyles))return;
    \Elementor\Plugin::$instance->dynamic_tags->get_tag_info('ew-lab-event-image');
    $id=ew_lab_context_id('event')?:ew_lab_context_id('channel');
    if(!$id)return;
    $styles=[];$classes=[];
    foreach($localStyles as $style){
        $dynamic=false;
        foreach($style['variants']??[] as $variant)if(ew_lab_has_dynamic_prop($variant['props']['background']??null))$dynamic=true;
        if(!$dynamic)continue;
        static $sequence=0;
        $token='ew-lab-atomic-bg-'.(++$sequence);
        $variants=[];
        foreach($style['variants'] as $variant){
            if(!isset($variant['props']['background']))continue;
            $variants[]=['meta'=>$variant['meta'],'props'=>['background'=>$variant['props']['background']]];
        }
        $styles[]=['id'=>$token,'type'=>'class','variants'=>$variants];$classes[]=$token;
    }
    if(!$styles)return;
    $previous=$GLOBALS['post']??null;
    try{
        // Native tags such as Featured Image read the current post, including in
        // an editor preview whose outer post is the Elementor library template.
        $GLOBALS['post']=get_post($id);
        $css=\Elementor\Modules\AtomicWidgets\Styles\Styles_Renderer::make(
            \Elementor\Plugin::$instance->breakpoints->get_breakpoints_config(),
            '.elementor.elementor'
        )->render($styles);
    }finally{$GLOBALS['post']=$previous;}
    foreach($classes as $token){
        // A missing image must not inherit another occurrence's cached image.
        $css='.elementor.elementor .'.$token.'{background-image:none;}'.$css;
    }
    // Atomic Twig templates take classes from typed settings, not _wrapper.
    // Remove previous runtime tokens when Loop Builder reuses an instance.
    $typed=$element->get_settings('classes');
    $existing=array_filter($typed['value']??[],fn($class)=>!str_starts_with($class,'ew-lab-atomic-bg-'));
    $element->set_settings('classes',['$$type'=>'classes','value'=>array_merge(array_values($existing),$classes)]);
    echo '<style data-ew-lab-atomic-background>'.str_ireplace('</style','<\/style',$css).'</style>';
});
