<?php
defined('ABSPATH') || exit;

final class EventWillow_Follow {
    public static function boot(): void {
        add_shortcode('eventwillow_follow',[self::class,'render']);
        add_action('admin_post_eventwillow_follow',[self::class,'submit']);
        add_action('admin_post_nopriv_eventwillow_follow',[self::class,'submit']);
    }

    public static function render(): string {
        $post_id=get_the_ID();
        if (!is_singular('ew_channel') || !$post_id || get_post_status($post_id)!=='publish'
            || get_post_meta($post_id,'ew_show_subscribe_panel',true)!=='1') return '';

        $raw_status=wp_unslash($_GET['ew_follow_status']??'');
        $status=is_string($raw_status)?sanitize_key($raw_status):'';
        $open=in_array($status,['sent','error'],true);
        $html='<details class="eventwillow-follow" id="eventwillow-follow-'.(int)$post_id.'"'.($open?' open':'').'>';
        $html.='<summary class="eventwillow-follow__trigger">'.esc_html__('Follow','eventwillow-sync').'</summary>';
        $html.='<p>'.esc_html__('Get occasional email updates about new events. No account needed; unsubscribe anytime.','eventwillow-sync').'</p>';
        if ($status==='sent') {
            $html.='<p role="status">'.esc_html__('Check your inbox to confirm. You will be following once you confirm your email.','eventwillow-sync').'</p>';
        } elseif ($status==='error') {
            $html.='<p role="status">'.esc_html__('We could not send the confirmation. Please check your details and try again.','eventwillow-sync').'</p>';
        }
        $html.='<form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';
        $html.='<input type="hidden" name="action" value="eventwillow_follow">';
        $html.='<input type="hidden" name="channel_post_id" value="'.(int)$post_id.'">';
        $html.=wp_nonce_field('eventwillow_follow_'.$post_id,'_eventwillow_nonce',true,false);
        $html.='<p><label for="eventwillow-follow-name-'.(int)$post_id.'">'.esc_html__('Name','eventwillow-sync').'</label>';
        $html.='<input id="eventwillow-follow-name-'.(int)$post_id.'" name="name" type="text" maxlength="255" autocomplete="name" required></p>';
        $html.='<p><label for="eventwillow-follow-email-'.(int)$post_id.'">'.esc_html__('Email address','eventwillow-sync').'</label>';
        $html.='<input id="eventwillow-follow-email-'.(int)$post_id.'" name="email" type="email" maxlength="255" autocomplete="email" required></p>';
        $html.='<p aria-hidden="true" style="position:absolute;width:1px;height:1px;overflow:hidden;clip:rect(0,0,0,0)">';
        $html.='<label for="eventwillow-follow-company-'.(int)$post_id.'">'.esc_html__('Leave this field empty','eventwillow-sync').'</label>';
        $html.='<input id="eventwillow-follow-company-'.(int)$post_id.'" name="company" type="text" tabindex="-1" autocomplete="off"></p>';
        $html.='<p><button type="submit">'.esc_html__('Send confirmation email','eventwillow-sync').'</button></p></form></details>';

        return $html;
    }

    public static function submit(): void {
        $raw_post_id=wp_unslash($_POST['channel_post_id']??0);
        $post_id=is_scalar($raw_post_id)?absint($raw_post_id):0;
        check_admin_referer('eventwillow_follow_'.$post_id,'_eventwillow_nonce');

        $status='error';
        $post=get_post($post_id);
        $raw_name=wp_unslash($_POST['name']??'');
        $raw_email=wp_unslash($_POST['email']??'');
        $name=is_string($raw_name)?sanitize_text_field($raw_name):'';
        $email=is_string($raw_email)?sanitize_email($raw_email):'';
        $identifier=(string)get_post_meta($post_id,'ew_identifier',true);
        $settings=EventWillow_Sync::settings();

        if (empty($_POST['company']) && $post && $post->post_type==='ew_channel' && $post->post_status==='publish'
            && get_post_meta($post_id,'ew_show_subscribe_panel',true)==='1'
            && $name!=='' && is_email($email) && strlen($email)<=255
            && preg_match('/^[a-zA-Z0-9_-]+$/D',$identifier) && $settings['url'] && EventWillow_Sync::key()) {
            $endpoint=rtrim($settings['url'],'/').'/channels/'.rawurlencode($identifier).'/follow';
            if (EventWillow_Sync::valid_url($endpoint)) {
                $response=EventWillow_Sync::post($endpoint,[
                    'timeout'=>15,
                    'redirection'=>0,
                    'limit_response_size'=>16384,
                    'headers'=>[
                        'Accept'=>'application/json',
                        'X-EventWillow-Site-Key'=>EventWillow_Sync::key(),
                    ],
                    'body'=>['name'=>$name,'email'=>$email],
                ]);
                if (!is_wp_error($response) && wp_remote_retrieve_response_code($response)===200) {
                    $body=json_decode(wp_remote_retrieve_body($response),true);
                    if (is_array($body) && ($body['success']??false)===true) $status='sent';
                }
            }
        }

        $fallback=$post && $post->post_type==='ew_channel' ? get_permalink($post_id) : home_url('/');
        $return=wp_validate_redirect(wp_get_referer()?:$fallback,$fallback);
        $return=add_query_arg('ew_follow_status',$status,$return).'#eventwillow-follow-'.(int)$post_id;
        wp_safe_redirect($return);
        exit;
    }
}