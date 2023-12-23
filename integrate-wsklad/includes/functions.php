<?php



defined('ABSPATH') or die('Not allowed!');

add_filter(HOOK_PREFIX . 'get_attributes', 'add_attributes', 10, 2);
add_filter(HOOK_PREFIX . 'get_categories_ids', 'create_categories_by_path', 20, 2);
add_filter(HOOK_PREFIX . 'set_variants', 'set_variants', 10, 2);
add_filter(HOOK_PREFIX . 'set_variations', 'set_variations', 10);
add_filter(HOOK_PREFIX . 'set_stock', 'set_stock_for_variations', 10, 2);




/**
 * Method to delete Woo Product
 * 
 * @param int $id the product ID.
 * @param bool $force true to permanently delete product, false to move to trash.
 
 */
function wh_deleteProducts($force = false, $batch = 10)
{


    $products = wc_get_products(['numberposts' => $batch, 'status' => 'draft']);

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

    if (is_wp_error($request)) {
        do_action(HOOK_PREFIX . 'log', "WSKLAD request error: " . $request->get_error_message() . ','
            . $request->get_error_data());
        return false;

    }

    $response = json_decode($request['body'], true);

    

    return $response;
}

function update_product($product, $item)
{
    require_once PLUGIN_PATH . 'includes/acf_funcs.php';
    $product->set_name($item['name']); // product title


    $product->set_price($item['minPrice']['value']);
    if (!empty($item['salePrices'])) {
        $prices = $item['salePrices'];
        $product->set_regular_price(array_pop($prices)['value']);
        if (!empty($prices)) {
            $product->set_sale_price($item['salePrices'][0]['value']);
        }
    } else {
        $product->set_regular_price($item['minPrice']['value']);
    }

    if (isset($item['description'])) {
        $desc = $item['description'];
        $first_sentence_end = strpos($desc, '.');
        if ($first_sentence_end) {
            $product->set_short_description(substr($desc, 0, $first_sentence_end));
        }
        $product->set_description($desc);
    } else {
        $product->set_description('');
    }
    $stock = intval(get_stock($item['id']));
    $product->set_manage_stock(true);
    $product->set_stock_status($stock > 0 ? 'instock' : 'outofstock');
    $product->set_stock_quantity($stock);
    $product->set_backorders('no'); // 'yes', 'no' or 'notify'
    $product->set_low_stock_amount(0);
    $product->set_sold_individually(true);
    $product->set_status('publish');
    $product->save();

    apply_filters(HOOK_PREFIX . 'get_categories_ids', $product->get_id(), $item['pathName']);
    if ($item['variantsCount'] > 0) {
        apply_filters(HOOK_PREFIX . 'get_attributes', $product, $item['id']);
        // apply_filters(HOOK_PREFIX . 'set_stock', $product, $item['id']);
    } else {
        $product->set_attributes([]);
    }


    if ($item['images']['meta']['size'] > 0) {
        do_action(HOOK_PREFIX . 'log', "Found " . $item['images']['meta']['size'] . ' images for '
            . $product->get_name() . ' adding to queue');
        $product->add_meta_data('wsklad_id', $item['id']);
        $product->add_meta_data('wsklad_imgs_url', $item['images']['meta']['href']);
        $product->save_meta_data();
        update_img_queue($product->get_id());
    } else {
        delete_post_meta($product->get_id(), 'wsklad_id');
        delete_post_meta($product->get_id(), 'wsklad_imgs_url');
        set_post_thumbnail($product->get_id(), get_option('woocommerce_placeholder_image'));
        $product->set_gallery_image_ids([get_option('woocommerce_placeholder_image')]);
        $product->save();

    }
    update_acf_fields_queue($product->get_id());
}

function create_or_update_woo_products($items)
{
    foreach ($items as $item) {
        $products = wc_get_products(['slug' => $item['id'], 'status' => 'draft']);
        $product;
        if (empty($products)) {
            do_action(HOOK_PREFIX . 'log', "Couldn't find " . $item['name'] . ". Creating new one...");
            $product = new WC_Product_Variable();

            $product->set_slug($item['id']);

        } else {
            $product = $products[0];
        }


        do_action(HOOK_PREFIX . 'log', "Updating product " . $item['name'] . "...");
        update_product($product, $item);
        $product->save();
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

function set_variations($attributes, $product)
{
    # We setting only color variations

    do_action(HOOK_PREFIX . 'log', "Setting variations...");
    $color_attr = null;

    foreach ($attributes as $attr) {

        if ($attr->get_name() === 'pa_czvet') {
            $color_attr = $attr;
            break;
        } else {
            continue;
        }

    }

    # Options from wsklad
    $color_options = [];
    # Existing product variations
    $color_variations = [];
    if ($color_attr) {
        $color_options = $color_attr->get_options();
        $variations = $product->get_available_variations();
        foreach ($variations as $variation) {
            if (isset($variation['attributes']['attribute_' . $color_attr->get_name()])) {
                array_push($color_variations, $variation);
            }
        }
    }

    if (count($color_variations) < 1 && !empty($color_options)) {
        do_action(HOOK_PREFIX . 'log', "No variations found. Creating variations...");
        create_variations_for_product($product, $color_attr->get_name(), $color_options);
        return;
    }


    # Update existing variations if there is a difference delete or create variation
    do_action(HOOK_PREFIX . 'log', "Updating variations... Found " . count($color_variations));

    foreach ($color_variations as $color_var) {
        $curr_var = wc_get_product($color_var['variation_id']);


        if (!empty($color_options)) {
            $color_option = array_pop($color_options);
            $curr_var->set_attributes([$color_attr->get_name() => $color_option]);
            $curr_var->set_manage_stock(true);
            $curr_var->set_regular_price($product->get_regular_price());
            $curr_var->set_stock_quantity($product->get_stock_quantity());
            $curr_var->set_stock_status($product->get_stock_status());
            $curr_var->set_sale_price($product->get_sale_price());
            $curr_var->set_price($product->get_price());
            $curr_var->save();
        } else {
            $curr_var->delete(true);
        }
    }

    if (!empty($color_options)) {
        create_variations_for_product($product, $color_attr->get_name(), $color_options);
    }
    return;
}

function create_variations_for_product($product, $attr_name, $options)
{
    foreach ($options as $option) {
        $variation = new WC_Product_Variation();
        $variation->set_parent_id($product->get_id());
        $variation->set_manage_stock(true);
        $variation->set_attributes(array($attr_name => $option));
        $variation->set_regular_price($product->get_regular_price());
        $variation->set_stock_quantity($product->get_stock_quantity());
        $variation->set_low_stock_amount(0);
        $variation->set_sale_price($product->get_sale_price());
        $variation->set_stock_status($product->get_stock_status());
        $variation->set_price($product->get_price());
        $variation->save();
    }

}

function set_variants($product, $variants)
{
    $attributes = [];
    $pos = 0;
    foreach ($variants as $variant => $value) {

        $attribute = new WC_Product_Attribute();
        $attribute->set_position($pos);
        $attribute->set_visible(true);
        $attribute->set_variation($variant === "Цвет" ? true : false);
        $pos += 1;

        $existingTaxes = wc_get_attribute_taxonomies();

        $attribute_labels = wp_list_pluck($existingTaxes, 'attribute_label', 'attribute_name');
        $slug = array_search($variant, $attribute_labels, true);

        if (!$slug) {
            //Not found, so create it:
            $slug = wc_sanitize_taxonomy_name($variant);
            $attribute_id = create_global_attribute($variant, $slug);
        } else {
            do_action(
                HOOK_PREFIX . 'log',
                "Taxonomy exist " . $variant . ". Using existing one."
            );
            //Otherwise find it's ID
            //Taxonomies are in the format: array("slug" => 12, "slug" => 14)
            $taxonomies = wp_list_pluck($existingTaxes, 'attribute_id', 'attribute_name');

            if (!isset($taxonomies[$slug])) {
                do_action(
                    HOOK_PREFIX . 'log',
                    "Could not get wc attribute ID for attribute " . $variant . " (slug: " . $slug . ") which should have existed!"
                );
                continue;
            }

            $attribute_id = (int) $taxonomies[$slug];
        }

        $taxonomy_name = wc_attribute_taxonomy_name($slug);

        $attribute->set_id($attribute_id);
        $attribute->set_name($taxonomy_name);
        $attribute->set_options($value);

        $attributes[] = $attribute;

    }

    $product->set_attributes($attributes);
    set_variations($attributes, $product);
    $product->save();
}


function set_stock_for_variations($product, $item_id)
{
    $variations = $product->get_available_variations();
    $stock = intval(get_stock($item_id));
    $product->set_stock_status($stock > 0 ? 'instock' : 'outofstock'); // 'instock', 'outofstock' or 'onbackorder'

    foreach ($variations as $variation) {
        wc_update_product_stock($variation['variation_id'], $stock);
    }

    $product->save();
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
    $i = 0;
    foreach ($category_names as $category_name) {

        $result = wp_insert_term(
            $category_name,
            'product_cat'
        );

        if (!is_wp_error($result)) {
            wp_set_object_terms($product_id, $result[0], 'product_cat', $i > 0);

        } else {
            do_action(HOOK_PREFIX . 'log', 'Failed to create category ' . $category_name
                . '. Error: ' . $result->get_error_message() . ' Using existing category.');

            # error sends ID of the category
            wp_set_object_terms($product_id, $result->get_error_data(), 'product_cat', $i > 0);
        }
        $i += 1;


    }

}

function updoad_and_attach_img($product_id, $filename = 'img.jpg', $image_url = '')
{

    $id = check_exist_image_by_url($image_url . $product_id);
    if ($id) {
        return $id;

    }

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

    $reroute_server = get_option(HOOK_PREFIX . 'reroute_server');
    $get = '';
    if (!empty($reroute_server)) {
        $endpoint = $reroute_server . '?image_url=' . $image_url;
        do_action(HOOK_PREFIX . 'log', "Reroute server endpoint exists. Sending request to" . $endpoint);
        $get = wp_remote_get($endpoint, $args);
    } else {
        $get = wp_remote_get($image_url, $args);
    }


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

    update_post_meta($attach_id, HOOK_PREFIX . 'url', $image_url . $product_id);

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

function create_global_attribute($name, $slug)
{

    $taxonomy_name = wc_attribute_taxonomy_name($slug);

    if (taxonomy_exists($taxonomy_name)) {
        return wc_attribute_taxonomy_id_by_name($slug);
    }

    do_action(HOOK_PREFIX . 'log', 'Creating a new Taxonomy ' . $taxonomy_name . ' with slug ' . '$slug');
    $attribute_id = wc_create_attribute(
        array(
            'name' => $name,
            'slug' => $slug,
            'type' => 'select',
            'order_by' => 'menu_order',
            'has_archives' => false,
        )
    );

    register_taxonomy(
        $taxonomy_name,
        apply_filters('woocommerce_taxonomy_objects_' . $taxonomy_name, array('product')),
        apply_filters(
            'woocommerce_taxonomy_args_' . $taxonomy_name,
            array(
                'labels' => array(
                    'name' => $name,
                ),
                'hierarchical' => true,
                'show_ui' => false,
                'query_var' => true,
                'rewrite' => false,
            )
        )
    );

    //Clear caches
    delete_transient('wc_attribute_taxonomies');

    return $attribute_id;
}

?>