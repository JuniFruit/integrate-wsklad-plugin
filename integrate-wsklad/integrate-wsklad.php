<?php
/**
 * Plugin Name: Integration with WSKLAD
 * Description: Sync products from WSKLAD CRM with Woocommerce
 * Version: 1.0.0
 * Author: Artyom Nebyansky
 * Author URI: 
 * License: GPLv2 or later
 * License URI: http://www.gnu.org/licenses/gpl-2.0.html
 * PHP requires at least: 7.0
 * WP requires at least: 5.0
 */


defined('ABSPATH') or die('Not allowed!');

define('PLUGIN_PATH', plugin_dir_path(__FILE__));
define('PLUGIN_URL', plugin_dir_url(__FILE__));
define('HOOK_PREFIX', 'integrate_wsklad_');

add_action('woocommerce_init', 'plugin_init');
add_action('admin_post_integrate_wsklad_sync', 'integrate_wsklad_sync', 5, 2);
add_action(HOOK_PREFIX . 'delete_current_products', 'delete_woo_products', 10, 1);
add_action(HOOK_PREFIX . 'create_products', 'add_products', 10, 1);
add_action(HOOK_PREFIX . 'log', 'add_log', 10, 1);
add_action(HOOK_PREFIX . 'upload_imgs', 'upload_imgs', 10, 1);
add_filter('plugin_action_links_' . plugin_basename(__FILE__), 'settings_link');



function settings_link($links)
{
    $setting_link = '<a href="options-general.php?page=integrate_wsklad_plugin">Settings</a>';
    array_push($links, $setting_link);
    return $links;
}

function add_log($message)
{

    if (is_array($message)) {
        $message = json_encode($message);
    }
    $file = fopen(PLUGIN_PATH . "debug.log", "a");
    fwrite($file, "\n" . date('Y-m-d h:i:s') . " :: " . $message);
    fclose($file);

}

function plugin_init()
{
    if (
        in_array('woocommerce/woocommerce.php', apply_filters('active_plugins', get_option('active_plugins')))
    ) {
        require_once PLUGIN_PATH . 'includes/Settings.php';


        $plugin = new SettingsInit();

        register_activation_hook(__FILE__, [$plugin, 'activate']);
        register_deactivation_hook(__FILE__, [$plugin, 'deactivate']);

        $plugin->start();


    }
}


function delete_woo_products($force)
{

    $batch = 40; # Delete per hook execution

    require_once PLUGIN_PATH . 'includes/functions.php';


    $is_more_entries = wh_deleteProducts($force, $batch);

    if (!$is_more_entries) {
        do_action(HOOK_PREFIX . 'log', "Finished deleting products.");
        # Start scheduled recursion
        as_schedule_single_action(time(), HOOK_PREFIX . 'create_products', array());
        return ['result' => 'finished'];
    } else {
        as_schedule_single_action(time(), HOOK_PREFIX . 'delete_current_products', array($force));
        // return ['result' => 'restart'];
    }
}


function clear_all_scheduled_hooks()
{
    as_unschedule_all_actions(HOOK_PREFIX . 'create_products');
    as_unschedule_all_actions(HOOK_PREFIX . 'delete_current_products');
    as_unschedule_all_actions(HOOK_PREFIX . 'upload_imgs');
}

function integrate_wsklad_sync()
{
    require_once PLUGIN_PATH . 'includes/functions.php';


    wp_redirect(admin_url('options-general.php?page=integrate_wsklad_plugin'));
    if (get_option(HOOK_PREFIX . 'sync') == 'running') {
        do_action(HOOK_PREFIX . 'log', "Sync was manually stopped.");
        clear_all_scheduled_hooks();
        update_option(HOOK_PREFIX . 'sync', 'stopped');
        return;
    }

    # Log action, update state
    do_action(HOOK_PREFIX . 'log', "Started sync with WSKLAD");
    update_option(HOOK_PREFIX . 'sync', 'running');


    # Start delete current products recursion
    $force = true; # Change this value to false if you want previous products to be moved to trash on sync process
    do_action(HOOK_PREFIX . 'log', "Started deleting process. IS PERMANENT: " . $force);
    as_schedule_single_action(time(), HOOK_PREFIX . 'delete_current_products', array($force));
}

function add_products($offset = 0)
{
    if (get_option(HOOK_PREFIX . 'sync') == 'stopped') {
        do_action(HOOK_PREFIX . 'log', "Sync was stopped. Stop hook execution.");
        return ['result' => 'finished'];
    }
    require_once PLUGIN_PATH . 'includes/functions.php';
    $batch = 30;

    $wsklad_products = wsklad_request("/entity/product?limit=$batch&offset=$offset");

    if (!$wsklad_products || empty($wsklad_products['rows'])) {
        do_action(HOOK_PREFIX . 'log', "No products returned from WSKLAD. Starting to load images...");
        $queue = get_option(HOOK_PREFIX . 'img_queue');
        clear_img_queue();
        as_schedule_single_action(time(), HOOK_PREFIX . 'upload_imgs', array($queue));
        return ['result' => 'finished'];
    } else {
        create_woo_products($wsklad_products['rows']);
        do_action(HOOK_PREFIX . 'log', 'Creating items. Done: ' . $offset + count($wsklad_products['rows']));
        as_schedule_single_action(time(), HOOK_PREFIX . 'create_products', array($offset + $batch));
    }



}

function upload_imgs($queue = [])
{
    if (get_option(HOOK_PREFIX . 'sync') == 'stopped') {
        do_action(HOOK_PREFIX . 'log', "Sync was stopped. Stop hook execution.");
        return ['result' => 'finished'];
    }

    require_once PLUGIN_PATH . 'includes/functions.php';
    # How many products we be processing at once
    $batch = 20;

    if (empty($queue)) {
        do_action(HOOK_PREFIX . 'log', "No products have images. Finishing the sync...");
        update_option(HOOK_PREFIX . 'sync', 'stopped');
        wp_redirect(admin_url('options-general.php?page=integrate_wsklad_plugin'));
        return ['result' => 'finished'];
    }

    $q = [];

    if (count($queue) > $batch) {
        $q = array_splice($queue, 0, $batch);
    } else {
        $q = array_splice($queue, 0);
    }
    $_pf = new WC_Product_Factory();

    foreach ($q as $product_id) {
        $product = $_pf->get_product($product_id);

        if ($product) {
            process_imgs($product, $product->get_meta('wsklad_imgs_url'));
        }

    }

    as_schedule_single_action(time(), HOOK_PREFIX . 'upload_imgs', array($queue));
    return ['result' => 'restart'];
}

?>