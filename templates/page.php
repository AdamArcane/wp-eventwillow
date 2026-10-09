<?php
if (!defined('ABSPATH')) exit;
get_header();
$route = EWP_Plugin::$route;
echo '<main id="content" class="ewp-page">';
if (isset($route['error'])) echo EWP_Views::message($route['error']);
elseif (isset($route['item'])) {
    $template = (int) EWP_API::settings()[$route['kind'].'_template'];
    echo EWP_Views::template($template,$route['item']) ?: EWP_Views::detail($route['item'],$route['kind']);
} else echo '<h1>'.esc_html($route['title']).'</h1>'.EWP_Views::listing($route['kind'],['filters'=>true]);
echo '</main>';
get_footer();
