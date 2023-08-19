<?php



defined('ABSPATH') or die('Not allowed!');

add_filter(HOOK_PREFIX . 'get_attributes', 'add_attributes', 10, 2);
add_filter(HOOK_PREFIX . 'get_categories_ids', 'create_categories_by_path', 20, 2);
add_filter(HOOK_PREFIX . 'set_variants', 'set_variants', 10, 2);





/**
 * Method to delete Woo Product
 * 
 * @param int $id the product ID.
 * @param bool $force true to permanently delete product, false to move to trash.
 
 */
function wh_deleteProducts($force = false, $batch = 10)
{


    $products = wc_get_products(['numberposts' => $batch]);

    if (empty($products)) {
        return false;
    }

    foreach ($products as $product) {
        $id = $product->get_id();

        do_action(HOOK_PREFIX . 'log', 'Deleting ' . $product->get_name());

        // If we're forcing, then delete permanently.
        if ($force) {
            if ($product->is_type('variable')) {
                foreach ($product->get_children() as $child_id) {
                    $child = wc_get_product($child_id);
                    $child->delete(true);
                }
            } elseif ($product->is_type('grouped')) {
                foreach ($product->get_children() as $child_id) {
                    $child = wc_get_product($child_id);
                    $child->set_parent_id(0);
                    $child->save();
                }
            }

            $product->delete(true);
            $result = $product->get_id() > 0 ? false : true;
        } else {
            $product->delete();
            $result = 'trash' === $product->get_status();
        }

        if (!$result) {
            do_action(HOOK_PREFIX . 'log', 'Product ' . $product->get_name() . ' cannot be deleted');
            continue;
        }
        delete_post_meta($id, 'wsklad_id');
        delete_post_meta($id, 'wsklad_imgs_url');
        // Delete parent product transients.
        if ($parent_id = wp_get_post_parent_id($id)) {
            wc_delete_product_transients($parent_id);
        }
    }

    return true;
}

/**
 * @param string $path
 */

function wsklad_request($path, $is_absolute = false)
{

    $base_endpoint = 'https://online.moysklad.ru/api/remap/1.2';
    $user_login = get_option(HOOK_PREFIX . 'login');
    if (!$user_login) {
        return;
    }

    $user_password = get_option(HOOK_PREFIX . 'password');
    if (!$user_password) {
        return;
    }

    $endpoint = $is_absolute ? $path : $base_endpoint . $path;

    $args = array(
        'method' => 'GET',
        'timeout' => 45,
        'redirection' => 5,
        'headers' => array(
            "Content-Type" => 'application/json;charset=utf-8',
            'Authorization' => 'Basic ' .
            base64_encode($user_login . ':' . $user_password),
        )
    );

    $request = wp_remote_request($endpoint, $args);



    $response = json_decode($request['body'], true);

    if (!empty($response["errors"]) and is_array($response["errors"])) {
        foreach ($response["errors"] as $error) {
            do_action(HOOK_PREFIX . 'log', "WSKLAD request error: " . $error);
        }
        return false;
    }

    return $response;
}

function create_woo_products($items)
{


    foreach ($items as $item) {
        $product = new WC_Product_Simple();

        do_action(HOOK_PREFIX . 'log', "Creating product " . $item['name'] . "...");

        $product->set_name($item['name']); // product title
        $product->set_slug($item['externalCode']);

        $product->set_price($item['minPrice']['value']);
        if (!empty($item['salePrices'])) {
            $product->set_sale_price($item['salePrices'][0]['value']);
        }
        $product->set_regular_price($item['buyPrice']['value']);

        if (array_key_exists('description', $item)) {
            $product->set_short_description($item['description']);
        }
        $stock = get_stock($item['id']);

        if ($item['variantsCount'] > 0) {
            apply_filters(HOOK_PREFIX . 'get_attributes', $product, $item['id']);
        }

        $product->set_stock_status($stock > 0 ? 'instock' : 'outofstock'); // 'instock', 'outofstock' or 'onbackorder'
        $product->set_stock_quantity($stock);
        $product->set_backorders('no'); // 'yes', 'no' or 'notify'
        $product->set_low_stock_amount(1);

        $product->set_sold_individually(true);
        $product->set_status('publish');
        $product->add_meta_data('wsklad_id', $item['id']);
        $product->add_meta_data('wsklad_imgs_url', $item['images']['meta']['href']);
        $product->save_meta_data();

        $product->save();

        apply_filters(HOOK_PREFIX . 'get_categories_ids', $product->get_id(), $item['pathName']);

        update_img_queue($product->get_id());

    }
}

function add_attributes($product, $item_id)
{
    $modifications = wsklad_request("/entity/variant?filter=productid=$item_id");

    $variants = [];

    if (!$modifications || empty($modifications['rows']))
        return $product;

    do_action(HOOK_PREFIX . 'log', 'Found modifications ' . count($modifications['rows']) . ' for ' . $product->get_name());

    # loop through modifications and get all variants names and associate it with their values
    foreach ($modifications['rows'] as $mod) {
        $chars = $mod['characteristics'];

        foreach ($chars as $var) {
            $name = $var['name'];
            $value = $var['value'];

            if (array_key_exists($name, $variants)) {
                # add only if not in array already
                if (!in_array($value, $variants[$name])) {
                    array_push($variants[$name], $value);
                }
            } else {
                $variants[$name] = [$value];
            }
        }

    }

    if (empty($variants))
        return $product;

    $product = apply_filters(HOOK_PREFIX . 'set_variants', $product, $variants);


    return $product;
}

function set_variants($product, $variants)
{
    $attributes = [];
    foreach ($variants as $variant => $value) {

        $attribute = new WC_Product_Attribute();
        $attribute->set_name($variant);
        $attribute->set_options($value);
        $attribute->set_position(0);
        $attribute->set_visible(true);
        $attribute->set_variation(true);
        $attributes[] = $attribute;
    }

    $product->set_attributes($attributes);

    return $product;
}

function get_stock($product_id)
{
    $data = wsklad_request('/report/stock/bystore/current?filter=assortmentId=' . $product_id);

    $total = 0.0;

    if (!$data || empty($data))
        return $total;


    foreach ($data as $item) {
        $total += $item['stock'];
    }

    return $total;

}

function create_categories_by_path($product_id, $product_pathName = 'Misc')
{

    $category_names = explode('/', $product_pathName);
    foreach ($category_names as $category_name) {

        $result = wp_insert_term(
            $category_name,
            'product_cat'
        );

        if (!is_wp_error($result)) {
            wp_set_object_terms($product_id, $result[0], 'product_cat');

        } else {
            $msg = 'A term with the name provided already exists with this parent.';
            do_action(HOOK_PREFIX . 'log', 'Failed to create category ' . $category_name
                . ' .Error: ' . $result->get_error_message() . ' .Using existing category.');

            # if exists then error sends ID of the category
            if ($msg == $result->get_error_message()) {
                wp_set_object_terms($product_id, $result->get_error_data(), 'product_cat', true);

            }

        }



    }

}

function updoad_and_attach_img($product_id, $filename = 'img.jpg', $image_url = '')
{

    $id = check_exist_image_by_url($image_url);
    if ($id)
        return $id;

    $uploads_dir = wp_upload_dir();
    $filename_data = wp_check_filetype($filename);
    $filename = explode('.', $filename)[0] . '.' . $filename_data['ext'];
    $filename = sanitize_file_name($filename);
    $filename = wp_unique_filename($uploads_dir['path'], $filename);


    $header_array = [
        'Authorization' => 'Basic ' . base64_encode(get_option(HOOK_PREFIX . 'login') . ':'
            . get_option(HOOK_PREFIX . 'password')),
    ];

    $args = [
        'headers' => $header_array,
    ];

    $get = wp_remote_get($image_url, $args);

    if (is_wp_error($get)) {
        do_action(
            HOOK_PREFIX . 'log',
            'Error loading an image: ' . $get->get_error_message() . $get->get_error_code()
        );

        return false;

    }

    if (empty($get['response']['code'])) {
        return false;
    }

    if (403 == $get['response']['code']) {
        $http_response = $get['http_response'];

        if ($http_response->get_status() == 403) {
            $response = $http_response->get_response_object();
            $url_image = $http_response->get_response_object()->url;

            $get2 = wp_remote_get($url_image);
            $mirror = wp_upload_bits($filename, '', wp_remote_retrieve_body($get2));
        }
    } else {

        $mirror = wp_upload_bits($filename, '', wp_remote_retrieve_body($get));
    }

    $type = $filename_data['type'];

    if (!$type)
        return false;


    $attachment = array(
        'post_title' => $filename,
        'post_mime_type' => $type
    );

    $attach_id = wp_insert_attachment($attachment, $mirror['file'], $product_id);

    require_once(ABSPATH . 'wp-admin/includes/image.php');

    $attach_data = wp_generate_attachment_metadata($attach_id, $mirror['file']);

    update_post_meta($attach_id, HOOK_PREFIX . 'url', $image_url);

    wp_update_attachment_metadata($attach_id, $attach_data);

    do_action(
        HOOK_PREFIX . 'log',
        "Image is downloaded: " . $filename
    );

    return $attach_id;
}


function process_imgs($product, $imgs_url)
{
    $images = wsklad_request($imgs_url, true);
    if (!$images || empty($images['rows']))
        return;

    $img_ids = [];
    foreach ($images['rows'] as $image) {
        do_action(HOOK_PREFIX . 'log', 'Trying to download img for ' . $product->get_name() . ' with size ' . $image['size']);
        $id = updoad_and_attach_img($product->get_id(), $image['filename'], $image['meta']['downloadHref']);
        array_push($img_ids, $id);
    }

    if (!empty($img_ids)) {
        set_post_thumbnail($product->get_id(), array_shift($img_ids));
    }
    if (!empty($img_ids)) {
        $product->set_gallery_image_ids($img_ids);
        $product->save();
    }


}

function check_exist_image_by_url($img_url)
{
    $posts = get_posts([
        'post_type' => 'attachment',
        'meta_key' => HOOK_PREFIX . 'url',
        'meta_value' => $img_url
    ]);
    if (empty($posts)) {
        return false;
    } else {

        do_action(
            HOOK_PREFIX . 'log',
            'We have such image already'
        );

        return $posts[0]->ID;
    }
}

function update_img_queue($product_id)
{
    # Subject to be reworked;

    $prev = get_option(HOOK_PREFIX . 'img_queue');
    if ($prev) {
        array_push($prev, $product_id);
    } else {
        $prev = [$product_id];
    }
    update_option(HOOK_PREFIX . 'img_queue', $prev);
}

function clear_img_queue()
{
    update_option(HOOK_PREFIX . 'img_queue', []);
}

?>