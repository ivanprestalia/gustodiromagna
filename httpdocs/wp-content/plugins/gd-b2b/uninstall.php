<?php
/**
 * Complete uninstall: remove all plugin data from the database.
 *
 * @package GD_B2B
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

global $wpdb;

// Remove ALL user meta created by the plugin.
$meta_keys = array(
	'gd_b2b_buyer_status',
	'gdrm_b2b_status',
	'gd_b2b_request_registered_at',
	'gd_b2b_activation_email_sent_count',
);
foreach ( $meta_keys as $key ) {
	$wpdb->delete( $wpdb->usermeta, array( 'meta_key' => $key ), array( '%s' ) );
}

// Remove ALL options.
$options = array(
	'gd_b2b_settings',
	'gd_b2b_version',
	'gd_b2b_flush_rewrite_pending',
	'gd_b2b_flush_rules_v1',
	'gdrm_b2b_flush_rules_richiesta_v2',
	'gd_b2b_db_version',
);
foreach ( $options as $opt ) {
	delete_option( $opt );
	delete_site_option( $opt );
}

// Drop the audit log table.
$table = $wpdb->prefix . 'gd_b2b_audit_log';
// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
$wpdb->query( "DROP TABLE IF EXISTS {$table}" );

// Clear all plugin transients.
$wpdb->query(
	"DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_gd_b2b_%' OR option_name LIKE '_transient_timeout_gd_b2b_%'"
);

// Flush rewrite rules.
if ( function_exists( 'flush_rewrite_rules' ) ) {
	flush_rewrite_rules( false );
}
