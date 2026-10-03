<?php
/**
 * Admin page for managing email templates with visual/HTML editor.
 *
 * @package GD_B2B
 * @since   2.1.0
 */

defined( 'ABSPATH' ) || exit;

class GD_B2B_Admin_Email_Templates {

	const OPTION_KEY = 'gd_b2b_email_templates';

	/**
	 * Template registry: slug => [ label, placeholders[], description ].
	 *
	 * @return array<string, array{label:string, placeholders:array<string,string>, description:string}>
	 */
	public static function get_template_registry(): array {
		return array(
			'base' => array(
				'label'        => __( 'Base (wrapper)', 'gd-b2b' ),
				'placeholders' => array(
					'{contenuto}'   => __( 'Contenuto interno dell\'email', 'gd-b2b' ),
					'{nome_sito}'   => __( 'Nome del sito', 'gd-b2b' ),
					'{url_logo}'    => __( 'URL del logo', 'gd-b2b' ),
					'{footer_text}' => __( 'Testo footer (indirizzo)', 'gd-b2b' ),
				),
				'description'  => __( 'Wrapper HTML che avvolge tutte le email. Include header con logo, separatore, area contenuto e footer.', 'gd-b2b' ),
			),
			'activation' => array(
				'label'        => __( 'Attivazione B2B', 'gd-b2b' ),
				'placeholders' => array(
					'{nome_cliente}' => __( 'Nome del cliente', 'gd-b2b' ),
					'{url_negozio}'  => __( 'URL della pagina negozio', 'gd-b2b' ),
				),
				'description'  => __( 'Email inviata al cliente quando il suo profilo B2B viene attivato.', 'gd-b2b' ),
			),
			'admin_notice' => array(
				'label'        => __( 'Notifica Admin', 'gd-b2b' ),
				'placeholders' => array(
					'{id_utente}'          => __( 'ID utente WordPress', 'gd-b2b' ),
					'{email_cliente}'      => __( 'Email del cliente', 'gd-b2b' ),
					'{ragione_sociale}'    => __( 'Ragione sociale', 'gd-b2b' ),
					'{piva}'               => __( 'Partita IVA', 'gd-b2b' ),
					'{sdi}'                => __( 'Codice SDI', 'gd-b2b' ),
					'{pec}'                => __( 'Indirizzo PEC', 'gd-b2b' ),
					'{referente}'          => __( 'Nome referente', 'gd-b2b' ),
					'{indirizzo}'          => __( 'Indirizzo completo', 'gd-b2b' ),
					'{telefono}'           => __( 'Numero di telefono', 'gd-b2b' ),
					'{url_modifica_utente}' => __( 'URL pagina modifica utente', 'gd-b2b' ),
				),
				'description'  => __( 'Email inviata all\'admin quando un nuovo cliente B2B si registra.', 'gd-b2b' ),
			),
			'user_confirmation' => array(
				'label'        => __( 'Conferma Registrazione', 'gd-b2b' ),
				'placeholders' => array(
					'{nome_sito}' => __( 'Nome del sito', 'gd-b2b' ),
				),
				'description'  => __( 'Email inviata al cliente subito dopo la registrazione per confermare la ricezione della richiesta.', 'gd-b2b' ),
			),
		);
	}

	/**
	 * Register hooks.
	 */
	public function init(): void {
		add_action( 'wp_ajax_gd_b2b_save_email_template', array( $this, 'ajax_save' ) );
		add_action( 'wp_ajax_gd_b2b_preview_email_template', array( $this, 'ajax_preview' ) );
		add_action( 'wp_ajax_gd_b2b_send_test_email', array( $this, 'ajax_send_test' ) );
		add_action( 'wp_ajax_gd_b2b_reset_email_template', array( $this, 'ajax_reset' ) );
	}

	/**
	 * Get all saved custom templates.
	 *
	 * @return array<string, string>
	 */
	public static function get_saved_templates(): array {
		$saved = get_option( self::OPTION_KEY, array() );
		return is_array( $saved ) ? $saved : array();
	}

	/**
	 * Get the default HTML for a template with {placeholder} tokens.
	 *
	 * Renders the PHP template file with special marker values that survive
	 * WordPress escaping functions, then reverse-replaces them with {placeholder} tokens.
	 *
	 * @param string $slug Template slug.
	 * @return string Default HTML with placeholder tokens.
	 */
	public static function get_default_html( string $slug ): string {
		$map = array(
			'base'              => 'base.php',
			'activation'        => 'activation.php',
			'admin_notice'      => 'admin-notice.php',
			'user_confirmation' => 'user-confirmation.php',
		);

		if ( ! isset( $map[ $slug ] ) ) {
			return '';
		}

		$email       = GD_B2B_Plugin::instance()->email;
		$render_args = self::get_placeholder_render_args( $slug );

		$html = $email->render_file_template( $map[ $slug ], $render_args );

		if ( '' === $html ) {
			return '';
		}

		$marker_map = self::get_marker_to_placeholder_map( $slug );
		if ( ! empty( $marker_map ) ) {
			$html = str_replace(
				array_keys( $marker_map ),
				array_values( $marker_map ),
				$html
			);
		}

		return $html;
	}

	/**
	 * Render-safe marker values for generating defaults with placeholder tokens.
	 *
	 * These values survive esc_url(), esc_html(), esc_attr() etc.
	 * and are then reverse-replaced with {placeholder} tokens.
	 *
	 * @param string $slug Template slug.
	 * @return array
	 */
	private static function get_placeholder_render_args( string $slug ): array {
		switch ( $slug ) {
			case 'base':
				return array(
					'inner_html'  => '<!--GDB2B:contenuto-->',
					'brand'       => 'GDB2B_NOME_SITO',
					'logo'        => 'https://gdb2b-placeholder.example/logo',
					'logo_url'    => 'https://gdb2b-placeholder.example/logo',
					'footer_text' => 'GDB2B_FOOTER_TEXT',
				);
			case 'activation':
				return array(
					'name'     => 'GDB2B_NOME_CLIENTE',
					'shop_url' => 'https://gdb2b-placeholder.example/url-negozio',
				);
			case 'admin_notice':
				return array(
					'intro'          => esc_html__( 'Nuova registrazione B2B', 'gd-b2b' ),
					'rows'           => array(
						array( __( 'Utente WP ID', 'gd-b2b' ), 'GDB2B_ID_UTENTE' ),
						array( __( 'Email login', 'gd-b2b' ), 'GDB2B_EMAIL_CLIENTE' ),
						array( __( 'Ragione sociale', 'gd-b2b' ), 'GDB2B_RAGIONE_SOCIALE' ),
						array( __( 'P.IVA', 'gd-b2b' ), 'GDB2B_PIVA' ),
						array( __( 'SDI', 'gd-b2b' ), 'GDB2B_SDI' ),
						array( __( 'PEC', 'gd-b2b' ), 'GDB2B_PEC' ),
						array( __( 'Referente', 'gd-b2b' ), 'GDB2B_REFERENTE' ),
						array( __( 'Indirizzo', 'gd-b2b' ), 'GDB2B_INDIRIZZO' ),
						array( __( 'Telefono', 'gd-b2b' ), 'GDB2B_TELEFONO' ),
					),
					'paragraph_html' => '<p style="margin:0 0 12px;"><strong>'
						. esc_html__( 'Controllare i dati e, se idoneo, dalla scheda Utente assegnare uno dei ruoli: Rivenditore, Azienda sconto Base, Azienda sconto Premium o Azienda sconto Gold.', 'gd-b2b' )
						. '</strong></p>'
						. '<p style="margin:0 0 12px;">'
						. esc_html__( 'Ora è in stato "pending" e verrà aggiornato automaticamente quando è attivo uno di questi ruoli.', 'gd-b2b' )
						. '</p>'
						. '<p style="margin:0;">'
						. esc_html__( 'Puoi aggiornare andando in "Modifica utente" cliccando sul pulsante o sul link:', 'gd-b2b' )
						. '</p>',
					'edit_url'       => 'https://gdb2b-placeholder.example/url-modifica-utente',
				);
			case 'user_confirmation':
				return array();
			default:
				return array();
		}
	}

	/**
	 * Map marker values back to {placeholder} tokens.
	 *
	 * @param string $slug Template slug.
	 * @return array<string, string> marker => placeholder
	 */
	private static function get_marker_to_placeholder_map( string $slug ): array {
		switch ( $slug ) {
			case 'base':
				return array(
					'<!--GDB2B:contenuto-->'                   => '{contenuto}',
					'GDB2B_NOME_SITO'                          => '{nome_sito}',
					'https://gdb2b-placeholder.example/logo'   => '{url_logo}',
					'GDB2B_FOOTER_TEXT'                         => '{footer_text}',
				);
			case 'activation':
				return array(
					'GDB2B_NOME_CLIENTE'                              => '{nome_cliente}',
					'https://gdb2b-placeholder.example/url-negozio'   => '{url_negozio}',
				);
			case 'admin_notice':
				return array(
					'GDB2B_ID_UTENTE'                                          => '{id_utente}',
					'GDB2B_EMAIL_CLIENTE'                                      => '{email_cliente}',
					'GDB2B_RAGIONE_SOCIALE'                                    => '{ragione_sociale}',
					'GDB2B_PIVA'                                               => '{piva}',
					'GDB2B_SDI'                                                => '{sdi}',
					'GDB2B_PEC'                                                => '{pec}',
					'GDB2B_REFERENTE'                                          => '{referente}',
					'GDB2B_INDIRIZZO'                                          => '{indirizzo}',
					'GDB2B_TELEFONO'                                           => '{telefono}',
					'https://gdb2b-placeholder.example/url-modifica-utente'    => '{url_modifica_utente}',
				);
			case 'user_confirmation':
				return array();
			default:
				return array();
		}
	}

	/**
	 * Get the current HTML for a template (custom or default fallback).
	 *
	 * @param string $slug Template slug.
	 * @return string
	 */
	public static function get_template_html( string $slug ): string {
		$saved = self::get_saved_templates();

		if ( ! empty( $saved[ $slug ] ) ) {
			return $saved[ $slug ];
		}

		return self::get_default_html( $slug );
	}

	/**
	 * Build dummy data for template rendering/preview.
	 *
	 * @param string $slug Template slug.
	 * @return array
	 */
	public static function get_dummy_args( string $slug ): array {
		$brand     = esc_html( wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ) );
		$email_obj = GD_B2B_Plugin::instance()->email;
		$logo      = $email_obj->get_logo_url();
		$footer    = $email_obj->get_footer_text();
		$shop_url  = function_exists( 'wc_get_page_permalink' ) ? wc_get_page_permalink( 'shop' ) : home_url( '/' );

		switch ( $slug ) {
			case 'base':
				return array(
					'inner_html'  => '<div style="padding:26px 24px 28px;"><p style="color:#41403f;font:15px/1.65 -apple-system,BlinkMacSystemFont,\'Segoe UI\',Roboto,Helvetica,sans-serif;">' . __( 'Questo è un contenuto di esempio per l\'anteprima.', 'gd-b2b' ) . '</p></div>',
					'brand'       => $brand,
					'logo'        => $logo,
					'logo_url'    => $logo,
					'footer_text' => $footer,
				);
			case 'activation':
				return array(
					'name'     => 'Mario Rossi',
					'shop_url' => $shop_url ?: home_url( '/' ),
				);
			case 'admin_notice':
				return array(
					'intro'          => esc_html__( 'Nuova registrazione B2B', 'gd-b2b' ),
					'rows'           => array(
						array( __( 'Utente WP ID', 'gd-b2b' ), '123' ),
						array( __( 'Email login', 'gd-b2b' ), 'mario.rossi@example.com' ),
						array( __( 'Ragione sociale', 'gd-b2b' ), 'Rossi S.r.l.' ),
						array( __( 'P.IVA', 'gd-b2b' ), '01234567890' ),
						array( __( 'SDI', 'gd-b2b' ), 'ABC1234' ),
						array( __( 'PEC', 'gd-b2b' ), 'rossi@pec.it' ),
						array( __( 'Referente', 'gd-b2b' ), 'Mario Rossi' ),
						array( __( 'Indirizzo', 'gd-b2b' ), 'Via Roma 1, 47924 Rimini (RN) — Italia' ),
						array( __( 'Telefono', 'gd-b2b' ), '+39 0541 123456' ),
					),
					'paragraph_html' => '<p style="margin:0 0 12px;"><strong>' . esc_html__( 'Controllare i dati e, se idoneo, dalla scheda Utente assegnare il ruolo appropriato.', 'gd-b2b' ) . '</strong></p>',
					'edit_url'       => admin_url( 'user-edit.php?user_id=123' ),
				);
			case 'user_confirmation':
				return array();
			default:
				return array();
		}
	}

	/**
	 * Get dummy placeholder values for DB-stored template rendering.
	 *
	 * @param string $slug Template slug.
	 * @return array<string, string>
	 */
	public static function get_dummy_placeholder_values( string $slug ): array {
		$brand    = esc_html( wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ) );
		$logo     = GD_B2B_Plugin::instance()->email->get_logo_url();
		$footer   = GD_B2B_Plugin::instance()->email->get_footer_text();
		$shop_url = function_exists( 'wc_get_page_permalink' ) ? wc_get_page_permalink( 'shop' ) : home_url( '/' );

		switch ( $slug ) {
			case 'base':
				return array(
					'{contenuto}'   => '<div style="padding:26px 24px 28px;"><p>' . esc_html__( 'Contenuto di esempio', 'gd-b2b' ) . '</p></div>',
					'{nome_sito}'   => $brand,
					'{url_logo}'    => esc_url( $logo ),
					'{footer_text}' => '' !== $footer ? nl2br( esc_html( $footer ) ) : '',
				);
			case 'activation':
				return array(
					'{nome_cliente}' => esc_html( 'Mario Rossi' ),
					'{url_negozio}'  => esc_url( $shop_url ?: home_url( '/' ) ),
				);
			case 'admin_notice':
				return array(
					'{id_utente}'           => '123',
					'{email_cliente}'       => esc_html( 'mario.rossi@example.com' ),
					'{ragione_sociale}'     => esc_html( 'Rossi S.r.l.' ),
					'{piva}'                => '01234567890',
					'{sdi}'                 => 'ABC1234',
					'{pec}'                 => esc_html( 'rossi@pec.it' ),
					'{referente}'           => esc_html( 'Mario Rossi' ),
					'{indirizzo}'           => esc_html( 'Via Roma 1, 47924 Rimini (RN) — Italia' ),
					'{telefono}'            => esc_html( '+39 0541 123456' ),
					'{url_modifica_utente}' => esc_url( admin_url( 'user-edit.php?user_id=123' ) ),
				);
			case 'user_confirmation':
				return array(
					'{nome_sito}' => $brand,
				);
			default:
				return array();
		}
	}

	/**
	 * Render the Template Email admin page.
	 */
	public function render_page(): void {
		if ( ! GD_B2B_Plugin::instance()->admin->user_can() ) {
			wp_die( esc_html__( 'Permesso negato.', 'gd-b2b' ) );
		}

		$registry = self::get_template_registry();
		$saved    = self::get_saved_templates();
		$current  = isset( $_GET['tpl'] ) ? sanitize_key( $_GET['tpl'] ) : '';
		if ( ! isset( $registry[ $current ] ) ) {
			$current = '';
		}

		echo '<div class="wrap gd-b2b-email-templates-wrap">';
		echo '<h1>' . esc_html__( 'Template Email', 'gd-b2b' ) . '</h1>';
		echo '<div class="gd-b2b-tpl-layout">';

		// Sidebar
		echo '<div class="gd-b2b-tpl-sidebar">';
		echo '<h3>' . esc_html__( 'Template disponibili', 'gd-b2b' ) . '</h3>';
		echo '<ul class="gd-b2b-tpl-list">';
		foreach ( $registry as $slug => $info ) {
			$url     = admin_url( 'admin.php?page=gd-b2b-email-templates&tpl=' . $slug );
			$active  = ( $slug === $current ) ? ' class="active"' : '';
			$custom  = ! empty( $saved[ $slug ] ) ? ' <span class="gd-b2b-tpl-custom-badge">' . esc_html__( 'personalizzato', 'gd-b2b' ) . '</span>' : '';
			echo '<li' . $active . '>';
			echo '<a href="' . esc_url( $url ) . '" data-slug="' . esc_attr( $slug ) . '">';
			echo esc_html( $info['label'] ) . $custom;
			echo '</a></li>';
		}
		echo '</ul>';
		echo '</div>';

		// Main area
		echo '<div class="gd-b2b-tpl-main">';

		if ( '' === $current ) {
			echo '<div class="gd-b2b-tpl-empty">';
			echo '<p>' . esc_html__( 'Seleziona un template dalla lista a sinistra per modificarlo.', 'gd-b2b' ) . '</p>';
			echo '</div>';
		} else {
			$info    = $registry[ $current ];
			$content = self::get_template_html( $current );
			$is_custom = ! empty( $saved[ $current ] );

			echo '<div class="gd-b2b-tpl-editor-header">';
			echo '<h2>' . esc_html( $info['label'] ) . '</h2>';
			echo '<p class="description">' . esc_html( $info['description'] ) . '</p>';
			echo '</div>';

			// Placeholder reference
			if ( ! empty( $info['placeholders'] ) ) {
				echo '<div class="gd-b2b-tpl-placeholders">';
				echo '<h4>' . esc_html__( 'Placeholder disponibili', 'gd-b2b' ) . ' <small>' . esc_html__( '(clicca per inserire)', 'gd-b2b' ) . '</small></h4>';
				echo '<div class="gd-b2b-tpl-ph-list">';
				foreach ( $info['placeholders'] as $ph => $desc ) {
					echo '<button type="button" class="gd-b2b-tpl-ph-btn" data-placeholder="' . esc_attr( $ph ) . '" title="' . esc_attr( $desc ) . '">';
					echo '<code>' . esc_html( $ph ) . '</code>';
					echo '</button>';
				}
				echo '</div>';
				echo '</div>';
			}

			// Editor
			echo '<div class="gd-b2b-tpl-editor-wrap">';
			$editor_id = 'gd_b2b_tpl_editor';
			wp_editor( $content, $editor_id, array(
				'textarea_name' => 'gd_b2b_tpl_content',
				'textarea_rows' => 20,
				'media_buttons' => false,
				'teeny'         => false,
				'quicktags'     => true,
				'tinymce'       => array(
					'toolbar1'      => 'formatselect,bold,italic,underline,strikethrough,|,bullist,numlist,|,link,unlink,|,forecolor,backcolor,|,removeformat,|,wp_fullscreen',
					'toolbar2'      => '',
					'valid_elements' => '*[*]',
					'extended_valid_elements' => '*[*]',
					'valid_children' => '+body[style]',
				),
			) );
			echo '</div>';

			// Actions
			echo '<div class="gd-b2b-tpl-actions">';
			echo '<input type="hidden" id="gd-b2b-tpl-slug" value="' . esc_attr( $current ) . '" />';
			wp_nonce_field( 'gd_b2b_email_tpl', 'gd_b2b_tpl_nonce', false );

			echo '<button type="button" class="button button-primary" id="gd-b2b-tpl-save">';
			echo '<span class="dashicons dashicons-saved" style="margin-top:3px;"></span> ';
			echo esc_html__( 'Salva', 'gd-b2b' );
			echo '</button> ';

			echo '<button type="button" class="button" id="gd-b2b-tpl-preview">';
			echo '<span class="dashicons dashicons-visibility" style="margin-top:3px;"></span> ';
			echo esc_html__( 'Anteprima', 'gd-b2b' );
			echo '</button> ';

			echo '<button type="button" class="button" id="gd-b2b-tpl-test">';
			echo '<span class="dashicons dashicons-email" style="margin-top:3px;"></span> ';
			echo esc_html__( 'Invia email di test', 'gd-b2b' );
			echo '</button> ';

			if ( $is_custom ) {
				echo '<button type="button" class="button gd-b2b-tpl-reset" id="gd-b2b-tpl-reset">';
				echo '<span class="dashicons dashicons-undo" style="margin-top:3px;"></span> ';
				echo esc_html__( 'Ripristina default', 'gd-b2b' );
				echo '</button>';
			}

			echo '</div>';
			echo '<div id="gd-b2b-tpl-notice" class="gd-b2b-tpl-notice" style="display:none;"></div>';
		}

		echo '</div>'; // .gd-b2b-tpl-main
		echo '</div>'; // .gd-b2b-tpl-layout
		echo '</div>'; // .wrap
	}

	/**
	 * AJAX: Save template.
	 */
	public function ajax_save(): void {
		check_ajax_referer( 'gd_b2b_email_tpl', 'nonce' );

		if ( ! GD_B2B_Plugin::instance()->admin->user_can() ) {
			wp_send_json_error( array( 'message' => __( 'Permesso negato.', 'gd-b2b' ) ) );
		}

		$slug    = sanitize_key( $_POST['slug'] ?? '' );
		$content = wp_kses_post( wp_unslash( $_POST['content'] ?? '' ) );

		$registry = self::get_template_registry();
		if ( ! isset( $registry[ $slug ] ) ) {
			wp_send_json_error( array( 'message' => __( 'Template non valido.', 'gd-b2b' ) ) );
		}

		$saved = self::get_saved_templates();
		$saved[ $slug ] = $content;
		update_option( self::OPTION_KEY, $saved, false );

		wp_send_json_success( array( 'message' => __( 'Template salvato.', 'gd-b2b' ) ) );
	}

	/**
	 * AJAX: Preview template with dummy data.
	 */
	public function ajax_preview(): void {
		check_ajax_referer( 'gd_b2b_email_tpl', 'nonce' );

		if ( ! GD_B2B_Plugin::instance()->admin->user_can() ) {
			wp_send_json_error( array( 'message' => __( 'Permesso negato.', 'gd-b2b' ) ) );
		}

		$slug    = sanitize_key( $_POST['slug'] ?? '' );
		$content = wp_kses_post( wp_unslash( $_POST['content'] ?? '' ) );

		$registry = self::get_template_registry();
		if ( ! isset( $registry[ $slug ] ) ) {
			wp_send_json_error( array( 'message' => __( 'Template non valido.', 'gd-b2b' ) ) );
		}

		$html = $this->render_preview( $slug, $content );

		wp_send_json_success( array( 'html' => $html ) );
	}

	/**
	 * AJAX: Send test email with dummy data.
	 */
	public function ajax_send_test(): void {
		check_ajax_referer( 'gd_b2b_email_tpl', 'nonce' );

		if ( ! GD_B2B_Plugin::instance()->admin->user_can() ) {
			wp_send_json_error( array( 'message' => __( 'Permesso negato.', 'gd-b2b' ) ) );
		}

		$slug    = sanitize_key( $_POST['slug'] ?? '' );
		$content = wp_kses_post( wp_unslash( $_POST['content'] ?? '' ) );

		$registry = self::get_template_registry();
		if ( ! isset( $registry[ $slug ] ) ) {
			wp_send_json_error( array( 'message' => __( 'Template non valido.', 'gd-b2b' ) ) );
		}

		$admin_email = sanitize_email( get_option( 'admin_email' ) );
		if ( ! is_email( $admin_email ) ) {
			wp_send_json_error( array( 'message' => __( 'Email admin non configurata.', 'gd-b2b' ) ) );
		}

		$html    = $this->render_preview( $slug, $content );
		$subject = sprintf(
			/* translators: %s: template name */
			__( '[Test] %s — GD B2B', 'gd-b2b' ),
			$registry[ $slug ]['label']
		);

		$email_obj = GD_B2B_Plugin::instance()->email;
		$sent      = $email_obj->send_html_email( $admin_email, $subject, $html, '' );

		if ( $sent ) {
			wp_send_json_success( array(
				'message' => sprintf(
					/* translators: %s: email address */
					__( 'Email di test inviata a %s', 'gd-b2b' ),
					$admin_email
				),
			) );
		} else {
			wp_send_json_error( array( 'message' => __( 'Invio email non riuscito.', 'gd-b2b' ) ) );
		}
	}

	/**
	 * AJAX: Reset template to default.
	 */
	public function ajax_reset(): void {
		check_ajax_referer( 'gd_b2b_email_tpl', 'nonce' );

		if ( ! GD_B2B_Plugin::instance()->admin->user_can() ) {
			wp_send_json_error( array( 'message' => __( 'Permesso negato.', 'gd-b2b' ) ) );
		}

		$slug = sanitize_key( $_POST['slug'] ?? '' );

		$registry = self::get_template_registry();
		if ( ! isset( $registry[ $slug ] ) ) {
			wp_send_json_error( array( 'message' => __( 'Template non valido.', 'gd-b2b' ) ) );
		}

		$saved = self::get_saved_templates();
		unset( $saved[ $slug ] );
		update_option( self::OPTION_KEY, $saved, false );

		$default_html = self::get_default_html( $slug );

		wp_send_json_success( array(
			'message' => __( 'Template ripristinato al default.', 'gd-b2b' ),
			'content' => $default_html,
		) );
	}

	/**
	 * Render a preview of the template with placeholder replacement.
	 *
	 * @param string $slug    Template slug.
	 * @param string $content Raw HTML content (may contain placeholders).
	 * @return string Full rendered HTML.
	 */
	private function render_preview( string $slug, string $content ): string {
		$placeholder_values = self::get_dummy_placeholder_values( $slug );

		$rendered = str_replace(
			array_keys( $placeholder_values ),
			array_values( $placeholder_values ),
			$content
		);

		if ( 'base' === $slug ) {
			return $rendered;
		}

		$email_obj = GD_B2B_Plugin::instance()->email;
		return $email_obj->build_branded_html( $rendered );
	}
}
