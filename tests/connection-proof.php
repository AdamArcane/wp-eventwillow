<?php
if (!defined('WP_CLI') || !WP_CLI || !str_ends_with(parse_url(home_url(),PHP_URL_HOST),'.ddev.site')) exit;
if (($args[0] ?? '')==='down') {
    if (get_option('ewp_connection_proof_backup',null)!==null) WP_CLI::error('Restore the existing connection proof first.');
    update_option('ewp_connection_proof_backup',get_option('ewp_settings',[]),false);
    $s=EWP_API::settings();$s['api_url']='http://127.0.0.1:9/api/eventwillow/v1';update_option('ewp_settings',$s);
} else {
    $s=get_option('ewp_connection_proof_backup',null);
    if ($s!==null) { update_option('ewp_settings',$s);delete_option('ewp_connection_proof_backup'); }
}
EWP_API::clear();WP_CLI::success('Local connection proof state updated.');
