<?php
/**
 * Centralized validation for P.IVA, SDI, and PEC fields.
 *
 * @package GD_B2B
 * @since   2.0.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class GD_B2B_PIVA_Validator
 *
 * Format checks and duplicate-detection for Italian tax/invoicing
 * fields stored via WooCommerce checkout additional fields.
 */
class GD_B2B_PIVA_Validator {

	/**
	 * Usermeta keys where WooCommerce stores the P.IVA field.
	 *
	 * Different WC versions use different separators; we search both.
	 *
	 * @var string[]
	 */
	private const PIVA_META_KEYS = array(
		'_wc_other/gustodiromagna/piva',
		'_wc_other_gustodiromagna/piva',
	);

	/**
	 * Validate that a P.IVA string is exactly 11 digits.
	 *
	 * @param string $piva Raw P.IVA input.
	 * @return bool
	 */
	public function validate_format( string $piva ): bool {
		$digits = preg_replace( '/\D/', '', $piva );

		return is_string( $digits ) && 11 === strlen( $digits );
	}

	/**
	 * Check that a P.IVA is not already associated with another user.
	 *
	 * Searches WooCommerce additional-field meta keys in usermeta.
	 *
	 * @param string $piva            Sanitized P.IVA (digits only recommended).
	 * @param int    $exclude_user_id User ID to exclude from the duplicate check.
	 * @return bool True when no duplicate is found.
	 */
	public function check_duplicate( string $piva, int $exclude_user_id = 0 ): bool {
		$piva = trim( $piva );
		if ( '' === $piva ) {
			return true;
		}

		global $wpdb;

		$placeholders = implode( ',', array_fill( 0, count( self::PIVA_META_KEYS ), '%s' ) );

		$args = self::PIVA_META_KEYS;
		$args[] = $piva;

		if ( $exclude_user_id > 0 ) {
			$sql = $wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->usermeta}
				 WHERE meta_key IN ({$placeholders})
				   AND meta_value = %s
				   AND user_id != %d",
				array_merge( $args, array( $exclude_user_id ) )
			);
		} else {
			$sql = $wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->usermeta}
				 WHERE meta_key IN ({$placeholders})
				   AND meta_value = %s",
				$args
			);
		}

		return 0 === (int) $wpdb->get_var( $sql );
	}

	/**
	 * Full P.IVA validation: format check then duplicate check.
	 *
	 * @param string $piva            Raw P.IVA input.
	 * @param int    $exclude_user_id User ID to exclude from the duplicate check.
	 * @return true|\WP_Error True on success, WP_Error with a translated message on failure.
	 */
	public function validate_piva( string $piva, int $exclude_user_id = 0 ) {
		$piva = sanitize_text_field( $piva );

		if ( ! $this->validate_format( $piva ) ) {
			return new \WP_Error(
				'gd_b2b_piva_format',
				__( 'La Partita IVA deve contenere esattamente 11 cifre.', 'gd-b2b' )
			);
		}

		$digits = preg_replace( '/\D/', '', $piva );

		if ( ! $this->check_duplicate( $digits, $exclude_user_id ) ) {
			return new \WP_Error(
				'gd_b2b_piva_duplicate',
				__( 'Questa Partita IVA è già associata a un altro account.', 'gd-b2b' )
			);
		}

		return true;
	}

	/**
	 * Validate SDI (Codice Destinatario) format.
	 *
	 * Empty value is acceptable; when provided it must be 7 alphanumeric characters.
	 *
	 * @param string $sdi Raw SDI input.
	 * @return true|\WP_Error
	 */
	public function validate_sdi( string $sdi ) {
		$sdi = sanitize_text_field( $sdi );

		if ( '' === $sdi ) {
			return true;
		}

		if ( ! preg_match( '/^[A-Za-z0-9]{7}$/', $sdi ) ) {
			return new \WP_Error(
				'gd_b2b_sdi_format',
				__( 'Il Codice Destinatario (SDI) deve essere composto da 7 caratteri alfanumerici.', 'gd-b2b' )
			);
		}

		return true;
	}

	/**
	 * Validate PEC email format.
	 *
	 * Empty value is acceptable; when provided it must be a valid email.
	 *
	 * @param string $pec Raw PEC input.
	 * @return true|\WP_Error
	 */
	public function validate_pec( string $pec ) {
		$pec = sanitize_email( $pec );

		if ( '' === $pec ) {
			return true;
		}

		if ( ! is_email( $pec ) ) {
			return new \WP_Error(
				'gd_b2b_pec_format',
				__( 'L\'indirizzo PEC inserito non è valido.', 'gd-b2b' )
			);
		}

		return true;
	}

	/**
	 * Validate P.IVA, SDI and PEC in one call.
	 *
	 * @param string $piva            Raw P.IVA.
	 * @param string $sdi             Raw SDI.
	 * @param string $pec             Raw PEC.
	 * @param int    $exclude_user_id User ID to exclude from the P.IVA duplicate check.
	 * @return \WP_Error[] Array of WP_Error objects (empty when all fields are valid).
	 */
	public function validate_all( string $piva, string $sdi, string $pec, int $exclude_user_id = 0 ): array {
		$errors = array();

		$result = $this->validate_piva( $piva, $exclude_user_id );
		if ( is_wp_error( $result ) ) {
			$errors[] = $result;
		}

		$result = $this->validate_sdi( $sdi );
		if ( is_wp_error( $result ) ) {
			$errors[] = $result;
		}

		$result = $this->validate_pec( $pec );
		if ( is_wp_error( $result ) ) {
			$errors[] = $result;
		}

		return $errors;
	}
}
