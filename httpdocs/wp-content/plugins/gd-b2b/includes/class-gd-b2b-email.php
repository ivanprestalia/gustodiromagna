<?php
/**
 * GD_B2B_Email — Centralized email management with templates, rate limiting, and configuration.
 *
 * @package GD_B2B
 * @since   2.0.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Handles all outgoing emails for the GD B2B plugin.
 *
 * Improvements:
 * - #3:  Template-based HTML email (templates/emails/)
 * - #5:  Rate limiting via transients
 * - #12: Configurable subjects, enable/disable, extra recipients
 */
class GD_B2B_Email {

	/**
	 * Default subjects keyed by email type.
	 *
	 * @var array<string,string>
	 */
	private const DEFAULT_SUBJECTS = array(
		'activation'        => 'Il tuo account B2B è attivo — {site_name}',
		'admin_notice'      => 'Nuova richiesta di registrazione Azienda da {site_name}',
		'user_confirmation' => 'Richiesta registrazione aziendale ricevuta — {site_name}',
	);

	/**
	 * Get email settings from plugin options.
	 *
	 * @return array
	 */
	private function get_settings(): array {
		$options = get_option( GD_B2B_OPTION_SETTINGS, array() );

		if ( ! is_array( $options ) || ! isset( $options['email'] ) || ! is_array( $options['email'] ) ) {
			return array();
		}

		return $options['email'];
	}

	/**
	 * Get a single email setting with default fallback.
	 *
	 * @param string $key     Setting key within the 'email' sub-array.
	 * @param mixed  $default Fallback value.
	 * @return mixed
	 */
	private function get_setting( string $key, $default = '' ) {
		$settings = $this->get_settings();

		return $settings[ $key ] ?? $default;
	}

	/**
	 * Check whether an email can be sent for a given user (rate limiting).
	 *
	 * Uses transient: gd_b2b_mail_throttle_{user_id}
	 *
	 * @param int $user_id WordPress user ID.
	 * @return bool True if sending is allowed.
	 */
	public function can_send( int $user_id ): bool {
		$transient = 'gd_b2b_mail_throttle_' . $user_id;

		return false === get_transient( $transient );
	}

	/**
	 * Record that an email was sent, activating the throttle window.
	 *
	 * @param int $user_id WordPress user ID.
	 */
	private function mark_sent( int $user_id ): void {
		$seconds   = (int) $this->get_setting( 'rate_limit', 60 );
		$transient = 'gd_b2b_mail_throttle_' . $user_id;

		set_transient( $transient, 1, max( 1, $seconds ) );
	}

	/**
	 * Check if a specific email type is enabled via settings.
	 *
	 * @param string $type One of 'activation', 'admin_notice', 'user_confirmation'.
	 * @return bool
	 */
	public function is_enabled( string $type ): bool {
		$key = $type . '_enabled';

		return (bool) $this->get_setting( $key, true );
	}

	/**
	 * Get the configured subject line for an email type, with placeholder replacement.
	 *
	 * @param string $type One of 'activation', 'admin_notice', 'user_confirmation'.
	 * @return string
	 */
	public function get_subject( string $type ): string {
		$key       = $type . '_subject';
		$custom    = trim( (string) $this->get_setting( $key, '' ) );
		$template  = '' !== $custom ? $custom : ( self::DEFAULT_SUBJECTS[ $type ] ?? '' );
		$site_name = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );

		return str_replace( '{site_name}', $site_name, $template );
	}

	/**
	 * Get the site logo URL for use in email headers.
	 *
	 * Preserves filter: gd_b2b_company_email_logo_url
	 *
	 * @return string Logo URL or empty string.
	 */
	public function get_logo_url(): string {
		$url = '';

		$configured = trim( (string) $this->get_setting( 'email_logo_url', '' ) );
		if ( '' !== $configured ) {
			$url = $configured;
		}

		if ( '' === $url ) {
			$lid = function_exists( 'get_theme_mod' ) ? (int) get_theme_mod( 'custom_logo', 0 ) : 0;
			if ( $lid && function_exists( 'wp_get_attachment_image_url' ) ) {
				foreach ( array( 'full', 'large', 'medium' ) as $size ) {
					$u = wp_get_attachment_image_url( $lid, $size );
					if ( is_string( $u ) && '' !== $u ) {
						$url = $u;
						break;
					}
				}
			}
		}

		if ( '' === $url && function_exists( 'get_option' ) ) {
			$site_logo = get_option( 'site_logo' );
			if ( is_numeric( $site_logo ) && (int) $site_logo > 0 && function_exists( 'wp_get_attachment_image_url' ) ) {
				foreach ( array( 'full', 'large', 'medium' ) as $size ) {
					$u = wp_get_attachment_image_url( (int) $site_logo, $size );
					if ( is_string( $u ) && '' !== $u ) {
						$url = $u;
						break;
					}
				}
			}
		}

		if ( '' === $url && function_exists( 'get_site_icon_url' ) ) {
			$u = get_site_icon_url( 192 );
			if ( is_string( $u ) && '' !== $u ) {
				$url = $u;
			}
		}

		/** @var string $url */
		$url = apply_filters( 'gd_b2b_company_email_logo_url', $url );

		return is_string( $url ) && '' !== $url ? esc_url_raw( $url ) : '';
	}

	/**
	 * Get the footer text for email templates.
	 *
	 * @return string
	 */
	public function get_footer_text(): string {
		$text = trim( (string) $this->get_setting( 'email_footer_text', '' ) );

		return apply_filters( 'gd_b2b_email_footer_text', $text );
	}

	/**
	 * Load and render an email template file.
	 *
	 * First checks for a DB-stored custom template (from the email template editor).
	 * If found, replaces placeholders. Otherwise falls back to the PHP file.
	 *
	 * @param string $template Template filename (e.g. 'activation.php').
	 * @param array  $args     Variables available inside the template.
	 * @return string Rendered HTML.
	 */
	public function render_template( string $template, array $args = [] ): string {
		$slug = $this->template_filename_to_slug( $template );

		if ( '' !== $slug && class_exists( 'GD_B2B_Admin_Email_Templates' ) ) {
			$saved = GD_B2B_Admin_Email_Templates::get_saved_templates();
			if ( ! empty( $saved[ $slug ] ) ) {
				return $this->render_db_template( $slug, $saved[ $slug ], $args );
			}
		}

		$file = GD_B2B_PATH . 'templates/emails/' . $template;

		if ( ! file_exists( $file ) ) {
			return '';
		}

		ob_start();
		// phpcs:ignore WordPress.PHP.DontExtract.extract_extract
		extract( $args, EXTR_SKIP );
		include $file;

		return (string) ob_get_clean();
	}

	/**
	 * Render a PHP template file directly, bypassing DB custom template lookup.
	 *
	 * Used by the admin editor to generate default HTML with placeholder markers.
	 *
	 * @param string $template Template filename (e.g. 'activation.php').
	 * @param array  $args     Variables available inside the template.
	 * @return string Rendered HTML.
	 */
	public function render_file_template( string $template, array $args = [] ): string {
		$file = GD_B2B_PATH . 'templates/emails/' . $template;

		if ( ! file_exists( $file ) ) {
			return '';
		}

		ob_start();
		// phpcs:ignore WordPress.PHP.DontExtract.extract_extract
		extract( $args, EXTR_SKIP );
		include $file;

		return (string) ob_get_clean();
	}

	/**
	 * Map a template filename to its slug for DB lookup.
	 *
	 * @param string $filename Template filename.
	 * @return string Slug or empty string.
	 */
	private function template_filename_to_slug( string $filename ): string {
		$map = array(
			'base.php'              => 'base',
			'activation.php'        => 'activation',
			'admin-notice.php'      => 'admin_notice',
			'user-confirmation.php' => 'user_confirmation',
		);
		return $map[ $filename ] ?? '';
	}

	/**
	 * Render a DB-stored template by replacing placeholders with actual values.
	 *
	 * @param string $slug    Template slug.
	 * @param string $html    Custom HTML from DB.
	 * @param array  $args    Runtime args from the calling method.
	 * @return string Rendered HTML.
	 */
	private function render_db_template( string $slug, string $html, array $args ): string {
		$replacements = $this->map_args_to_placeholders( $slug, $args );

		return str_replace(
			array_keys( $replacements ),
			array_values( $replacements ),
			$html
		);
	}

	/**
	 * Convert runtime $args into a placeholder => value map for a given template.
	 *
	 * @param string $slug Template slug.
	 * @param array  $args Runtime template arguments.
	 * @return array<string, string>
	 */
	private function map_args_to_placeholders( string $slug, array $args ): array {
		switch ( $slug ) {
			case 'base':
				$ft = $args['footer_text'] ?? '';
				return array(
					'{contenuto}'   => $args['inner_html'] ?? '',
					'{nome_sito}'   => $args['brand'] ?? '',
					'{url_logo}'    => $args['logo'] ?? ( $args['logo_url'] ?? '' ),
					'{footer_text}' => '' !== $ft ? nl2br( esc_html( $ft ) ) : '',
				);
			case 'activation':
				return array(
					'{nome_cliente}' => esc_html( $args['name'] ?? '' ),
					'{url_negozio}'  => esc_url( $args['shop_url'] ?? '' ),
				);
			case 'admin_notice':
				$rows = $args['rows'] ?? array();
				$row_map = array();
				foreach ( $rows as $pair ) {
					$row_map[ $pair[0] ] = $pair[1] ?? '';
				}
				return array(
					'{id_utente}'           => esc_html( (string) ( $args['customer_id'] ?? ( $row_map[ __( 'Utente WP ID', 'gd-b2b' ) ] ?? '' ) ) ),
					'{email_cliente}'       => esc_html( $row_map[ __( 'Email login', 'gd-b2b' ) ] ?? '' ),
					'{ragione_sociale}'     => esc_html( $row_map[ __( 'Ragione sociale', 'gd-b2b' ) ] ?? '' ),
					'{piva}'                => esc_html( $row_map[ __( 'P.IVA', 'gd-b2b' ) ] ?? '' ),
					'{sdi}'                 => esc_html( $row_map[ __( 'SDI', 'gd-b2b' ) ] ?? '' ),
					'{pec}'                 => esc_html( $row_map[ __( 'PEC', 'gd-b2b' ) ] ?? '' ),
					'{referente}'           => esc_html( $row_map[ __( 'Referente', 'gd-b2b' ) ] ?? '' ),
					'{indirizzo}'           => esc_html( $row_map[ __( 'Indirizzo', 'gd-b2b' ) ] ?? '' ),
					'{telefono}'            => esc_html( $row_map[ __( 'Telefono', 'gd-b2b' ) ] ?? '' ),
					'{url_modifica_utente}' => esc_url( $args['edit_url'] ?? '' ),
				);
			case 'user_confirmation':
				$brand = esc_html( wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ) );
				return array(
					'{nome_sito}' => $brand,
				);
			default:
				return array();
		}
	}

	/**
	 * Build a complete branded email HTML document wrapping inner content.
	 *
	 * @param string $inner_html Main content block (already escaped where needed).
	 * @return string Full HTML email document.
	 */
	public function build_branded_html( string $inner_html ): string {
		$brand       = esc_html( wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ) );
		$logo        = $this->get_logo_url();
		$footer_text = $this->get_footer_text();

		$template_file = GD_B2B_PATH . 'templates/emails/base.php';

		if ( file_exists( $template_file ) ) {
			return $this->render_template( 'base.php', array(
				'brand'       => $brand,
				'logo'        => $logo,
				'inner_html'  => $inner_html,
				'footer_text' => $footer_text,
			) );
		}

		if ( '' !== $logo ) {
			$logo_html = '<img src="' . esc_attr( $logo ) . '" alt="' . $brand . '" style="display:block;margin:0 auto;max-height:72px;width:auto;max-width:100%;" />';
		} else {
			$logo_html = '<span style="display:inline-block;color:#292524;font:bold 22px/1.2 -apple-system,BlinkMacSystemFont,\'Segoe UI\',Roboto,Helvetica,sans-serif;letter-spacing:-0.02em;">'
				. $brand
				. '</span>';
		}

		return '<!DOCTYPE html><html lang="it"><head><meta charset="UTF-8" /><meta name="viewport" content="width=device-width,initial-scale=1" /></head>'
			. '<body style="margin:0;background:#f5f5f4;">'
			. '<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="margin:0;padding:8px 12px;"><tr><td align="center">'
			. '<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="600" style="max-width:600px;background:#ffffff;border-radius:10px;overflow:hidden;box-shadow:0 4px 24px rgba(0,0,0,0.06);">'
			. '<tr><td align="center" style="padding:14px 24px 12px;background:#ffffff;">'
			. $logo_html
			. '</td></tr>'
			. '<tr><td style="padding:0;font-size:0;line-height:0;"><div style="height:2px;background:#fcb900;"></div></td></tr>'
			. '<tr><td style="padding:0;">'
			. $inner_html
			. '</td></tr>'
			. '</table>'
			. '<p style="margin:16px auto 0;max-width:600px;color:#a8a29e;font:12px/1.6 -apple-system,BlinkMacSystemFont,\'Segoe UI\',Roboto,Helvetica,sans-serif;text-align:center;">'
			. $brand
			. ( '' !== $footer_text ? '<br />' . nl2br( esc_html( $footer_text ) ) : '' )
			. '</p>'
			. '</td></tr></table></body></html>';
	}

	/**
	 * Get the number of times the activation email was sent to a user.
	 *
	 * @param int $user_id WordPress user ID.
	 * @return int
	 */
	public function get_send_count( int $user_id ): int {
		$n = get_user_meta( $user_id, GD_B2B_META_ACTIVATION_EMAIL_SENT, true );

		return max( 0, (int) $n );
	}

	/**
	 * Send B2B activation email to a customer.
	 *
	 * Includes rate limiting (#5), template rendering (#3), and configurability (#12).
	 *
	 * Filters preserved:
	 * - gd_b2b_activation_email_bcc_recipients
	 * - gd_b2b_activation_email_headers
	 *
	 * @param int $user_id WordPress user ID.
	 * @return array{success:bool,total_sent:int,to:string,error:string}
	 */
	public function send_activation( int $user_id ): array {
		$uid        = (int) $user_id;
		$user       = get_userdata( $uid );
		$count_prev = $this->get_send_count( $uid );

		$ret_fail = static function ( $error_msg ) use ( $count_prev ) {
			return array(
				'success'    => false,
				'total_sent' => $count_prev,
				'to'         => '',
				'error'      => (string) $error_msg,
			);
		};

		if ( ! $this->is_enabled( 'activation' ) ) {
			return $ret_fail( __( 'L\'invio email di attivazione è disabilitato nelle impostazioni.', 'gd-b2b' ) );
		}

		if ( ! $this->can_send( $uid ) ) {
			$seconds = (int) $this->get_setting( 'rate_limit', 60 );
			return $ret_fail(
				sprintf(
					/* translators: %d: seconds to wait */
					__( 'Attendi almeno %d secondi tra un invio e l\'altro.', 'gd-b2b' ),
					$seconds
				)
			);
		}

		if ( ! $user instanceof \WP_User ) {
			return $ret_fail( __( 'Utente non trovato.', 'gd-b2b' ) );
		}

		$to = sanitize_email( $user->user_email );
		if ( '' === $to || ! is_email( $to ) ) {
			return $ret_fail( __( 'Email destinatario non valida sul profilo cliente.', 'gd-b2b' ) );
		}

		$shop_url = function_exists( 'wc_get_page_permalink' ) ? wc_get_page_permalink( 'shop' ) : '';
		if ( ! is_string( $shop_url ) || '' === $shop_url ) {
			$shop_url = home_url( '/' );
		}

		$display = trim( sprintf( '%s %s', (string) $user->first_name, (string) $user->last_name ) );
		if ( '' === $display ) {
			$display = $user->display_name;
		}

		$inner = $this->render_template( 'activation.php', array(
			'name'     => $display,
			'shop_url' => $shop_url,
		) );

		if ( '' === $inner ) {
			$inner = $this->build_activation_fallback_html( $display, $shop_url );
		}

		$html = $this->build_branded_html( $inner );

		$plain = sprintf(
			/* translators: 1: greeting name, 2: shop URL */
			__( "Ciao %1\$s,\r\n\r\nIl tuo profilo commerciale (B2B) è stato attivato: puoi accedere al negozio e acquistare.\r\n\r\nNegozio: %2\$s\r\n", 'gd-b2b' ),
			wp_strip_all_tags( $display ),
			$shop_url
		);

		$subject = $this->get_subject( 'activation' );

		$headers    = array( 'Content-Type: text/html; charset=UTF-8' );
		$admin_e    = sanitize_email( (string) get_option( 'admin_email' ) );
		if ( is_email( $admin_e ) ) {
			$headers[] = 'Reply-To: ' . $admin_e;
		}

		$bcc_raw = apply_filters( 'gd_b2b_activation_email_bcc_recipients', array(), $uid, $user );
		$bcc_raw = array_map( 'trim', is_array( $bcc_raw ) ? $bcc_raw : array() );
		$bcc_ok  = array_values( array_unique( array_filter( array_map( 'sanitize_email', $bcc_raw ), 'is_email' ) ) );
		if ( array() !== $bcc_ok ) {
			$headers[] = 'Bcc: ' . implode( ', ', $bcc_ok );
		}

		/** @var array $headers */
		$headers = apply_filters( 'gd_b2b_activation_email_headers', $headers, $uid, $user );
		if ( ! is_array( $headers ) ) {
			$headers = array( 'Content-Type: text/html; charset=UTF-8' );
		}

		$this->log( sprintf(
			'activation pre-wp_mail to=%s uid=%d',
			$to,
			$uid
		) );

		$mail_error_msg      = '';
		$capture_mail_failed = static function ( $failure ) use ( &$mail_error_msg ) {
			if ( $failure instanceof \WP_Error && $failure->has_errors() ) {
				$mail_error_msg = implode( ' ', $failure->get_error_messages() );
			}
		};
		add_action( 'wp_mail_failed', $capture_mail_failed, 10, 1 );

		$ok = $this->send_html_email( $to, $subject, $html, $plain, $headers );

		remove_action( 'wp_mail_failed', $capture_mail_failed, 10 );

		$this->log( sprintf(
			'activation post-wp_mail result=%s error=%s',
			$ok ? 'true' : 'false',
			'' !== $mail_error_msg ? $mail_error_msg : '-'
		) );

		if ( ! $ok ) {
			$detail = '' !== $mail_error_msg
				? $mail_error_msg
				: __( 'Il server ha rifiutato l\'invio (wp_mail: false). Controllare SMTP/logging.', 'gd-b2b' );

			error_log( 'GD B2B activation email failed user=' . $uid . ' to=' . $to . ' err=' . $detail );

			return $ret_fail( $detail );
		}

		$this->mark_sent( $uid );
		update_user_meta( $uid, GD_B2B_META_ACTIVATION_EMAIL_SENT, $count_prev + 1 );

		return array(
			'success'    => true,
			'total_sent' => $count_prev + 1,
			'to'         => $to,
			'error'      => '',
		);
	}

	/**
	 * Send admin notification about a new B2B registration request.
	 *
	 * Sends to the site admin email plus any extra recipients configured in settings.
	 *
	 * @param array $payload    Registration payload data.
	 * @param int   $customer_id WordPress user ID of the new customer.
	 */
	public function send_admin_notice( array $payload, int $customer_id ): void {
		if ( ! $this->is_enabled( 'admin_notice' ) ) {
			return;
		}

		$admin_email = sanitize_email( get_option( 'admin_email' ) );
		if ( '' === $admin_email ) {
			return;
		}

		$recipients = array( $admin_email );
		$extra      = trim( (string) $this->get_setting( 'admin_notice_recipients', '' ) );
		if ( '' !== $extra ) {
			$extra_list = array_map( 'trim', explode( ',', $extra ) );
			foreach ( $extra_list as $addr ) {
				$addr = sanitize_email( $addr );
				if ( is_email( $addr ) ) {
					$recipients[] = $addr;
				}
			}
		}
		$recipients = array_unique( $recipients );

		$countries     = WC()->countries->countries;
		$country_label = $countries[ $payload['billing_country'] ] ?? $payload['billing_country'];
		$edit_url      = admin_url( 'user-edit.php?user_id=' . $customer_id );
		$subject       = $this->get_subject( 'admin_notice' );

		$new_paragraph_plain =
			__( 'Controllare i dati e, se idoneo, dalla scheda Utente assegnare uno dei ruoli: Rivenditore, Azienda sconto Base, Azienda sconto Premium o Azienda sconto Gold.', 'gd-b2b' )
			. "\r\n\r\n"
			. __( 'Ora è in stato "pending" e verrà aggiornato automaticamente quando è attivo uno di questi ruoli.', 'gd-b2b' )
			. "\r\n\r\n"
			. __( 'Puoi aggiornare andando in "Modifica utente" cliccando qui:', 'gd-b2b' );

		$new_paragraph_html =
			'<p style="margin:0 0 12px;"><strong>'
			. esc_html__(
				'Controllare i dati e, se idoneo, dalla scheda Utente assegnare uno dei ruoli: Rivenditore, Azienda sconto Base, Azienda sconto Premium o Azienda sconto Gold.',
				'gd-b2b'
			)
			. '</strong></p>'
			. '<p style="margin:0 0 12px;">'
			. esc_html__(
				'Ora è in stato "pending" e verrà aggiornato automaticamente quando è attivo uno di questi ruoli.',
				'gd-b2b'
			)
			. '</p>'
			. '<p style="margin:0;">'
			. esc_html__(
				'Puoi aggiornare andando in "Modifica utente" cliccando sul pulsante o sul link:',
				'gd-b2b'
			)
			. '</p>';

		$plain = $this->get_admin_notice_plain( $payload, $customer_id, $country_label, $edit_url );

		$inner = $this->render_template( 'admin-notice.php', array(
			'payload'         => $payload,
			'customer_id'     => $customer_id,
			'country_label'   => $country_label,
			'edit_url'        => $edit_url,
			'rows'            => $this->get_admin_notice_rows( $payload, $customer_id, $country_label ),
			'paragraph_html'  => $new_paragraph_html,
		) );

		if ( '' === $inner ) {
			$inner = $this->build_admin_notice_fallback_html(
				$payload,
				$customer_id,
				$country_label,
				$edit_url,
				$new_paragraph_html
			);
		}

		$html = $this->build_branded_html( $inner );

		foreach ( $recipients as $mailto ) {
			$this->send_html_email( $mailto, $subject, $html, $plain );
		}
	}

	/**
	 * Send user confirmation email (registration request received).
	 *
	 * @param string $to_email Recipient email address.
	 */
	public function send_user_confirmation( string $to_email ): void {
		if ( ! $this->is_enabled( 'user_confirmation' ) ) {
			return;
		}

		$to = sanitize_email( $to_email );
		if ( '' === $to || ! is_email( $to ) ) {
			return;
		}

		$subject = $this->get_subject( 'user_confirmation' );

		$inner = $this->render_template( 'user-confirmation.php', array() );

		if ( '' === $inner ) {
			$inner = '<div style="padding:26px 24px 28px;">'
				. '<p style="margin:0 0 14px;color:#431407;font:600 17px -apple-system,BlinkMacSystemFont,\'Segoe UI\',Roboto,Helvetica,sans-serif;">'
				. esc_html__( 'Abbiamo ricevuto la tua richiesta', 'gd-b2b' )
				. '</p>'
				. '<p style="margin:0 0 12px;color:#41403f;font:15px/1.65 -apple-system,BlinkMacSystemFont,\'Segoe UI\',Roboto,Helvetica,sans-serif;">'
				. esc_html__(
					'Abbiamo ricevuto la tua richiesta di registrazione profilo commerciale (B2B). Riceverai un\'email quando sarà stata esaminata e il tuo account sarà stato abilitato.',
					'gd-b2b'
				)
				. '</p>'
				. '<p style="margin:0;color:#57534e;font:15px/1.65 -apple-system,BlinkMacSystemFont,\'Segoe UI\',Roboto,Helvetica,sans-serif;">'
				. esc_html__( 'Fino ad allora non è possibile effettuare acquisti sul sito.', 'gd-b2b' )
				. '</p></div>';
		}

		$html = $this->build_branded_html( $inner );

		$plain = __(
			'Abbiamo ricevuto la tua richiesta di registrazione profilo commerciale (B2B). Riceverai un\'email quando sarà stata esaminata e il tuo account sarà stato abilitato.',
			'gd-b2b'
		)
			. "\r\n\r\n"
			. __( 'Fino ad allora non è possibile effettuare acquisti sul sito.', 'gd-b2b' );

		$this->send_html_email( $to, $subject, $html, $plain );
	}

	/**
	 * Build data rows for admin notice email.
	 *
	 * @param array  $payload       Registration payload.
	 * @param int    $customer_id   WordPress user ID.
	 * @param string $country_label Localized country name.
	 * @return array<int, array{0:string,1:string}> Pairs of [label, value].
	 */
	public function get_admin_notice_rows( array $payload, int $customer_id, string $country_label ): array {
		$sdi_upper = isset( $payload['sdi'] ) ? strtoupper( (string) $payload['sdi'] ) : '';
		$addr      = isset( $payload['billing_postcode'], $payload['billing_city'], $payload['billing_state'] )
			? sprintf(
				/* translators: 1 street, 2 zip, 3 city, 4 province, 5 country */
				__( '%1$s, %2$s %3$s (%4$s) — %5$s', 'gd-b2b' ),
				(string) $payload['billing_address_1'],
				(string) $payload['billing_postcode'],
				(string) $payload['billing_city'],
				(string) $payload['billing_state'],
				wc_clean( $country_label )
			)
			: '';

		return array(
			array( __( 'Utente WP ID', 'gd-b2b' ), (string) $customer_id ),
			array( __( 'Email login', 'gd-b2b' ), (string) $payload['email'] ),
			array( __( 'Ragione sociale', 'gd-b2b' ), (string) $payload['billing_company'] ),
			array( __( 'P.IVA', 'gd-b2b' ), (string) $payload['piva'] ),
			array( __( 'SDI', 'gd-b2b' ), $sdi_upper ),
			array( __( 'PEC', 'gd-b2b' ), (string) $payload['pec'] ),
			array(
				__( 'Referente', 'gd-b2b' ),
				trim( sprintf( '%s %s', (string) $payload['billing_first_name'], (string) $payload['billing_last_name'] ) ),
			),
			array( __( 'Indirizzo', 'gd-b2b' ), $addr ),
			array( __( 'Telefono', 'gd-b2b' ), (string) $payload['billing_phone'] ),
		);
	}

	/**
	 * Build plain text body for admin notice email.
	 *
	 * @param array  $payload       Registration payload.
	 * @param int    $customer_id   WordPress user ID.
	 * @param string $country_label Localized country name.
	 * @param string $edit_url      Admin edit URL for the user.
	 * @return string
	 */
	public function get_admin_notice_plain( array $payload, int $customer_id, string $country_label, string $edit_url ): string {
		$intro = __( 'È stata inoltrata una richiesta di registrazione aziendale.', 'gd-b2b' );
		$rows  = $this->get_admin_notice_rows( $payload, $customer_id, $country_label );
		$lines = array( $intro, '' );

		foreach ( $rows as $r ) {
			$lines[] = sprintf( '%s: %s', $r[0], $r[1] );
		}

		$lines[] = '';
		$lines[] = __( 'Controllare i dati e, se idoneo, dalla scheda Utente assegnare uno dei ruoli: Rivenditore, Azienda sconto Base, Azienda sconto Premium o Azienda sconto Gold.', 'gd-b2b' );
		$lines[] = '';
		$lines[] = __( 'Ora è in stato "pending" e verrà aggiornato automaticamente quando è attivo uno di questi ruoli.', 'gd-b2b' );
		$lines[] = '';
		$lines[] = __( 'Puoi aggiornare andando in "Modifica utente" cliccando qui:', 'gd-b2b' );
		$lines[] = '';
		$lines[] = $edit_url;

		return implode( "\r\n", $lines );
	}

	/**
	 * Send an HTML email with a plain-text AltBody via PHPMailer.
	 *
	 * Handles:
	 * - Content-Type header injection
	 * - phpmailer_init hook for AltBody
	 * - Debug logging sniff
	 * - Clean hook removal after send
	 *
	 * @param string $to            Recipient email.
	 * @param string $subject       Email subject.
	 * @param string $html          HTML body.
	 * @param string $plain         Plain-text alternative body.
	 * @param array  $extra_headers Additional headers (Content-Type is auto-added if missing).
	 * @return bool True on success.
	 */
	public function send_html_email( string $to, string $subject, string $html, string $plain, array $extra_headers = [] ): bool {
		$headers = $extra_headers;

		$has_ct = false;
		foreach ( $headers as $h ) {
			if ( stripos( $h, 'content-type' ) !== false ) {
				$has_ct = true;
				break;
			}
		}
		if ( ! $has_ct ) {
			array_unshift( $headers, 'Content-Type: text/html; charset=UTF-8' );
		}

		$set_alt = static function ( $phpmailer ) use ( $plain ) {
			if ( is_object( $phpmailer ) && property_exists( $phpmailer, 'AltBody' ) ) {
				$phpmailer->AltBody = wp_specialchars_decode( $plain, ENT_QUOTES );
			}
		};
		add_action( 'phpmailer_init', $set_alt, 10, 1 );

		$sniff = static function ( $phpmailer ) {
			if ( ! ( defined( 'GD_B2B_MAIL_DEBUG' ) && GD_B2B_MAIL_DEBUG ) || ! is_object( $phpmailer ) ) {
				return;
			}
			$mailer = property_exists( $phpmailer, 'Mailer' ) ? (string) $phpmailer->Mailer : '?';
			$from   = property_exists( $phpmailer, 'From' ) ? (string) $phpmailer->From : '?';
			$host   = ( 'smtp' === $mailer && property_exists( $phpmailer, 'Host' ) ) ? (string) $phpmailer->Host : '-';
			error_log( sprintf( 'GD B2B phpmailer_init Mailer=%s From=%s Host=%s', $mailer, $from, $host ) );
		};
		add_action( 'phpmailer_init', $sniff, 10000, 1 );

		$ok = false;
		try {
			$ok = wp_mail( $to, $subject, $html, $headers );
		} finally {
			remove_action( 'phpmailer_init', $set_alt, 10 );
			remove_action( 'phpmailer_init', $sniff, 10000 );
		}

		return (bool) $ok;
	}

	/**
	 * Whether debug logging is active.
	 *
	 * Activate in wp-config.php: define( 'GD_B2B_MAIL_DEBUG', true );
	 *
	 * @return bool
	 */
	private function should_log(): bool {
		return defined( 'GD_B2B_MAIL_DEBUG' ) && GD_B2B_MAIL_DEBUG;
	}

	/**
	 * Log a message when debug mode is active.
	 *
	 * @param string $message Log message.
	 */
	private function log( string $message ): void {
		if ( $this->should_log() ) {
			error_log( 'GD B2B ' . $message );
		}
	}

	/**
	 * Fallback activation email HTML when no template file exists.
	 *
	 * @param string $display  Customer display name.
	 * @param string $shop_url Shop page URL.
	 * @return string Inner HTML block.
	 */
	private function build_activation_fallback_html( string $display, string $shop_url ): string {
		$name_line = esc_html( $display );

		$intro = '<p style="margin:0 0 12px;color:#431407;font:600 17px -apple-system,BlinkMacSystemFont,\'Segoe UI\',Roboto,Helvetica,sans-serif;">'
			. sprintf(
				/* translators: %s: customer name */
				esc_html__( 'Ciao %s,', 'gd-b2b' ),
				$name_line
			)
			. '</p>';

		$body_p = '<p style="margin:0 0 14px;color:#41403f;font:15px/1.65 -apple-system,BlinkMacSystemFont,\'Segoe UI\',Roboto,Helvetica,sans-serif;">'
			. esc_html__(
				'Il tuo profilo commerciale (B2B) è stato attivato: puoi accedere al negozio e procedere con gli acquisti secondo le condizioni previste per il tuo account.',
				'gd-b2b'
			)
			. '</p>';

		$btn = '<p style="margin:20px 0 8px;">'
			. '<a href="' . esc_url( $shop_url ) . '" style="display:inline-block;padding:11px 20px;background:#15803d;color:#ffffff;text-decoration:none;border-radius:7px;font:600 14px -apple-system,BlinkMacSystemFont,\'Segoe UI\',Roboto,Helvetica,sans-serif;">'
			. esc_html__( 'Vai al negozio', 'gd-b2b' )
			. '</a></p>'
			. '<p style="margin:0;font-size:13px;line-height:1.5;color:#78716c;">'
			. '<a href="' . esc_url( $shop_url ) . '" style="color:#92400e;word-break:break-all;">'
			. esc_html( $shop_url )
			. '</a></p>';

		return '<div style="padding:26px 24px 28px;">'
			. $intro . $body_p . $btn
			. '</div>';
	}

	/**
	 * Fallback admin notice HTML when no template file exists.
	 *
	 * @param array  $payload             Registration payload.
	 * @param int    $customer_id         WordPress user ID.
	 * @param string $country_label       Localized country name.
	 * @param string $edit_url            Admin edit URL.
	 * @param string $paragraph_lines_html Instruction paragraph HTML.
	 * @return string Inner HTML block.
	 */
	private function build_admin_notice_fallback_html(
		array $payload,
		int $customer_id,
		string $country_label,
		string $edit_url,
		string $paragraph_lines_html
	): string {
		$intro = esc_html__( 'È stata inoltrata una richiesta di registrazione aziendale.', 'gd-b2b' );
		$rows  = $this->get_admin_notice_rows( $payload, $customer_id, $country_label );
		$tab   = '';

		foreach ( $rows as $i => $pair ) {
			$stripe = 0 === $i % 2 ? '#fafaf9' : '#ffffff';
			$tab   .= sprintf(
				'<tr>'
				. '<td style="padding:12px 16px;background:%3$s;color:#78350f;font-weight:700;font-size:14px;line-height:1.4;width:38%%;vertical-align:top;border-bottom:1px solid #e7e5e4;font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Roboto,Helvetica,sans-serif;">%s</td>'
				. '<td style="padding:12px 16px;background:%4$s;color:#292524;font-size:14px;line-height:1.5;border-bottom:1px solid #e7e5e4;vertical-align:top;font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Roboto,Helvetica,sans-serif;">%s</td>'
				. '</tr>',
				esc_html( $pair[0] ),
				nl2br( esc_html( $pair[1] ) ),
				$stripe,
				$stripe
			);
		}

		return '<div style="padding:26px 24px 14px;color:#431407;font:600 17px -apple-system,BlinkMacSystemFont,\'Segoe UI\',Roboto,Helvetica,sans-serif;">'
			. $intro
			. '</div>'
			. '<div style="padding:0 24px 24px;"><table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="border-collapse:collapse;border-radius:8px;overflow:hidden;border:1px solid #e7e5e4;">'
			. $tab
			. '</table></div>'
			. '<div style="padding:0 24px 28px;color:#41403f;font:15px/1.65 Georgia,\'Times New Roman\',Times,serif;">'
			. $paragraph_lines_html
			. '<p style="margin:18px 0 8px;"><a href="' . esc_url( $edit_url ) . '" style="display:inline-block;padding:11px 20px;background:#15803d;color:#ffffff;text-decoration:none;border-radius:7px;font:600 14px -apple-system,BlinkMacSystemFont,\'Segoe UI\',Roboto,Helvetica,sans-serif;">'
			. esc_html__( 'Apri Modifica utente', 'gd-b2b' )
			. '</a></p>'
			. '<p style="margin:0;font-size:13px;line-height:1.5;"><a href="' . esc_url( $edit_url ) . '" style="color:#92400e;word-break:break-all;">'
			. esc_html( $edit_url )
			. '</a></p>'
			. '</div>';
	}
}
