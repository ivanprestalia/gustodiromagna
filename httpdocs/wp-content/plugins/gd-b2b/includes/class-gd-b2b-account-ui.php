<?php
/**
 * My Account banners and status messages for B2B users.
 *
 * @package GD_B2B
 */

defined( 'ABSPATH' ) || exit;

/**
 * Renders pending/active banners and panels on the WooCommerce My Account pages.
 */
class GD_B2B_Account_UI {

	/**
	 * Register all hooks.
	 *
	 * @return void
	 */
	public function init(): void {
		add_action( 'wp_head', [ $this, 'enqueue_frontend_css' ], 97 );
		add_filter( 'body_class', [ $this, 'add_body_class' ], 20 );
		add_action( 'woocommerce_before_account_navigation', [ $this, 'render_pending_banner' ], 5 );
		add_action( 'woocommerce_account_dashboard', [ $this, 'render_active_panel' ], 5 );
	}

	/**
	 * Enqueue the frontend account CSS on My Account pages.
	 *
	 * @return void
	 */
	public function enqueue_frontend_css(): void {
		if ( ! function_exists( 'is_account_page' ) || ! is_account_page() ) {
			return;
		}
		wp_enqueue_style(
			'gd-b2b-frontend-account',
			GD_B2B_URL . 'assets/css/frontend-account.css',
			[],
			GD_B2B_VERSION
		);
	}

	/**
	 * Add gd-b2b-account-pending body class for pending buyers.
	 *
	 * @param array $classes Existing body classes.
	 * @return array
	 */
	public function add_body_class( array $classes ): array {
		if ( ! function_exists( 'is_account_page' ) || ! is_account_page() || ! is_user_logged_in() ) {
			return $classes;
		}
		if ( GD_B2B_Plugin::instance()->roles->is_pending_buyer( get_current_user_id() ) ) {
			$classes[] = 'gd-b2b-account-pending';
		}
		return $classes;
	}

	/**
	 * Render the pending buyer banner above My Account navigation.
	 *
	 * @return void
	 */
	public function render_pending_banner(): void {
		if ( ! is_user_logged_in() || ! GD_B2B_Plugin::instance()->roles->is_pending_buyer( get_current_user_id() ) ) {
			return;
		}
		$req_url = wc_get_account_endpoint_url( GD_B2B_ACCOUNT_RICHIESTA_ENDPOINT );
		$title   = esc_html__( 'Stato richiesta profilo aziendale', 'gd-b2b' );
		$body    = esc_html__(
			'La tua richiesta è ancora in valutazione. Non è possibile acquistare finché lo staff non assegna un ruolo commerciale (Rivenditore o Azienda) al tuo account.',
			'gd-b2b'
		);
		$btn_lbl = esc_html__( 'Vai al riepilogo della richiesta', 'gd-b2b' );

		echo '<div class="gd-b2b-account-banner-wrap">';
		echo '<div class="woocommerce-info gd-b2b-account-panel gd-b2b-account-panel--pending gd-b2b-pending-banner" role="status">';
		echo '<p class="gd-b2b-pending-banner__title">' . $title . '</p>';
		echo '<div class="gd-b2b-pending-banner__body">';
		echo '<div class="gd-b2b-pending-banner__cell gd-b2b-pending-banner__cell--text">';
		echo '<p class="gd-b2b-pending-banner__text">' . $body . '</p>';
		echo '</div>';
		echo '<div class="gd-b2b-pending-banner__cell gd-b2b-pending-banner__cell--action">';
		echo '<a class="button" href="' . esc_url( $req_url ) . '">' . $btn_lbl . '</a>';
		echo '</div>';
		echo '</div>';
		echo '</div></div>';
	}

	/**
	 * Render the active profile panel on the account dashboard.
	 *
	 * @return void
	 */
	public function render_active_panel(): void {
		if ( ! is_user_logged_in() ) {
			return;
		}
		$roles = GD_B2B_Plugin::instance()->roles;
		if ( $roles->is_pending_buyer( get_current_user_id() ) ) {
			return;
		}
		$user = wp_get_current_user();
		if ( ! $roles->user_has_trade_role( $user ) ) {
			return;
		}
		$label = $roles->get_badge_label( $user );
		if ( '' === $label ) {
			return;
		}
		echo '<div class="woocommerce-message woocommerce-message--success gd-b2b-account-panel gd-b2b-account-panel--ok" role="status">';
		echo '<p><strong>' . esc_html__( 'Profilo commerciale attivo', 'gd-b2b' ) . '</strong></p>';
		echo '<p>';
		echo esc_html__( 'Il tuo account è stato abilitato per gli acquisti con condizioni B2B.', 'gd-b2b' );
		echo ' ';
		echo esc_html__( 'Ruolo assegnato:', 'gd-b2b' );
		echo ' <strong>' . esc_html( $label ) . '</strong>.';
		echo '</p>';
		echo '</div>';
	}
}
