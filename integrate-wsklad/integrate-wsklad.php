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
define('PRODUCT_BATCH', intval(get_option('integrate_wsklad_product_batch'))); // how many products we process at once

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
    $date = date('Y-m-d h:i:s');
    $debug_log_db = get_option(HOOK_PREFIX . "debug_log");
    if (!$debug_log_db) $debug_log_db = array();
    array_push($debug_log_db, $date . "::" . $message);
    update_option(HOOK_PREFIX . 'debug_log', $debug_log_db);
    fwrite($file, "\n" . $date . " :: " . $message);
    fclose($file);

}


function execute_sync_step($step_num = 0, $params, $execute_now = false) {

    $steps = [
        0 => 'Unpublish products',
        1 => 'Update products',
        2 => 'Load images',
        3 => 'Fill ACF fields for products',
        4 => 'Delete draft products'
    ];
    do_action(HOOK_PREFIX . 'log', 'Executing step: ' . $steps[$step_num]);
    $action_message = $execute_now ? 'Started action:' : 'Scheduled action:';
    do_action(HOOK_PREFIX . 'log', $action_message . " " . $steps[$step_num]);
    
    switch ($step_num) {
        case 0:
            if ($execute_now) return do_action(HOOK_PREFIX . 'unpublish_products', $params);
            as_schedule_single_action(time(), HOOK_PREFIX . 'unpublish_products', $params);
            break;
        case 1:
             if ($execute_now) return do_action(HOOK_PREFIX . 'update_products', $params);
            as_schedule_single_action(time(), HOOK_PREFIX . 'update_products',$params);
            break;
        case 2:
             if ($execute_now) return do_action(HOOK_PREFIX . 'upload_imgs', $params);
            as_schedule_single_action(time(), HOOK_PREFIX . 'upload_imgs', $params);
            break;
        case 3:
             if ($execute_now) return do_action(HOOK_PREFIX . 'update_acf', $params);
            as_schedule_single_action(time(), HOOK_PREFIX . 'update_acf',$params);
            break;
        case 4:
             if ($execute_now) return do_action(HOOK_PREFIX . 'delete_current_products', $params);
            as_schedule_single_action(time(), HOOK_PREFIX . 'delete_current_products', $params);
            break;
    }


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
    update_option(HOOK_PREFIX . 'debug_log', array());
    update_option(HOOK_PREFIX . 'sync', 'running');
    do_action(HOOK_PREFIX . 'log', "Started sync with WSKLAD");
    do_action(HOOK_PREFIX . 'log', "Product batch is set to: " . PRODUCT_BATCH);
    # Start execution chain
    execute_sync_step(0, array(PRODUCT_BATCH));
}

function unpublish_current_products($batch)
{
    do_action(HOOK_PREFIX . 'log', "Function unpublish_current_products started");
    $products = wc_get_products(['status' => 'publish', 'numberposts' => $batch]);

    if (empty($products)) {
        do_action(HOOK_PREFIX . 'log', 'No products to unpublish, skipping this step.');
        execute_sync_step(1, 0, true);
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
        execute_sync_step(0, array(PRODUCT_BATCH));

    } else {
        execute_sync_step(1, array(0));
        return ['result' => 'finished'];
    }

}

function delete_woo_products($force = true)
{
    do_action(HOOK_PREFIX . 'log', "Function delete_woo_products started");
    if (get_option(HOOK_PREFIX . 'sync') == 'stopped') {
        do_action(HOOK_PREFIX . 'log', "Sync was stopped. Stop hook execution.");
        update_option(HOOK_PREFIX . 'sync', 'stopped');
        return ['result' => 'finished'];
    }

    $batch = PRODUCT_BATCH; # Delete per hook execution

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

    execute_sync_step(4, array($force));
}


function process_products($offset = 0)
{
    do_action(HOOK_PREFIX . 'log', "Function process_products started");
    if (get_option(HOOK_PREFIX . 'sync') == 'stopped') {
        do_action(HOOK_PREFIX . 'log', "Sync was stopped. Stop hook execution.");
        update_option(HOOK_PREFIX . 'sync', 'stopped');
        return ['result' => 'finished'];
    }
    require_once PLUGIN_PATH . 'includes/functions.php';
    $batch = PRODUCT_BATCH;

    $wsklad_products = wsklad_request("/entity/product?limit=$batch&offset=$offset");
    $wsklad_total_count = $wsklad_products['meta']['size'];

    if (!$wsklad_products || empty($wsklad_products['rows'])) {
        do_action(HOOK_PREFIX . 'log', "No products returned from WSKLAD. Skip to loading images now.");
        execute_sync_step(2,  get_option(HOOK_PREFIX . 'img_queue'), true);
        return ['result' => 'finished'];
    }

    create_or_update_woo_products($wsklad_products['rows']);
    $done = $offset + count($wsklad_products['rows']);
    do_action(HOOK_PREFIX . 'log', 'Processing items. Done: ' . $done);
    // If returned count is less then batch then we can proceed with next step
    if ($done >= $wsklad_total_count) {
        execute_sync_step(2, array(get_option(HOOK_PREFIX . 'img_queue')));
        return ['result' => 'finished'];
    }
    execute_sync_step(1, array($offset + $batch)); // fetch next batch of products recursive
}


function upload_imgs($queue = [])
{
    do_action(HOOK_PREFIX . 'log', "Function upload_imgs started");
    if (get_option(HOOK_PREFIX . 'sync') == 'stopped') {
        do_action(HOOK_PREFIX . 'log', "Sync was stopped. Stop hook execution.");
        update_option(HOOK_PREFIX . 'sync', 'stopped');
        return ['result' => 'finished'];
    }

    require_once PLUGIN_PATH . 'includes/functions.php';
    # How many products we be processing at once
    $batch = PRODUCT_BATCH;

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

    if (empty($queue)) {
        do_action(HOOK_PREFIX . 'log', "No products have images. Skip to fill ACF fields now");
        execute_sync_step(3, array(get_option(HOOK_PREFIX . 'acf_fields_queue')));
        return ['result' => 'finished'];
    }

    execute_sync_step(2, array($queue));
    return ['result' => 'restart'];
}

function update_acf($queue = [])
{
    do_action(HOOK_PREFIX . 'log', "Function update_acf started");
    if (get_option(HOOK_PREFIX . 'sync') == 'stopped') {
        do_action(HOOK_PREFIX . 'log', "Sync was stopped. Stop hook execution.");
        update_option(HOOK_PREFIX . 'sync', 'stopped');
        return ['result' => 'finished'];
    }

    $batch = PRODUCT_BATCH;

    require_once PLUGIN_PATH . 'includes/acf_funcs.php';

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

    if (empty($queue)) {
        do_action(HOOK_PREFIX . 'log', "No products left. Starting to look for products to delete.");
        execute_sync_step(4, array(true));
        return ['result' => 'finished'];
    }

    execute_sync_step(3, array($queue));
}


function clear_all_scheduled_hooks()
{
    as_unschedule_all_actions(HOOK_PREFIX . 'update_products');
    as_unschedule_all_actions(HOOK_PREFIX . 'delete_current_products');
    as_unschedule_all_actions(HOOK_PREFIX . 'upload_imgs');
    as_unschedule_all_actions(HOOK_PREFIX . 'update_acf');
}



?>