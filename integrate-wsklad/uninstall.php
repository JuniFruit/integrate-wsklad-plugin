<?php

// if uninstall.php is not called by WordPress, die
if (!defined('WP_UNINSTALL_PLUGIN')) {
    die;
}



delete_option('integrate_wsklad_login');
delete_option('integrate_wsklad_password');
delete_option('integrate_wsklad_reroute_server');

# Delete attachment db records

$posts = get_posts([
    'post_type' => 'attachment',
    'meta_key' => 'integrate_wsklad_url'
]);

foreach ($posts as $post) {
    wp_delete_post($post->ID, true);
}


?>