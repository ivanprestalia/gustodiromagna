<?php
/**
 * Pagina admin "Prezzi B2B": pubblico delle varianti, prezzi e sconti per livello.
 *
 * @package GD_B2B
 * @since   2.1.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class GD_B2B_Admin_Pricing
 */
class GD_B2B_Admin_Pricing {

	private const ACTION = 'gd_b2b_save_pricing';

	/**
	 * Registra gli hook.
	 */
	public function init(): void {
		add_action( 'admin_post_' . self::ACTION, array( $this, 'handle_save' ) );
	}

	/**
	 * Salvataggio del modulo.
	 */
	public function handle_save(): void {
		if ( ! GD_B2B_Plugin::instance()->admin->user_can() ) {
			wp_die( esc_html__( 'Permesso negato.', 'gd-b2b' ) );
		}
		check_admin_referer( self::ACTION );

		$tiers = isset( $_POST['gd_tier'] ) ? (array) wp_unslash( $_POST['gd_tier'] ) : array();
		GD_B2B_Pricing::save_tier_discounts( $tiers );

		$rows    = isset( $_POST['gd_var'] ) ? (array) wp_unslash( $_POST['gd_var'] ) : array();
		$parents = array();
		$count   = 0;

		foreach ( $rows as $variation_id => $row ) {
			$variation_id = (int) $variation_id;
			$variation    = wc_get_product( $variation_id );
			if ( ! $variation instanceof WC_Product_Variation || ! is_array( $row ) ) {
				continue;
			}
			if ( isset( $row['price'] ) && '' !== trim( (string) $row['price'] ) ) {
				$price = wc_format_decimal( $row['price'] );
				if ( is_numeric( $price ) && (float) $price >= 0 ) {
					$variation->set_regular_price( $price );
					$variation->set_sale_price( '' );
					$variation->set_price( $price );
					$variation->save();
				}
			}
			GD_B2B_Pricing::set_audience( $variation_id, sanitize_key( (string) ( $row['audience'] ?? '' ) ) );
			$parents[ $variation->get_parent_id() ] = true;
			++$count;
		}

		foreach ( array_keys( $parents ) as $parent_id ) {
			WC_Product_Variable::sync( $parent_id );
			wc_delete_product_transients( $parent_id );
		}

		GD_B2B_Plugin::instance()->audit_log->log(
			'pricing_update',
			0,
			sprintf( 'Prezzi B2B aggiornati (%d varianti).', $count )
		);

		wp_safe_redirect( add_query_arg( 'gd_b2b_saved', '1', admin_url( 'admin.php?page=gd-b2b-pricing' ) ) );
		exit;
	}

	/**
	 * Rende la pagina.
	 */
	public function render_page(): void {
		if ( ! GD_B2B_Plugin::instance()->admin->user_can() ) {
			wp_die( esc_html__( 'Permesso negato.', 'gd-b2b' ) );
		}

		$roles    = GD_B2B_Plugin::instance()->roles;
		$slugs    = $roles->get_trade_slugs();
		$tiers    = GD_B2B_Pricing::get_tier_discounts();
		$audience = GD_B2B_Pricing::audience_labels();
		$products = wc_get_products(
			array(
				'type'    => 'variable',
				'status'  => array( 'publish', 'draft', 'private' ),
				'limit'   => -1,
				'orderby' => 'title',
				'order'   => 'ASC',
			)
		);

		echo '<div class="wrap gd-b2b-pricing">';
		echo '<h1>' . esc_html__( 'Prezzi B2B', 'gd-b2b' ) . '</h1>';

		if ( ! empty( $_GET['gd_b2b_saved'] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Prezzi salvati.', 'gd-b2b' ) . '</p></div>';
		}

		echo '<p class="description" style="max-width:900px">' . esc_html__(
			'Ogni variante ha un pubblico: "Solo privati" è visibile e acquistabile dai clienti non B2B, "Solo aziende" solo dai clienti con un ruolo commerciale, "Tutti" da entrambi. Amministratori e gestori negozio vedono sempre tutte le varianti. Lo sconto extra per livello si applica solo alle varianti "Solo aziende", in percentuale sul prezzo di listino indicato qui.',
			'gd-b2b'
		) . '</p>';

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="' . esc_attr( self::ACTION ) . '" />';
		wp_nonce_field( self::ACTION );

		echo '<h2>' . esc_html__( 'Sconto extra per livello', 'gd-b2b' ) . '</h2>';
		echo '<table class="form-table" role="presentation">';
		foreach ( $slugs as $slug ) {
			echo '<tr><th scope="row"><label for="gd_tier_' . esc_attr( $slug ) . '">' . esc_html( $roles->get_label( $slug ) ) . '</label></th><td>';
			echo '<input type="number" step="0.01" min="0" max="100" class="small-text" id="gd_tier_' . esc_attr( $slug ) . '" name="gd_tier[' . esc_attr( $slug ) . ']" value="' . esc_attr( (string) ( $tiers[ $slug ] ?? 0 ) ) . '" /> %';
			echo ' <span class="description">' . esc_html__( 'sconto sul prezzo aziende (0 = nessuno)', 'gd-b2b' ) . '</span></td></tr>';
		}
		echo '</table>';

		echo '<h2>' . esc_html__( 'Varianti, pubblico e prezzi', 'gd-b2b' ) . '</h2>';
		echo '<table class="widefat striped" style="max-width:1100px"><thead><tr>';
		echo '<th>' . esc_html__( 'Prodotto / variante', 'gd-b2b' ) . '</th>';
		echo '<th>' . esc_html__( 'Pubblico', 'gd-b2b' ) . '</th>';
		echo '<th>' . esc_html__( 'Prezzo di listino (€)', 'gd-b2b' ) . '</th>';
		echo '<th>' . esc_html__( 'Prezzo per livello (anteprima)', 'gd-b2b' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $products as $product ) {
			echo '<tr class="gd-b2b-pricing-product"><td colspan="4"><strong><a href="' . esc_url( (string) get_edit_post_link( $product->get_id() ) ) . '">' . esc_html( $product->get_name() ) . '</a></strong></td></tr>';
			foreach ( $product->get_children() as $variation_id ) {
				$variation = wc_get_product( $variation_id );
				if ( ! $variation instanceof WC_Product_Variation ) {
					continue;
				}
				$aud   = GD_B2B_Pricing::get_audience( (int) $variation_id );
				$label = implode( ' / ', array_filter( array_map( 'strval', $variation->get_attributes() ) ) );
				$reg   = (float) $variation->get_regular_price();

				echo '<tr><td style="padding-left:24px">' . esc_html( '' !== $label ? $label : '#' . $variation_id ) . '</td>';
				echo '<td><select name="gd_var[' . (int) $variation_id . '][audience]">';
				foreach ( $audience as $val => $lbl ) {
					echo '<option value="' . esc_attr( $val ) . '"' . selected( $aud, $val, false ) . '>' . esc_html( $lbl ) . '</option>';
				}
				echo '</select></td>';
				echo '<td><input type="text" inputmode="decimal" class="small-text" name="gd_var[' . (int) $variation_id . '][price]" value="' . esc_attr( wc_format_localized_decimal( (string) $variation->get_regular_price() ) ) . '" /></td>';

				echo '<td>';
				if ( GD_B2B_Pricing::AUD_RETAIL === $aud ) {
					echo '<span class="description">' . esc_html__( 'Prezzo unico privati', 'gd-b2b' ) . '</span>';
				} else {
					$parts = array();
					foreach ( $slugs as $slug ) {
						$pct     = GD_B2B_Pricing::AUD_B2B === $aud ? (float) ( $tiers[ $slug ] ?? 0 ) : 0.0;
						$parts[] = esc_html( $roles->get_label( $slug ) ) . ': ' . wp_kses_post( wc_price( $reg * ( 1 - $pct / 100 ) ) );
					}
					echo implode( '<br />', $parts ); // phpcs:ignore WordPress.Security.EscapeOutput
				}
				echo '</td></tr>';
			}
		}
		echo '</tbody></table>';

		echo '<p class="description">' . esc_html__( 'Per aggiungere o eliminare una confezione modifica il prodotto in WooCommerce (scheda Variazioni), poi torna qui per assegnare pubblico e prezzo.', 'gd-b2b' ) . '</p>';
		submit_button( __( 'Salva prezzi', 'gd-b2b' ) );
		echo '</form></div>';
	}
}
