<?php
/**
 * Plugin Name:       GD-B2B
 * Plugin URI:        https://gustodiromagna.com
 * Description:       Registrazione aziende B2B, campi checkout (blocchi), validazione ordini fino all'assegnazione di un ruolo commerciale.
 * Version:           2.0.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Gusto di Romagna
 * Requires Plugins:  woocommerce
 * Text Domain:       gd-b2b
 * Domain Path:       /languages
 *
 * @package GD_B2B
 */

defined( 'ABSPATH' ) || exit;

define( 'GD_B2B_VERSION', '2.0.0' );
define( 'GD_B2B_FILE', __FILE__ );
define( 'GD_B2B_PATH', plugin_dir_path( __FILE__ ) );
define( 'GD_B2B_URL', plugin_dir_url( __FILE__ ) );

define( 'GD_B2B_META_STATUS', 'gd_b2b_buyer_status' );
define( 'GD_B2B_LEGACY_META_STATUS', 'gdrm_b2b_status' );
define( 'GD_B2B_PENDING_VALUE', 'pending' );
define( 'GD_B2B_META_REQUEST_DATE', 'gd_b2b_request_registered_at' );
define( 'GD_B2B_META_ACTIVATION_EMAIL_SENT', 'gd_b2b_activation_email_sent_count' );

define( 'GD_B2B_OPTION_SETTINGS', 'gd_b2b_settings' );

define( 'GD_B2B_FIELD_NS', 'gustodiromagna' );

define( 'GD_B2B_REG_SRC', 'gd_b2b_company_form' );
define( 'GD_B2B_NONCE_ACT', 'gd_b2b_company_register' );
define( 'GD_B2B_REG_RETRY_QUERY', 'gd_b2b_co_reg' );
define( 'GD_B2B_SESSION_STASH', 'gd_b2b_co_reg_retry_v1' );
define( 'GD_B2B_SESSION_REG_ERRORS', 'gd_b2b_last_reg_errors' );
define( 'GD_B2B_ACCOUNT_RICHIESTA_ENDPOINT', 'richiesta-rivenditore' );

// Core class files (always available).
require_once GD_B2B_PATH . 'includes/class-gd-b2b-roles.php';
require_once GD_B2B_PATH . 'includes/class-gd-b2b-piva-validator.php';
require_once GD_B2B_PATH . 'includes/class-gd-b2b-audit-log.php';
require_once GD_B2B_PATH . 'includes/class-gd-b2b-email.php';
require_once GD_B2B_PATH . 'includes/class-gd-b2b-registration.php';
require_once GD_B2B_PATH . 'includes/class-gd-b2b-checkout-fields.php';
require_once GD_B2B_PATH . 'includes/class-gd-b2b-account-ui.php';
require_once GD_B2B_PATH . 'includes/class-gd-b2b-cart-blocker.php';
require_once GD_B2B_PATH . 'includes/class-gd-b2b-plugin.php';
require_once GD_B2B_PATH . 'includes/functions-core.php';

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	require_once GD_B2B_PATH . 'includes/cli-mail-test.php';
}

/**
 * Activation: migrate legacy meta, create audit table, seed default settings.
 */
function gd_b2b_activate() {
	global $wpdb;

	$wpdb->query(
		$wpdb->prepare(
			"UPDATE {$wpdb->usermeta} SET meta_key = %s WHERE meta_key = %s",
			GD_B2B_META_STATUS,
			GD_B2B_LEGACY_META_STATUS
		)
	);

	foreach ( array( 'gdrm_b2b_flush_rules_richiesta_v2' ) as $opt ) {
		delete_option( $opt );
	}

	add_option( 'gd_b2b_flush_rewrite_pending', '1', '', 'no' );
	update_option( 'gd_b2b_version', GD_B2B_VERSION );

	GD_B2B_Audit_Log::create_table();

	if ( false === get_option( GD_B2B_OPTION_SETTINGS, false ) ) {
		add_option(
			GD_B2B_OPTION_SETTINGS,
			array(
				'approvable_roles' => array(
					'rivenditore',
					'azienda_sc_base',
					'azienda_sc_premium',
					'azienda_sc_gold',
				),
				'email' => array(
					'activation_enabled'        => true,
					'activation_subject'        => '',
					'admin_notice_enabled'      => true,
					'admin_notice_subject'      => '',
					'admin_notice_recipients'   => '',
					'user_confirmation_enabled' => true,
					'user_confirmation_subject' => '',
					'rate_limit'                => 60,
				),
			),
			'',
			false
		);
	}
}
register_activation_hook( GD_B2B_FILE, 'gd_b2b_activate' );

/**
 * Deactivation: flush rewrite rules.
 */
function gd_b2b_deactivate() {
	if ( function_exists( 'flush_rewrite_rules' ) ) {
		flush_rewrite_rules( false );
	}
}
register_deactivation_hook( GD_B2B_FILE, 'gd_b2b_deactivate' );

// Text domain.
add_action( 'plugins_loaded', static function () {
	load_plugin_textdomain( 'gd-b2b', false, dirname( plugin_basename( GD_B2B_FILE ) ) . '/languages' );
}, 5 );

// Bootstrap (requires WooCommerce).
add_action( 'plugins_loaded', static function () {
	if ( ! class_exists( 'WooCommerce' ) ) {
		add_action( 'admin_notices', static function () {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'GD-B2B richiede WooCommerce attivo.', 'gd-b2b' ) . '</p></div>';
		} );
		return;
	}
	GD_B2B_Plugin::instance()->init();
}, 26 );
