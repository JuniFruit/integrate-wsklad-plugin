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
add_action(HOOK_PREFIX . 'update_products', 'process_products', 10, 1);
add_action(HOOK_PREFIX . 'log', 'add_log', 10, 1);
add_action(HOOK_PREFIX . 'upload_imgs', 'upload_imgs', 10, 1);
add_action(HOOK_PREFIX . 'update_acf', 'update_acf', 10, 1);
add_action(HOOK_PREFIX . 'unpublish_products', 'unpublish_current_products', 10, 1);
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
    $file_path = PLUGIN_PATH . "debug.log";
    $size = filesize($file_path);
    $size_cap = 1024 * 1024 * 2; # 2MB
    $option = 'a';
    if ($size && $size > $size_cap) {
        $option = 'w'; # to overwrite
    }

    $file = fopen($file_path, $option);
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


function integrate_wsklad_sync()
{
    require_once PLUGIN_PATH . 'includes/functions.php';
    require_once PLUGIN_PATH . 'includes/acf_funcs.php';

    wp_redirect(admin_url('options-general.php?page=integrate_wsklad_plugin'));
    clear_img_queue();
    clear_acf_fields_queue();

    if (get_option(HOOK_PREFIX . 'sync') == 'running') {
        do_action(HOOK_PREFIX . 'log', "Sync was manually stopped.");
        clear_all_scheduled_hooks();
        update_option(HOOK_PREFIX . 'sync', 'stopped');
        return;
    }

    # Log action, update state
    do_action(HOOK_PREFIX . 'log', "Started sync with WSKLAD");
    update_option(HOOK_PREFIX . 'sync', 'running');
    # Start execution chain
    do_action(HOOK_PREFIX . 'log', "Started to unpublish products.");
    as_schedule_single_action(time(), HOOK_PREFIX . 'unpublish_products', array(100));
    // do_action(HOOK_PREFIX . 'unpublish_products', 1);


}

function unpublish_current_products($batch = 100)
{

    $products = wc_get_products(['status' => 'publish', 'numberposts' => $batch]);

    if (empty($products)) {
        do_action(HOOK_PREFIX . 'log', 'No products to unpublish, skipping this step.');
        as_schedule_single_action(time(), HOOK_PREFIX . 'update_products', array(0));
        return ['result' => 'finished'];

    }
    $i = 0;
    foreach ($products as $product) {
        $product->set_status('draft');
        $product->save();
        $i += 1;
    }

    if (!empty(wc_get_products(['status' => 'publish', 'numberposts' => $batch]))) {

        do_action(HOOK_PREFIX . 'log', 'Continue unpublishing.');
        as_schedule_single_action(time(), HOOK_PREFIX . 'unpublish_products', array($batch));

    } else {
        do_action(HOOK_PREFIX . 'log', 'Finished unpublishing. Starting to sync products.');
        as_schedule_single_action(time(), HOOK_PREFIX . 'update_products', array(0));
        return ['result' => 'finished'];


    }

}

function delete_woo_products($force = true)
{
    if (get_option(HOOK_PREFIX . 'sync') == 'stopped') {

        do_action(HOOK_PREFIX . 'log', "Sync was stopped. Stop hook execution.");
        return ['result' => 'finished'];
    }

    $batch = 50; # Delete per hook execution

    require_once PLUGIN_PATH . 'includes/functions.php';
    require_once PLUGIN_PATH . 'includes/acf_funcs.php';


    $is_more_entries = wh_deleteProducts($force, $batch);

    if (!$is_more_entries) {
        clear_img_queue();
        clear_acf_fields_queue();
        do_action(HOOK_PREFIX . 'log', "Finished deleting products. Sync successfully finished.");
        update_option(HOOK_PREFIX . 'sync', 'stopped');
        return ['result' => 'finished'];
    }

    as_schedule_single_action(time(), HOOK_PREFIX . 'delete_current_products', array($force));
}


function process_products($offset = 0)
{
    if (get_option(HOOK_PREFIX . 'sync') == 'stopped') {
        do_action(HOOK_PREFIX . 'log', "Sync was stopped. Stop hook execution.");
        return ['result' => 'finished'];
    }
    require_once PLUGIN_PATH . 'includes/functions.php';
    $batch = 50;

    $wsklad_products = wsklad_request("/entity/product?limit=$batch&offset=$offset");

    if (!$wsklad_products || empty($wsklad_products['rows'])) {
        do_action(HOOK_PREFIX . 'log', "No products returned from WSKLAD. Starting to load images...");
        as_schedule_single_action(time(), HOOK_PREFIX . 'upload_imgs', array(get_option(HOOK_PREFIX . 'img_queue')));
        return ['result' => 'finished'];
    }

    create_or_update_woo_products($wsklad_products['rows']);
    do_action(HOOK_PREFIX . 'log', 'Processing items. Done: ' . $offset + count($wsklad_products['rows']));
    as_schedule_single_action(time(), HOOK_PREFIX . 'update_products', array($offset + $batch, $create_new));
    // do_action(HOOK_PREFIX . 'upload_imgs', get_option(HOOK_PREFIX . 'img_queue'));


}


function upload_imgs($queue = [])
{
    if (get_option(HOOK_PREFIX . 'sync') == 'stopped') {
        do_action(HOOK_PREFIX . 'log', "Sync was stopped. Stop hook execution.");
        return ['result' => 'finished'];
    }

    require_once PLUGIN_PATH . 'includes/functions.php';
    # How many products we be processing at once
    $batch = 30;

    if (empty($queue)) {
        do_action(HOOK_PREFIX . 'log', "No products have images. Starting to fill ACF fields...");
        as_schedule_single_action(time(), HOOK_PREFIX . 'update_acf', array(get_option(HOOK_PREFIX . 'acf_fields_queue')));
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
        // $product = $_pf->get_product($product_id);
        $product = wc_get_product($product_id);

        if ($product) {
            process_imgs($product, $product->get_meta('wsklad_imgs_url'));
        }

    }

    as_schedule_single_action(time(), HOOK_PREFIX . 'upload_imgs', array($queue));
    // do_action(HOOK_PREFIX . 'update_acf', get_option(HOOK_PREFIX . 'acf_fields_queue'));
    return ['result' => 'restart'];
}

function update_acf($queue = [])
{
    if (get_option(HOOK_PREFIX . 'sync') == 'stopped') {
        do_action(HOOK_PREFIX . 'log', "Sync was stopped. Stop hook execution.");
        return ['result' => 'finished'];
    }

    $batch = 50;

    require_once PLUGIN_PATH . 'includes/acf_funcs.php';

    if (empty($queue)) {
        do_action(HOOK_PREFIX . 'log', "No products left. Starting to look for products to delete.");
        as_schedule_single_action(time(), HOOK_PREFIX . 'delete_current_products', array(true));

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
        // $product = $_pf->get_product($product_id);
        $product = wc_get_product($product_id);

        if ($product) {
            add_acf_fields($product);
        }

    }

    as_schedule_single_action(time(), HOOK_PREFIX . 'update_acf', array($queue));
    // do_action(HOOK_PREFIX . 'delete_current_products', true);
}


function clear_all_scheduled_hooks()
{
    as_unschedule_all_actions(HOOK_PREFIX . 'update_products');
    as_unschedule_all_actions(HOOK_PREFIX . 'delete_current_products');
    as_unschedule_all_actions(HOOK_PREFIX . 'upload_imgs');
    as_unschedule_all_actions(HOOK_PREFIX . 'update_acf');
}



?>