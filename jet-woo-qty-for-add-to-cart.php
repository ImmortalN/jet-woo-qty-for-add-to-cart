<?php
/**
 * Plugin Name: Jet WooCommerce Qty Input
 * Plugin URI:  
 * Description: Shortcode with quantity. Supports regular Add to Cart and update mode on the cart page.
 * Version:     1.0.0
 * Author:      Crocoblock
 * Author URI:  https://crocoblock.com/
 * License:     GPL-3.0+
 * License URI: http://www.gnu.org/licenses/gpl-3.0.txt
 */

// If this file is called directly, abort.
if (!defined('WPINC')) {
	die();
}

class Jet_Woo_Qty_For_Add_To_Cart {

	private $print_js = true;

	public function __construct() {

		add_shortcode( 'jet_woo_add_to_cart_with_qty', array( $this, 'shortcode' ) );
		add_action('wp_ajax_jet_update_cart_item_qty', array($this, 'update_cart_item_qty'));
        add_action('wp_ajax_nopriv_jet_update_cart_item_qty', array($this, 'update_cart_item_qty'));
	}

	public function shortcode( $atts ) {
		
		global $post;

		$atts = shortcode_atts(
			array(
				'id'         => '',
				'quantity'   => '1',
				'sku'        => '',
				'mode'     => 'normal',
				'cart_item_key' => '',
			),
			$atts,
			'jet_woo_add_to_cart_with_qty'
		);

		if (!empty($atts['id'])) {
            $product = wc_get_product($atts['id']);
        } elseif (!empty($atts['sku'])) {
            $product_id = wc_get_product_id_by_sku($atts['sku']);
            $product = wc_get_product($product_id);
        } else {
            return '';
        }

		if (!$product || !$product->is_purchasable()) {
            return '<p>Product is unavailable</p>';
        }

        $product_id = $product->get_id();
		$parent_id    = $product->get_parent_id();
        $is_variation = $product->is_type('variation');

		wc_setup_product_data($product);
		
		$mode = in_array($atts['mode'], ['cart', 'update'], true) ? 'cart' : 'normal';
        $cart_item_key = false;
if ($mode === 'cart') {
    $cart_item_key = !empty($atts['cart_item_key']) 
        ? sanitize_text_field($atts['cart_item_key']) 
        : $this->get_cart_item_key($product_id, $is_variation ? $parent_id : 0);
}
        $current_qty   = $cart_item_key ? WC()->cart->get_cart()[$cart_item_key]['quantity'] : 0;
		
		$qty_product = $is_variation ? wc_get_product($parent_id) : $product;

		ob_start();

		?>
        <div class="jet-woo-add-to-cart" 
             data-product-id="<?php echo esc_attr($product_id); ?>"
             data-parent-id="<?php echo esc_attr($parent_id); ?>"
             data-is-variation="<?php echo $is_variation ? '1' : '0'; ?>"
             data-cart-item-key="<?php echo esc_attr($cart_item_key); ?>"
             data-mode="<?php echo esc_attr($mode); ?>">

            <?php

			woocommerce_quantity_input( array(
				'min_value'   => apply_filters('woocommerce_quantity_input_min', $qty_product->get_min_purchase_quantity(), $qty_product),
                'max_value'   => apply_filters('woocommerce_quantity_input_max', $qty_product->get_max_purchase_quantity(), $qty_product),
				'input_id'    => 'jet_product_' . $product_id,
				'classes'     => array( 'input-text', 'qty', 'text', 'jet-woo-loop-qty' ),
				'input_value' => $current_qty ?: $qty_product->get_min_purchase_quantity(),
            ), $qty_product);
		?>

			 <?php if ($mode === 'normal'): ?>
                <a href="<?php echo esc_url($product->add_to_cart_url()); ?>" 
                   data-quantity="<?php echo esc_attr($atts['quantity']); ?>" 
                   class="button 
                          <?php echo $is_variation ? 'product_type_variation' : 'product_type_simple'; ?> 
                          add_to_cart_button ajax_add_to_cart" 
                   data-product_id="<?php echo esc_attr($product_id); ?>"
                   <?php if ($is_variation): ?>
                       data-variation_id="<?php echo esc_attr($product_id); ?>"
                   <?php endif; ?>>
                    <?php echo esc_html($product->add_to_cart_text()); ?>
                </a>
            <?php endif; ?>

	</div>
        <?php

		if ( $this->print_js ) {
			$this->print_js = false;
			$this->print_js_script();
		}

		// Restore Product global in case this is shown inside a product post.
		wc_setup_product_data( $post );

		return ob_get_clean();
	}

private function get_cart_item_key($product_id, $parent_id = 0) {
        if (empty(WC()->cart)) {
            return false;
        }

        foreach (WC()->cart->get_cart() as $cart_item_key => $cart_item) {
            if ($cart_item['product_id'] == $product_id ||
                ($parent_id && $cart_item['product_id'] == $parent_id && $cart_item['variation_id'] == $product_id)) {
                return $cart_item_key;
            }
        }
        return false;
    }

    private function print_js_script() {
        ?>
        <script>
        (function($) {
            "use strict";

            // Regular behavior for Add to Cart
            $(document).on('change', '.jet-woo-loop-qty', function() {
                var $qty = $(this);
                var $container = $qty.closest('.jet-woo-add-to-cart');
                var mode = $container.data('mode');

                if (mode === 'cart') return; // do not change data-quantity in cart mode

                var $addBtn = $container.find('.add_to_cart_button');
                if ($addBtn.length) {
                    $addBtn.data('quantity', $qty.val()).attr('data-quantity', $qty.val());
                }
            });

            // Cart mode — update via AJAX
            $(document).on('change', '.jet-woo-loop-qty', function() {
                var $input = $(this);
                var $container = $input.closest('.jet-woo-add-to-cart');
                var mode = $container.data('mode');
                var cart_item_key = $container.data('cart-item-key');

                if (mode !== 'cart' || !cart_item_key) return;

                var qty = parseInt($input.val(), 10) || 0;
                if (qty < 0) {
                    qty = 0;
                    $input.val(0);
                }

                $input.prop('disabled', true);

                $.ajax({
                    type: 'POST',
                    url: wc_cart_params?.ajax_url || wc_add_to_cart_params?.ajax_url || '/wp-admin/admin-ajax.php',
                    data: {
                        action: 'jet_update_cart_item_qty',
                        cart_item_key: cart_item_key,
                        qty: qty,
                        security: wc_cart_params?.nonce || ''
                    },
                    success: function(response) {
                        if (response.success) {
                            $(document.body).trigger('wc_update_cart');
                            $(document.body).trigger('updated_wc_div');
                        } else {
                            alert('Failed to update quantity');
                        }
                    },
                    error: function() {
                        alert('Connection error');
                    },
                    complete: function() {
                        $input.prop('disabled', false);
                    }
                });
            });

        })(jQuery);
        </script>
        <?php
    }

    public function update_cart_item_qty() {
        if (!WC()->cart) {
            wp_send_json_error();
        }

        $cart_item_key = sanitize_text_field($_POST['cart_item_key'] ?? '');
        $qty = intval($_POST['qty'] ?? 0);

        if ($cart_item_key) {
            WC()->cart->set_quantity($cart_item_key, $qty);
            WC()->cart->calculate_totals();
            wp_send_json_success();
        }

        wp_send_json_error();
    }
}

new Jet_Woo_Qty_For_Add_To_Cart();
