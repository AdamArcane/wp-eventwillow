<?php
if (!defined('WP_CLI') || !WP_CLI || !str_ends_with(parse_url(home_url(),PHP_URL_HOST),'.ddev.site')) exit;
$previous = EWP_Views::$context;
$channel = ['id'=>'channel-1','identifier'=>'studio','name'=>'Studio','summary'=>'Short channel text','description'=>'Full channel text','type'=>'curator','accent_color'=>'#123abc','slug'=>'studio','image'=>'https://example.test/channel.png','canonical_url'=>'https://example.test/studio','website'=>'https://example.test'];
$event = ['name'=>'Event','image'=>'https://example.test/event.png','canonical_url'=>'https://example.test/event','summary'=>'Event summary','description'=>'Event description','channels'=>[$channel]];
$count = 0;
$assert = function($condition,$message)use(&$count){if(!$condition)throw new RuntimeException($message);$count++;};
try {
    foreach ([$channel,$event] as $record) {
        $assert(EWP_Views::value('channel_short_description',$record)==='Short channel text','Channel summary resolves in both contexts');
        $assert(EWP_Views::value('channel_description',$record)==='Full channel text','Channel description resolves in both contexts');
        $assert(EWP_Views::value('type',$record)==='curator','Existing type field resolves in both contexts');
        $assert(EWP_Views::value('channel_accent_color',$record)==='#123abc','Accent color resolves in both contexts');
    }
    $assert(EWP_Views::value('description',$event)==='Event description','Generic event description remains unchanged');
    $assert(EWP_Views::value('description',$channel)==='Full channel text','Generic channel description remains supported');
    foreach (['channel_short_description','channel_description','channel_accent_color','type'] as $field) {
        $assert(EWP_Views::value($field,['channels'=>[]])==='','Missing channel stays empty');
        $assert(isset(EWP_Elementor::fields()[$field]),'Field is available to Elementor');
    }
    EWP_Views::$context=$event;
    $assert(EWP_Views::preview('event')===$event,'Event selection retains the current event');
    $assert(EWP_Views::preview('channel')===$channel,'Channel selection resolves the live associated channel');
    $textTag=new EWP_Text_Tag(['id'=>'channel-text-test','settings'=>[]]);
    $textTag->set_settings('record_kind','channel');
    $textTag->set_settings('custom_key','');
    $textTag->set_settings('field','canonical_url');
    ob_start(); $textTag->render(); $output=ob_get_clean();
    $assert($output==='https://example.test/studio','Channel text tag uses the channel standalone URL');
    $textTag->set_settings('field','website');
    ob_start(); $textTag->render(); $output=ob_get_clean();
    $assert($output==='https://example.test','Channel text tag uses the channel website');
    $imageTag=new EWP_Image_Tag(['id'=>'channel-image-test','settings'=>[]]);
    $imageTag->set_settings('record_kind','channel');
    $imageTag->set_settings('field','image');
    $assert($imageTag->get_value()['url']==='https://example.test/channel.png','Channel image tag uses the profile image');
    $imageTag->set_settings('record_kind','event');
    $assert($imageTag->get_value()['url']==='https://example.test/event.png','Event image tag retains the flyer');
    EWP_Views::$context=$channel;
    $assert(EWP_Views::preview('event')===$channel,'Existing default controls still work on channel pages');
    EWP_Views::$context=['name'=>'Event without channel','image'=>'https://example.test/event.png','channels'=>[]];
    $imageTag->set_settings('record_kind','channel');
    $assert($imageTag->get_value()['url']==='','Missing channel never falls back to the event flyer');
    $textTag->set_settings('field','canonical_url');
    ob_start(); $textTag->render(); $output=ob_get_clean();
    $assert($output==='','Missing channel never uses an editor preview record');
    $assert(EWP_Views::value('local_url',EWP_Views::preview('channel'))==='','Missing channel detail link stays empty');
    EWP_Views::$context=$event;
    $tag=new EWP_Channel_Color_Tag(['id'=>'channel-color-test','settings'=>[]]);
    $assert($tag->get_value()==='#123abc','Color tag returns the associated channel color');
    $assert(in_array(\Elementor\Modules\DynamicTags\Module::COLOR_CATEGORY,$tag->get_categories(),true),'Color tag works in native color controls');
    EWP_Views::$context=['identifier'=>'studio','accent_color'=>'red; background:url(x)'];
    $assert($tag->get_value()==='','Invalid color is rejected');
    WP_CLI::success($count.' channel field assertions passed.');
} finally { EWP_Views::$context=$previous; }
