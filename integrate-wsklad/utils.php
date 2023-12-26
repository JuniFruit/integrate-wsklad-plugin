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

function splice_queue($queue, $splice_by) {

    $result_queue = [];

    if (count($queue) > $splice_by) {
        $result_queue = array_splice($queue, 0, $splice_by);
    } else {
        $result_queue = array_splice($queue, 0);
    }
    return ['spliced' => $result_queue, 'remained' => $queue];
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