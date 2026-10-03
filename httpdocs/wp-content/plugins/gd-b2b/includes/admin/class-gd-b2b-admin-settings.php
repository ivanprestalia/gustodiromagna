<?php
/**
 * Admin settings page for GD-B2B.
 *
 * @package GD_B2B
 */

defined( 'ABSPATH' ) || exit;

/**
 * Handles registration, sanitization, and rendering of plugin settings.
 */
class GD_B2B_Admin_Settings {

	/**
	 * Register hooks.
	 */
	public function init(): void {
		add_action( 'admin_init', array( $this, 'register_settings' ) );
	}

	/**
	 * Register the settings with WordPress.
	 */
	public function register_settings(): void {
		register_setting(
			'gd_b2b_settings_group',
			GD_B2B_OPTION_SETTINGS,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize_settings' ),
			)
		);
	}

	/**
	 * Sanitize incoming settings (roles + email configuration).
	 *
	 * @param mixed $input Raw input from form.
	 * @return array<string,mixed>
	 */
	public function sanitize_settings( $input ): array {
		$prev = get_option( GD_B2B_OPTION_SETTINGS, $this->get_default_settings() );
		if ( ! is_array( $prev ) ) {
			$prev = $this->get_default_settings();
		}

		if ( ! is_array( $input ) ) {
			return $prev;
		}

		$out = array(
			'approvable_roles' => array(),
			'email'            => $this->get_default_email_settings(),
		);

		// Roles.
		if ( ! empty( $input['approvable_roles'] ) && is_array( $input['approvable_roles'] ) ) {
			foreach ( $input['approvable_roles'] as $slug ) {
				$s = sanitize_key( $slug );
				if ( '' !== $s ) {
					$out['approvable_roles'][] = $s;
				}
			}
			$out['approvable_roles'] = array_values( array_unique( $out['approvable_roles'] ) );
		}

		if ( array() === $out['approvable_roles'] ) {
			if ( ! empty( $prev['approvable_roles'] ) && is_array( $prev['approvable_roles'] ) ) {
				$out['approvable_roles'] = $prev['approvable_roles'];
			} else {
				$out['approvable_roles'] = $this->get_default_settings()['approvable_roles'];
			}
		}

		// Email settings.
		if ( isset( $input['email'] ) && is_array( $input['email'] ) ) {
			$em = $input['email'];

			$out['email']['email_logo_url']            = esc_url_raw( trim( $em['email_logo_url'] ?? '' ) );
			$out['email']['email_footer_text']         = sanitize_textarea_field( $em['email_footer_text'] ?? '' );
			$out['email']['activation_enabled']       = ! empty( $em['activation_enabled'] );
			$out['email']['activation_subject']       = sanitize_text_field( $em['activation_subject'] ?? '' );
			$out['email']['admin_notice_enabled']     = ! empty( $em['admin_notice_enabled'] );
			$out['email']['admin_notice_subject']     = sanitize_text_field( $em['admin_notice_subject'] ?? '' );
			$out['email']['admin_notice_recipients']  = $this->sanitize_recipients( $em['admin_notice_recipients'] ?? '' );
			$out['email']['user_confirmation_enabled'] = ! empty( $em['user_confirmation_enabled'] );
			$out['email']['user_confirmation_subject'] = sanitize_text_field( $em['user_confirmation_subject'] ?? '' );

			$rate = isset( $em['rate_limit'] ) ? (int) $em['rate_limit'] : 60;
			$out['email']['rate_limit'] = max( 10, $rate );
		} elseif ( isset( $prev['email'] ) && is_array( $prev['email'] ) ) {
			$out['email'] = wp_parse_args( $prev['email'], $this->get_default_email_settings() );
		}

		return $out;
	}

	/**
	 * Sanitize a comma-separated list of email addresses.
	 *
	 * @param string $raw Raw input.
	 * @return string Cleaned comma-separated emails.
	 */
	private function sanitize_recipients( string $raw ): string {
		$parts  = array_map( 'trim', explode( ',', (string) $raw ) );
		$valid  = array();
		foreach ( $parts as $part ) {
			$clean = sanitize_email( $part );
			if ( is_email( $clean ) ) {
				$valid[] = $clean;
			}
		}
		return implode( ', ', $valid );
	}

	/**
	 * Render the settings page.
	 */
	public function render_page(): void {
		if ( ! GD_B2B_Plugin::instance()->admin->user_can() ) {
			wp_die( esc_html__( 'Permesso negato.', 'gd-b2b' ) );
		}

		$settings = wp_parse_args(
			get_option( GD_B2B_OPTION_SETTINGS, array() ),
			$this->get_default_settings()
		);
		$selected = isset( $settings['approvable_roles'] ) ? (array) $settings['approvable_roles'] : array();
		$email    = wp_parse_args(
			isset( $settings['email'] ) ? (array) $settings['email'] : array(),
			$this->get_default_email_settings()
		);

		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'Configurazione GD B2B', 'gd-b2b' ) . '</h1>';

		echo '<form method="post" action="options.php">';
		settings_fields( 'gd_b2b_settings_group' );

		// Section 1: Roles.
		echo '<h2>' . esc_html__( 'Ruoli approvabili', 'gd-b2b' ) . '</h2>';
		echo '<p class="description">' . esc_html__(
			'Seleziona i ruoli WordPress che possono essere assegnati ai clienti B2B approvati. Questi ruoli definiscono anche chi può effettuare acquisti con condizioni commerciali.',
			'gd-b2b'
		) . '</p>';
		echo '<table class="form-table" role="presentation">';
		echo '<tr><th scope="row">' . esc_html__( 'Ruoli', 'gd-b2b' ) . '</th><td>';

		$roles = get_editable_roles();
		foreach ( $roles as $slug => $info ) {
			if ( 'administrator' === $slug ) {
				continue;
			}
			$id = 'gd_b2b_role_' . $slug;
			echo '<p><label><input type="checkbox" name="' . esc_attr( GD_B2B_OPTION_SETTINGS ) . '[approvable_roles][]" value="' . esc_attr( $slug ) . '" id="' . esc_attr( $id ) . '" ' . checked( in_array( $slug, $selected, true ), true, false ) . ' /> ';
			echo esc_html( $info['name'] ?? $slug );
			echo ' <code style="opacity:.75">(' . esc_html( $slug ) . ')</code></label></p>';
		}
		echo '</td></tr></table>';

		// Section 2: Email.
		echo '<h2>' . esc_html__( 'Impostazioni Email', 'gd-b2b' ) . '</h2>';
		echo '<table class="form-table" role="presentation">';

		$logo_url = isset( $email['email_logo_url'] ) ? (string) $email['email_logo_url'] : '';
		echo '<tr><th scope="row"><label for="gd_b2b_email_logo_url">' . esc_html__( 'URL logo email', 'gd-b2b' ) . '</label></th>';
		echo '<td><input type="url" id="gd_b2b_email_logo_url" name="' . esc_attr( GD_B2B_OPTION_SETTINGS ) . '[email][email_logo_url]" value="' . esc_attr( $logo_url ) . '" placeholder="https://example.com/wp-content/uploads/logo.png" class="regular-text" />';
		echo '<p class="description">' . esc_html__( 'URL dell\'immagine logo da mostrare nell\'header delle email. Usa un formato PNG o JPG (SVG non supportato dalla maggior parte dei client email). Se vuoto, viene usato il logo del sito configurato in WordPress.', 'gd-b2b' ) . '</p>';
		if ( '' !== $logo_url ) {
			echo '<p style="margin-top:8px;"><img src="' . esc_url( $logo_url ) . '" alt="Logo preview" style="max-height:60px;max-width:300px;background:#f5f5f5;padding:4px;border:1px solid #ddd;border-radius:4px;" /></p>';
		}
		echo '</td></tr>';

		$footer_text = isset( $email['email_footer_text'] ) ? (string) $email['email_footer_text'] : '';
		echo '<tr><th scope="row"><label for="gd_b2b_email_footer_text">' . esc_html__( 'Testo footer email', 'gd-b2b' ) . '</label></th>';
		echo '<td><textarea id="gd_b2b_email_footer_text" name="' . esc_attr( GD_B2B_OPTION_SETTINGS ) . '[email][email_footer_text]" rows="3" class="large-text">' . esc_textarea( $footer_text ) . '</textarea>';
		echo '<p class="description">' . esc_html__( 'Testo visualizzato nel footer di tutte le email sotto il nome del sito. Usa le interruzioni di riga per separare le righe (es. ragione sociale + indirizzo).', 'gd-b2b' ) . '</p>';
		echo '</td></tr>';

		$this->render_checkbox_row(
			__( 'Abilita email di attivazione B2B', 'gd-b2b' ),
			GD_B2B_OPTION_SETTINGS . '[email][activation_enabled]',
			$email['activation_enabled']
		);
		$this->render_text_row(
			__( 'Oggetto email attivazione', 'gd-b2b' ),
			GD_B2B_OPTION_SETTINGS . '[email][activation_subject]',
			$email['activation_subject'],
			__( 'Il tuo account B2B è stato attivato', 'gd-b2b' )
		);

		$this->render_checkbox_row(
			__( 'Abilita notifica admin nuova registrazione', 'gd-b2b' ),
			GD_B2B_OPTION_SETTINGS . '[email][admin_notice_enabled]',
			$email['admin_notice_enabled']
		);
		$this->render_text_row(
			__( 'Oggetto notifica admin', 'gd-b2b' ),
			GD_B2B_OPTION_SETTINGS . '[email][admin_notice_subject]',
			$email['admin_notice_subject'],
			__( 'Nuova registrazione B2B', 'gd-b2b' )
		);
		$this->render_text_row(
			__( 'Destinatari aggiuntivi notifica admin', 'gd-b2b' ),
			GD_B2B_OPTION_SETTINGS . '[email][admin_notice_recipients]',
			$email['admin_notice_recipients'],
			'email1@example.com, email2@example.com'
		);

		$this->render_checkbox_row(
			__( 'Abilita email conferma al cliente', 'gd-b2b' ),
			GD_B2B_OPTION_SETTINGS . '[email][user_confirmation_enabled]',
			$email['user_confirmation_enabled']
		);
		$this->render_text_row(
			__( 'Oggetto conferma cliente', 'gd-b2b' ),
			GD_B2B_OPTION_SETTINGS . '[email][user_confirmation_subject]',
			$email['user_confirmation_subject'],
			__( 'Registrazione B2B ricevuta', 'gd-b2b' )
		);

		echo '<tr><th scope="row"><label for="gd_b2b_email_rate_limit">' . esc_html__( 'Rate limit invio email (secondi)', 'gd-b2b' ) . '</label></th>';
		echo '<td><input type="number" id="gd_b2b_email_rate_limit" name="' . esc_attr( GD_B2B_OPTION_SETTINGS ) . '[email][rate_limit]" value="' . esc_attr( (string) $email['rate_limit'] ) . '" min="10" step="1" class="small-text" /></td></tr>';

		echo '</table>';

		submit_button();
		echo '</form></div>';
	}

	/**
	 * Render a checkbox row for the form-table.
	 *
	 * @param string $label    Row label.
	 * @param string $name     Input name attribute.
	 * @param bool   $checked  Current value.
	 */
	private function render_checkbox_row( string $label, string $name, bool $checked ): void {
		$id = sanitize_key( str_replace( array( '[', ']' ), '_', $name ) );
		echo '<tr><th scope="row">' . esc_html( $label ) . '</th>';
		echo '<td><label><input type="checkbox" id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" value="1" ' . checked( $checked, true, false ) . ' /></label></td></tr>';
	}

	/**
	 * Render a text input row for the form-table.
	 *
	 * @param string $label       Row label.
	 * @param string $name        Input name attribute.
	 * @param string $value       Current value.
	 * @param string $placeholder Placeholder text.
	 */
	private function render_text_row( string $label, string $name, string $value, string $placeholder = '' ): void {
		$id = sanitize_key( str_replace( array( '[', ']' ), '_', $name ) );
		echo '<tr><th scope="row"><label for="' . esc_attr( $id ) . '">' . esc_html( $label ) . '</label></th>';
		echo '<td><input type="text" id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" value="' . esc_attr( $value ) . '" placeholder="' . esc_attr( $placeholder ) . '" class="regular-text" /></td></tr>';
	}

	/**
	 * Default settings array (roles + email).
	 *
	 * @return array<string,mixed>
	 */
	public function get_default_settings(): array {
		return array(
			'approvable_roles' => array(
				'rivenditore',
				'azienda_sc_base',
				'azienda_sc_premium',
				'azienda_sc_gold',
			),
			'email' => $this->get_default_email_settings(),
		);
	}

	/**
	 * Default email sub-settings.
	 *
	 * @return array<string,mixed>
	 */
	public function get_default_email_settings(): array {
		return array(
			'email_logo_url'            => '',
			'email_footer_text'         => "Gusto Di Romagna S.r.l.\nViale Don Domenico Masi, 13 - 47924 - Miramare di Rimini, (RN)",
			'activation_enabled'        => true,
			'activation_subject'        => '',
			'admin_notice_enabled'      => true,
			'admin_notice_subject'      => '',
			'admin_notice_recipients'   => '',
			'user_confirmation_enabled' => true,
			'user_confirmation_subject' => '',
			'rate_limit'                => 60,
		);
	}
}
