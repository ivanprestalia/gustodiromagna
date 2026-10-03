<?php
/**
 * Backward-compatible function wrappers delegating to OOP classes.
 *
 * All public function signatures from v1.x are preserved to avoid breaking
 * external code or theme integrations.
 *
 * @package GD_B2B
 */

defined( 'ABSPATH' ) || exit;

/**
 * Unicode-safe lowercase.
 *
 * @param string $s Input string.
 * @return string
 */
function gd_b2b_safe_strtolower( $s ) {
	return GD_B2B_Roles::safe_strtolower( (string) $s );
}

/**
 * Configured trade role slugs.
 *
 * @return string[]
 */
function gd_b2b_trade_role_slugs() {
	return GD_B2B_Plugin::instance()->roles->get_trade_slugs();
}

/**
 * Whether a slug is a trade role.
 *
 * @param string $slug Role slug.
 * @return bool
 */
function gd_b2b_is_trade_role( $slug ) {
	return GD_B2B_Plugin::instance()->roles->is_trade_role( (string) $slug );
}

/**
 * Whether a user has any trade role.
 *
 * @param WP_User|int|null $user_or_id User object, ID, or null for current.
 * @return bool
 */
function gd_b2b_user_has_trade_role( $user_or_id = null ) {
	return GD_B2B_Plugin::instance()->roles->user_has_trade_role( $user_or_id );
}

/**
 * Read buyer meta status (pending or empty).
 *
 * @param int $user_id User ID.
 * @return string
 */
function gd_b2b_read_buyer_meta( $user_id ) {
	return GD_B2B_Plugin::instance()->roles->read_buyer_meta( (int) $user_id );
}

/**
 * Set pending buyer meta.
 *
 * @param int $user_id User ID.
 */
function gd_b2b_set_pending_buyer_meta( $user_id ) {
	GD_B2B_Plugin::instance()->roles->set_pending_meta( (int) $user_id );
}

/**
 * Clear buyer meta (remove pending state).
 *
 * @param int $user_id User ID.
 */
function gd_b2b_clear_buyer_meta( $user_id ) {
	GD_B2B_Plugin::instance()->roles->clear_buyer_meta( (int) $user_id );
}

/**
 * Whether the user is a pending buyer (no trade role yet).
 *
 * @param int|null $user_id User ID or null for current.
 * @return bool
 */
function gd_b2b_is_pending_buyer( $user_id = null ) {
	return GD_B2B_Plugin::instance()->roles->is_pending_buyer( $user_id );
}

/**
 * Badge label for the user list table.
 *
 * @param WP_User $user User object.
 * @return string
 */
function gd_b2b_trade_role_badge_label( WP_User $user ) {
	return GD_B2B_Plugin::instance()->roles->get_badge_label( $user );
}

/**
 * First trade role slug assigned to a user.
 *
 * @param WP_User $user User object.
 * @return string|null
 */
function gd_b2b_first_trade_role_slug( WP_User $user ) {
	return GD_B2B_Plugin::instance()->roles->get_first_slug( $user );
}

/**
 * Whether user matches the B2B users column criteria.
 *
 * @param int $user_id User ID.
 * @return bool
 */
function gd_b2b_user_matches_b2b_users_column( $user_id ) {
	return GD_B2B_Plugin::instance()->roles->user_matches_b2b_column( (int) $user_id );
}

/**
 * IDs of users with pending buyer request.
 *
 * @return int[]
 */
function gd_b2b_get_pending_buyer_user_ids() {
	return GD_B2B_Plugin::instance()->roles->get_pending_user_ids();
}

/**
 * IDs of users with at least one trade role.
 *
 * @return int[]
 */
function gd_b2b_get_trade_role_user_ids() {
	return GD_B2B_Plugin::instance()->roles->get_trade_role_user_ids();
}

/**
 * Combined B2B clients list user IDs (pending ∪ trade roles).
 *
 * @return int[]
 */
function gd_b2b_get_b2b_clients_list_user_ids() {
	return GD_B2B_Plugin::instance()->roles->get_b2b_client_ids();
}

/**
 * PHP fallback scan for B2B client IDs.
 *
 * @param bool $ignore_user_count_limit Bypass the soft limit.
 * @return int[]
 */
function gd_b2b_get_b2b_clients_list_user_ids_php_fallback( $ignore_user_count_limit = false ) {
	$max_site_users = (int) apply_filters( 'gd_b2b_clients_list_php_fallback_max_site_users', 3500 );
	$absolute_max   = (int) apply_filters( 'gd_b2b_clients_list_php_fallback_absolute_max', 25000 );

	if ( ! $ignore_user_count_limit && $max_site_users <= 0 ) {
		return array();
	}

	$counts = count_users();
	$total  = isset( $counts['total_users'] ) ? (int) $counts['total_users'] : 0;
	if ( $total <= 0 ) {
		return array();
	}
	if ( $ignore_user_count_limit && $total > $absolute_max ) {
		return array();
	}
	if ( ! $ignore_user_count_limit && $total > $max_site_users ) {
		return array();
	}

	$out = array();
	foreach ( get_users( array(
		'fields'  => 'ID',
		'number'  => -1,
		'orderby' => 'ID',
		'order'   => 'ASC',
	) ) as $uid ) {
		$uid = (int) $uid;
		if ( gd_b2b_user_matches_b2b_users_column( $uid ) ) {
			$out[] = $uid;
		}
	}
	return array_values( array_unique( array_map( 'intval', $out ) ) );
}

/**
 * WC_Customer for admin list table (robust fallback).
 *
 * @param int $user_id User ID.
 * @return WC_Customer|null
 */
function gd_b2b_wc_customer_for_admin_list( $user_id ) {
	return GD_B2B_Plugin::instance()->roles->wc_customer_for_admin( (int) $user_id );
}

/**
 * Role label for a slug.
 *
 * @param string $slug Role slug.
 * @return string
 */
function gd_b2b_role_label_for_slug( $slug ) {
	return GD_B2B_Plugin::instance()->roles->get_label( (string) $slug );
}

/**
 * Send customer activation email.
 *
 * @param int $user_id User ID.
 * @return array{success:bool,total_sent?:int,to?:string,error?:string}
 */
function gd_b2b_send_customer_activation_email( $user_id ) {
	return GD_B2B_Plugin::instance()->email->send_activation( (int) $user_id );
}

/**
 * Get activation email sent count.
 *
 * @param int $user_id User ID.
 * @return int
 */
function gd_b2b_get_activation_email_sent_count( $user_id ) {
	return GD_B2B_Plugin::instance()->email->get_send_count( (int) $user_id );
}

/**
 * Build branded email HTML wrapper.
 *
 * @param string $main_inner_html Inner HTML content.
 * @return string
 */
function gd_b2b_build_branded_email_html( $main_inner_html ) {
	return GD_B2B_Plugin::instance()->email->build_branded_html( (string) $main_inner_html );
}

/**
 * Get checkout fields service instance.
 *
 * @return object|null
 */
function gd_b2b_checkout_fields_service() {
	if ( ! class_exists( '\Automattic\WooCommerce\Blocks\Package' ) ) {
		return null;
	}
	try {
		$container = \Automattic\WooCommerce\Blocks\Package::container();
		return $container->get( \Automattic\WooCommerce\Blocks\Domain\Services\CheckoutFields::class );
	} catch ( \Throwable $e ) {
		return null;
	}
}

/**
 * Get additional field value for a customer.
 *
 * @param int    $user_id  User ID.
 * @param string $field_id Field identifier (e.g. 'gustodiromagna/piva').
 * @param string $location Field location ('other', 'billing', etc.).
 * @return string
 */
function gd_b2b_get_additional_field_for_customer( $user_id, $field_id, $location = 'other' ) {
	$cf = gd_b2b_checkout_fields_service();
	if ( ! $cf ) {
		return '';
	}
	try {
		$meta_key = $cf->get_field_meta_key( $field_id, $location );
		$value    = get_user_meta( (int) $user_id, $meta_key, true );
		return is_string( $value ) ? $value : '';
	} catch ( \Throwable $e ) {
		return '';
	}
}

/**
 * Whether admin can edit frontend checkout fields for a user.
 *
 * @param int $user_id User ID.
 * @return bool
 */
function gd_b2b_admin_can_edit_fe_fields( $user_id ) {
	if ( ! $user_id ) {
		return false;
	}
	if ( ! current_user_can( 'edit_user', (int) $user_id ) ) {
		return false;
	}
	return null !== gd_b2b_checkout_fields_service();
}

/**
 * Status filter options for the admin list (value => label).
 *
 * @return array<string,string>
 */
function gd_b2b_admin_client_stato_filter_options() {
	$admin = GD_B2B_Plugin::instance()->admin;
	if ( $admin ) {
		return $admin->get_stato_filter_options();
	}
	$out = array(
		''        => __( 'Tutti gli stati', 'gd-b2b' ),
		'pending' => __( 'In attesa di approvazione', 'gd-b2b' ),
	);
	foreach ( gd_b2b_trade_role_slugs() as $slug ) {
		$out[ (string) $slug ] = gd_b2b_role_label_for_slug( $slug );
	}
	return $out;
}

/**
 * Admin capability helpers (backward compat).
 */
function gd_b2b_admin_capability_candidates() {
	return apply_filters(
		'gd_b2b_admin_capability_candidates',
		array( 'manage_options', 'manage_woocommerce', 'list_users', 'edit_users' )
	);
}

function gd_b2b_admin_menu_capability() {
	foreach ( gd_b2b_admin_capability_candidates() as $cap ) {
		$cap = (string) $cap;
		if ( '' !== $cap && current_user_can( $cap ) ) {
			return $cap;
		}
	}
	$legacy = (string) apply_filters( 'gd_b2b_admin_capability', 'manage_woocommerce' );
	return ( '' !== $legacy && current_user_can( $legacy ) ) ? $legacy : '';
}

function gd_b2b_admin_cap() {
	$c = gd_b2b_admin_menu_capability();
	return '' !== $c ? $c : 'manage_woocommerce';
}

function gd_b2b_admin_user_can() {
	return '' !== gd_b2b_admin_menu_capability();
}
