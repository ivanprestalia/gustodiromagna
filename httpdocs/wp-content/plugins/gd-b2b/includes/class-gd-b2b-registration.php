<?php
/**
 * Company registration form, processing, session management, endpoint.
 *
 * @package GD_B2B
 */

defined( 'ABSPATH' ) || exit;

/**
 * Handles company registration on the My Account page.
 *
 * Replaces procedural code from company-registration.php with a proper OOP class.
 * Manages WC session, form rendering/validation, user creation, admin columns,
 * and the "richiesta-rivenditore" endpoint.
 */
class GD_B2B_Registration {

	/**
	 * Payload passed between process_form() and the woocommerce_created_customer callback.
	 *
	 * @var array|null
	 */
	private ?array $company_payload = null;

	/**
	 * Register all hooks.
	 *
	 * @return void
	 */
	public function init(): void {
		add_action( 'init', [ $this, 'maybe_flush_rewrite' ], 99 );
		add_action( 'init', [ $this, 'add_rewrite_endpoint' ], 5 );
		add_action( 'woocommerce_init', [ $this, 'ensure_guest_session' ], 50 );
		add_action( 'template_redirect', [ $this, 'ensure_session_before_post' ], 1 );
		add_action( 'template_redirect', [ $this, 'process_form' ], 15 );
		add_action( 'woocommerce_created_customer', [ $this, 'apply_payload_to_customer' ], 50, 1 );
		add_action( 'wp_head', [ $this, 'enqueue_frontend_css' ], 99 );
		add_action( 'woocommerce_after_customer_login_form', [ $this, 'render_form' ], 20 );
		add_action( 'set_user_role', [ $this, 'clear_pending_on_role_change' ], 99, 2 );
		add_action( 'add_user_role', [ $this, 'clear_pending_on_role_change' ], 99, 2 );
		add_filter( 'manage_users_columns', [ $this, 'add_users_column' ], 20 );
		add_filter( 'manage_users_custom_column', [ $this, 'render_users_column' ], 10, 3 );
		add_action( 'admin_head', [ $this, 'enqueue_admin_badge_css' ], 99 );
		add_filter( 'woocommerce_get_query_vars', [ $this, 'register_query_var' ], 0 );
		add_filter( 'woocommerce_account_menu_items', [ $this, 'add_menu_item' ], 40 );
		add_action( 'woocommerce_account_richiesta-rivenditore_endpoint', [ $this, 'render_endpoint_content' ] );
	}

	/**
	 * Flush rewrite rules if pending from activation.
	 *
	 * @return void
	 */
	public function maybe_flush_rewrite(): void {
		if ( '1' === get_option( 'gd_b2b_flush_rewrite_pending' ) ) {
			flush_rewrite_rules( false );
			delete_option( 'gd_b2b_flush_rewrite_pending' );
		}
	}

	/**
	 * Register the My Account rewrite endpoint.
	 *
	 * @return void
	 */
	public function add_rewrite_endpoint(): void {
		add_rewrite_endpoint( GD_B2B_ACCOUNT_RICHIESTA_ENDPOINT, EP_PAGES );
	}

	/**
	 * Ensure WC session cookie is active for guests on My Account page.
	 *
	 * @return void
	 */
	public function ensure_guest_session(): void {
		if (
			wp_doing_ajax()
			|| is_admin()
			|| ! function_exists( 'is_account_page' )
			|| ! is_account_page()
			|| is_user_logged_in()
		) {
			return;
		}
		if ( ! $this->session_ready() ) {
			return;
		}
		$s = WC()->session;
		if ( method_exists( $s, 'has_session' ) && method_exists( $s, 'set_customer_session_cookie' ) && ! $s->has_session() ) {
			$s->set_customer_session_cookie( true );
		}
	}

	/**
	 * Ensure guest session is active before processing the registration POST.
	 *
	 * @return void
	 */
	public function ensure_session_before_post(): void {
		if ( is_admin() || wp_doing_ajax() || wp_doing_cron() ) {
			return;
		}
		if ( ( $_SERVER['REQUEST_METHOD'] ?? '' ) !== 'POST' ) {
			return;
		}
		if ( empty( $_POST['gd_b2b_company_register'] ) ) {
			return;
		}
		$this->ensure_customer_session();
	}

	/**
	 * Process the company registration form submission.
	 *
	 * @return void
	 */
	public function process_form(): void {
		if ( is_admin() || wp_doing_ajax() || wp_doing_cron() ) {
			return;
		}
		if ( ( $_SERVER['REQUEST_METHOD'] ?? '' ) !== 'POST' ) {
			return;
		}
		if ( empty( $_POST['gd_b2b_company_register'] ) ) {
			return;
		}
		if (
			empty( $_POST['gd_b2b_nonce'] )
			|| ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['gd_b2b_nonce'] ) ), GD_B2B_NONCE_ACT )
		) {
			$nonce_msg = __(
				'Richiesta non inviabile: sicurezza (nonce) non valida o pagina troppo vecchia. Ricarica la pagina «Mi account» e invia di nuovo il modulo «Registrazione azienda».',
				'gd-b2b'
			);
			$this->store_reg_errors( [ $nonce_msg ] );
			wc_add_notice( $nonce_msg, 'error' );
			$this->redirect_after_reg_fail();
		}
		if ( ! function_exists( 'is_account_page' ) || ! is_account_page() ) {
			return;
		}

		if ( ! empty( $_POST['billing_company_hp'] ?? '' ) ) {
			$this->redirect_after_reg_fail( false );
		}

		$p = [
			'email'              => sanitize_email( wp_unslash( $_POST['account_email'] ?? '' ) ),
			'password'           => wp_unslash( $_POST['account_password'] ?? '' ),
			'billing_company'    => sanitize_text_field( wp_unslash( $_POST['billing_company'] ?? '' ) ),
			'piva'               => sanitize_text_field( wp_unslash( $_POST['gd_b2b_piva'] ?? '' ) ),
			'sdi'                => strtoupper( sanitize_text_field( wp_unslash( $_POST['gd_b2b_sdi'] ?? '' ) ) ),
			'pec'                => strtolower( sanitize_text_field( wp_unslash( $_POST['gd_b2b_pec'] ?? '' ) ) ),
			'billing_first_name' => sanitize_text_field( wp_unslash( $_POST['billing_first_name'] ?? '' ) ),
			'billing_last_name'  => sanitize_text_field( wp_unslash( $_POST['billing_last_name'] ?? '' ) ),
			'billing_address_1'  => sanitize_text_field( wp_unslash( $_POST['billing_address_1'] ?? '' ) ),
			'billing_address_2'  => sanitize_text_field( wp_unslash( $_POST['billing_address_2'] ?? '' ) ),
			'billing_city'       => sanitize_text_field( wp_unslash( $_POST['billing_city'] ?? '' ) ),
			'billing_postcode'   => sanitize_text_field( wp_unslash( $_POST['billing_postcode'] ?? '' ) ),
			'billing_state'      => sanitize_text_field( wp_unslash( $_POST['billing_state'] ?? '' ) ),
			'billing_country'    => sanitize_text_field( wp_unslash( $_POST['billing_country'] ?? 'IT' ) ),
			'billing_phone'      => sanitize_text_field( wp_unslash( $_POST['billing_phone'] ?? '' ) ),
		];

		$errs = [];
		if ( empty( $_POST['gd_b2b_accept_privacy'] ) ) {
			$errs[] = __( "È richiesto accettare l'Informativa privacy.", 'gd-b2b' );
		}
		if ( ! is_email( $p['email'] ) ) {
			$errs[] = __( 'Email non valida.', 'gd-b2b' );
		}
		$min_pw = apply_filters( 'woocommerce_min_password_length', 6 );
		if ( strlen( (string) $p['password'] ) < (int) $min_pw ) {
			$errs[] = sprintf( __( 'Password troppo corta (minimo %d caratteri).', 'gd-b2b' ), (int) $min_pw );
		}
		if ( mb_strlen( $p['billing_company'] ?? '' ) < 2 ) {
			$errs[] = __( 'Inserisci la ragione sociale.', 'gd-b2b' );
		}

		$digits = preg_replace( '/\D/', '', (string) $p['piva'] );
		if ( strlen( $digits ) !== 11 ) {
			$errs[] = __( 'Partita IVA non valida (11 cifre).', 'gd-b2b' );
		} else {
			$piva_validator = GD_B2B_Plugin::instance()->piva_validator;
			if ( $piva_validator->check_duplicate( $digits ) ) {
				$errs[] = __( 'Questa Partita IVA è già registrata da un altro utente.', 'gd-b2b' );
			}
		}
		$p['piva'] = $digits;

		$sdi_val = strtoupper( trim( (string) $p['sdi'] ) );
		if ( '' !== $sdi_val && ( strlen( $sdi_val ) !== 7 || ! ctype_alnum( $sdi_val ) ) ) {
			$errs[] = __( 'Codice SDI non valido (7 caratteri alfanumerici).', 'gd-b2b' );
		}
		$p['sdi'] = $sdi_val;

		$pec_in = trim( (string) $p['pec'] );
		if ( '' !== $pec_in && ! is_email( $pec_in ) ) {
			$errs[] = __( 'PEC non valida.', 'gd-b2b' );
		}
		$p['pec'] = '' !== $pec_in ? strtolower( sanitize_email( $pec_in ) ) : '';

		$mand = [
			'billing_first_name' => __( 'Nome referente', 'gd-b2b' ),
			'billing_last_name'  => __( 'Cognome referente', 'gd-b2b' ),
			'billing_address_1'  => __( 'Indirizzo (via e numero)', 'gd-b2b' ),
			'billing_city'       => __( 'Città', 'gd-b2b' ),
			'billing_postcode'   => __( 'CAP', 'gd-b2b' ),
			'billing_state'      => __( 'Provincia (sigla)', 'gd-b2b' ),
			'billing_country'    => __( 'Paese', 'gd-b2b' ),
			'billing_phone'      => __( 'Telefono', 'gd-b2b' ),
		];
		foreach ( $mand as $f => $label ) {
			if ( 0 === mb_strlen( (string) ( $p[ $f ] ?? '' ) ) ) {
				$errs[] = sprintf(
					__( '«%s»: campo obbligatorio.', 'gd-b2b' ),
					$label
				);
			}
		}

		if ( $errs ) {
			$this->store_reg_errors( $errs );
			wc_add_notice(
				__(
					'La registrazione azienda non è stata inviata: correggi gli errori elencati sotto il modulo «Registrazione azienda».',
					'gd-b2b'
				),
				'error'
			);
			$this->redirect_after_reg_fail();
		}

		if ( email_exists( $p['email'] ) ) {
			$email_err = __(
				"Questo indirizzo email è già registrato sul sito. Per la richiesta B2B non serve un secondo account: accedi con le tue credenziali e contattaci se devi abilitare il profilo aziendale. Per una nuova richiesta usa un'email diversa.",
				'gd-b2b'
			);
			$this->store_reg_errors( [ $email_err ] );
			wc_add_notice( $email_err, 'error' );
			$this->redirect_after_reg_fail();
		}

		$this->company_payload = $p;
		$new_id = wc_create_new_customer(
			$p['email'],
			'',
			$p['password'],
			[
				'first_name' => $p['billing_first_name'],
				'last_name'  => $p['billing_last_name'],
				'source'     => GD_B2B_REG_SRC,
			]
		);
		$this->company_payload = null;

		if ( is_wp_error( $new_id ) ) {
			$code = $new_id->get_error_code();
			$msg  = $new_id->get_error_message();
			if ( in_array( $code, [ 'registration-error-email-exists', 'email_exists' ], true ) ) {
				$msg = __(
					"Questo indirizzo email è già registrato sul sito. Accedi con le tue credenziali o usa un'altra email per la richiesta B2B.",
					'gd-b2b'
				);
			}
			$this->store_reg_errors( [ $msg ] );
			wc_add_notice( $msg, 'error' );
			$this->redirect_after_reg_fail();
		}

		$this->clear_reg_stash();

		GD_B2B_Plugin::instance()->audit_log->log(
			'registration_submitted',
			(int) $new_id,
			[ 'email' => $p['email'], 'company' => $p['billing_company'] ]
		);

		if ( apply_filters( 'woocommerce_registration_auth_new_customer', true, $new_id ) ) {
			wc_set_customer_auth_cookie( $new_id );
			wp_safe_redirect( wc_get_account_endpoint_url( GD_B2B_ACCOUNT_RICHIESTA_ENDPOINT ) );
			exit;
		}
		wp_safe_redirect( wc_get_page_permalink( 'myaccount' ) );
		exit;
	}

	/**
	 * Apply company payload to the newly created customer.
	 *
	 * @param int $customer_id New customer user ID.
	 * @return void
	 */
	public function apply_payload_to_customer( int $customer_id ): void {
		$payload = $this->company_payload;
		if ( ! is_array( $payload ) ) {
			return;
		}

		$c = new \WC_Customer( $customer_id );

		$c->set_billing_email( $payload['email'] );
		$c->set_billing_first_name( $payload['billing_first_name'] );
		$c->set_billing_last_name( $payload['billing_last_name'] );
		$c->set_billing_company( $payload['billing_company'] );
		$c->set_billing_address_1( $payload['billing_address_1'] );
		$c->set_billing_address_2( $payload['billing_address_2'] );
		$c->set_billing_city( $payload['billing_city'] );
		$c->set_billing_postcode( $payload['billing_postcode'] );
		$c->set_billing_state( $payload['billing_state'] );
		$c->set_billing_country( $payload['billing_country'] ?: 'IT' );
		$c->set_billing_phone( $payload['billing_phone'] );

		$bc = $payload['billing_country'] ?: 'IT';
		$c->set_shipping_company( $payload['billing_company'] );
		$c->set_shipping_first_name( $payload['billing_first_name'] );
		$c->set_shipping_last_name( $payload['billing_last_name'] );
		$c->set_shipping_address_1( $payload['billing_address_1'] );
		$c->set_shipping_address_2( $payload['billing_address_2'] );
		$c->set_shipping_city( $payload['billing_city'] );
		$c->set_shipping_postcode( $payload['billing_postcode'] );
		$c->set_shipping_state( $payload['billing_state'] );
		$c->set_shipping_country( $bc );

		if ( class_exists( '\Automattic\WooCommerce\Blocks\Package' ) ) {
			try {
				$cf = \Automattic\WooCommerce\Blocks\Package::container()->get(
					\Automattic\WooCommerce\Blocks\Domain\Services\CheckoutFields::class
				);
				$cf->persist_field_for_customer( GD_B2B_FIELD_NS . '/customer-type', 'company', $c, 'other' );
				$cf->persist_field_for_customer( GD_B2B_FIELD_NS . '/ragione-sociale', $payload['billing_company'], $c, 'other' );
				$cf->persist_field_for_customer( GD_B2B_FIELD_NS . '/piva', $payload['piva'], $c, 'other' );
				$cf->persist_field_for_customer( GD_B2B_FIELD_NS . '/codice-sdi', strtoupper( $payload['sdi'] ), $c, 'other' );
				$cf->persist_field_for_customer( GD_B2B_FIELD_NS . '/pec', $payload['pec'], $c, 'other' );
			} catch ( \Throwable $t ) {
				unset( $t );
			}
		}

		$c->save();

		GD_B2B_Plugin::instance()->roles->set_pending_meta( $customer_id );

		GD_B2B_Plugin::instance()->email->send_admin_notice( $payload, $customer_id );
		GD_B2B_Plugin::instance()->email->send_user_confirmation( $payload['email'] );
	}

	/**
	 * Enqueue frontend registration CSS on My Account (guest).
	 *
	 * @return void
	 */
	public function enqueue_frontend_css(): void {
		if ( ! function_exists( 'is_account_page' ) || ! is_account_page() ) {
			return;
		}
		if ( is_user_logged_in() ) {
			return;
		}
		wp_enqueue_style(
			'gd-b2b-frontend-registration',
			GD_B2B_URL . 'assets/css/frontend-registration.css',
			[],
			GD_B2B_VERSION
		);
	}

	/**
	 * Render the company registration form below the login form.
	 *
	 * @return void
	 */
	public function render_form(): void {
		if ( is_user_logged_in() ) {
			return;
		}
		$countries = WC()->countries->get_allowed_countries();
		$privacy_u = '';
		if ( function_exists( 'wc_privacy_policy_page_id' ) && wc_privacy_policy_page_id() ) {
			$privacy_u = get_permalink( wc_privacy_policy_page_id() );
		}

		$populate     = $this->get_reg_populate();
		$reg_errors   = $this->take_reg_errors();
		$details_open = $this->register_retry_requested()
			|| [] !== $populate
			|| [] !== $reg_errors;

		echo '<div class="gd-b2b-co-reg-wrap">';
		echo '<details class="gd-b2b-co-reg woocommerce-account-gd-b2b" id="gd-b2b-reg-azienda"'
			. ( $details_open ? ' open' : '' ) . '>';
		echo '<summary class="gd-b2b-co-reg__summary">';
		echo '<span class="gd-b2b-co-reg__summary-inner">';
		echo '<span class="gd-b2b-co-reg__title">' . esc_html__( 'Registrazione azienda', 'gd-b2b' ) . '</span>';
		echo '<span class="gd-b2b-co-reg__hint">' . esc_html__( 'Clicca per aprire o chiudere il modulo', 'gd-b2b' ) . '</span>';
		echo '<span class="gd-b2b-co-reg__chev" aria-hidden="true">';
		echo '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" viewBox="0 0 24 24"><path d="M6 9l6 6 6-6"/></svg>';
		echo '</span></span>';
		echo '<span class="gd-b2b-visually-hidden"> ' . esc_html__( '(interruttore formato espandibile)', 'gd-b2b' ) . '</span>';
		echo '</summary><div class="gd-b2b-co-reg__inner">';

		if ( [] !== $reg_errors ) {
			echo '<div class="woocommerce-error gd-b2b-reg-inline-errors" role="alert"><ul class="woocommerce-error" style="margin:0 0 1rem;padding-left:1.25em;">';
			foreach ( $reg_errors as $e ) {
				echo '<li>' . esc_html( $e ) . '</li>';
			}
			echo '</ul></div>';
		}

		echo '<form method="post" action="' . esc_url( wc_get_page_permalink( 'myaccount' ) ) . '" class="woocommerce-form woocommerce-form-register register">';
		echo '<input type="hidden" name="gd_b2b_company_register" value="1" />';
		wp_nonce_field( GD_B2B_NONCE_ACT, 'gd_b2b_nonce', true, true );

		echo '<div class="gd-b2b-co-reg-hp"><label for="billing_company_hp">' . esc_html__( 'Non compilare questo campo', 'gd-b2b' ) . '</label>';
		echo '<input type="text" class="woocommerce-Input input-text" autocomplete="off" id="billing_company_hp" name="billing_company_hp" value="" tabindex="-1" /></div>';

		echo '<p class="woocommerce-form-row woocommerce-form-row--first form-row form-row-first">';
		echo '<label for="gd_b2b_acc_email">' . esc_html__( 'Email (login)', 'gd-b2b' ) . '&nbsp;<span class="required">*</span></label>';
		echo '<input type="email" class="woocommerce-Input woocommerce-Input--text input-text" name="account_email" id="gd_b2b_acc_email" required autocomplete="email" '
			. 'value="' . esc_attr( $this->pop_val( $populate, 'account_email' ) ) . '" /></p>';

		echo '<p class="woocommerce-form-row woocommerce-form-row--last form-row form-row-last">';
		echo '<label for="gd_b2b_acc_pass">' . esc_html__( 'Password account', 'gd-b2b' ) . '&nbsp;<span class="required">*</span></label>';
		echo '<input type="password" class="woocommerce-Input woocommerce-Input--password input-text" name="account_password" id="gd_b2b_acc_pass" required autocomplete="new-password" /></p>';
		echo '<div class="clear"></div>';

		echo '<p class="woocommerce-form-row woocommerce-form-row--first form-row form-row-first">';
		echo '<label for="gd_b2b_co">' . esc_html__( 'Ragione sociale', 'gd-b2b' ) . '&nbsp;<span class="required">*</span></label>';
		echo '<input type="text" class="woocommerce-Input input-text" name="billing_company" id="gd_b2b_co" required autocomplete="organization" '
			. 'value="' . esc_attr( $this->pop_val( $populate, 'billing_company' ) ) . '" /></p>';

		echo '<p class="woocommerce-form-row woocommerce-form-row--last form-row form-row-last">';
		echo '<label for="gd_b2b_piva">' . esc_html__( 'Partita IVA', 'gd-b2b' ) . '&nbsp;<span class="required">*</span></label>';
		echo '<input type="text" class="woocommerce-Input input-text" name="gd_b2b_piva" id="gd_b2b_piva" maxlength="13" inputmode="numeric" autocomplete="off" required '
			. 'value="' . esc_attr( $this->pop_val( $populate, 'gd_b2b_piva' ) ) . '" /></p>';
		echo '<div class="clear"></div>';

		echo '<p class="woocommerce-form-row woocommerce-form-row--first form-row form-row-first">';
		echo '<label for="gd_b2b_sdi">' . esc_html__( 'Codice SDI', 'gd-b2b' ) . '</label>';
		echo '<input type="text" class="woocommerce-Input input-text" name="gd_b2b_sdi" id="gd_b2b_sdi" maxlength="7" autocomplete="off" '
			. 'value="' . esc_attr( $this->pop_val( $populate, 'gd_b2b_sdi' ) ) . '" /></p>';

		echo '<p class="woocommerce-form-row woocommerce-form-row--last form-row form-row-last">';
		echo '<label for="gd_b2b_pec">' . esc_html__( 'PEC', 'gd-b2b' ) . '</label>';
		echo '<input type="email" class="woocommerce-Input input-text" name="gd_b2b_pec" id="gd_b2b_pec" autocomplete="email" '
			. 'value="' . esc_attr( $this->pop_val( $populate, 'gd_b2b_pec' ) ) . '" /></p>';
		echo '<div class="clear" role="presentation"></div>';

		$billing_pairs = [
			[
				[ 'billing_first_name', __( 'Nome referente', 'gd-b2b' ), 'given-name', true ],
				[ 'billing_last_name', __( 'Cognome referente', 'gd-b2b' ), 'family-name', true ],
			],
			[
				[ 'billing_address_1', __( 'Indirizzo (via e numero)', 'gd-b2b' ), 'address-line1', true ],
				[ 'billing_address_2', __( 'Interno / piano (facoltativo)', 'gd-b2b' ), 'address-line2', false ],
			],
			[
				[ 'billing_postcode', __( 'CAP', 'gd-b2b' ), 'postal-code', true ],
				[ 'billing_city', __( 'Città', 'gd-b2b' ), 'address-level2', true ],
			],
			[
				[ 'billing_state', __( 'Provincia (sigla)', 'gd-b2b' ), 'address-level1', true ],
				[ 'billing_phone', __( 'Telefono', 'gd-b2b' ), 'tel', true ],
			],
		];

		foreach ( $billing_pairs as $pair ) {
			if ( ! isset( $pair[1] ) || ! is_array( $pair[1] ) ) {
				continue;
			}
			foreach ( [ [ $pair[0], 'first' ], [ $pair[1], 'last' ] ] as $slot ) {
				list( $row, $pos ) = $slot;
				list( $field, $label, $auto, $req ) = [ $row[0], $row[1], $row[2], $row[3] ];
				$req_m   = $req ? '&nbsp;<span class="required">*</span>' : '';
				$row_cls = 'woocommerce-form-row woocommerce-form-row--'
					. ( 'first' === $pos ? 'first' : 'last' )
					. ' form-row form-row-' . ( 'first' === $pos ? 'first' : 'last' );

				echo '<p class="' . esc_attr( $row_cls ) . '">';
				echo '<label for="' . esc_attr( $field ) . '">' . esc_html( $label ) . $req_m . '</label>';
				echo '<input type="text" class="woocommerce-Input input-text" name="' . esc_attr( $field ) . '" id="' . esc_attr( $field )
					. '" autocomplete="' . esc_attr( $auto ) . '"'
					. ( $req ? ' required' : '' )
					. ' value="' . esc_attr( $this->pop_val( $populate, $field ) ) . '" />';
				echo '</p>';
			}
			echo '<div class="clear"></div>';
		}

		$pop_country = $this->pop_val( $populate, 'billing_country', 'IT' );
		if ( ! isset( $countries[ $pop_country ] ) ) {
			$pop_country = isset( $countries['IT'] ) ? 'IT' : ( [] !== $countries ? (string) array_key_first( $countries ) : 'IT' );
		}

		echo '<p class="woocommerce-form-row woocommerce-form-row--first form-row form-row-first gd-b2b-billing-country">';
		echo '<label for="billing_country">' . esc_html__( 'Paese', 'gd-b2b' ) . '&nbsp;<span class="required">*</span></label>';
		echo '<select class="woocommerce-Input woocommerce-Input--select input-text country_select" name="billing_country" id="billing_country" required>';
		foreach ( $countries as $code => $name ) {
			echo '<option value="' . esc_attr( $code ) . '"' . selected( $pop_country, $code, false ) . '>' . esc_html( $name ) . '</option>';
		}
		echo '</select></p>';
		echo '<div class="clear" role="presentation"></div>';

		echo '<p class="woocommerce-form-row woocommerce-form-row--wide form-row form-row-wide gd-b2b-privacy">';
		echo '<label class="woocommerce-form__label woocommerce-form__label-for-checkbox checkbox" style="display:flex;gap:.65rem;align-items:flex-start">';
		echo '<input class="woocommerce-form__input woocommerce-form__input-checkbox input-checkbox" type="checkbox" name="gd_b2b_accept_privacy" value="1" required '
			. checked( '1' === $this->pop_val( $populate, 'gd_b2b_accept_privacy' ), true, false ) . ' /> ';
		echo '<span class="woocommerce-form__label-text">';
		if ( $privacy_u ) {
			echo esc_html__( 'Dichiaro di aver letto', 'gd-b2b' ) . ' ';
			echo '<a target="_blank" rel="noopener" href="' . esc_url( $privacy_u ) . '">';
			echo esc_html__( "l'Informativa privacy", 'gd-b2b' );
			echo '</a> ';
			echo esc_html__(
				"e acconsento al trattamento dei dati ai fini dell'abilitazione del profilo commerciale B2B.",
				'gd-b2b'
			);
		} else {
			echo esc_html__(
				"Dichiaro di aver letto l'informativa privacy e acconsento al trattamento dei dati ai fini dell'abilitazione del profilo commerciale B2B.",
				'gd-b2b'
			);
		}
		echo '</span></label></p>';

		echo '<p class="woocommerce-form-row form-row">';
		echo '<button type="submit" class="woocommerce-Button woocommerce-button button woocommerce-form-register__submit" name="gd_b2b_company_submit" value="1">';
		esc_html_e( 'Invia richiesta registrazione azienda', 'gd-b2b' );
		echo '</button></p>';
		echo '<p class="woocommerce-info">';
		echo esc_html__(
			"Dopo aver inviato verificheremo i tuoi dati. Fino ad allora puoi navigare sul sito ma non potrai aggiungere prodotti al carrello o pagare gli ordini (listini e condizioni commerciali B2B sono disponibili solo dopo l'assegnazione di un ruolo commerciale valido).",
			'gd-b2b'
		);
		echo '</p>';
		echo '</form></div></details></div>';

		if ( $details_open ) {
			add_action(
				'wp_footer',
				static function () {
					echo '<script>document.getElementById("gd-b2b-reg-azienda")?.scrollIntoView({behavior:"smooth",block:"nearest"});</script>';
				},
				99
			);
		}
	}

	/**
	 * Clear pending buyer meta when a trade role is assigned.
	 *
	 * @param int    $user_id User ID.
	 * @param string $role    Role slug.
	 * @return void
	 */
	public function clear_pending_on_role_change( int $user_id, string $role ): void {
		if ( GD_B2B_Plugin::instance()->roles->is_trade_role( $role ) ) {
			GD_B2B_Plugin::instance()->roles->clear_buyer_meta( $user_id );
		}
	}

	/**
	 * Add the "Richiesta B2B" column to the wp-admin Users list.
	 *
	 * @param array $cols Existing columns.
	 * @return array
	 */
	public function add_users_column( array $cols ): array {
		$cols['gd_b2b_req'] = __( 'Richiesta B2B', 'gd-b2b' );
		return $cols;
	}

	/**
	 * Render the "Richiesta B2B" column content.
	 *
	 * @param string $val         Current column value.
	 * @param string $column_name Column name.
	 * @param int    $user_id     User ID.
	 * @return string
	 */
	public function render_users_column( string $val, string $column_name, int $user_id ): string {
		if ( 'gd_b2b_req' !== $column_name ) {
			return $val;
		}
		$roles = GD_B2B_Plugin::instance()->roles;
		if ( $roles->is_pending_buyer( $user_id ) ) {
			return '<span class="gd-b2b-user-badge gd-b2b-user-badge--pending">' . esc_html__( 'In attesa', 'gd-b2b' ) . '</span>';
		}
		$u = get_userdata( $user_id );
		if ( $u && $roles->user_has_trade_role( $u ) ) {
			$lbl = $roles->get_badge_label( $u );
			return '<span class="gd-b2b-user-badge gd-b2b-user-badge--ok">' . esc_html( $lbl ) . '</span>';
		}
		return '<span class="gd-b2b-user-badge gd-b2b-user-badge--na">—</span>';
	}

	/**
	 * Enqueue admin badge CSS on Users screen.
	 *
	 * @return void
	 */
	public function enqueue_admin_badge_css(): void {
		if ( ! function_exists( 'get_current_screen' ) ) {
			return;
		}
		$screen = get_current_screen();
		if ( ! $screen || 'users' !== $screen->id ) {
			return;
		}
		wp_enqueue_style(
			'gd-b2b-admin-users-badge',
			GD_B2B_URL . 'assets/css/admin-users-badge.css',
			[],
			GD_B2B_VERSION
		);
	}

	/**
	 * Register the endpoint query var.
	 *
	 * @param array $vars Existing query vars.
	 * @return array
	 */
	public function register_query_var( array $vars ): array {
		$vars[ GD_B2B_ACCOUNT_RICHIESTA_ENDPOINT ] = GD_B2B_ACCOUNT_RICHIESTA_ENDPOINT;
		return $vars;
	}

	/**
	 * Add the "Richiesta B2B" menu item to My Account navigation.
	 *
	 * @param array $items Existing menu items.
	 * @return array
	 */
	public function add_menu_item( array $items ): array {
		if ( ! is_user_logged_in() || ! GD_B2B_Plugin::instance()->roles->is_pending_buyer( get_current_user_id() ) ) {
			return $items;
		}
		$label = __( 'Richiesta B2B', 'gd-b2b' );
		$keys  = array_keys( $items );
		$pos   = array_search( 'dashboard', $keys, true );
		if ( false === $pos ) {
			return array_merge( [ GD_B2B_ACCOUNT_RICHIESTA_ENDPOINT => $label ], $items );
		}
		$before = array_slice( $items, 0, $pos + 1, true );
		$after  = array_slice( $items, $pos + 1, null, true );
		return $before + [ GD_B2B_ACCOUNT_RICHIESTA_ENDPOINT => $label ] + $after;
	}

	/**
	 * Render the "richiesta-rivenditore" endpoint content.
	 *
	 * @return void
	 */
	public function render_endpoint_content(): void {
		if ( ! GD_B2B_Plugin::instance()->roles->is_pending_buyer( get_current_user_id() ) ) {
			wp_safe_redirect( wc_get_account_endpoint_url( 'dashboard' ) );
			exit;
		}
		echo '<div class="woocommerce-message woocommerce-message--info">';
		echo esc_html__(
			'Abbiamo ricevuto la tua richiesta di registrazione profilo commerciale (B2B). I dati sono in fase di controllo da parte dello staff.',
			'gd-b2b'
		);
		echo '</div>';
		echo '<p>' . esc_html__(
			"Riceverai un'email quando l'account sarà stato abilitato. Fino ad allora puoi navigare sul sito ma non è possibile effettuare acquisti.",
			'gd-b2b'
		) . '</p>';
		echo '<p><a class="button" href="' . esc_url( wc_get_endpoint_url( 'edit-address', 'billing', wc_get_page_permalink( 'myaccount' ) ) ) . '">';
		esc_html_e( 'Modifica dati di fatturazione e P.IVA / SDI / PEC', 'gd-b2b' );
		echo '</a> ';
		echo '<a class="button" href="' . esc_url( wc_get_page_permalink( 'shop' ) ) . '">';
		esc_html_e( 'Torna al negozio', 'gd-b2b' );
		echo '</a></p>';
	}

	// ------------------------------------------------------------------
	// Session helpers (private)
	// ------------------------------------------------------------------

	/**
	 * @return bool
	 */
	private function session_ready(): bool {
		return function_exists( 'WC' )
			&& WC()
			&& WC()->session instanceof \WC_Session;
	}

	/**
	 * @return void
	 */
	private function ensure_customer_session(): void {
		if ( ! $this->session_ready() ) {
			return;
		}
		$s = WC()->session;
		if ( method_exists( $s, 'has_session' ) && method_exists( $s, 'set_customer_session_cookie' ) && ! $s->has_session() ) {
			$s->set_customer_session_cookie( true );
		}
	}

	/**
	 * @return void
	 */
	private function session_save(): void {
		if ( ! $this->session_ready() ) {
			return;
		}
		if ( method_exists( WC()->session, 'save_data' ) ) {
			WC()->session->save_data();
		}
	}

	/**
	 * @param array<int, string> $errs Error messages.
	 * @return void
	 */
	private function store_reg_errors( array $errs ): void {
		if ( ! $this->session_ready() ) {
			return;
		}
		$clean = [];
		foreach ( $errs as $e ) {
			$t = wp_strip_all_tags( (string) $e );
			if ( '' !== $t ) {
				$clean[] = $t;
			}
		}
		WC()->session->set( GD_B2B_SESSION_REG_ERRORS, $clean );
		$this->session_save();
	}

	/**
	 * @return array<int, string>
	 */
	private function take_reg_errors(): array {
		if ( ! $this->session_ready() ) {
			return [];
		}
		$raw = WC()->session->get( GD_B2B_SESSION_REG_ERRORS );
		WC()->session->set( GD_B2B_SESSION_REG_ERRORS, null );
		if ( ! is_array( $raw ) ) {
			$this->session_save();
			return [];
		}
		$out = array_values(
			array_filter(
				array_map(
					static function ( $e ) {
						return is_string( $e ) ? wp_strip_all_tags( $e ) : '';
					},
					$raw
				)
			)
		);
		$this->session_save();
		return $out;
	}

	/**
	 * @param array  $populate Stashed form data.
	 * @param string $key      Field key.
	 * @param string $default  Default value.
	 * @return string
	 */
	private function pop_val( array $populate, string $key, string $default = '' ): string {
		return array_key_exists( $key, $populate ) ? (string) $populate[ $key ] : $default;
	}

	/**
	 * @return void
	 */
	private function stash_retry_from_post(): void {
		if ( ! $this->session_ready() ) {
			return;
		}
		$post      = wp_unslash( $_POST ?? [] );
		$countries = WC()->countries->get_allowed_countries();
		$bc        = sanitize_text_field( $post['billing_country'] ?? 'IT' );
		if ( '' === $bc || ! isset( $countries[ $bc ] ) ) {
			$bc = array_key_first( $countries ) ? (string) array_key_first( $countries ) : 'IT';
		}
		WC()->session->set(
			GD_B2B_SESSION_STASH,
			[
				'account_email'         => sanitize_email( $post['account_email'] ?? '' ),
				'billing_company'       => sanitize_text_field( $post['billing_company'] ?? '' ),
				'gd_b2b_piva'           => sanitize_text_field( $post['gd_b2b_piva'] ?? '' ),
				'gd_b2b_sdi'            => strtoupper( sanitize_text_field( $post['gd_b2b_sdi'] ?? '' ) ),
				'gd_b2b_pec'            => sanitize_text_field( $post['gd_b2b_pec'] ?? '' ),
				'billing_first_name'    => sanitize_text_field( $post['billing_first_name'] ?? '' ),
				'billing_last_name'     => sanitize_text_field( $post['billing_last_name'] ?? '' ),
				'billing_address_1'     => sanitize_text_field( $post['billing_address_1'] ?? '' ),
				'billing_address_2'     => sanitize_text_field( $post['billing_address_2'] ?? '' ),
				'billing_postcode'      => sanitize_text_field( $post['billing_postcode'] ?? '' ),
				'billing_city'          => sanitize_text_field( $post['billing_city'] ?? '' ),
				'billing_state'         => sanitize_text_field( $post['billing_state'] ?? '' ),
				'billing_country'       => $bc,
				'billing_phone'         => sanitize_text_field( $post['billing_phone'] ?? '' ),
				'gd_b2b_accept_privacy' => ! empty( $post['gd_b2b_accept_privacy'] ) ? '1' : '',
			]
		);
		$this->session_save();
	}

	/**
	 * @return void
	 */
	private function clear_reg_stash(): void {
		if ( ! $this->session_ready() ) {
			return;
		}
		WC()->session->set( GD_B2B_SESSION_STASH, null );
		WC()->session->set( GD_B2B_SESSION_REG_ERRORS, null );
		$this->session_save();
	}

	/**
	 * @return bool
	 */
	private function register_retry_requested(): bool {
		if ( ! isset( $_GET[ GD_B2B_REG_RETRY_QUERY ] ) ) {
			return false;
		}
		$slug = strtolower( sanitize_text_field( wp_unslash( $_GET[ GD_B2B_REG_RETRY_QUERY ] ?? '' ) ) );
		return 'retry' === $slug;
	}

	/**
	 * @return array<string, string>
	 */
	private function get_reg_populate(): array {
		if ( ! $this->session_ready() ) {
			return [];
		}
		$row = WC()->session->get( GD_B2B_SESSION_STASH );
		return is_array( $row ) ? $row : [];
	}

	/**
	 * @param bool $stash Whether to stash form data before redirect.
	 * @return void
	 */
	private function redirect_after_reg_fail( bool $stash = true ): void {
		if ( $stash ) {
			$this->stash_retry_from_post();
			wp_safe_redirect(
				add_query_arg(
					[ GD_B2B_REG_RETRY_QUERY => 'retry' ],
					wc_get_page_permalink( 'myaccount' )
				)
			);
			exit;
		}
		wp_safe_redirect( wc_get_page_permalink( 'myaccount' ) );
		exit;
	}
}
