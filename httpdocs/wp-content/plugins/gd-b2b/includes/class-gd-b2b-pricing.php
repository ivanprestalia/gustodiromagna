<?php
/**
 * Prezzi B2B: visibilità delle varianti per tipologia di cliente e sconti per livello.
 *
 * Ogni variante WooCommerce ha un "pubblico" (meta _gd_b2b_audience):
 *  - retail: visibile e acquistabile solo dai clienti non B2B;
 *  - b2b:    visibile e acquistabile solo dai clienti con ruolo commerciale;
 *  - both:   visibile a tutti.
 * Admin e gestori negozio vedono sempre tutte le varianti.
 * Sulle varianti "b2b" può essere applicato uno sconto extra per ruolo (premium, gold, ...).
 *
 * @package GD_B2B
 * @since   2.1.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class GD_B2B_Pricing
 */
class GD_B2B_Pricing {

	public const META_AUDIENCE = '_gd_b2b_audience';
	public const META_LEGACY   = '_gd_b2b_only';
	public const OPTION        = 'gd_b2b_pricing';

	public const AUD_RETAIL = 'retail';
	public const AUD_B2B    = 'b2b';
	public const AUD_BOTH   = 'both';

	/** @var string|null Contesto del visitatore corrente (cache per richiesta). */
	private ?string $context = null;

	/** @var string Ruolo commerciale corrente (cache per richiesta). */
	private string $role = '';

	/**
	 * Registra gli hook WooCommerce.
	 */
	public function init(): void {
		add_action(
			'set_current_user',
			function () {
				$this->context = null;
			}
		);
		add_filter( 'woocommerce_variation_is_visible', array( $this, 'filter_variation_visible' ), 10, 2 );
		add_filter( 'woocommerce_variation_is_purchasable', array( $this, 'filter_variation_purchasable' ), 10, 2 );
		add_filter( 'woocommerce_variation_prices', array( $this, 'filter_variation_prices' ) );
		add_filter( 'woocommerce_get_variation_prices_hash', array( $this, 'filter_prices_hash' ) );
		add_filter( 'woocommerce_dropdown_variation_attribute_options_args', array( $this, 'filter_dropdown_args' ) );
		add_filter( 'woocommerce_product_get_default_attributes', array( $this, 'filter_default_attributes' ), 10, 2 );
		add_filter( 'woocommerce_product_variation_get_price', array( $this, 'filter_variation_price' ), 20, 2 );
		add_filter( 'woocommerce_variation_prices_price', array( $this, 'filter_variation_price' ), 20, 2 );
	}

	/* ------------------------------------------------------------------
	 * Dati
	 * ----------------------------------------------------------------*/

	/**
	 * Valori ammessi per il pubblico di una variante.
	 *
	 * @return array<string,string>
	 */
	public static function audience_labels(): array {
		return array(
			self::AUD_RETAIL => __( 'Solo privati', 'gd-b2b' ),
			self::AUD_B2B    => __( 'Solo aziende (B2B)', 'gd-b2b' ),
			self::AUD_BOTH   => __( 'Tutti', 'gd-b2b' ),
		);
	}

	/**
	 * Pubblico di una variante.
	 *
	 * @param int $variation_id ID variante.
	 * @return string
	 */
	public static function get_audience( int $variation_id ): string {
		$aud = (string) get_post_meta( $variation_id, self::META_AUDIENCE, true );
		if ( isset( self::audience_labels()[ $aud ] ) ) {
			return $aud;
		}
		return 'yes' === get_post_meta( $variation_id, self::META_LEGACY, true ) ? self::AUD_B2B : self::AUD_RETAIL;
	}

	/**
	 * Imposta il pubblico di una variante.
	 *
	 * @param int    $variation_id ID variante.
	 * @param string $audience     retail|b2b|both.
	 */
	public static function set_audience( int $variation_id, string $audience ): void {
		if ( ! isset( self::audience_labels()[ $audience ] ) ) {
			$audience = self::AUD_RETAIL;
		}
		update_post_meta( $variation_id, self::META_AUDIENCE, $audience );
		delete_post_meta( $variation_id, self::META_LEGACY );
	}

	/**
	 * Sconti extra (percentuale) per ruolo commerciale, solo sulle varianti B2B.
	 *
	 * @return array<string,float>
	 */
	public static function get_tier_discounts(): array {
		$opt = get_option( self::OPTION, array() );
		$out = array();
		foreach ( (array) ( $opt['tier_discounts'] ?? array() ) as $slug => $pct ) {
			$out[ sanitize_key( (string) $slug ) ] = max( 0.0, min( 100.0, (float) $pct ) );
		}
		return $out;
	}

	/**
	 * Salva gli sconti per ruolo.
	 *
	 * @param array<string,mixed> $discounts slug => percentuale.
	 */
	public static function save_tier_discounts( array $discounts ): void {
		$clean = array();
		foreach ( $discounts as $slug => $pct ) {
			$clean[ sanitize_key( (string) $slug ) ] = max( 0.0, min( 100.0, (float) wc_format_decimal( $pct ) ) );
		}
		update_option( self::OPTION, array( 'tier_discounts' => $clean ), false );
	}

	/* ------------------------------------------------------------------
	 * Contesto visitatore
	 * ----------------------------------------------------------------*/

	/**
	 * Contesto corrente: all (admin/gestori), b2b, retail.
	 */
	private function get_context(): string {
		if ( null !== $this->context ) {
			return $this->context;
		}
		$this->role    = '';
		$this->context = 'retail';

		if ( is_user_logged_in() ) {
			$user = wp_get_current_user();
			if ( user_can( $user, 'manage_woocommerce' ) || user_can( $user, 'manage_options' ) ) {
				$this->context = 'all';
			} else {
				$slug = GD_B2B_Plugin::instance()->roles->get_first_slug( $user );
				if ( $slug && GD_B2B_Plugin::instance()->roles->user_has_trade_role( $user ) ) {
					$this->context = 'b2b';
					$this->role    = $slug;
				}
			}
		}
		return $this->context;
	}

	/**
	 * La variante è visibile nel contesto corrente?
	 *
	 * @param int $variation_id ID variante.
	 */
	private function is_available( int $variation_id ): bool {
		$ctx = $this->get_context();
		if ( 'all' === $ctx ) {
			return true;
		}
		$aud = self::get_audience( $variation_id );
		if ( self::AUD_BOTH === $aud ) {
			return true;
		}
		return ( 'b2b' === $ctx ) === ( self::AUD_B2B === $aud );
	}

	/* ------------------------------------------------------------------
	 * Filtri
	 * ----------------------------------------------------------------*/

	public function filter_variation_visible( $visible, $variation_id ) {
		return $visible && $this->is_available( (int) $variation_id );
	}

	public function filter_variation_purchasable( $purchasable, $variation ) {
		return $purchasable && $this->is_available( (int) $variation->get_id() );
	}

	/**
	 * Toglie le varianti non disponibili dall'intervallo di prezzo.
	 *
	 * @param array $prices Prezzi per variante.
	 * @return array
	 */
	public function filter_variation_prices( $prices ) {
		if ( 'all' === $this->get_context() ) {
			return $prices;
		}
		foreach ( array( 'price', 'regular_price', 'sale_price' ) as $key ) {
			if ( empty( $prices[ $key ] ) || ! is_array( $prices[ $key ] ) ) {
				continue;
			}
			foreach ( array_keys( $prices[ $key ] ) as $variation_id ) {
				if ( ! $this->is_available( (int) $variation_id ) ) {
					unset( $prices[ $key ][ $variation_id ] );
				}
			}
		}
		return $prices;
	}

	/**
	 * La cache dei prezzi deve distinguere contesto, ruolo e sconti.
	 *
	 * @param array $hash Hash WooCommerce.
	 * @return array
	 */
	public function filter_prices_hash( $hash ) {
		$ctx    = $this->get_context();
		$tiers  = self::get_tier_discounts();
		$hash[] = 'gd-b2b:' . $ctx . ':' . $this->role . ':' . ( $tiers[ $this->role ] ?? 0 );
		return $hash;
	}

	/**
	 * Toglie dal menu a tendina le opzioni non disponibili.
	 *
	 * @param array $args Argomenti del dropdown.
	 * @return array
	 */
	public function filter_dropdown_args( $args ) {
		if ( 'all' === $this->get_context() || empty( $args['product'] ) || empty( $args['options'] ) || ! $args['product'] instanceof WC_Product_Variable ) {
			return $args;
		}
		$args['options'] = array_values( array_intersect( $args['options'], $this->visible_values( $args['product'], (string) $args['attribute'] ) ) );
		return $args;
	}

	/**
	 * Se la confezione predefinita non è disponibile, preseleziona la prima disponibile.
	 *
	 * @param array      $defaults Attributi predefiniti.
	 * @param WC_Product $product  Prodotto.
	 * @return array
	 */
	public function filter_default_attributes( $defaults, $product ) {
		if ( 'all' === $this->get_context() || ! $product instanceof WC_Product_Variable ) {
			return $defaults;
		}
		foreach ( array_keys( $product->get_attributes() ) as $name ) {
			$visible = $this->visible_values( $product, (string) $name );
			if ( array() === $visible ) {
				continue;
			}
			$current = $defaults[ $name ] ?? '';
			if ( ! in_array( $current, $visible, true ) ) {
				$defaults[ $name ] = $visible[0];
			}
		}
		return $defaults;
	}

	/**
	 * Applica lo sconto per livello alle varianti riservate alle aziende.
	 *
	 * @param string|float $price     Prezzo.
	 * @param WC_Product   $variation Variante.
	 * @return string|float
	 */
	public function filter_variation_price( $price, $variation ) {
		if ( '' === $price || 'b2b' !== $this->get_context() ) {
			return $price;
		}
		$tiers = self::get_tier_discounts();
		$pct   = $tiers[ $this->role ] ?? 0.0;
		if ( $pct <= 0 || self::AUD_B2B !== self::get_audience( (int) $variation->get_id() ) ) {
			return $price;
		}
		return wc_format_decimal( (float) $price * ( 1 - $pct / 100 ), wc_get_price_decimals() );
	}

	/**
	 * Valori di un attributo usati da varianti disponibili.
	 *
	 * @param WC_Product_Variable $product   Prodotto.
	 * @param string              $attribute Nome attributo.
	 * @return string[]
	 */
	private function visible_values( WC_Product_Variable $product, string $attribute ): array {
		$key    = 'attribute_' . sanitize_title( $attribute );
		$values = array();
		foreach ( $product->get_children() as $variation_id ) {
			if ( $this->is_available( (int) $variation_id ) ) {
				$values[] = (string) get_post_meta( $variation_id, $key, true );
			}
		}
		return $values;
	}
}
