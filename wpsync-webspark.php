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

function wpsync_webspark_activation()
{
    // Подключаем необходимые файлы WooCommerce
    if (!class_exists('WC_Product')) {
        include_once WC_ABSPATH . 'includes/abstracts/abstract-wc-product.php';
    }
    if (!class_exists('WC_Product_Simple')) {
        include_once WC_ABSPATH . 'includes/class-wc-product-simple.php';
    }

    $arr = [
        [
            'scu' => 'a1123c6d-2307-4c6c-8d15-a174d4cd031e',
            'name' => 'Wine',
            'description' => 'Pain in joint, upper arm',
            'price' => '$32.52',
            'picture' => 'http://dummyimage.com/229x121.jpg/5fa2dd/ffffff',
            'in_stock' => 162
        ],
        [
            'scu' => 'a1123c6d-2307-4c6c-8d15-a174d4cd031a',
            'name' => 'Salo',
            'description' => 'Pain in joint, upper arm',
            'price' => '$32.52',
            'picture' => 'http://dummyimage.com/229x121.jpg/5fa2dd/ffffff',
            'in_stock' => 164
        ],
        [
            'scu' => 'a1123c6d-2307-4c6c-8d15-a174d4cd031n',
            'name' => 'Vodka',
            'description' => 'Pain in joint, upper arm',
            'price' => '$32.52',
            'picture' => 'http://dummyimage.com/229x121.jpg/5fa2dd/ffffff',
            'in_stock' => 165
        ],
    ];

    // Получаем все товары из базы данных
    $existing_products = get_posts(array(
        'post_type' => 'product',
        'numberposts' => -1,
    ));

    foreach ($existing_products as $existing_product) {
        $existing_product_id = $existing_product->ID;
        $existing_sku = get_post_meta($existing_product_id, '_sku', true);

        if (!in_array($existing_sku, array_column($arr, 'scu'))) {
            // Товар не найден в массиве, выполняем удаление
            wp_delete_post($existing_product_id, true);
        }
    }

    foreach ($arr as $product_data) {
        $sku = $product_data['scu'];

        // Проверяем, был ли товар уже записан в базу
        $existing_product_id = wc_get_product_id_by_sku($sku);

        if ($existing_product_id) {
            // Товар уже записан в базу, получаем объект товара
            $existing_product = wc_get_product($existing_product_id);

            // Обновляем характеристики товара
            $existing_product->set_name($product_data['name']);
            $existing_product->set_description($product_data['description']);
            $existing_product->set_regular_price($product_data['price']);
            $existing_product->set_stock_quantity($product_data['in_stock']);
            // Здесь можно также обновить другие характеристики товара

            // Сохраняем обновленные характеристики товара в базе данных
            $existing_product->save();
        } else {
            // Товар еще не записан в базу, создаем новый объект товара
            $product = new WC_Product_Simple();

            // Устанавливаем значения свойств товара из массива $product_data
            $product->set_name($product_data['name']);
            $product->set_description($product_data['description']);
            $product->set_regular_price($product_data['price']);
            $product->set_sku($sku);
            $product->set_stock_quantity($product_data['in_stock']);
            // Здесь можно также установить другие свойства товара, такие как изображение и наличие на складе

            // Сохраняем товар в базе данных WooCommerce
            $product->save();
        }
    }
}

register_activation_hook(__FILE__, 'wpsync_webspark_activation');
