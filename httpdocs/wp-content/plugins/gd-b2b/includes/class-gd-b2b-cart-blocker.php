<?php
/**
 * Prevents pending B2B buyers from purchasing.
 *
 * @package GD_B2B
 */

defined( 'ABSPATH' ) || exit;

/**
 * Blocks add-to-cart and checkout for users whose B2B registration is still pending.
 */
class GD_B2B_Cart_Blocker {

	/**
	 * Register all hooks.
	 *
	 * @return void
	 */
	public function init(): void {
		add_filter( 'woocommerce_add_to_cart_validation', [ $this, 'block_add_to_cart' ], 99, 6 );
		add_action( 'woocommerce_check_cart_items', [ $this, 'block_cart_checkout' ], 99 );
	}

	/**
	 * Prevent pending buyers from adding items to the cart.
	 *
	 * @param bool  $passed         Whether validation passed so far.
	 * @param int   $product_id     Product ID.
	 * @param int   $quantity       Quantity.
	 * @param int   $variation_id   Variation ID.
	 * @param array $variation      Variation attributes.
	 * @param array $cart_item_data Cart item data.
	 * @return bool
	 */
	public function block_add_to_cart( $passed, $product_id, $quantity, $variation_id = 0, $variation = [], $cart_item_data = [] ): bool {
		if ( ! $passed ) {
			return false;
		}
		if ( ! GD_B2B_Plugin::instance()->roles->is_pending_buyer( get_current_user_id() ) ) {
			return $passed;
		}
		wc_add_notice(
			__(
				'Il tuo profilo commerciale non è ancora stato abilitato: attendi conferma prima di acquistare.',
				'gd-b2b'
			),
			'error'
		);
		return false;
	}

	/**
	 * Prevent pending buyers from proceeding to checkout.
	 *
	 * @return void
	 */
	public function block_cart_checkout(): void {
		if ( ! GD_B2B_Plugin::instance()->roles->is_pending_buyer( get_current_user_id() ) ) {
			return;
		}
		$cart = WC()->cart;
		if ( ! $cart || $cart->is_empty() ) {
			return;
		}
		wc_add_notice(
			__(
				'Il tuo profilo commerciale non è ancora stato abilitato: non puoi procedere finché lo staff non conferma la richiesta e assegna un ruolo commerciale (Rivenditore o Azienda).',
				'gd-b2b'
			),
			'error'
		);
	}
}
