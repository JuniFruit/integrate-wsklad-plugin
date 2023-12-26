<?php


defined('ABSPATH') or die('Not allowed!');


function update_queue($queue_name, $product_id)
{

    $prev = get_option($queue_name);
    if ($prev) {
        $prev[$product_id] = $product_id;
    } else {
        $prev = [$product_id => $product_id];
    }
    update_option($queue_name, $prev);
}

function transform_wsklad_price($price) {
    $price_float = floatval($price / 100);
    return round($price_float, 2);
}

/**
 * @param stop accepts when to stop in secs
 * returns stop timestamp
 */
function set_execution_timer($stop = 100) {
    $date = new DateTime();
    $stop_timestamp =  $date->add(new DateInterval('PT' . $stop . 'S'))->getTimestamp();
    return $stop_timestamp;
}

/**
 * @param stop_timestamp accepts timestamp when to stop
 * returns boolean 
 */

function check_if_timeout($stop_timestamp) {
    $current = new DateTime();
    $t1 = $current->getTimestamp();
    if ($t1 >= $stop_timestamp) {
        do_action(HOOK_PREFIX . 'log', "Timed out, scheduling another step...");
        return true;
    }

    return false;
}

function clear_all_queues()
{

    update_option(IMAGES_QUEUE, []);
    update_option(VARIATIONS_QUEUE, []);
    update_option(ATTRIBUTES_QUEUE, []);
    update_option(ACF_QUEUE, []);
}

function clear_all_scheduled_hooks()
{
    as_unschedule_all_actions(HOOK_PREFIX . 'update_products');
    as_unschedule_all_actions(HOOK_PREFIX . 'delete_current_products');
    as_unschedule_all_actions(HOOK_PREFIX . 'upload_imgs');
    as_unschedule_all_actions(HOOK_PREFIX . 'update_acf');
    as_unschedule_all_actions(HOOK_PREFIX . 'process_attributes');
    as_unschedule_all_actions(HOOK_PREFIX . 'update_variations');
}

function default_queue_array($queue) {

    $result_queue = [];

    if (!empty($queue)) {
        $result_queue = array_slice($queue, 0);
    } 
    
    return $result_queue;
}

function is_sync_stopped() {
    if (get_option(HOOK_PREFIX . 'sync') === 'stopped') {
        do_action(HOOK_PREFIX . 'log', "Sync was stopped. Stop hook execution.");
        update_option(HOOK_PREFIX . 'sync', 'stopped');
        return true;
    } else {
        return false;
    }
}


?>