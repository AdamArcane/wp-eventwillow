<?php
if (!defined('WP_CLI') || !WP_CLI || !str_ends_with(parse_url(home_url(),PHP_URL_HOST),'.ddev.site')) exit;
$mode=$args[0] ?? 'restore';
$ids=get_option('ewp_local_templates',[]);
$backup=get_option('ewp_shell_proof_backup',[]);
if ($mode==='apply') {
    if ($backup) WP_CLI::error('Restore the existing shell proof first.');
    foreach (['header','footer'] as $key) {
        $id=$ids[$key]; $backup[$id]=get_post_meta($id,'_elementor_data',true);
    }
    update_option('ewp_shell_proof_backup',$backup,false);
    $walk=function (&$elements) use (&$walk) {
        foreach ($elements as &$element) {
            if (($element['widgetType'] ?? '')==='heading' && ($element['settings']['title'] ?? '')==='EventWillow') $element['settings']['title']='EventWillow · Shared template check';
            if (!empty($element['elements'])) $walk($element['elements']);
        }
    };
    foreach ($backup as $id=>$original) { $data=json_decode($original,true);$walk($data);update_post_meta($id,'_elementor_data',wp_slash(wp_json_encode($data))); }
} else {
    foreach ($backup as $id=>$original) update_post_meta($id,'_elementor_data',wp_slash($original));
    delete_option('ewp_shell_proof_backup');
}
\Elementor\Plugin::$instance->files_manager->clear_cache();
WP_CLI::success($mode==='apply' ? 'Applied reversible shared template check.' : 'Restored shared templates.');
