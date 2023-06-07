<?php
/*
Plugin Name: wpsync-webspark
Description: updates the product database for woocommerce.
Version:  1.0
Author: Ruslan Toloshnyi
*/

/*  Copyright 2023  Ruslan Toloshnyi  (email: ruslantoloshnyi@gmail.com)

    This program is free software; you can redistribute it and/or modify
    it under the terms of the GNU General Public License as published by
    the Free Software Foundation; either version 2 of the License, or
    (at your option) any later version.

    This program is distributed in the hope that it will be useful,
    but WITHOUT ANY WARRANTY; without even the implied warranty of
    MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
    GNU General Public License for more details.

    You should have received a copy of the GNU General Public License
    along with this program; if not, write to the Free Software
    Foundation, Inc., 51 Franklin St, Fifth Floor, Boston, MA  02110-1301  USA
*/
?>

<?php
defined('ABSPATH') || exit;

function add_webp_support($mimes) {
    $mimes['webp'] = 'image/webp';
    return $mimes;
}
add_filter('mime_types', 'add_webp_support');

// Register a plugin activation hook
register_activation_hook(__FILE__, 'wpsync_webspark_activation');

// Register a plugin deactivation hook
register_deactivation_hook(__FILE__, 'wpsync_webspark_deactivation');

// Register cron event to execute every hour
add_action('wpsync_webspark_hourly_event', 'wpsync_webspark_hourly_cron');

// Plugin activation function
function wpsync_webspark_activation() {

    // Cron event to run every hour
    if (!wp_next_scheduled('wpsync_webspark_hourly_event')) {
        wp_schedule_event(time(), 'hourly', 'wpsync_webspark_hourly_event');
    }
}

// Plugin deactivation function
function wpsync_webspark_deactivation() {
    // Delete cron event when deactivate plugin
    wp_clear_scheduled_hook('wpsync_webspark_hourly_event');
}

// Function to be called when the cron event every hour
function wpsync_webspark_hourly_cron() {
    $api_url = 'https://wp.webspark.dev/wp-api/products';
    $max_count = 5;
    $count = 1;

    set_time_limit(3000);

    do {
        // Get JSON data from API
        $response = wp_remote_get($api_url);

        // Check the success of the request
        if (!is_wp_error($response)) {
            break; 
        }

        $count++;
    } while ($count <= $max_count);

    if (is_wp_error($response)) {
        return; // Exit the function if all attempts fail
    }

    // Get the response body
    $body = wp_remote_retrieve_body($response);

    // Convert JSON to Data Array
    $data = json_decode($body, true);

    // Check Successful Data Conversion
    if (!$data || !isset($data['error']) || $data['error']) {
        return;
    }

    // Get an array of products from data
    $products = isset($data['data']) ? $data['data'] : [];

    // Including the necessary WooCommerce files
    if (!class_exists('WC_Product')) {
        include_once WC_ABSPATH . 'includes/abstracts/abstract-wc-product.php';
    }
    if (!class_exists('WC_Product_Simple')) {
        include_once WC_ABSPATH . 'includes/class-wc-product-simple.php';
    }

    // Get all products from the database
    $existing_products = get_posts(array(
        'post_type' => 'product',
        'numberposts' => -1,
    ));

    foreach ($existing_products as $existing_product) {
        $existing_product_id = $existing_product->ID;
        $existing_sku = get_post_meta($existing_product_id, '_sku', true);

        // Check if the SKU exists in the API data
        $product_exists = false;
        foreach ($products as $product) {
            if ($product['sku'] === $existing_sku) {
                $product_exists = true;
                break;
            }
        }

        if (!$product_exists) {
            // Product not found in API data, delete from database
            wp_delete_post($existing_product_id, true);
        }
    }

    foreach ($products as $product_data) {
        $sku = $product_data['sku'];

        // Check if the product has already been added to the database
        $existing_product_id = wc_get_product_id_by_sku($sku);

        if ($existing_product_id) {
            // The product has already been written to the database, get the product object
            $existing_product = wc_get_product($existing_product_id);

            // update the characteristics of the product
            $existing_product->set_name($product_data['name']);
            $existing_product->set_description($product_data['description']);
            $existing_product->set_regular_price($product_data['price']);
            $existing_product->set_stock_quantity($product_data['in_stock']);

            $existing_product->save();
        } else {

            // The product has not yet been added to the database, create a new product object
            $product = new WC_Product_Simple();

            // Set product properties from $product_data
            $product->set_name($product_data['name']);
            $product->set_description($product_data['description']);
            $product->set_regular_price($product_data['price']);
            $product->set_sku($sku);
            $product->set_stock_quantity($product_data['in_stock']);

            // Add a product image
            if (!empty($product_data['picture'])) {
                $attachment_id = wpsync_webspark_upload_product_image($product_data['picture']);
                if ($attachment_id) {
                    $product->set_image_id($attachment_id);
                }
            }

            $product->save();
        }
    }
}

// Getting the Path to the WordPress Downloads Directory
function get_upload_dir() {
    $upload_dir = wp_upload_dir();
    return $upload_dir['path'];
}

// get image data from url
function get_image_data($image_url) {
    $image_data = file_get_contents($image_url);
    return $image_data;
}

// Create image file
function create_image_file($upload_dir, $image_data) {
    $filename = uniqid() . '.webp'; // Generating a unique filename
    $file = $upload_dir . '/' . $filename;
    file_put_contents($file, $image_data);
    return $file;
}

// Add an Image to the WordPress Media Library
function add_image_to_media_library($file) {
    $attachment = array(
        'post_mime_type' => 'image/webp',
        'post_title'     => sanitize_file_name(basename($file)),
        'post_content'   => '',
        'post_status'    => 'inherit'
    );
    $attachment_id = wp_insert_attachment($attachment, $file);
    require_once(ABSPATH . 'wp-admin/includes/image.php');
    $attachment_data = wp_generate_attachment_metadata($attachment_id, $file);
    wp_update_attachment_metadata($attachment_id, $attachment_data);
    return $attachment_id;
}

function wpsync_webspark_upload_product_image($image_url) {
    $upload_dir = get_upload_dir();
    $image_data = get_image_data($image_url);
    
    if ($image_data) {
        $file = create_image_file($upload_dir, $image_data);
        $attachment_id = add_image_to_media_library($file);
        return $attachment_id;
    }
    
    return 0;
}



