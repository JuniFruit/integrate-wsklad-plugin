<?php



defined('ABSPATH') or die('Not allowed!');



/* Main function for adding ACF fields to a product */
function add_acf_fields($product)
{
    do_action(HOOK_PREFIX . 'log', 'Filling ACF fields for ' . $product->get_name());

    if (!function_exists('acf_get_field_groups')) {
        do_action(HOOK_PREFIX . 'log', 'ACF plugin is not initiated or doesnt exist');
        return;
    }

    $classes_res = add_product_classes($product);
    if (gettype($classes_res) === 'string') {
        do_action(HOOK_PREFIX . 'log', 'Cannot update classes: ' . $classes_res);
    }


    $images_res = add_product_images($product);
    if (gettype($images_res) === 'string') {
        do_action(HOOK_PREFIX . 'log', 'Cannot update product images: ' . $images_res);

    }

    $tech_desc_res = add_product_tech_description($product);
    if (gettype($tech_desc_res) === 'string') {
        do_action(HOOK_PREFIX . 'log', 'Cannot update product tech block: ' . $tech_desc_res);

    }

    do_action(HOOK_PREFIX . 'log', 'Updating variations imgs...');
    update_variations_imgs($product);

}

/* 
    Update Classes tab for a product 
*/
function add_product_classes($product)
{
    $product_id = $product->get_id();
    $wsklad_mod_name = 'version'; # hardcoded taken from WSKLAD
    $acf_classes_id = 'klassy'; # hardcoded value taken from DB

    $acf_fields = acf_get_field($acf_classes_id);

    if (!$acf_fields) {
        return "ACF field $acf_classes_id was not found.";
    }

    $classes = $product->get_attribute($wsklad_mod_name);
    $gallery = get_images_from_product($product);

    if (empty($classes)) {
        return "Product does not have $wsklad_mod_name modification.";
    }

    # Should be Название, Картинка;
    if (empty($acf_fields['sub_fields'] || count($acf_fields['sub_fields']) < 2)) {
        return "ACF field $acf_classes_id has been changed.";
    }

    $classes_opt = explode(",", $classes);

    $fields_arr = [];
    $ind = 0;
    foreach ($classes_opt as $class) {

        $img = '';
        if (!empty($gallery)) {
            $img = array_shift($gallery);
        }

        $row = [
            $acf_fields['sub_fields'][0]['name'] => $class,
            $acf_fields['sub_fields'][1]['name'] => $img && $img !== 0 ? $img : intval(get_option('woocommerce_placeholder_image', 0)),
        ];

        $ind += 1;

        array_push($fields_arr, $row);
    }



    $field_key = $acf_fields['key'];

    return update_field($field_key, $fields_arr, $product_id) == false ? "Something went wrong." : true;

}

/* Update product images */

function add_product_images($product)
{
    $gallery = get_images_from_product($product);



    $product_id = $product->get_id();
    $acf_pics = "bolshie_kartinki"; # hardcoded taken from DB;
    $acf_pic_fields = acf_get_field($acf_pics);

    if (empty($acf_pic_fields)) {
        return "ACF field $acf_pics was not found.";
    }

    # Should have only Картинка sub-type
    if (empty($acf_pic_fields['sub_fields'])) {
        return "ACF field $acf_pics has been changed.";
    }
    $fields_arr = [];
    if (empty($gallery)) {
        $row = [
            $acf_pic_fields['sub_fields'][0]['name'] => intval(get_option('woocommerce_placeholder_image')),
        ];
        array_push($fields_arr, $row);
    } else {
        foreach ($gallery as $img_id) {

            $row = [
                $acf_pic_fields['sub_fields'][0]['name'] => $img_id !== 0 ? $img_id : intval(get_option('woocommerce_placeholder_image')),
            ];

            array_push($fields_arr, $row);

        }
    }

    $field_key = $acf_pic_fields['key'];

    return update_field($field_key, $fields_arr, $product_id) == false ? "Something went wrong." : true;

}

function update_variations_imgs($product)
{
    $variations = $product->get_available_variations();
    $gallery = get_images_from_product($product);
    foreach ($variations as $variant) {
        $variation = wc_get_product_object('variation', $variant['variation_id']);

        if (!empty($gallery)) {
            $img_id = array_pop($gallery);
            $variation->set_image_id($img_id !== 0 ? $img_id : get_option('woocommerce_placeholder_image'));
        } else {
            $variation->set_image_id(get_option('woocommerce_placeholder_image'));
        }

        $variation->save();
    }
}


/* Update product techs block */
function add_product_tech_description($product)
{
    $product_id = $product->get_id();
    $acf_tech_id = 'tehnologii'; # hardcoded value taken from DB
    $product_desc = $product->get_description();
    $acf_fields = acf_get_field($acf_tech_id);

    if (!$acf_fields) {
        return "ACF field $acf_tech_id was not found.";
    }

    if (empty($product_desc)) {
        return "No product description.";
    }


    $gallery = get_images_from_product($product);

    $sub_fields = $acf_fields['sub_fields'];

    # Should be Картинка, Вкладки
    if (empty($sub_fields)) {
        return "ACF field $acf_tech_id has been changed.";
    }
    $pic_field = $sub_fields[0]['name'];

    $fields_arr = [$pic_field => $gallery[0] && $gallery[0] !== 0 ? $gallery[0] : intval(get_option('woocommerce_placeholder_image'))]; # картинка
    $block_fields_arr = []; # блок вкладки

    $last_pos = 0;
    while ($last_pos <= strlen($product_desc)) {
        $block = $sub_fields[1]['sub_fields'];
        $block_title = $block[0]['name'];
        $sub_block = $block[1]['sub_fields'];
        $sub_block_title = $sub_block[0]['name'];
        $sub_block_body = $sub_block[1]['name'];

        $decoded = decode_wsklad_desc($last_pos, $product_desc);

        # if pos didnt change then we stuck
        if ($decoded[2] === $last_pos) {
            break;
        }

        $row = [
            $block_title => $decoded[0],
            $block[1]['name'] => [
                [
                    $sub_block_title => $decoded[0],
                    $sub_block_body => $decoded[1]
                ]
            ]
        ];

        array_push($block_fields_arr, $row);
        $last_pos = $decoded[2];

    }




    if (empty($block_fields_arr)) {
        return "Malformed description.";
    }

    $fields_arr[$sub_fields[1]['name']] = $block_fields_arr;
    $field_key = $acf_fields['key'];

    return update_field($field_key, $fields_arr, $product_id) == false ? "Something went wrong." : true;
}

function decode_wsklad_desc($start_pos = 0, $desc = '')
{

    # We return [0]=>title [1]=>paragraph [2]=>pos where we stopped
    $res = ['', '', $start_pos];


    $title_tag = '<h1>';
    $title_close_tag = '</h1>';
    $p_tag = '<p>';
    $p_close_tag = '</p>';

    $title_start = strpos($desc, $title_tag, $start_pos);
    $title_end = strpos($desc, $title_close_tag, $start_pos);

    if ($title_start === false || $title_end === false) {
        return $res;
    }
    $res[0] = substr($desc, $title_start + strlen($title_tag), $title_end - ($title_start + strlen($title_tag))); # Get title

    $p_tag_start = strpos($desc, $p_tag, $start_pos);
    $p_tag_end = strpos($desc, $p_close_tag, $start_pos);
    if ($p_tag_start === false || $p_tag_end === false) {
        return $res;
    }
    $res[1] = substr($desc, $p_tag_start + strlen($p_tag), $p_tag_end - ($p_tag_start + strlen($p_tag))); # Get paragraph
    $res[2] = $p_tag_end + strlen($p_close_tag);
    return $res;

}


/* Utils */

function get_images_from_product($product)
{
    $images = [intval($product->get_image_id()), ...$product->get_gallery_image_ids()];
    return $images;
}


function update_acf_fields_queue($product_id)
{

    $prev = get_option(HOOK_PREFIX . 'acf_fields_queue');
    if ($prev) {
        array_push($prev, $product_id);
    } else {
        $prev = [$product_id];
    }
    update_option(HOOK_PREFIX . 'acf_fields_queue', $prev);
}

function clear_acf_fields_queue()
{
    update_option(HOOK_PREFIX . 'acf_fields_queue', []);
}

?>