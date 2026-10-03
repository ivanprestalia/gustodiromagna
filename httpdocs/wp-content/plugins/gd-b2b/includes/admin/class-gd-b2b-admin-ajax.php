<?php
/**
 * Admin AJAX handlers for GD-B2B.
 *
 * @package GD_B2B
 */

defined( 'ABSPATH' ) || exit;

/**
 * Handles AJAX requests: role assignment, email resend, bulk actions, client save.
 */
class GD_B2B_Admin_Ajax {

	/**
	 * Register AJAX and admin_post hooks.
	 */
	public function init(): void {
		add_action( 'wp_ajax_gd_b2b_resend_activation_email', array( $this, 'handle_resend_email' ) );
		add_action( 'wp_ajax_gd_b2b_assign_role', array( $this, 'handle_assign_role' ) );
		add_action( 'wp_ajax_gd_b2b_bulk_action', array( $this, 'handle_bulk_action' ) );
		add_action( 'admin_post_gd_b2b_save_client', array( $this, 'handle_save_client' ) );
	}

	/**
	 * AJAX: resend activation email.
	 */
	public function handle_resend_email(): void {
		$plugin = GD_B2B_Plugin::instance();

		if ( ! $plugin->admin->user_can() || ! check_ajax_referer( 'gd_b2b_admin', 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => 'forbidden' ), 403 );
		}

		$uid = isset( $_POST['user_id'] ) ? (int) $_POST['user_id'] : 0;
		if ( $uid <= 0 || ! current_user_can( 'edit_user', $uid ) ) {
			wp_send_json_error( array( 'message' => 'cap' ), 403 );
		}

		$result = $plugin->email->send_activation( $uid );

		$plugin->audit_log->log( 'activation_email_sent', $uid, array(
			'success' => ! empty( $result['success'] ),
		) );

		if ( empty( $result['success'] ) ) {
			wp_send_json_error(
				array(
					'message'    => isset( $result['error'] ) ? (string) $result['error'] : __( 'Invio fallito.', 'gd-b2b' ),
					'total_sent' => isset( $result['total_sent'] ) ? (int) $result['total_sent'] : 0,
					'to'         => isset( $result['to'] ) ? (string) $result['to'] : '',
				),
				500
			);
		}

		wp_send_json_success(
			array(
				'total_sent' => (int) $result['total_sent'],
				'to'         => (string) $result['to'],
			)
		);
	}

	/**
	 * AJAX: assign/change role.
	 */
	public function handle_assign_role(): void {
		$plugin = GD_B2B_Plugin::instance();

		if ( ! $plugin->admin->user_can() || ! check_ajax_referer( 'gd_b2b_admin', 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => 'forbidden' ), 403 );
		}

		$uid  = isset( $_POST['user_id'] ) ? (int) $_POST['user_id'] : 0;
		$role = isset( $_POST['role'] ) ? sanitize_key( wp_unslash( $_POST['role'] ) ) : '';

		if ( $uid <= 0 || ! current_user_can( 'edit_user', $uid ) ) {
			wp_send_json_error( array( 'message' => 'cap' ), 403 );
		}

		$roles   = $plugin->roles;
		$allowed = $roles->get_trade_slugs();
		$user    = get_userdata( $uid );

		if ( ! $user ) {
			wp_send_json_error( array( 'message' => 'user' ), 404 );
		}

		if ( '__pending__' === $role ) {
			$user->set_role( 'customer' );
			$roles->set_pending_meta( $uid );
			$roles->invalidate_user_cache();

			$plugin->audit_log->log( 'role_removed', $uid, array( 'set_to' => 'pending' ) );

			wp_send_json_success( array( 'status' => 'pending' ) );
		}

		if ( ! in_array( $role, $allowed, true ) ) {
			wp_send_json_error( array( 'message' => 'role' ), 400 );
		}

		$user->set_role( $role );
		$roles->clear_buyer_meta( $uid );
		$roles->invalidate_user_cache();

		$plugin->audit_log->log( 'role_assigned', $uid, array( 'role' => $role ) );

		wp_send_json_success(
			array(
				'status' => 'ok',
				'label'  => $roles->get_label( $role ),
			)
		);
	}

	/**
	 * AJAX: bulk action (assign role or send email to multiple users).
	 */
	public function handle_bulk_action(): void {
		$plugin = GD_B2B_Plugin::instance();

		if ( ! $plugin->admin->user_can() || ! check_ajax_referer( 'gd_b2b_admin', 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => 'forbidden' ), 403 );
		}

		$action_type = isset( $_POST['action_type'] ) ? sanitize_key( wp_unslash( $_POST['action_type'] ) ) : '';
		$user_ids    = isset( $_POST['user_ids'] ) ? array_map( 'intval', (array) $_POST['user_ids'] ) : array();
		$user_ids    = array_filter( $user_ids, static function ( $id ) {
			return $id > 0;
		} );

		if ( empty( $user_ids ) ) {
			wp_send_json_error( array( 'message' => 'no_users' ), 400 );
		}

		$errors    = array();
		$processed = 0;

		if ( 'assign_role' === $action_type ) {
			$role = isset( $_POST['role'] ) ? sanitize_key( wp_unslash( $_POST['role'] ) ) : '';

			if ( ! in_array( $role, $plugin->roles->get_trade_slugs(), true ) && '__pending__' !== $role ) {
				wp_send_json_error( array( 'message' => 'invalid_role' ), 400 );
			}

			foreach ( $user_ids as $uid ) {
				if ( ! current_user_can( 'edit_user', $uid ) ) {
					$errors[] = sprintf( 'uid %d: no cap', $uid );
					continue;
				}
				$user = get_userdata( $uid );
				if ( ! $user ) {
					$errors[] = sprintf( 'uid %d: not found', $uid );
					continue;
				}

				if ( '__pending__' === $role ) {
					$user->set_role( 'customer' );
					$plugin->roles->set_pending_meta( $uid );
				} else {
					$user->set_role( $role );
					$plugin->roles->clear_buyer_meta( $uid );
				}

				$plugin->audit_log->log( 'bulk_role_assigned', $uid, array( 'role' => $role ) );
				$processed++;
			}

			$plugin->roles->invalidate_user_cache();

		} elseif ( 'send_email' === $action_type ) {
			foreach ( $user_ids as $uid ) {
				if ( ! current_user_can( 'edit_user', $uid ) ) {
					$errors[] = sprintf( 'uid %d: no cap', $uid );
					continue;
				}
				$result = $plugin->email->send_activation( $uid );
				if ( empty( $result['success'] ) ) {
					$errors[] = sprintf( 'uid %d: %s', $uid, $result['error'] ?? 'failed' );
				} else {
					$processed++;
				}
				$plugin->audit_log->log( 'bulk_activation_email_sent', $uid, array(
					'success' => ! empty( $result['success'] ),
				) );
			}
		} else {
			wp_send_json_error( array( 'message' => 'invalid_action_type' ), 400 );
		}

		wp_send_json_success( array(
			'processed' => $processed,
			'errors'    => $errors,
		) );
	}

	/**
	 * Admin POST: save client data from detail panel.
	 */
	public function handle_save_client(): void {
		$plugin = GD_B2B_Plugin::instance();

		if ( ! $plugin->admin->user_can() ) {
			wp_die( esc_html__( 'Permesso negato.', 'gd-b2b' ) );
		}

		$uid = isset( $_POST['user_id'] ) ? (int) $_POST['user_id'] : 0;
		if ( $uid <= 0 || ! current_user_can( 'edit_user', $uid ) ) {
			wp_die( esc_html__( 'Utente non modificabile.', 'gd-b2b' ) );
		}

		check_admin_referer( 'gd_b2b_save_client_' . $uid, '_gd_b2b_nonce' );

		$redirect = admin_url( 'admin.php?page=gd-b2b-clients&gd_b2b_saved=1' );

		$email_raw = sanitize_email( wp_unslash( $_POST['billing_email'] ?? '' ) );
		$user_prev = get_userdata( $uid );
		$fallback  = ( $user_prev && is_email( $user_prev->user_email ) ) ? $user_prev->user_email : '';

		if ( is_email( $email_raw ) ) {
			$email = $email_raw;
		} elseif ( '' !== $fallback ) {
			$email = $fallback;
		} else {
			wp_safe_redirect( add_query_arg( 'gd_b2b_err', 'email', $redirect ) );
			exit;
		}

		wp_update_user( array(
			'ID'         => $uid,
			'user_email' => $email,
		) );

		$c = new \WC_Customer( $uid );
		$c->set_billing_email( $email );
		$c->set_billing_first_name( sanitize_text_field( wp_unslash( $_POST['billing_first_name'] ?? '' ) ) );
		$c->set_billing_last_name( sanitize_text_field( wp_unslash( $_POST['billing_last_name'] ?? '' ) ) );
		$c->set_billing_company( sanitize_text_field( wp_unslash( $_POST['billing_company'] ?? '' ) ) );
		$c->set_billing_address_1( sanitize_text_field( wp_unslash( $_POST['billing_address_1'] ?? '' ) ) );
		$c->set_billing_address_2( sanitize_text_field( wp_unslash( $_POST['billing_address_2'] ?? '' ) ) );
		$c->set_billing_postcode( sanitize_text_field( wp_unslash( $_POST['billing_postcode'] ?? '' ) ) );
		$c->set_billing_city( sanitize_text_field( wp_unslash( $_POST['billing_city'] ?? '' ) ) );
		$c->set_billing_state( sanitize_text_field( wp_unslash( $_POST['billing_state'] ?? '' ) ) );
		$bc = sanitize_text_field( wp_unslash( $_POST['billing_country'] ?? 'IT' ) );
		$c->set_billing_country( $bc );
		$c->set_billing_phone( sanitize_text_field( wp_unslash( $_POST['billing_phone'] ?? '' ) ) );

		$c->set_shipping_company( $c->get_billing_company() );
		$c->set_shipping_first_name( $c->get_billing_first_name() );
		$c->set_shipping_last_name( $c->get_billing_last_name() );
		$c->set_shipping_address_1( $c->get_billing_address_1() );
		$c->set_shipping_address_2( $c->get_billing_address_2() );
		$c->set_shipping_postcode( $c->get_billing_postcode() );
		$c->set_shipping_city( $c->get_billing_city() );
		$c->set_shipping_state( $c->get_billing_state() );
		$c->set_shipping_country( $bc );

		$piva    = preg_replace( '/\D/', '', sanitize_text_field( wp_unslash( $_POST['gd_b2b_myaccount_piva'] ?? '' ) ) );
		$sdi     = strtoupper( trim( sanitize_text_field( wp_unslash( $_POST['gd_b2b_myaccount_sdi'] ?? '' ) ) ) );
		$pec_raw = trim( (string) wp_unslash( $_POST['gd_b2b_myaccount_pec'] ?? '' ) );
		$pec     = '' !== $pec_raw ? strtolower( sanitize_email( $pec_raw ) ) : '';
		$rsx     = sanitize_text_field( wp_unslash( $_POST['gd_b2b_rs_extra'] ?? '' ) );

		if ( '' !== $piva ) {
			$piva_result = $plugin->piva_validator->validate_piva( $piva, $uid );
			if ( ! empty( $piva_result['error'] ) ) {
				// Keep the value anyway: admin override.
			}
		}

		$cf = function_exists( 'gd_b2b_checkout_fields_service' ) ? gd_b2b_checkout_fields_service() : null;
		if ( $cf ) {
			try {
				$cf->persist_field_for_customer( GD_B2B_FIELD_NS . '/piva', $piva, $c, 'other' );
				$cf->persist_field_for_customer( GD_B2B_FIELD_NS . '/codice-sdi', $sdi, $c, 'other' );
				$cf->persist_field_for_customer( GD_B2B_FIELD_NS . '/pec', $pec, $c, 'other' );
				$cf->persist_field_for_customer( GD_B2B_FIELD_NS . '/ragione-sociale', $rsx, $c, 'other' );
			} catch ( \Throwable $t ) {
				unset( $t );
			}
		}

		$c->save();

		$plugin->audit_log->log( 'client_data_updated', $uid, array(
			'company' => $c->get_billing_company(),
		) );

		wp_safe_redirect( $redirect );
		exit;
	}
}
