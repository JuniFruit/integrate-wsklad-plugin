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




/* Setup */

define('PLUGIN_PATH', plugin_dir_path(__FILE__));
define('PLUGIN_URL', plugin_dir_url(__FILE__));
define('HOOK_PREFIX', 'integrate_wsklad_');
define('PRODUCT_BATCH', get_option('integrate_wsklad_product_batch') ? intval(get_option('integrate_wsklad_product_batch')) : 50); // how many products we process at once
define('IMAGES_QUEUE', HOOK_PREFIX . 'img_queue');
define('VARIATIONS_QUEUE', HOOK_PREFIX . 'product_variations_queue');
define('ATTRIBUTES_QUEUE', HOOK_PREFIX . 'product_attributes_queue');
define('ACF_QUEUE',  HOOK_PREFIX . 'acf_fields_queue');

require_once PLUGIN_PATH . 'includes/acf_funcs.php';
require_once PLUGIN_PATH . 'includes/functions.php';
require_once PLUGIN_PATH . 'utils.php';

add_action('woocommerce_init', 'plugin_init');
add_action('admin_post_integrate_wsklad_sync', 'integrate_wsklad_sync', 5, 2);
add_action('admin_post_integrate_wsklad_continue_sync', 'continue_sync', 5, 2);
add_action(HOOK_PREFIX . 'delete_current_products', 'delete_woo_products', 10, 1);
add_action(HOOK_PREFIX . 'update_products', 'process_products', 10, 1);
add_action(HOOK_PREFIX . 'log', 'add_log', 10, 1);
add_action(HOOK_PREFIX . 'upload_imgs', 'upload_imgs', 10, 1);
add_action(HOOK_PREFIX . 'update_acf', 'update_acf', 10, 1);
add_action(HOOK_PREFIX . 'update_variations', 'update_variations', 10, 1);
add_action(HOOK_PREFIX . 'unpublish_products', 'unpublish_current_products', 10, 1);
add_action(HOOK_PREFIX . 'process_attributes', 'process_attributes', 10, 1);
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

/* Function that starts the sync */

function integrate_wsklad_sync()
{

    
    if (get_option(HOOK_PREFIX . 'sync') === 'running') {
        do_action(HOOK_PREFIX . 'log', "Sync was manually stopped.");
        clear_all_scheduled_hooks();
        update_option(HOOK_PREFIX . 'sync', 'stopped');
        wp_redirect(admin_url('options-general.php?page=integrate_wsklad_plugin'));
        return;
    }
    
    # Log action, update state
    update_option(HOOK_PREFIX . 'debug_log', array());
    update_option(HOOK_PREFIX . 'sync', 'running');
    clear_all_queues();
    do_action(HOOK_PREFIX . 'log', "Started sync with WSKLAD");
    do_action(HOOK_PREFIX . 'log', "Product batch is set to: " . PRODUCT_BATCH);
    # Start execution chain
    execute_sync_step(0, array(PRODUCT_BATCH));
    wp_redirect(admin_url('options-general.php?page=integrate_wsklad_plugin'));
}

function continue_sync() {
    if (!is_sync_stopped()) {
        do_action(HOOK_PREFIX . 'log', "Failed to continue sync. Finish current sync first.");
        return;
    }
    update_option(HOOK_PREFIX . 'debug_log', array());
    clear_all_scheduled_hooks();
    update_option(HOOK_PREFIX . 'sync', 'running');
    $last_step = get_option(HOOK_PREFIX . 'sync_step');
    if (!$last_step) {
        $last_step = 0;
    } else {
        $last_step = intval($last_step);
    }
    $steps_to_params = [
        0 => array(PRODUCT_BATCH),
        1 => array(0),
        2 => array(get_option(ATTRIBUTES_QUEUE)),
        3 => array(get_option(VARIATIONS_QUEUE)),
        4 => array(get_option(IMAGES_QUEUE)),
        5 => array(get_option(ACF_QUEUE)),
        6 => array(true)
    ];

    if ($last_step === 0 || $last_step === 1) {
        clear_all_queues();
    }
    
    do_action(HOOK_PREFIX . 'log', "Product batch is set to: " . PRODUCT_BATCH);
    do_action(HOOK_PREFIX . 'log', "Started with params: " . json_encode($steps_to_params[$last_step]));
    execute_sync_step($last_step, $steps_to_params[$last_step]);
    wp_redirect(admin_url('options-general.php?page=integrate_wsklad_plugin'));
}

/* Processors */ 

function execute_sync_step($step_num = 0, $params, $execute_now = false)
{

    $steps = [
        0 => 'Unpublish products',
        1 => 'Update products',
        2 => 'Set attributes',
        3 => 'Update product variations',
        4 => 'Load images',
        5 => 'Fill ACF fields for products',
        6 => 'Delete draft products'
    ];
    $action_message = $execute_now ? 'Started action:' : 'Scheduled action:';
    do_action(HOOK_PREFIX . 'log', $action_message . " " . $steps[$step_num]);
    update_option(HOOK_PREFIX . 'sync_step', $step_num);
    switch ($step_num) {
        case 0:
            if ($execute_now) return do_action(HOOK_PREFIX . 'unpublish_products', $params);
            as_schedule_single_action(time(), HOOK_PREFIX . 'unpublish_products', $params);
            break;
        case 1:
            if ($execute_now) return do_action(HOOK_PREFIX . 'update_products', $params);
            as_schedule_single_action(time(), HOOK_PREFIX . 'update_products', $params);
            break;
        case 2:
            if ($execute_now) return do_action(HOOK_PREFIX . 'process_attributes', $params);
            as_schedule_single_action(time(), HOOK_PREFIX . 'process_attributes', $params);
            break;
        case 3:
            if ($execute_now) return do_action(HOOK_PREFIX . 'update_variations', $params);
            as_schedule_single_action(time(), HOOK_PREFIX . 'update_variations', $params);
            break;
        case 4:
            if ($execute_now) return do_action(HOOK_PREFIX . 'upload_imgs', $params);
            as_schedule_single_action(time(), HOOK_PREFIX . 'upload_imgs', $params);
            break;
        case 5:
            if ($execute_now) return do_action(HOOK_PREFIX . 'update_acf', $params);
            as_schedule_single_action(time(), HOOK_PREFIX . 'update_acf', $params);
            break;
        case 6:
            if ($execute_now) return do_action(HOOK_PREFIX . 'delete_current_products', $params);
            as_schedule_single_action(time(), HOOK_PREFIX . 'delete_current_products', $params);
            break;
    }
}


function unpublish_current_products($batch)
{
    do_action(HOOK_PREFIX . 'log', "Function unpublish_current_products started");
    if (is_sync_stopped()) return ['result' => 'finished'];

    $products = get_wsklad_wc_products('publish', $batch);

    if (empty($products)) {
        do_action(HOOK_PREFIX . 'log', 'No products to unpublish, skipping this step.');
        execute_sync_step(1, 0, true);
        return ['result' => 'finished'];
    }
    $done = 0;
    $stop_timestamp = set_execution_timer();
    foreach ($products as $product) {
        if (check_if_timeout($stop_timestamp)) break;
        $product->set_status('draft');
        $product->save();
        $done += 1;
    }

    if ($done >= $batch && $done !== 0) {

        do_action(HOOK_PREFIX . 'log', 'Continue unpublishing...');
        execute_sync_step(0, array(PRODUCT_BATCH));
    } else {
        execute_sync_step(1, array(0));
        return ['result' => 'finished'];
    }
}


function process_products($offset = 0)
{

    do_action(HOOK_PREFIX . 'log', "Function process_products started");
    if (is_sync_stopped()) return ['result' => 'finished'];

    $batch = PRODUCT_BATCH;

    $wsklad_products = wsklad_request("/entity/product?limit=$batch&offset=$offset");
    $wsklad_total_count = $wsklad_products['meta']['size'];

    if (!$wsklad_products || empty($wsklad_products['rows'])) {
        do_action(HOOK_PREFIX . 'log', "No products returned from WSKLAD. Skip to updating attributes now.");
        execute_sync_step(2,  get_option(ATTRIBUTES_QUEUE), true);
        return ['result' => 'finished'];
    }
    $done_processing = create_or_update_woo_products($wsklad_products['rows']);
    $done_total = $offset + $done_processing;
  
    do_action(HOOK_PREFIX . 'log', 'Processing items. Done: ' . $done_total);
    // If returned count is less then batch then we can proceed with next step
    if ($done_total >= $wsklad_total_count) {
        execute_sync_step(2, array(get_option(ATTRIBUTES_QUEUE)));
        return ['result' => 'finished'];
    }
    execute_sync_step(1, array($offset + $done_processing)); // fetch next batch of products recursive
}

function process_attributes($queue = []) {
    do_action(HOOK_PREFIX . 'log', "Function process_attributes started");
    if (is_sync_stopped()) return ['result' => 'finished'];
    
    $batch = PRODUCT_BATCH;
    $ids = default_queue_array($queue);
    $done = 0;
    $stop_timestamp = set_execution_timer();

    while (count($ids) > 0) {
        if ($done >= $batch) break;
        if (check_if_timeout($stop_timestamp)) break;
        $product_id = array_shift($ids);

        $product = wc_get_product($product_id);
        $wsklad_id = $product->get_meta('wsklad_id');
        if ($product) {
            add_attributes($product, $wsklad_id);
        }
        $done += 1;
    }

    if (empty($ids)) {
        do_action(HOOK_PREFIX . 'log', "No products left. Starting to update variations.");
        execute_sync_step(3, array(get_option(VARIATIONS_QUEUE)));
        return ['result' => 'finished'];
    }

    execute_sync_step(2, array($ids));
}

function update_variations($queue = [])
{
    do_action(HOOK_PREFIX . 'log', "Function update_variations started");

    if (is_sync_stopped()) return ['result' => 'finished'];

    # How many products we be processing at once
    $batch = PRODUCT_BATCH; // it's an optimal value, it's bigger it's getting stuck

    $ids = default_queue_array($queue);
    $done = 0;
    $stop_timestamp = set_execution_timer();

    while (count($ids) > 0) {
        if ($done >= $batch) break;
        if (check_if_timeout($stop_timestamp)) break;
        $product_id = array_shift($ids);

        $product = wc_get_product($product_id);
        $wsklad_id = $product->get_meta('wsklad_id');
        if ($product) {
            set_variations_for_product($product, $wsklad_id);
        }
        $done += 1;
    }

    if (empty($ids)) {
        do_action(HOOK_PREFIX . 'log', "No products have variations. Skip to images now");
        execute_sync_step(4, array(get_option(IMAGES_QUEUE)));
        return ['result' => 'finished'];
    }

    execute_sync_step(3, array($ids));
    return ['result' => 'restart'];
}

function upload_imgs($queue = [])
{

    do_action(HOOK_PREFIX . 'log', "Function upload_imgs started");
    if (is_sync_stopped()) return ['result' => 'finished'];

    # How many products we be processing at once
    $batch = PRODUCT_BATCH;

    $ids = default_queue_array($queue);
    $done = 0;
    $stop_timestamp = set_execution_timer();

    while (count($ids) > 0) {
        if ($done >= $batch) break;
        if (check_if_timeout($stop_timestamp)) break;
        $product_id = array_shift($ids);

        $product = wc_get_product($product_id);
        $product_img_url = $product->get_meta('wsklad_imgs_url');
        $is_variation = $product->get_meta('is_wsklad_variation') === "true";

        if ($product) {
            $img_ids = process_imgs($product, $product_img_url);
            set_images_for_product($product, $img_ids, !$is_variation);
        }
        $done += 1;
    }

    if (empty($ids)) {
        do_action(HOOK_PREFIX . 'log', "No products have images. Skip to fill ACF fields now");
        execute_sync_step(5, array(get_option(ACF_QUEUE)));
        return ['result' => 'finished'];
    }

    execute_sync_step(4, array($ids));
    return ['result' => 'restart'];
}

function update_acf($queue = [])
{

    do_action(HOOK_PREFIX . 'log', "Function update_acf started");
    if (is_sync_stopped()) return ['result' => 'finished'];

    $batch = PRODUCT_BATCH;

    $ids = default_queue_array($queue);
    $done = 0;
    $stop_timestamp = set_execution_timer();

    while (count($ids) > 0) {
        if ($done >= $batch) break;
        if (check_if_timeout($stop_timestamp)) break;

        $product_id = array_shift($ids);
        $product = wc_get_product($product_id);

        if ($product) {
            add_acf_fields($product);
        }
        $done += 1;
    }

    if (empty($ids)) {
        do_action(HOOK_PREFIX . 'log', "No products left. Starting to look for products to delete.");
        execute_sync_step(6, array(true));
        return ['result' => 'finished'];
    }

    execute_sync_step(5, array($ids));
}


function delete_woo_products($force = true)
{

    do_action(HOOK_PREFIX . 'log', "Function delete_woo_products started");
    if (is_sync_stopped()) return ['result' => 'finished'];

    $batch = PRODUCT_BATCH; # Delete per hook execution

    $done = wh_deleteProducts($force, $batch);

    if ($done === 0) {
        clear_all_queues();
        do_action(HOOK_PREFIX . 'log', "Finished deleting products. Sync successfully finished.");
        update_option(HOOK_PREFIX . 'sync', 'stopped');
        update_option(HOOK_PREFIX . 'sync_step', NULL);
        return ['result' => 'finished'];
    }

    execute_sync_step(6, array($force));
}


?>