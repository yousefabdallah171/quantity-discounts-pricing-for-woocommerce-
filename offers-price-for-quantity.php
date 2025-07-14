<?php
/**
 * Plugin Name: Offers Price for Quantity in woo
 * Plugin URI: https://rakmyat.com/
 * Description: Calculate shipping costs based on total cart weight with a fixed price per kilogram. Includes per-product shipping tax settings.
 * Version: 1.0.0
 * Author: Yousef Abdallah
 * Author URI: https://rakmyat.com/
 * Text Domain: wc-weight-shipping
 * Domain Path: /languages
 * Requires at least: 6.0
 * Tested up to: 6.8
 * Requires PHP: 7.3
 * WC requires at least: 5.0
 * WC tested up to: 8.0
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Check if WooCommerce is active
function sqp_check_woocommerce_active() {
    if (!class_exists('WooCommerce')) {
        add_action('admin_notices', 'sqp_woocommerce_missing_notice');
        return false;
    }
    return true;
}

// Notice when WooCommerce is not active
function sqp_woocommerce_missing_notice() {
    ?>
    <div class="error">
        <p><?php _e('Simple Quantity Pricing requires WooCommerce to be installed and activated.', 'simple-quantity-pricing'); ?></p>
    </div>
    <?php
}

// Initialize the plugin
function sqp_init() {
    if (!sqp_check_woocommerce_active()) {
        return;
    }

    // Add settings fields to product page (under product pricing)
    add_action('woocommerce_product_options_pricing', 'sqp_add_product_fields');
    
    // Save entered data
    add_action('woocommerce_process_product_meta', 'sqp_save_product_fields');
    
    // Add pricing ranges table on product page (before add to cart button)
    add_action('woocommerce_before_add_to_cart_button', 'sqp_display_quantity_pricing_table');
    
    // Apply quantity pricing to cart based on quantity
    add_action('woocommerce_before_calculate_totals', 'sqp_apply_quantity_pricing');
}
add_action('plugins_loaded', 'sqp_init');

// Add quantity offer settings fields to product page
function sqp_add_product_fields() {
    global $woocommerce, $post;
    
    echo '<div class="options_group">';
    
    // Enable/disable quantity pricing feature
    woocommerce_wp_checkbox(array(
        'id' => '_sqp_enable',
        'label' => 'Enable Quantity Offers',
        'description' => 'Enable special offers for different quantities'
    ));
    
    echo '</div>';
    
    // Quantity offers table
    echo '<div class="options_group">';
    echo '<h4>Quantity Offers</h4>';
    
    // Get saved quantity offers
    $quantity_offers = get_post_meta($post->ID, '_sqp_quantity_offers', true);
    
    if (empty($quantity_offers)) {
        $quantity_offers = array(
            array(
                'quantity' => 3,
                'price' => '',
                'active' => 'yes',
                'best_seller' => 'no'
            )
        );
    }
    
    ?>
    <div id="sqp_quantity_offers">
        <table class="widefat">
            <thead>
                <tr>
                    <th>Quantity</th>
                    <th>Total Price for Quantity</th>
                    <th>Enable Offer</th>
                    <th>Best Seller</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($quantity_offers as $index => $offer) : ?>
                <tr class="sqp-offer">
                    <td>
                        <input type="number" name="sqp_offer_quantity[]" value="<?php echo esc_attr($offer['quantity']); ?>" step="1" min="2" placeholder="3">
                    </td>
                    <td>
                        <input type="number" name="sqp_offer_price[]" value="<?php echo esc_attr($offer['price']); ?>" step="0.01" min="0" placeholder="<?php echo get_woocommerce_currency_symbol(); ?>">
                    </td>
                    <td>
                        <input type="checkbox" name="sqp_offer_active[]" value="yes" <?php checked(isset($offer['active']) ? $offer['active'] : 'yes', 'yes'); ?>>
                        <input type="hidden" name="sqp_offer_active_hidden[]" value="no">
                    </td>
                    <td>
                        <input type="radio" name="sqp_offer_best_seller" value="<?php echo $index; ?>" <?php checked(isset($offer['best_seller']) ? $offer['best_seller'] : 'no', 'yes'); ?>>
                    </td>
                    <td>
                        <?php if ($index === 0) : ?>
                            <button type="button" class="button add-offer">Add Offer</button>
                        <?php else : ?>
                            <button type="button" class="button remove-offer">Remove</button>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php
    
    echo '</div>';
    
    // JavaScript for adding and removing offers
    ?>
    <script type="text/javascript">
        jQuery(function($) {
            // Add new offer
            $('#sqp_quantity_offers').on('click', '.add-offer', function() {
                var row = $(this).closest('tr').clone();
                row.find('input[type=number], input[type=radio]').val('');
                row.find('input[type=checkbox]').prop('checked', true);
                row.find('input[type=radio]').prop('checked', false);
                row.find('.add-offer').removeClass('add-offer').addClass('remove-offer').text('Remove');
                $('#sqp_quantity_offers tbody').append(row);
            });
            
            // Remove offer
            $('#sqp_quantity_offers').on('click', '.remove-offer', function() {
                $(this).closest('tr').remove();
            });
        });
    </script>
    <?php
}

// Save product fields data
function sqp_save_product_fields($post_id) {
    // Save activation status
    $sqp_enable = isset($_POST['_sqp_enable']) ? 'yes' : 'no';
    update_post_meta($post_id, '_sqp_enable', $sqp_enable);
    
    // Save quantity offers
    if (isset($_POST['sqp_offer_quantity'])) {
        $quantity_offers = array();
        $best_seller_index = isset($_POST['sqp_offer_best_seller']) ? intval($_POST['sqp_offer_best_seller']) : -1;
        
        for ($i = 0; $i < count($_POST['sqp_offer_quantity']); $i++) {
            // Store offer even if price or quantity is empty
            $quantity = !empty($_POST['sqp_offer_quantity'][$i]) ? absint($_POST['sqp_offer_quantity'][$i]) : 0;
            $price = !empty($_POST['sqp_offer_price'][$i]) ? floatval($_POST['sqp_offer_price'][$i]) : 0;
            
            // Check if offer is active
            $active = (isset($_POST['sqp_offer_active'][$i]) && $_POST['sqp_offer_active'][$i] === 'yes') ? 'yes' : 'no';
            
            // Check if this offer is the best seller
            $best_seller = ($i == $best_seller_index) ? 'yes' : 'no';
            
            // Store offer only if quantity is greater than 0
            if ($quantity > 0) {
                $quantity_offers[] = array(
                    'quantity' => $quantity,
                    'price' => $price,
                    'active' => $active,
                    'best_seller' => $best_seller
                );
            }
        }
        
        // Sort offers by quantity ascending
        usort($quantity_offers, function($a, $b) {
            return $a['quantity'] - $b['quantity'];
        });
        
        update_post_meta($post_id, '_sqp_quantity_offers', $quantity_offers);
    } else {
        delete_post_meta($post_id, '_sqp_quantity_offers');
    }
}

// Apply quantity pricing to cart
function sqp_apply_quantity_pricing($cart) {
    if (is_admin() && !defined('DOING_AJAX')) {
        return;
    }
    
    if (did_action('woocommerce_before_calculate_totals') >= 2) {
        return;
    }
    
    foreach ($cart->get_cart() as $cart_item_key => $cart_item) {
        $product_id = $cart_item['product_id'];
        $quantity = $cart_item['quantity'];
        
        // Check if quantity pricing is enabled for this product
        $sqp_enable = get_post_meta($product_id, '_sqp_enable', true);
        
        if ($sqp_enable != 'yes') {
            continue;
        }
        
        // Get quantity offers
        $quantity_offers = get_post_meta($product_id, '_sqp_quantity_offers', true);
        
        if (empty($quantity_offers)) {
            continue;
        }
        
        // Look for exact match for quantity
        $exact_match = null;
        
        foreach ($quantity_offers as $offer) {
            if (!empty($offer['price']) && $quantity == $offer['quantity']) {
                $exact_match = $offer;
                break;
            }
        }
        
        // If we found an exact match for quantity
        if ($exact_match) {
            // Set new price (total price / quantity)
            $new_unit_price = $exact_match['price'] / $exact_match['quantity'];
            $cart_item['data']->set_price($new_unit_price);
            continue;
        }
        
        // If no exact match, look for the largest quantity less than or equal to required
        $matched_offer = null;
        
        foreach ($quantity_offers as $offer) {
            if (!empty($offer['price']) && $quantity >= $offer['quantity'] && (is_null($matched_offer) || $offer['quantity'] > $matched_offer['quantity'])) {
                $matched_offer = $offer;
            }
        }
        
        if ($matched_offer) {
            // Set new price (total price / quantity)
            $new_unit_price = $matched_offer['price'] / $matched_offer['quantity'];
            $cart_item['data']->set_price($new_unit_price);
        }
    }
}

// Display quantity pricing table on product page
function sqp_display_quantity_pricing_table() {
    global $product;
    
    if (!$product) {
        return;
    }
    
    $product_id = $product->get_id();
    
    // Check if quantity pricing is enabled for this product
    $sqp_enable = get_post_meta($product_id, '_sqp_enable', true);
    
    if ($sqp_enable != 'yes') {
        return;
    }
    
    // Get quantity offers
    $quantity_offers = get_post_meta($product_id, '_sqp_quantity_offers', true);
    
    if (empty($quantity_offers)) {
        return;
    }
    
    $currency_symbol = get_woocommerce_currency_symbol();
    $regular_price = $product->get_regular_price();
    $sale_price = $product->get_sale_price();
    $current_price = $sale_price ? $sale_price : $regular_price;
    
    ?>
    <div class="sqp-pricing-table">
        <h4>Special Offers</h4>
        <div class="sqp-offers-container">
            <?php 
            foreach ($quantity_offers as $index => $offer) : 
                // Skip offers with zero price or empty or inactive
                if (empty($offer['price']) || (isset($offer['active']) && $offer['active'] == 'no')) {
                    continue;
                }
                
                $regular_total = $current_price * $offer['quantity'];
                $savings = $regular_total - $offer['price'];
                $savings_percent = ($savings / $regular_total) * 100;
                
                // Check if this offer is the best seller
                $is_best_seller = isset($offer['best_seller']) && $offer['best_seller'] == 'yes';
                $best_seller_class = $is_best_seller ? 'sqp-best-seller' : '';
            ?>
                <div class="sqp-offer-item <?php echo $best_seller_class; ?>" data-quantity="<?php echo $offer['quantity']; ?>">
                    <?php if ($is_best_seller) : ?>
                        <div class="sqp-best-seller-badge">Best Seller</div>
                    <?php endif; ?>
                    <div class="sqp-offer-quantity"><?php echo $offer['quantity']; ?> pieces</div>
                    <div class="sqp-offer-price"><?php echo wc_price($offer['price']); ?></div>
                    <div class="sqp-offer-savings">
                        You save <?php echo wc_price($savings); ?> (<?php echo round($savings_percent, 1); ?>%)
                    </div>
                    <div class="sqp-select-offer">Select Offer</div>
                </div>
            <?php endforeach; ?>
        </div>
        
        <?php
        // Add script for offer interaction
        ?>
        <script type="text/javascript">
            jQuery(function($) {
                // When clicking on an offer
                $('.sqp-offer-item').on('click', function() {
                    // Remove active class from all offers
                    $('.sqp-offer-item').removeClass('sqp-active');
                    
                    // Add active class to selected offer
                    $(this).addClass('sqp-active');
                    
                    // Set quantity in quantity field
                    var quantity = $(this).data('quantity');
                    $('input.qty').val(quantity);
                    $('input.qty').trigger('change');
                    
                    // Add product to cart directly when clicking "Select Offer"
                    var addToCartButton = $('.single_add_to_cart_button');
                    setTimeout(function() {
                        addToCartButton.trigger('click');
                    }, 100);
                });
                
                // Check for manual quantity changes
                $('input.qty').on('change', function() {
                    var currentQty = parseInt($(this).val());
                    
                    // Deactivate all offers
                    $('.sqp-offer-item').removeClass('sqp-active');
                    
                    // Find offer matching the quantity
                    $('.sqp-offer-item').each(function() {
                        if (parseInt($(this).data('quantity')) === currentQty) {
                            $(this).addClass('sqp-active');
                        }
                    });
                });
                
                // Activate offer matching current quantity (if exists)
                var initialQty = parseInt($('input.qty').val());
                $('.sqp-offer-item').each(function() {
                    if (parseInt($(this).data('quantity')) === initialQty) {
                        $(this).addClass('sqp-active');
                    }
                });
            });
        </script>
    </div>
    
    <style>
        .sqp-pricing-table {
            margin: 15px 0 25px;
            max-width: 500px;
        }
        
        .sqp-pricing-table h4 {
            color: #b09351;
            margin-bottom: 15px;
            font-size: 18px;
        }
        
        .sqp-offers-container {
            display: flex;
            flex-direction: column; /* Changed from flex-wrap to column */
            gap: 15px; /* Increased gap between offers */
            margin-bottom: 20px;
        }
        
        .sqp-offer-item {
            border: 2px solid #b09351;
            border-radius: 6px;
            padding: 15px; /* Increased internal padding */
            width: 100%; /* Changed width to take full space */
            box-sizing: border-box;
            cursor: pointer;
            transition: all 0.3s ease;
            position: relative;
        }
        
        .sqp-offer-item:hover {
            box-shadow: 0 0 10px rgba(176, 147, 81, 0.3);
        }
        
        .sqp-offer-item.sqp-active {
            background-color: rgba(176, 147, 81, 0.1);
            border-color: #b09351;
            box-shadow: 0 0 10px rgba(176, 147, 81, 0.5);
        }
        
        .sqp-offer-quantity {
            font-weight: bold;
            font-size: 16px;
            color: #333;
            margin-bottom: 5px;
        }
        
        .sqp-offer-price {
            font-size: 18px;
            color: #b09351;
            font-weight: bold;
            margin-bottom: 5px;
        }
        
        .sqp-offer-savings {
            font-size: 14px;
            color: #28a745;
        }
        
        .sqp-select-offer {
            background-color: #b09351;
            color: white;
            text-align: center;
            padding: 8px; /* Increased padding for button */
            margin-top: 12px;
            border-radius: 4px;
            font-weight: bold;
            transition: background-color 0.3s ease;
        }
        
        .sqp-offer-item:hover .sqp-select-offer {
            background-color: #8c7642;
        }
        
        /* Best seller offer styling */
        .sqp-best-seller {
            border: 3px solid #e74c3c;
            transform: scale(1.03); /* Reduced scale to fit vertical layout */
            z-index: 10;
            box-shadow: 0 0 15px rgba(231, 76, 60, 0.3);
        }
        
        .sqp-best-seller:hover {
            box-shadow: 0 0 20px rgba(231, 76, 60, 0.5);
        }
        
        .sqp-best-seller-badge {
            position: absolute;
            top: -12px;
            right: 10px;
            background-color: #e74c3c;
            color: white;
            font-weight: bold;
            font-size: 14px;
            padding: 3px 10px;
            border-radius: 15px;
            box-shadow: 0 2px 5px rgba(0,0,0,0.2);
            animation: pulse 1.5s infinite;
        }
        
        @keyframes pulse {
            0% {
                transform: scale(1);
            }
            50% {
                transform: scale(1.1);
            }
            100% {
                transform: scale(1);
            }
        }
    </style>
    <?php
}

// Add plugin settings link
function sqp_plugin_action_links($links) {
    $plugin_links = array(
        '<a href="' . admin_url('admin.php?page=wc-settings&tab=products') . '">' . __('Settings', 'simple-quantity-pricing') . '</a>',
    );
    return array_merge($plugin_links, $links);
}
add_filter('plugin_action_links_' . plugin_basename(__FILE__), 'sqp_plugin_action_links');

// Initialize language files
function sqp_load_textdomain() {
    load_plugin_textdomain('simple-quantity-pricing', false, dirname(plugin_basename(__FILE__)) . '/languages');
}
add_action('plugins_loaded', 'sqp_load_textdomain');