<?php
/*
* Plugin Name: Kluvos
* Description: Official plugin for Kluvos.
* Author: Kluvos, LLC
* Version: 1.1.2
* Requires at least: 1.0
* Tested up to: 6.6.1
* Stable tag: 1.1.2
* Requires PHP: 7.0
* License: GPLv2 or later
* License URI: https://www.gnu.org/licenses/gpl-2.0.html
*/

if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly


add_action('wp_loaded', 'kluvos_init_plugin');
add_action('admin_notices', 'kluvos_plugin_admin_notice' );
add_action('admin_init', 'kluvos_register_settings');
add_action('admin_menu', 'kluvos_add_admin_menu');
add_action('rest_api_init', function () {
    register_rest_route('kluvos/v1', '/save-options', array(
        'methods' => 'POST',
        'callback' => 'kluvos_save_options',
        'permission_callback' => '__return_true', // No permission check for simplicity
    ));
});





function kluvos_init_plugin() {

    // Fetch the entire options array
    $options = get_option('kluvos_plugin_options');



    // Access the property_id and property_token from the options array
    $property_id = isset($options['property_id']) ? $options['property_id'] : '';
    $property_token = isset($options['property_token']) ? $options['property_token'] : '';


    // Check if both property_id and property_token are set
    if ( empty($property_id) || empty($property_token) ) {
        // You can also add an admin notice here if needed
        return;
    }

      // Check for WooCommerce dependency
      if (!function_exists('WC')) {
        return;
    }

    if (!is_admin()) {
         kluvos_enqueue_woocommerce_script($property_id);
    }

    add_action( 'wp_enqueue_scripts', 'kluvos_enqueue_woocommerce_script' );

    add_filter( 'script_loader_tag', 'kluvos_customize_script_tag', 10, 3 );

    add_action('woocommerce_add_to_cart', 'kluvos_send_add_to_cart_data_to_server', 10, 6);
    add_action('woocommerce_thankyou', 'kluvos_send_order_to_server', 10, 1);

    add_action('template_redirect', 'kluvos_check_for_cart_token');

    add_action('woocommerce_cart_item_removed', 'kluvos_handle_cart_item_removal', 10, 2);

    // Hook into WooCommerce product page load
    add_action('woocommerce_after_single_product', 'kluvos_send_viewed_product_to_server');

}

##
##ALERT NOTICES
##

function kluvos_plugin_admin_notice() {
    // Fetch the entire options array
    $options = get_option('kluvos_plugin_options');

    // Access the property_id and property_token from the options array
    $property_id = isset($options['property_id']) ? $options['property_id'] : '';
    $property_token = isset($options['property_token']) ? $options['property_token'] : '';


    // Check if WooCommerce is not active
    if (!class_exists('WooCommerce')) {
        ?>
        <div class="notice notice-warning is-dismissible">
            <p><?php esc_html_e('Error: WooCommerce is not active. The Kluvos plugin requires WooCommerce to function.', 'text-domain'); ?></p>
            <p>
                <a href="https://kluvos.com" class="button button-primary"><?php esc_html_e('Visit Kluvos', 'text-domain'); ?></a>
            </p>
        </div>
        <?php
    } elseif (empty($property_id) || empty($property_token)) {
        // Check if both property_id and property_token are set
        ?>
        <div class="notice notice-error is-dismissible">
            <p><?php esc_html_e('In order to use the Kluvos plugin, you must have a Kluvos account. If you do not have one, setting one up is quick and easy!', 'text-domain'); ?></p>
            <p> <a href="https://kluvos.com/users/sign_up" class="button button-primary"><?php esc_html_e('Create Account', 'text-domain'); ?></a>
            </p>
            <p> <?php esc_html_e('Otherwise, please authenticate Kluvos with your WooCommerce store. This can be done in the Set-Up menu inside your Kluvos dashboard.', 'text-domain'); ?></p>
        </div>
        <?php
    }

}

###API 

function kluvos_save_options(WP_REST_Request $request) {
    $options = $request->get_param('kluvos_plugin_options');

    if (!is_array($options)) {
        return new WP_Error('rest_invalid_param', 'Invalid options format', array('status' => 400));
    }

    // Define the expected structure of the options array
    $expected_options = array(
        'property_id' => 'intval',
        'property_token' => 'sanitize_text_field',
    );

    $sanitized_options = array();

    foreach ($expected_options as $key => $sanitize_callback) {
        if (isset($options[$key])) {
            // Sanitize the value
            $sanitized_options[$key] = call_user_func($sanitize_callback, $options[$key]);
        } else {
            return new WP_Error('rest_invalid_param', "Missing required option: $key", array('status' => 400));
        }
    }

    update_option('kluvos_plugin_options', $sanitized_options);

    return new WP_REST_Response('Options saved', 200);
}

###
##MAIN FUNCTIONS 
###

function kluvos_enqueue_woocommerce_script($property_id) {
        wp_enqueue_script( 'kluvos-site-script', "https://track.kluvos.com/properties/$property_id/trx.js", array(), null, true );
}





function kluvos_customize_script_tag( $tag, $handle, $src ) {
// Fetch the entire options array
$options = get_option('kluvos_plugin_options');

// Access property_token from the options array
$property_token = isset($options['property_token']) ? $options['property_token'] : '';

    if ( $handle === 'kluvos-site-script' ) {
        // Add the data attribute
        $data_attribute = ' data-kluvos-verification="' . esc_attr($property_token) . '"';
        
        // Choose between async or defer
        $async_or_defer = ' defer'; 

        // Modify the script tag
        $tag = str_replace( ' src', $data_attribute . $async_or_defer . ' src', $tag );
    }

    return $tag;
}


function kluvos_send_add_to_cart_data_to_server($cart_item_key, $product_id, $quantity, $variation_id, $variation, $cart_item_data) {

    // Sanitize and validate cart item key
    $cart_item_key = sanitize_text_field($cart_item_key);

    // Check if the cookie exists and sanitize it
    if (!isset($_COOKIE['_kpixel_s'])) {
        return;
    }

    if (isset($_COOKIE['_kpixel_lt'])) {
        $ltCookie = sanitize_text_field($_COOKIE['_kpixel_lt']);

        // Find the position of the first colon
        $pos = strpos($ltCookie, ':');
    
        if ($pos !== false) {
            // Extract the substring after the first colon
            $ltCookie = substr($ltCookie, $pos + 1);
        }
    
        // Now remove all remaining colons
        $cart_token = str_replace(':', '', $ltCookie);
    
        // $cart_token now contains the cookie value with the first part and all colons removed
    }

    
    $session_cookie = sanitize_text_field($_COOKIE['_kpixel_s']);

    // Get WooCommerce cart
    $cart = WC()->cart;

    // Trigger cart totals recalculation
    $cart->calculate_totals();

    $cart_contents = $cart->get_cart();


    // Add variant details to each cart item
    $cart_contents_with_details = kluvos_add_variant_details_to_cart_items($cart_contents);



    // Fetch the entire options array
    $options = get_option('kluvos_plugin_options');

    // Access the property_id and property_token from the options array
    $property_id = isset($options['property_id']) ? $options['property_id'] : '';



    // Prepare event data
    $event_data = array(
        'session_cookie' => $session_cookie,
        'property_id' => sanitize_text_field($property_id),
        'items' =>  $cart_contents_with_details, // Consider sanitizing individual item data if needed
        'item_count' => $cart->get_cart_contents_count(),
        'cart_token' => $cart_token,
        'currency' => get_woocommerce_currency(),
        'total_discount' => $cart->get_cart_discount_total(),
        'original_total_price' => $cart->get_subtotal(),
        'total_price' => $cart->get_total('edit'),
        'time_utc' => current_time('c')
    );


    // Data structure
    $data = array(
        'events' => array(
            array(
                'event_type' => 'add_to_cart',
                'event_data' => $event_data
            )
        )
    );

    // Send POST request to server
    $url = 'https://track.kluvos.com/events/create-batch';
    $response = wp_remote_post($url, array(
        'method' => 'POST',
        'headers' => array('Content-Type' => 'application/json; charset=utf-8'),
        'body' => wp_json_encode($data),
        'data_format' => 'body',
        'timeout' => 5
    ));


}

function kluvos_handle_cart_item_removal($cart_item_key, $cart) {

    // Check if the cookie exists and sanitize it
    if (!isset($_COOKIE['_kpixel_s'])) {
        return;
    }

    if (isset($_COOKIE['_kpixel_lt'])) {
        $ltCookie = sanitize_text_field($_COOKIE['_kpixel_lt']);    
        
        // Find the position of the first colon
        $pos = strpos($ltCookie, ':');
    
        if ($pos !== false) {
            // Extract the substring after the first colon
            $ltCookie = substr($ltCookie, $pos + 1);
        }
    
        // Now remove all remaining colons
        $cart_token = str_replace(':', '', $ltCookie);
    
        // $cart_token now contains the cookie value with the first part and all colons removed
    }

    $session_cookie = sanitize_text_field($_COOKIE['_kpixel_s']);


    // Fetch the entire options array
    $options = get_option('kluvos_plugin_options');

    // Access the property_id and property_token from the options array
    $property_id = isset($options['property_id']) ? $options['property_id'] : '';

    $cart = WC()->cart;
    // Trigger cart totals recalculation
    $cart->calculate_totals();
    $cart_contents = $cart->get_cart();

    // Add variant details to each cart item
    $cart_contents_with_details = kluvos_add_variant_details_to_cart_items($cart_contents);

    if (empty($cart_contents)) {
        //really don't want an empty cart
        return;
    }
    if (empty($cart_contents)) {
        // this is nullified by previous empty cart check.
        $event_data = array(
            'session_cookie' => $session_cookie,
            'property_id' => sanitize_text_field($property_id),
            'items' => array("empty"), // Empty array to represent an empty cart
            'item_count' => 0,
            'cart_token' => $cart_token// Handle cart_token accordingly
        );
    } else {
        $event_data = array( 
            'session_cookie' => $session_cookie,
            'property_id' => sanitize_text_field($property_id),
            'items' => $cart_contents_with_details, 
            'item_count' => $cart->get_cart_contents_count(),
            'cart_token' => $cart_token,
            'currency' => get_woocommerce_currency(),
            'total_discount' => $cart->get_cart_discount_total(),
            'original_total_price' => $cart->get_subtotal(),
            'total_price' => $cart->get_total('edit'),
            'time_utc' => current_time('c')
        );

    }

    // Data structure
    $data = array(
        'events' => array(
            array(
                'event_type' => 'add_to_cart',
                'event_data' => $event_data
            )
        )
    );

    // Send POST request to server
    $url = 'https://track.kluvos.com/events/create-batch';

    $response = wp_remote_post($url, array(
        'method' => 'POST',
        'headers' => array('Content-Type' => 'application/json; charset=utf-8'),
        'body' => wp_json_encode($data),
        'data_format' => 'body',
        'timeout' => 5
    ));


}

####viewed product



function kluvos_send_viewed_product_to_server() {
    // Get the global product object
    global $product;

    if (!$product) {
        return;
    }

    // Check if the cookie exists and sanitize it
    if (!isset($_COOKIE['_kpixel_s'])) {
        return;
    }


    $session_cookie = sanitize_text_field($_COOKIE['_kpixel_s']);

    // Fetch the entire options array
    $options = get_option('kluvos_plugin_options');

    // Access the property_id and property_token from the options array
    $property_id = isset($options['property_id']) ? $options['property_id'] : '';

    // Get product details
    $product_id = $product->get_id();
    $product_name = $product->get_name();
    $product_url = get_permalink($product_id);
    $product_price = $product->get_price(); // Get the plain product price value
    $product_compare_at_price = $product->get_regular_price(); // Get the plain regular price value
    $product_categories = wp_get_post_terms($product_id, 'product_cat', array('fields' => 'names'));


    // Check if the product is a variation and get the appropriate image and name
    $image_id = null;
    $variant_name = '';
    if ($product->is_type('variable')) {
        $variations = $product->get_available_variations();
        if (!empty($variations)) {
            $variation_id = $variations[0]['variation_id'];
            $variation_product = wc_get_product($variation_id);
            $image_id = $variation_product->get_image_id();
            
            // Extract only attribute values for variant name
            $attributes = $variation_product->get_attributes();
            $variant_name_parts = array();
            foreach ($attributes as $attribute_value) {
                $variant_name_parts[] = $attribute_value;
            }
            $variant_name = implode(', ', $variant_name_parts);
        }
    }

    // Fallback to main product image if no variant image is found
    if (!$image_id) {
        $image_id = $product->get_image_id();
    }

    $product_image_url = wp_get_attachment_image_url($image_id, 'full');


    // Prepare product data
    $product_data = array(
        'url' => $product_url,
        'name' => $product_name,
        'variant_name' => $variant_name,
        'brand' => get_bloginfo('name'),
        'price' => $product_price,
        'image_url' => $product_image_url,
        'categories' => $product_categories,
        'product_id' => $product_id,
        'compare_at_price' => $product_compare_at_price
    );

    // Prepare event data
    $event_data = array(
        'session_cookie' => $session_cookie,
        'property_id' => sanitize_text_field($property_id),
        'product_data' => $product_data,
        'time_utc' => current_time('c')
    );

    // Data structure
    $data = array(
        'events' => array(
            array(
                'event_type' => 'product_view',
                'event_data' => $event_data
            )
        )
    );

    // Send POST request to server
    $url = 'https://track.kluvos.com/events/create-batch';
    $response = wp_remote_post($url, array(
        'method' => 'POST',
        'headers' => array('Content-Type' => 'application/json; charset=utf-8'),
        'body' => wp_json_encode($data),
        'data_format' => 'body',
        'timeout' => 5
    ));

    // Handle response (optional)
    if (is_wp_error($response)) {
        error_log('Error sending viewed product data: ' . $response->get_error_message());
    } else {
        error_log('Viewed product data sent successfully');
    }
}





#########

function kluvos_send_order_to_server( $order_id ) {
   
    // Ensure WooCommerce is active
    if ( ! function_exists('wc_get_order') ) {
        return;
    }

    $order = wc_get_order( $order_id );

    if ( ! $order ) {
        return;
    }

    // Retrieve plugin settings
        // Fetch the entire options array
    $options = get_option('kluvos_plugin_options');

    // Access the property_id and property_token from the options array
    $property_id = isset($options['property_id']) ? $options['property_id'] : '';
    $property_token = isset($options['property_token']) ? $options['property_token'] : '';


    if ( empty($property_id) || empty($property_token) ) {
        return;
    }

    // Sanitize and validate data
    $session_cookie = sanitize_text_field( $_COOKIE['_kpixel_s'] ?? '' );
    $lt_cookie = sanitize_text_field( $_COOKIE['_kpixel_lt'] ?? '' );

    // Prepare data
    $purchase_data = array(
        'purchase' => array(
            'session_cookie'   => $session_cookie,
            'property_id'      => sanitize_text_field($property_id),
            'property_token'   => sanitize_text_field($property_token),
            'order_id'         => sanitize_text_field('wc_' . $order_id),
            'platform'         => 'Woocommerce',
            'lt_cookie'        => $lt_cookie
        )
    );

    $requestOptions = array(
        'method'  => 'POST',
        'headers' => array(
            'Content-Type' => 'application/json',
            'ngrok-skip-browser-warning' => 'true'
        ),
        'body' => json_encode($purchase_data)
    );

    // Define the URL to which you want to send the data
    $url = "https://track.kluvos.com/properties/$property_token/purchase";

    // Use wp_remote_post to send data
    $response = wp_remote_post($url, $requestOptions);

}


###
##UPDATE CART
###

function kluvos_check_for_cart_token() {
    // Check if 'cart_token' is present in the URL
    if (isset($_GET['klv_cx'])) {
        $cart_token = sanitize_text_field($_GET['klv_cx']);


    // Remove the action to prevent it from firing
    remove_action('woocommerce_add_to_cart', 'kluvos_send_add_to_cart_data_to_server', 10);



    // Process the cart token
    kluvos_fetch_and_populate_cart_from_server($cart_token);

    // Re-add the action after populating the cart
    add_action('woocommerce_add_to_cart', 'kluvos_send_add_to_cart_data_to_server', 10, 6);


    // Optionally set a flag to trigger the URL cleanup in JavaScript
    add_filter('wp_footer', 'kluvos_trigger_url_cleanup_script');

    }
}


function kluvos_fetch_and_populate_cart_from_server($cart_token) {
    // Fetch the entire options array
    $options = get_option('kluvos_plugin_options');

    // Access property_token from the options array
    $property_token = isset($options['property_token']) ? $options['property_token'] : '';

    // Endpoint to fetch the cart data
    $url = "https://track.kluvos.com/woocommerce/add_to_carts?property_token=$property_token&cart_token=" . urlencode($cart_token);

    // Make the HTTP request to your server
    $response = wp_remote_get($url, array('timeout' => 5));
    

    if (is_wp_error($response)) {
        // Handle error - server didn't respond or other issue
        return;
    }
    //make sure response is successful
    $response_code = wp_remote_retrieve_response_code($response);
    if ($response_code != 200) {
        return;
    }

    // Retrieve the body and decode JSON data
    $response_data = json_decode(wp_remote_retrieve_body($response), true);

    if (is_null($response_data)) {
        return;
    }

      // Check if the status is successful
      if (empty($response_data['success']) || $response_data['success'] !== true) {
        // Handle the case where the status is not successful
        return;
    }

     // Ensure WC Cart is empty before populating
     WC()->cart->empty_cart();

   // Populate the cart
    foreach ($response_data['data'] as $item_data) {
        $product_id = isset($item_data['product_id']) ? intval($item_data['product_id']) : 0;
        $quantity = isset($item_data['quantity']) ? intval($item_data['quantity']) : 0;

        // Skip if the product ID is invalid or quantity is zero
        if (!$product_id || !$quantity) {
            continue;
        }

        // Get the product object
        $product = wc_get_product($product_id);
        if (!$product || !$product->is_purchasable()) {
            // Skip if the product is not purchasable
            continue;
        }

        // Handle product variations if any
        $variation_id = isset($item_data['variation_id']) ? intval($item_data['variation_id']) : 0;
        $variations = !empty($item_data['variation']) && is_array($item_data['variation']) ? $item_data['variation'] : array();

        // Add the product or variation to the cart
        if ($variation_id && $product->is_type('variable') && $product->has_child($variation_id)) {
            WC()->cart->add_to_cart($product_id, $quantity, $variation_id, $variations);
        } else {
            WC()->cart->add_to_cart($product_id, $quantity);
        }

        // Save the cart session and recalculate totals
        WC()->cart->set_session();
        WC()->cart->calculate_totals();
    }

}


function kluvos_trigger_url_cleanup_script() {
    echo "<script>var kluvosShouldCleanUrl = true;</script>";
}



// Function to add variant details to each cart item
function kluvos_add_variant_details_to_cart_items($cart_items) {
    
    foreach ($cart_items as $cart_item_key => $cart_item) {
        $product_id = $cart_item['product_id'];
        $variation_id = isset($cart_item['variation_id']) ? $cart_item['variation_id'] : null;

        // Get the correct product object
        if ($variation_id) {
            // If it's a variation, get the variation product object
            $product = wc_get_product($variation_id);
        } else {
            // Otherwise, get the main product object
            $product = wc_get_product($product_id);
        }

        // Retrieve the image ID associated with the product or variation
        $image_id = $product->get_image_id();

        // Get the image URL using the image ID and specify the image size ('full' in this case)
        $image_url = wp_get_attachment_image_url($image_id, 'full');

        // Get the product name
        $product_name = $product->get_name();

        // Get the variation name if it exists
        $variation_name = '';
        if (!$product_name && $variation_id) {
            $variation = wc_get_product($variation_id);
            $variation_name = $variation->get_name();
        }

        // Get the product permalink
        $product_permalink = get_permalink($product_id);

        // Add the details to the cart item
        $cart_items[$cart_item_key]['image_url'] = $image_url;
        $cart_items[$cart_item_key]['product_name'] = $product_name;
        $cart_items[$cart_item_key]['variation_name'] = $variation_name;
        $cart_items[$cart_item_key]['product_url'] = $product_permalink;

    }

    return $cart_items;
}