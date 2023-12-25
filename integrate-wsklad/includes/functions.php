<?php



defined('ABSPATH') or die('Not allowed!');

add_filter(HOOK_PREFIX . 'add_attributes', 'add_attributes', 10, 2);
add_filter(HOOK_PREFIX . 'set_categories', 'create_categories_by_path', 20, 2);
add_filter(HOOK_PREFIX . 'set_variants', 'set_variants', 10, 2);

/**
 * Gets products with wsklad_id meta key, returns Wc_Product instances
 */

function get_wsklad_wc_products($status, $batch) {
    $args = array(
        'post_type' => 'product',
        'post_status' => $status,
        'numberposts' => $batch,
        'meta_query' => [
            [
            'key' => 'wsklad_id',
            'compare' => 'EXISTS',
            ]
        ]
    );
    
    $posts = get_posts( $args );
    $wc_instance_products = [];

    if (count($posts) > 0) {
        foreach ($posts as $post) {
            $wc_instance = wc_get_product($post->ID);
            if ($wc_instance) array_push($wc_instance_products, $wc_instance);
        }
    }

    return $wc_instance_products;
}

function get_wsklad_wc_product($status, $wsklad_id) {
    $args = array(
        'post_type' => 'product',
        'post_status' => $status,
        'meta_query' => [
            [
            'key' => 'wsklad_id',
            'value' => $wsklad_id
            ]
        ]
    );
    $posts = get_posts( $args );
    if (count($posts) === 0) return;
    $wc_instance = wc_get_product($posts[0]->ID);
    return $wc_instance;
}


/**
 * Method to delete Woo Product
 * 
 * @param int $id the product ID.
 * @param bool $force true to permanently delete product, false to move to trash.
 
 */
function wh_deleteProducts($force = false, $batch = 10)
{


    $products = get_wsklad_wc_products('draft', $batch);

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
        delete_post_meta($id, 'is_wsklad_variation');
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
        $product->set_short_description($desc);
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

    apply_filters(HOOK_PREFIX . 'set_categories', $product->get_id(), $item['pathName']);
    if ($item['variantsCount'] > 0) {
        apply_filters(HOOK_PREFIX . 'add_attributes', $product, $item['id']);
        // apply_filters(HOOK_PREFIX . 'set_stock', $product, $item['id']);
    } else {
        update_product_variations_queue($product->get_id()); // put in queue to delete existing variations later
        $product->set_attributes([]);
    }


    $product->add_meta_data('wsklad_id', $item['id']);

    if ($item['images']['meta']['size'] > 0) {
        do_action(HOOK_PREFIX . 'log', "Found " . $item['images']['meta']['size'] . ' images for '
            . $product->get_name() . ' adding to queue');
        $product->add_meta_data('wsklad_imgs_url', $item['images']['meta']['href']);
        $product->add_meta_data('is_wsklad_variation', "false");
        update_img_queue($product->get_id());
    } else {
        delete_post_meta($product->get_id(), 'wsklad_imgs_url');
        delete_post_meta($product->get_id(), 'is_wsklad_variation');
        set_post_thumbnail($product->get_id(), get_option('woocommerce_placeholder_image'));
        $product->set_gallery_image_ids([get_option('woocommerce_placeholder_image')]);
        
    }
    $product->save_meta_data();
    update_acf_fields_queue($product->get_id());
}

function create_or_update_woo_products($items)
{
    foreach ($items as $item) {
        $product = get_wsklad_wc_product('draft', $item['id']);
        
        if (!$product) {
            do_action(HOOK_PREFIX . 'log', "Couldn't find " . $item['name'] . ". Creating new one...");
            $product = new WC_Product_Variable();
            $product->set_slug($item['id']);
        }

        do_action(HOOK_PREFIX . 'log', "Updating product " . $item['name'] . "..." . 'ID: ' . $item['id']);
        update_product($product, $item);
        $product->save();
    }
}

function delete_product_variations($product) {
    $variation_ids = $product->get_children();

    if (count($variation_ids) > 0) {
        foreach ($variation_ids as $variation) {
            $wc_instance = wc_get_product($variation);
            if (!$wc_instance) continue;
            $wc_instance->delete(true);
            delete_post_meta($variation, 'wsklad_imgs_url');
            delete_post_meta($variation, 'is_wsklad_variation');
        } 
    } 
}


function add_attributes($product, $item_id)
{
    $modifications = wsklad_request("/entity/variant?filter=productid=$item_id");

    $variants = [];
    
    if (!$modifications || empty($modifications['rows'])) {
        return $product;
    }
    
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

    update_product_variations_queue($product->get_id());
    $product = apply_filters(HOOK_PREFIX . 'set_variants', $product, $variants);

    return $product;
}


function set_variations_for_product($product, $wsklad_id) {
    $modifications = wsklad_request("/entity/variant?filter=productid=$wsklad_id");
    $variations_data = [];
    
    if (!$modifications || empty($modifications['rows'])) {
        do_action(HOOK_PREFIX . 'log', 'Modifications not found'  . ' for ' . $product->get_name());
        delete_product_variations($product);
        return $product;
    }

    do_action(HOOK_PREFIX . 'log', "Setting variations for " . $product->get_name() . ' ID: ' . $wsklad_id);

    foreach ($modifications['rows'] as $mod) {
        $chars = $mod['characteristics'];
        $name = $mod['name'];
        $variations_data[$name] = array();
        $variation_attrs = array();
        foreach($chars as $characteristic) {
            $char_name = $characteristic['name'];
            $char_value = $characteristic['value'];
            $variation_attrs[$char_name] = $char_value;
        }
        $price = $product->get_regular_price();
        if (count($mod['salePrices']) > 0) {
            $price = $mod['salePrices'][0]['value'];
        }
        $variations_data[$name]['attributes'] = $variation_attrs;
        $variations_data[$name]['price'] = $price;
        $stock = get_stock($mod['id']);
        if (!$stock) {
            $stock = $product->get_stock_quantity();
        }
        $variations_data[$name]['stock'] = $stock;
        $variations_data[$name]['name'] = $name;
        $variations_data[$name]['description'] = $mod['description'] ? $mod['description'] : '';
        $variations_data[$name]['images_count'] =  $mod['images']['meta']['size'];
        $variations_data[$name]['images_url'] = $mod['images']['meta']['href'];
    }

    $existing_variations = $product->get_children(); // ids
    if (count($existing_variations) > 0) {
        do_action(HOOK_PREFIX . 'log', "Found variations for " . $product->get_name() . ".Updating existing ones...");
        while (count($existing_variations) > 0) {
            $current_variation = wc_get_product( array_pop($existing_variations));
            if (!$current_variation) continue;
            $new_variation_data = null;
            if (count($variations_data) > 0) $new_variation_data = array_pop($variations_data);
            if ($new_variation_data) update_variation_for_product($product, $new_variation_data, $current_variation);
            if (!$new_variation_data && $current_variation->get_meta('is_wsklad_variation') === "true") {
                do_action(HOOK_PREFIX . 'log', "Excessive variation exist. Deleting...");
                $current_variation->delete(true);
            }
        }
        if (count($variations_data) > 0) {
            do_action(HOOK_PREFIX . 'log', "New variations were found. Creating...");
            create_variations_for_product($product, $variations_data);
        }

    } else {
        do_action(HOOK_PREFIX . 'log', "Creating new variations for " . $product->get_name());
        create_variations_for_product($product, $variations_data);
    }

}


/**
 * $product = WC_Product
 * $variation = WC_Product_Variation()
 */

function update_variation_for_product($product, $variation_data, $variation) {
        $variation->set_parent_id($product->get_id());
        $variation->set_manage_stock(true);
        $variation->set_attributes($variation_data['attributes']);
        $variation->set_regular_price($variation_data['price']);
        $variation->set_stock_quantity($variation_data['stock']);
        $variation->set_low_stock_amount(0);
        $variation->set_stock_status($variation_data['stock'] > 0 ? 'instock' : 'outofstock');
        $variation->set_price($variation_data['price']);
         if ($variation_data['description']) {
            $variation->set_description($variation_data['description']);
            $variation->set_short_description($variation_data['description']);
        }
        $variation->save();

        update_post_meta($variation->get_id(), 'is_wsklad_variation', "true");
        if ($variation_data['images_count'] > 0) {
            update_post_meta($variation->get_id(), 'wsklad_imgs_url', $variation_data['images_url']);
            update_img_queue($variation->get_id());
        }

}

function create_variations_for_product($product, $variations)
{
    foreach ($variations as $variation_data) {
        $variation = new WC_Product_Variation();
        update_variation_for_product($product, $variation_data, $variation);
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
    return $img_ids;
}


function set_images_for_product($product, $img_ids, $is_set_thumbnail = false) {
    if (!empty($img_ids) && $is_set_thumbnail) {
        set_post_thumbnail($product->get_id(), array_shift($img_ids));
        if (!empty($img_ids)) {
            $product->set_gallery_image_ids($img_ids);
        }
    } else if (!empty($img_ids)) {
        $product->set_image_id(array_shift($img_ids));
    }
    $product->save();
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

function update_product_variations_queue($product_id) {
    $prev = get_option(HOOK_PREFIX . 'product_variations_queue');
    if ($prev) {
        array_push($prev, $product_id);
    } else {
        $prev = [$product_id];
    }
    update_option(HOOK_PREFIX . 'product_variations_queue', $prev);
}

function clear_product_variations_queue() {
    update_option(HOOK_PREFIX . 'product_variations_queue', []);
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