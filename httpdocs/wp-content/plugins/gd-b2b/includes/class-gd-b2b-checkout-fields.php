<?php
/**
 * WooCommerce Blocks checkout fields, My Account billing extras, wp-admin user profile fields.
 *
 * @package GD_B2B
 */

defined( 'ABSPATH' ) || exit;

/**
 * Manages additional checkout fields (customer type, P.IVA, SDI, PEC),
 * My Account billing edit extras, and wp-admin user profile fields.
 */
class GD_B2B_Checkout_Fields {

	/**
	 * Register all hooks.
	 *
	 * @return void
	 */
	public function init(): void {
		add_filter( 'option_woocommerce_checkout_company_field', [ $this, 'filter_company_field_visibility' ], 20 );
		add_filter( 'woocommerce_billing_fields', [ $this, 'filter_billing_fields' ], 25, 2 );
		add_filter( 'woocommerce_sanitize_additional_field', [ $this, 'sanitize_additional_field' ], 10, 2 );
		add_filter( 'gettext', [ $this, 'translate_billing_labels' ], 10, 3 );
		add_action( 'woocommerce_store_api_checkout_update_order_from_request', [ $this, 'sync_ragione_sociale_to_order' ], 15, 2 );
		add_action( 'woocommerce_init', [ $this, 'register_checkout_fields' ] );

		foreach ( apply_filters( 'gd_b2b_edit_address_billing_hook_suffixes', [ 'billing', 'fatturazione' ] ) as $slug ) {
			$slug = sanitize_title( (string) $slug );
			if ( '' !== $slug ) {
				add_action( 'woocommerce_after_edit_address_form_' . $slug, [ $this, 'render_myaccount_billing_fields' ], 20 );
			}
		}

		add_action( 'woocommerce_after_save_address_validation', [ $this, 'validate_myaccount_billing_save' ], 20, 4 );
		add_action( 'woocommerce_customer_save_address', [ $this, 'save_myaccount_billing_fields' ], 30, 4 );
		add_action( 'show_user_profile', [ $this, 'render_admin_user_fields' ], 75 );
		add_action( 'edit_user_profile', [ $this, 'render_admin_user_fields' ], 75 );
		add_action( 'personal_options_update', [ $this, 'save_admin_user_fields' ], 99 );
		add_action( 'edit_user_profile_update', [ $this, 'save_admin_user_fields' ], 99 );
		add_action( 'admin_notices', [ $this, 'show_admin_settings_errors' ] );
	}

	/**
	 * Hide billing_company on checkout, show as required on My Account edit billing.
	 *
	 * @param mixed $value Option value.
	 * @return string
	 */
	public function filter_company_field_visibility( $value ): string {
		if ( $this->is_account_edit_billing_address() ) {
			return 'required';
		}
		return 'hidden';
	}

	/**
	 * Relabel billing_company on My Account edit billing page.
	 *
	 * @param array  $fields  Billing fields.
	 * @param string $country Country code.
	 * @return array
	 */
	public function filter_billing_fields( array $fields, string $country = '' ): array {
		if ( $this->is_account_edit_billing_address() && isset( $fields['billing_company'] ) ) {
			$fields['billing_company']['label'] = __( 'Ragione sociale', 'gd-b2b' );
		}
		return $fields;
	}

	/**
	 * Uppercase SDI code on sanitize.
	 *
	 * @param mixed  $value     Field value.
	 * @param string $field_key Field key.
	 * @return mixed
	 */
	public function sanitize_additional_field( $value, string $field_key ) {
		if ( GD_B2B_FIELD_NS . '/codice-sdi' === $field_key ) {
			return strtoupper( sanitize_text_field( (string) $value ) );
		}
		return $value;
	}

	/**
	 * Translate WooCommerce "Billing address/details" labels.
	 *
	 * Optimized with early domain check, admin skip, and static map cache.
	 *
	 * @param string $translation Translated text.
	 * @param string $text        Original text.
	 * @param string $domain      Text domain.
	 * @return string
	 */
	public function translate_billing_labels( string $translation, string $text, string $domain ): string {
		if ( ! in_array( $domain, [ 'woocommerce', 'woocommerce-blocks' ], true ) ) {
			return $translation;
		}
		if ( is_admin() && ! wp_doing_ajax() ) {
			return $translation;
		}
		static $map = null;
		if ( null === $map ) {
			$map = [
				'Billing address' => __( 'Dati di fatturazione', 'gd-b2b' ),
				'Billing Address' => __( 'Dati di fatturazione', 'gd-b2b' ),
				'Billing details' => __( 'Dati di fatturazione', 'gd-b2b' ),
				'Billing Details' => __( 'Dati di fatturazione', 'gd-b2b' ),
			];
		}
		return $map[ $text ] ?? $translation;
	}

	/**
	 * Sync ragione-sociale additional field to billing_company on order.
	 *
	 * @param \WC_Order $order   Order object.
	 * @param mixed     $request API request.
	 * @return void
	 */
	public function sync_ragione_sociale_to_order( $order, $request ): void {
		if ( ! $order instanceof \WC_Order || ! class_exists( '\Automattic\WooCommerce\Blocks\Package' ) ) {
			return;
		}
		$cf = \Automattic\WooCommerce\Blocks\Package::container()->get(
			\Automattic\WooCommerce\Blocks\Domain\Services\CheckoutFields::class
		);
		$rs = $cf->get_field_from_object( GD_B2B_FIELD_NS . '/ragione-sociale', $order, 'other' );
		if ( is_string( $rs ) && '' !== $rs ) {
			if ( '' === $order->get_billing_company() ) {
				$order->set_billing_company( $rs );
			}
		}
	}

	/**
	 * Register additional checkout fields (customer type, P.IVA, SDI, PEC).
	 *
	 * @return void
	 */
	public function register_checkout_fields(): void {
		if ( ! function_exists( 'woocommerce_register_additional_checkout_field' ) ) {
			return;
		}

		$type_field                  = GD_B2B_FIELD_NS . '/customer-type';
		$company_conditions_required = [
			'customer' => [
				'properties' => [
					'additional_fields' => [
						'properties' => [
							$type_field => [
								'const' => 'company',
							],
						],
					],
				],
			],
		];
		$company_conditions_hidden = [
			'customer' => [
				'properties' => [
					'additional_fields' => [
						'properties' => [
							$type_field => [
								'not' => [
									'const' => 'company',
								],
							],
						],
					],
				],
			],
		];

		woocommerce_register_additional_checkout_field(
			[
				'id'                => $type_field,
				'label'             => __( 'Tipo cliente', 'gd-b2b' ),
				'location'          => 'contact',
				'type'              => 'select',
				'required'          => true,
				'sanitize_callback' => static function ( $value ) {
					$value = sanitize_text_field( (string) $value );
					return in_array( $value, [ 'private', 'company' ], true ) ? $value : 'private';
				},
				'validate_callback' => static function ( $value ) {
					if ( ! in_array( (string) $value, [ 'private', 'company' ], true ) ) {
						return new \WP_Error(
							'gd_b2b_invalid_customer_type',
							__( 'Seleziona un tipo cliente valido.', 'gd-b2b' )
						);
					}
				},
				'options'           => [
					[
						'value' => 'private',
						'label' => __( 'Privato', 'gd-b2b' ),
					],
					[
						'value' => 'company',
						'label' => __( 'Azienda', 'gd-b2b' ),
					],
				],
			]
		);

		$order_fields = [
			[
				'id'                => GD_B2B_FIELD_NS . '/ragione-sociale',
				'label'             => __( 'Ragione sociale', 'gd-b2b' ),
				'validate_callback' => static function ( $value ) {
					if ( strlen( trim( (string) $value ) ) < 2 ) {
						return new \WP_Error(
							'gd_b2b_invalid_ragione_sociale',
							__( 'Inserisci la ragione sociale completa.', 'gd-b2b' )
						);
					}
				},
				'attributes'        => [
					'autocomplete' => 'organization',
				],
			],
			[
				'id'                => GD_B2B_FIELD_NS . '/piva',
				'label'             => __( 'Partita IVA', 'gd-b2b' ),
				'validate_callback' => static function ( $value ) {
					$digits = preg_replace( '/\D/', '', (string) $value );
					if ( strlen( $digits ) !== 11 ) {
						return new \WP_Error(
							'gd_b2b_invalid_piva',
							__( 'La Partita IVA italiana deve essere composta da 11 cifre.', 'gd-b2b' )
						);
					}
				},
				'attributes'        => [
					'autocomplete' => 'off',
					'maxlength'    => '13',
				],
			],
		];

		foreach ( $order_fields as $field ) {
			woocommerce_register_additional_checkout_field(
				array_merge(
					[
						'location'          => 'order',
						'type'              => 'text',
						'required'          => $company_conditions_required,
						'hidden'            => $company_conditions_hidden,
						'sanitize_callback' => static function ( $value ) {
							return sanitize_text_field( (string) $value );
						},
					],
					$field
				)
			);
		}

		$order_fields_sdi_pec_optional = [
			[
				'id'                => GD_B2B_FIELD_NS . '/codice-sdi',
				'label'             => __( 'Codice SDI', 'gd-b2b' ),
				'validate_callback' => static function ( $value ) {
					$c = strtoupper( trim( (string) $value ) );
					if ( '' === $c ) {
						return;
					}
					if ( strlen( $c ) !== 7 || ! ctype_alnum( $c ) ) {
						return new \WP_Error(
							'gd_b2b_invalid_sdi',
							__( 'Il codice SDI deve essere di 7 caratteri alfanumerici.', 'gd-b2b' )
						);
					}
				},
				'attributes'        => [
					'autocomplete' => 'off',
					'maxlength'    => '7',
					'title'        => __( 'Codice destinatario (7 caratteri).', 'gd-b2b' ),
				],
			],
			[
				'id'                => GD_B2B_FIELD_NS . '/pec',
				'label'             => __( 'PEC', 'gd-b2b' ),
				'validate_callback' => static function ( $value ) {
					$raw = trim( (string) $value );
					if ( '' === $raw ) {
						return;
					}
					if ( ! is_email( $raw ) ) {
						return new \WP_Error(
							'gd_b2b_invalid_pec',
							__( 'Inserisci un indirizzo PEC valido (formato email).', 'gd-b2b' )
						);
					}
				},
				'attributes'        => [
					'autocomplete' => 'email',
				],
			],
		];

		foreach ( $order_fields_sdi_pec_optional as $field ) {
			woocommerce_register_additional_checkout_field(
				array_merge(
					[
						'location'          => 'order',
						'type'              => 'text',
						'required'          => false,
						'hidden'            => $company_conditions_hidden,
						'sanitize_callback' => static function ( $value ) {
							return sanitize_text_field( (string) $value );
						},
					],
					$field
				)
			);
		}
	}

	/**
	 * Render P.IVA / SDI / PEC fields on My Account billing edit form.
	 *
	 * @return void
	 */
	public function render_myaccount_billing_fields(): void {
		if ( ! is_user_logged_in() ) {
			return;
		}
		$uid  = get_current_user_id();
		$piva = $this->get_additional_field_for_customer( $uid, GD_B2B_FIELD_NS . '/piva' );
		$sdi  = $this->get_additional_field_for_customer( $uid, GD_B2B_FIELD_NS . '/codice-sdi' );
		$pec  = $this->get_additional_field_for_customer( $uid, GD_B2B_FIELD_NS . '/pec' );

		echo '<div class="gd-b2b-billing-extra" style="margin-top:1.25em;padding-top:1.25em;border-top:1px solid var(--wc-border-color,rgba(0,0,0,.08));">';
		echo '<h3 style="margin:0 0 .75em;font-size:1.05em;">' . esc_html__( 'Dati fatturazione elettronica (azienda)', 'gd-b2b' ) . '</h3>';
		echo '<p class="form-row form-row-first" id="gd_b2b_piva_field">';
		echo '<label for="gd_b2b_myaccount_piva">' . esc_html__( 'Partita IVA', 'gd-b2b' ) . '&nbsp;<span class="required">*</span></label>';
		echo '<input type="text" class="input-text" name="gd_b2b_myaccount_piva" id="gd_b2b_myaccount_piva" maxlength="13" inputmode="numeric" value="' . esc_attr( $piva ) . '" required /></p>';
		echo '<p class="form-row form-row-last" id="gd_b2b_sdi_field">';
		echo '<label for="gd_b2b_myaccount_sdi">' . esc_html__( 'Codice SDI', 'gd-b2b' ) . '</label>';
		echo '<input type="text" class="input-text" name="gd_b2b_myaccount_sdi" id="gd_b2b_myaccount_sdi" maxlength="7" value="' . esc_attr( $sdi ) . '" /></p>';
		echo '<div class="clear"></div>';
		echo '<p class="form-row form-row-wide" id="gd_b2b_pec_field">';
		echo '<label for="gd_b2b_myaccount_pec">' . esc_html__( 'PEC', 'gd-b2b' ) . '</label>';
		echo '<input type="email" class="input-text" name="gd_b2b_myaccount_pec" id="gd_b2b_myaccount_pec" value="' . esc_attr( $pec ) . '" /></p>';
		echo '</div>';
	}

	/**
	 * Validate P.IVA / SDI / PEC on My Account billing save.
	 *
	 * @param int    $user_id      User ID.
	 * @param string $address_type Address type.
	 * @param mixed  $address      Address data.
	 * @param mixed  $customer     Customer object.
	 * @return void
	 */
	public function validate_myaccount_billing_save( $user_id, $address_type, $address = null, $customer = null ): void {
		if ( 'billing' !== $address_type ) {
			return;
		}
		if ( empty( $_POST['woocommerce-edit-address-nonce'] ) ) {
			return;
		}
		if ( ! isset( $_POST['gd_b2b_myaccount_piva'], $_POST['gd_b2b_myaccount_sdi'], $_POST['gd_b2b_myaccount_pec'] ) ) {
			return;
		}

		$piva_validator = GD_B2B_Plugin::instance()->piva_validator;

		$piva    = preg_replace( '/\D/', '', sanitize_text_field( wp_unslash( $_POST['gd_b2b_myaccount_piva'] ?? '' ) ) );
		$sdi     = strtoupper( trim( sanitize_text_field( wp_unslash( $_POST['gd_b2b_myaccount_sdi'] ?? '' ) ) ) );
		$pec_raw = trim( (string) wp_unslash( $_POST['gd_b2b_myaccount_pec'] ?? '' ) );
		$pec     = '' !== $pec_raw ? strtolower( sanitize_email( $pec_raw ) ) : '';

		if ( ! $piva_validator->validate_format( $piva ) ) {
			wc_add_notice( __( 'Partita IVA non valida (11 cifre).', 'gd-b2b' ), 'error' );
		} elseif ( $piva_validator->check_duplicate( $piva, (int) $user_id ) ) {
			wc_add_notice( __( 'Questa Partita IVA è già registrata da un altro utente.', 'gd-b2b' ), 'error' );
		}
		if ( ! $piva_validator->validate_sdi( $sdi ) ) {
			wc_add_notice( __( 'Codice SDI non valido (7 caratteri alfanumerici).', 'gd-b2b' ), 'error' );
		}
		if ( ! $piva_validator->validate_pec( $pec ) ) {
			wc_add_notice( __( 'PEC non valida.', 'gd-b2b' ), 'error' );
		}
	}

	/**
	 * Persist P.IVA / SDI / PEC on My Account billing save.
	 *
	 * @param int    $user_id      User ID.
	 * @param string $address_type Address type.
	 * @param mixed  $address      Address data.
	 * @param mixed  $customer     Customer object.
	 * @return void
	 */
	public function save_myaccount_billing_fields( $user_id, $address_type, $address = null, $customer = null ): void {
		if ( 'billing' !== $address_type ) {
			return;
		}
		if ( ! isset( $_POST['gd_b2b_myaccount_piva'], $_POST['gd_b2b_myaccount_sdi'], $_POST['gd_b2b_myaccount_pec'] ) ) {
			return;
		}
		$cf = $this->checkout_fields_service();
		if ( ! $cf ) {
			return;
		}
		$piva    = preg_replace( '/\D/', '', sanitize_text_field( wp_unslash( $_POST['gd_b2b_myaccount_piva'] ?? '' ) ) );
		$sdi     = strtoupper( trim( sanitize_text_field( wp_unslash( $_POST['gd_b2b_myaccount_sdi'] ?? '' ) ) ) );
		$pec_raw = trim( (string) wp_unslash( $_POST['gd_b2b_myaccount_pec'] ?? '' ) );
		$pec     = '' !== $pec_raw ? strtolower( sanitize_email( $pec_raw ) ) : '';
		$c       = new \WC_Customer( (int) $user_id );
		try {
			$cf->persist_field_for_customer( GD_B2B_FIELD_NS . '/piva', $piva, $c, 'other' );
			$cf->persist_field_for_customer( GD_B2B_FIELD_NS . '/codice-sdi', $sdi, $c, 'other' );
			$cf->persist_field_for_customer( GD_B2B_FIELD_NS . '/pec', $pec, $c, 'other' );
			$rs = $c->get_billing_company();
			if ( is_string( $rs ) && strlen( trim( $rs ) ) >= 2 ) {
				$cf->persist_field_for_customer( GD_B2B_FIELD_NS . '/ragione-sociale', $rs, $c, 'other' );
			}
			$c->save();
		} catch ( \Throwable $t ) {
			wc_add_notice( __( 'Impossibile salvare i dati aggiuntivi. Riprova o contatta il negozio.', 'gd-b2b' ), 'error' );
		}
	}

	/**
	 * Render admin user profile fields for P.IVA / SDI / PEC.
	 *
	 * @param \WP_User $user User object.
	 * @return void
	 */
	public function render_admin_user_fields( $user ): void {
		if ( ! $user instanceof \WP_User || ! $this->admin_can_edit_fe_fields( (int) $user->ID ) ) {
			return;
		}
		$uid  = (int) $user->ID;
		$piva = $this->get_additional_field_for_customer( $uid, GD_B2B_FIELD_NS . '/piva' );
		$sdi  = $this->get_additional_field_for_customer( $uid, GD_B2B_FIELD_NS . '/codice-sdi' );
		$pec  = $this->get_additional_field_for_customer( $uid, GD_B2B_FIELD_NS . '/pec' );
		$rs   = $this->get_additional_field_for_customer( $uid, GD_B2B_FIELD_NS . '/ragione-sociale' );

		echo '<h2 id="gd-b2b-admin-fe">' . esc_html__( 'Fatturazione elettronica (checkout)', 'gd-b2b' ) . '</h2>';
		echo '<p class="description">' . esc_html__(
			'Partita IVA, SDI e PEC usati al checkout a blocchi. Modificando qui aggiorni gli stessi dati del cliente.',
			'gd-b2b'
		) . '</p>';
		echo '<table class="form-table" role="presentation"><tbody>';
		echo '<tr><th><label for="gd_b2b_admin_fe_piva">' . esc_html__( 'Partita IVA', 'gd-b2b' ) . '</label></th><td>';
		echo '<input type="text" class="regular-text" name="gd_b2b_admin_fe_piva" id="gd_b2b_admin_fe_piva" maxlength="13" value="' . esc_attr( $piva ) . '" /></td></tr>';
		echo '<tr><th><label for="gd_b2b_admin_fe_sdi">' . esc_html__( 'Codice SDI', 'gd-b2b' ) . '</label></th><td>';
		echo '<input type="text" class="regular-text" name="gd_b2b_admin_fe_sdi" id="gd_b2b_admin_fe_sdi" maxlength="7" value="' . esc_attr( $sdi ) . '" /></td></tr>';
		echo '<tr><th><label for="gd_b2b_admin_fe_pec">' . esc_html__( 'PEC', 'gd-b2b' ) . '</label></th><td>';
		echo '<input type="email" class="regular-text" name="gd_b2b_admin_fe_pec" id="gd_b2b_admin_fe_pec" value="' . esc_attr( $pec ) . '" /></td></tr>';
		echo '<tr><th><label for="gd_b2b_admin_fe_rs">' . esc_html__( 'Ragione sociale (campo checkout)', 'gd-b2b' ) . '</label></th><td>';
		echo '<input type="text" class="regular-text" name="gd_b2b_admin_fe_rs" id="gd_b2b_admin_fe_rs" value="' . esc_attr( $rs ) . '" /> ';
		echo '<span class="description">' . esc_html__(
			'Di solito coincide con «Azienda» / ragione sociale in fatturazione; se vuoi allineare solo il campo checkout modifica qui.',
			'gd-b2b'
		) . '</span></td></tr>';
		echo '</tbody></table>';
		echo '<input type="hidden" name="gd_b2b_admin_fe_fields" value="1" />';
	}

	/**
	 * Save admin user profile fields for P.IVA / SDI / PEC.
	 *
	 * @param int $user_id User ID.
	 * @return void
	 */
	public function save_admin_user_fields( int $user_id ): void {
		if ( $user_id <= 0 || ! $this->admin_can_edit_fe_fields( $user_id ) ) {
			return;
		}
		if ( empty( $_POST['gd_b2b_admin_fe_fields'] ) ) {
			return;
		}
		if ( empty( $_POST['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ), 'update-user_' . $user_id ) ) {
			return;
		}
		$cf = $this->checkout_fields_service();
		if ( ! $cf ) {
			return;
		}

		$piva_validator = GD_B2B_Plugin::instance()->piva_validator;

		$piva    = preg_replace( '/\D/', '', sanitize_text_field( wp_unslash( $_POST['gd_b2b_admin_fe_piva'] ?? '' ) ) );
		$sdi     = strtoupper( trim( sanitize_text_field( wp_unslash( $_POST['gd_b2b_admin_fe_sdi'] ?? '' ) ) ) );
		$pec_raw = trim( (string) wp_unslash( $_POST['gd_b2b_admin_fe_pec'] ?? '' ) );
		$pec     = '' !== $pec_raw ? strtolower( sanitize_email( $pec_raw ) ) : '';
		$rs      = sanitize_text_field( wp_unslash( $_POST['gd_b2b_admin_fe_rs'] ?? '' ) );

		$errs = [];
		if ( '' !== $piva && ! $piva_validator->validate_format( $piva ) ) {
			$errs[] = __( 'Partita IVA non valida (11 cifre), oppure lasciare vuoto per cancellare.', 'gd-b2b' );
		} elseif ( '' !== $piva && $piva_validator->check_duplicate( $piva, $user_id ) ) {
			$errs[] = __( 'Questa Partita IVA è già registrata da un altro utente.', 'gd-b2b' );
		}
		if ( '' !== $sdi && ! $piva_validator->validate_sdi( $sdi ) ) {
			$errs[] = __( 'Codice SDI non valido (7 caratteri alfanumerici).', 'gd-b2b' );
		}
		if ( '' !== $pec_raw && ! $piva_validator->validate_pec( $pec ) ) {
			$errs[] = __( 'PEC non valida.', 'gd-b2b' );
		}
		foreach ( $errs as $msg ) {
			add_settings_error( 'gd_b2b_fe', 'gd_b2b_fe_err', $msg, 'error' );
		}
		if ( $errs ) {
			return;
		}

		$c = new \WC_Customer( $user_id );
		try {
			$cf->persist_field_for_customer( GD_B2B_FIELD_NS . '/piva', $piva, $c, 'other' );
			$cf->persist_field_for_customer( GD_B2B_FIELD_NS . '/codice-sdi', $sdi, $c, 'other' );
			$cf->persist_field_for_customer( GD_B2B_FIELD_NS . '/pec', $pec, $c, 'other' );
			$cf->persist_field_for_customer( GD_B2B_FIELD_NS . '/ragione-sociale', $rs, $c, 'other' );
			if ( '' !== $rs && ( '' === $c->get_billing_company() || $c->get_billing_company() !== $rs ) ) {
				$c->set_billing_company( $rs );
			}
			$c->save();
		} catch ( \Throwable $t ) {
			add_settings_error(
				'gd_b2b_fe',
				'gd_b2b_fe_save',
				__( 'Salvataggio dati fatturazione elettronica non riuscito.', 'gd-b2b' ),
				'error'
			);
		}
	}

	/**
	 * Display settings errors on the user edit screen.
	 *
	 * @return void
	 */
	public function show_admin_settings_errors(): void {
		if ( ! function_exists( 'get_current_screen' ) ) {
			return;
		}
		$screen = get_current_screen();
		if ( ! $screen || ! in_array( $screen->id, [ 'user', 'profile' ], true ) ) {
			return;
		}
		settings_errors( 'gd_b2b_fe' );
	}

	// ------------------------------------------------------------------
	// Private helpers
	// ------------------------------------------------------------------

	/**
	 * @return bool
	 */
	private function is_account_edit_billing_address(): bool {
		if ( ! function_exists( 'is_account_page' ) || ! is_account_page() ) {
			return false;
		}
		if ( ! function_exists( 'is_wc_endpoint_url' ) || ! is_wc_endpoint_url( 'edit-address' ) ) {
			return false;
		}
		global $wp;
		$qv = isset( $wp->query_vars['edit-address'] ) ? (string) $wp->query_vars['edit-address'] : '';
		if ( '' === $qv && isset( $_GET['address'] ) ) {
			$qv = sanitize_title( wp_unslash( $_GET['address'] ) );
		}
		if ( '' === $qv ) {
			return false;
		}
		$slug = sanitize_title( $qv );
		if ( function_exists( 'wc_edit_address_i18n' ) ) {
			$resolved = wc_edit_address_i18n( $slug, true );
			return 'billing' === $resolved;
		}
		return 'billing' === $slug;
	}

	/**
	 * @return object|null
	 */
	private function checkout_fields_service() {
		if ( ! class_exists( '\Automattic\WooCommerce\Blocks\Package' ) ) {
			return null;
		}
		try {
			return \Automattic\WooCommerce\Blocks\Package::container()->get(
				\Automattic\WooCommerce\Blocks\Domain\Services\CheckoutFields::class
			);
		} catch ( \Throwable $t ) {
			return null;
		}
	}

	/**
	 * @param int    $user_id  User ID.
	 * @param string $field_id Full field ID.
	 * @param string $location Field location.
	 * @return string
	 */
	private function get_additional_field_for_customer( int $user_id, string $field_id, string $location = 'other' ): string {
		$cf = $this->checkout_fields_service();
		if ( ! $cf ) {
			return '';
		}
		$c = new \WC_Customer( $user_id );
		try {
			$v = $cf->get_field_from_object( $field_id, $c, $location );
		} catch ( \Throwable $t ) {
			return '';
		}
		return is_string( $v ) ? $v : '';
	}

	/**
	 * @param int $user_id User ID.
	 * @return bool
	 */
	private function admin_can_edit_fe_fields( int $user_id ): bool {
		if ( ! $user_id ) {
			return false;
		}
		return current_user_can( 'manage_woocommerce' ) || current_user_can( 'edit_users' );
	}
}
