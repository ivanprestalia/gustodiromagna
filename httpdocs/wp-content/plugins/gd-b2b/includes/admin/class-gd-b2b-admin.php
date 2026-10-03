<?php
/**
 * Admin menu, pages, asset enqueue, pending badge.
 *
 * @package GD_B2B
 */

defined( 'ABSPATH' ) || exit;

/**
 * Main admin orchestrator.
 */
class GD_B2B_Admin {

	/** @var GD_B2B_Admin_Ajax */
	private GD_B2B_Admin_Ajax $ajax;

	/** @var GD_B2B_Admin_Settings */
	private GD_B2B_Admin_Settings $settings;

	/** @var GD_B2B_Admin_Email_Templates */
	private GD_B2B_Admin_Email_Templates $email_templates;

	public function __construct() {
		$this->ajax            = new GD_B2B_Admin_Ajax();
		$this->settings        = new GD_B2B_Admin_Settings();
		$this->email_templates = new GD_B2B_Admin_Email_Templates();
	}

	/**
	 * Register hooks.
	 */
	public function init(): void {
		$this->ajax->init();
		$this->settings->init();
		$this->email_templates->init();
		add_action( 'admin_menu', array( $this, 'register_menu' ), 26 );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ), 30 );
	}

	/**
	 * Capability candidates for the menu.
	 *
	 * @return string[]
	 */
	private function get_capability_candidates(): array {
		return apply_filters(
			'gd_b2b_admin_capability_candidates',
			array(
				'manage_options',
				'manage_woocommerce',
				'list_users',
				'edit_users',
			)
		);
	}

	/**
	 * First capability the current user possesses.
	 *
	 * @return string
	 */
	private function resolve_capability(): string {
		foreach ( $this->get_capability_candidates() as $cap ) {
			$cap = (string) $cap;
			if ( '' !== $cap && current_user_can( $cap ) ) {
				return $cap;
			}
		}
		$legacy = (string) apply_filters( 'gd_b2b_admin_capability', 'manage_woocommerce' );
		return ( '' !== $legacy && current_user_can( $legacy ) ) ? $legacy : '';
	}

	/**
	 * Register admin menu with pending count badge.
	 */
	public function register_menu(): void {
		$cap = $this->resolve_capability();
		if ( '' === $cap ) {
			return;
		}

		$menu_title = __( 'GD B2B', 'gd-b2b' );

		$pending = count( GD_B2B_Plugin::instance()->roles->get_pending_user_ids() );
		if ( $pending > 0 ) {
			$menu_title .= ' <span class="awaiting-mod">' . esc_html( (string) $pending ) . '</span>';
		}

		add_menu_page(
			__( 'GD B2B', 'gd-b2b' ),
			$menu_title,
			$cap,
			'gd-b2b-clients',
			array( $this, 'render_clients_page' ),
			'dashicons-groups',
			56
		);
		add_submenu_page(
			'gd-b2b-clients',
			__( 'Clienti B2B', 'gd-b2b' ),
			__( 'Clienti B2B', 'gd-b2b' ),
			$cap,
			'gd-b2b-clients',
			array( $this, 'render_clients_page' )
		);
		add_submenu_page(
			'gd-b2b-clients',
			__( 'Configurazione GD B2B', 'gd-b2b' ),
			__( 'Configurazione', 'gd-b2b' ),
			$cap,
			'gd-b2b-settings',
			array( GD_B2B_Plugin::instance()->admin->settings, 'render_page' )
		);
		add_submenu_page(
			'gd-b2b-clients',
			__( 'Template Email', 'gd-b2b' ),
			__( 'Template Email', 'gd-b2b' ),
			$cap,
			'gd-b2b-email-templates',
			array( $this->email_templates, 'render_page' )
		);
	}

	/**
	 * Enqueue admin CSS and JS on GD-B2B pages.
	 *
	 * @param string $hook Current admin page hook.
	 */
	public function enqueue_assets( string $hook ): void {
		if ( false === strpos( $hook, 'gd-b2b' ) ) {
			return;
		}

		wp_enqueue_style( 'list-tables' );

		wp_enqueue_style(
			'gd-b2b-admin-clients',
			GD_B2B_URL . 'assets/css/admin-clients.css',
			array(),
			GD_B2B_VERSION
		);

		wp_enqueue_script(
			'gd-b2b-admin-clients',
			GD_B2B_URL . 'assets/js/admin-clients.js',
			array( 'jquery' ),
			GD_B2B_VERSION,
			true
		);

		wp_localize_script(
			'gd-b2b-admin-clients',
			'gdB2bClientsAdmin',
			array(
				'ajaxUrl'              => admin_url( 'admin-ajax.php' ),
				'emailSentLbl'         => __( 'Email inviata.', 'gd-b2b' ),
				'mailFailGeneric'      => __( 'Invio email non riuscito.', 'gd-b2b' ),
				'networkError'         => __( 'Errore di rete.', 'gd-b2b' ),
				'pendingLbl'           => __( 'In attesa di approvazione', 'gd-b2b' ),
				'modalTitle'           => __( 'Aggiorna stato cliente', 'gd-b2b' ),
				'modalAsk'             => __( 'Vuoi inviare email di conferma al cliente?', 'gd-b2b' ),
				'btnSendNow'           => __( 'INVIA ORA', 'gd-b2b' ),
				'btnNoClose'           => __( 'NO, CHIUDI', 'gd-b2b' ),
				'mailConfirmedOk'      => __( 'Invio email confermato senza errori.', 'gd-b2b' ),
				'mailSentToRecipient'  => __( 'Destinatario: %s', 'gd-b2b' ),
				'btnModalClose'        => __( 'Chiudi', 'gd-b2b' ),
				'roleAssignFail'       => __( 'Aggiornamento stato non riuscito.', 'gd-b2b' ),
				'bulkNoSelection'      => __( 'Seleziona almeno un utente.', 'gd-b2b' ),
				'bulkConfirmRole'      => __( 'Assegnare il ruolo selezionato a %d utenti?', 'gd-b2b' ),
				'bulkConfirmEmail'     => __( 'Inviare email di attivazione a %d utenti?', 'gd-b2b' ),
			)
		);

		if ( false !== strpos( $hook, 'gd-b2b-email-templates' ) ) {
			wp_enqueue_style(
				'gd-b2b-admin-email-templates',
				GD_B2B_URL . 'assets/css/admin-email-templates.css',
				array(),
				GD_B2B_VERSION
			);

			wp_enqueue_editor();

			wp_enqueue_script(
				'gd-b2b-admin-email-templates',
				GD_B2B_URL . 'assets/js/admin-email-templates.js',
				array( 'jquery', 'editor' ),
				GD_B2B_VERSION,
				true
			);

			wp_localize_script(
				'gd-b2b-admin-email-templates',
				'gdB2bTpl',
				array(
					'ajaxUrl'      => admin_url( 'admin-ajax.php' ),
					'saving'       => __( 'Salvataggio…', 'gd-b2b' ),
					'genericError' => __( 'Si è verificato un errore.', 'gd-b2b' ),
					'networkError' => __( 'Errore di rete.', 'gd-b2b' ),
					'popupBlocked' => __( 'Popup bloccato dal browser. Consenti i popup per questo sito.', 'gd-b2b' ),
					'confirmTest'  => __( 'Inviare un\'email di test all\'indirizzo admin?', 'gd-b2b' ),
					'confirmReset' => __( 'Ripristinare il template al contenuto originale? Le modifiche andranno perse.', 'gd-b2b' ),
				)
			);
		}
	}

	/**
	 * Whether the current user can access the GD-B2B panel.
	 *
	 * @return bool
	 */
	public function user_can(): bool {
		return '' !== $this->resolve_capability();
	}

	/**
	 * Resolved capability string for permission checks.
	 *
	 * @return string
	 */
	public function get_cap(): string {
		$c = $this->resolve_capability();
		return '' !== $c ? $c : 'manage_woocommerce';
	}

	/**
	 * Render the clients admin page.
	 */
	public function render_clients_page(): void {
		if ( ! $this->user_can() ) {
			wp_die( esc_html__( 'Permesso negato.', 'gd-b2b' ) );
		}

		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'Clienti B2B', 'gd-b2b' ) . '</h1>';

		if ( ! empty( $_GET['gd_b2b_saved'] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Dati salvati correttamente.', 'gd-b2b' ) . '</p></div>';
		}

		try {
			$this->render_search_form();
			$table = new GD_B2B_Clients_List_Table();
			$table->prepare_items();
			$table->display();
		} catch ( \Throwable $e ) {
			error_log(
				sprintf(
					'GD B2B admin clients: %s in %s:%d',
					$e->getMessage(),
					$e->getFile(),
					$e->getLine()
				)
			);
			echo '<div class="notice notice-error"><p>' . esc_html__( 'Impossibile caricare l\'elenco.', 'gd-b2b' ) . '</p>';
			if ( current_user_can( 'manage_options' ) ) {
				echo '<p><code>' . esc_html( $e->getMessage() ) . '</code></p>';
				echo '<p><code>' . esc_html( $e->getFile() . ':' . (string) $e->getLine() ) . '</code></p>';
			}
			echo '</div>';
		}
		echo '</div>';
	}

	/**
	 * Render the search/filter form with CSRF nonce.
	 */
	public function render_search_form(): void {
		$s_g     = isset( $_GET['gd_b2b_search'] ) ? sanitize_text_field( wp_unslash( $_GET['gd_b2b_search'] ) ) : '';
		$f_r     = isset( $_GET['gd_b2b_f_ragione'] ) ? sanitize_text_field( wp_unslash( $_GET['gd_b2b_f_ragione'] ) ) : '';
		$f_e     = isset( $_GET['gd_b2b_f_email'] ) ? sanitize_text_field( wp_unslash( $_GET['gd_b2b_f_email'] ) ) : '';
		$f_s_raw = isset( $_GET['gd_b2b_f_stato'] ) ? sanitize_key( wp_unslash( $_GET['gd_b2b_f_stato'] ) ) : '';
		$st_opts = $this->get_stato_filter_options();

		if ( '' !== $f_s_raw && ! array_key_exists( $f_s_raw, $st_opts ) ) {
			$f_s_raw = '';
		}

		$reset_url = admin_url( 'admin.php?page=gd-b2b-clients' );

		echo '<div class="postbox gd-b2b-search-card">';
		echo '<div class="postbox-header"><h2 class="hndle">' . esc_html__( 'Ricerca clienti', 'gd-b2b' ) . '</h2></div>';
		echo '<div class="inside">';
		echo '<form method="get" id="gd-b2b-clients-filter" action="' . esc_url( admin_url( 'admin.php' ) ) . '" class="gd-b2b-list-search-form">';
		echo '<input type="hidden" name="page" value="gd-b2b-clients" />';
		wp_nonce_field( 'gd_b2b_clients_search', '_gd_b2b_search_nonce', false );
		echo '<div class="gd-b2b-search-grid">';

		echo '<p class="gd-b2b-search-field"><label for="gd_b2b_search">' . esc_html__( 'Ricerca libera', 'gd-b2b' ) . '</label>';
		echo '<input type="search" class="regular-text" id="gd_b2b_search" name="gd_b2b_search" value="' . esc_attr( $s_g ) . '" placeholder="' . esc_attr__( 'Testo in ragione sociale, email, nome, stato…', 'gd-b2b' ) . '" autocomplete="off" /></p>';

		echo '<p class="gd-b2b-search-field"><label for="gd_b2b_f_ragione">' . esc_html__( 'Ragione sociale', 'gd-b2b' ) . '</label>';
		echo '<input type="search" class="regular-text" id="gd_b2b_f_ragione" name="gd_b2b_f_ragione" value="' . esc_attr( $f_r ) . '" autocomplete="off" /></p>';

		echo '<p class="gd-b2b-search-field"><label for="gd_b2b_f_email">' . esc_html__( 'Email', 'gd-b2b' ) . '</label>';
		echo '<input type="search" class="regular-text" id="gd_b2b_f_email" name="gd_b2b_f_email" value="' . esc_attr( $f_e ) . '" autocomplete="off" /></p>';

		echo '<p class="gd-b2b-search-field"><label for="gd_b2b_f_stato">' . esc_html__( 'Stato', 'gd-b2b' ) . '</label>';
		echo '<select id="gd_b2b_f_stato" name="gd_b2b_f_stato">';
		foreach ( $st_opts as $val => $lbl ) {
			echo '<option value="' . esc_attr( (string) $val ) . '"' . selected( $f_s_raw, (string) $val, false ) . '>' . esc_html( $lbl ) . '</option>';
		}
		echo '</select></p>';
		echo '</div>';

		echo '<p class="gd-b2b-search-actions">';
		submit_button( __( 'Filtra', 'gd-b2b' ), 'secondary', '', false, array( 'id' => 'gd-b2b-filter-submit' ) );
		echo ' ';
		echo '<a class="button" href="' . esc_url( $reset_url ) . '">' . esc_html__( 'Reset', 'gd-b2b' ) . '</a>';
		echo '</p></form></div></div>';
	}

	/**
	 * Status filter options (value => label).
	 *
	 * @return array<string,string>
	 */
	public function get_stato_filter_options(): array {
		$out = array(
			''        => __( 'Tutti gli stati', 'gd-b2b' ),
			'pending' => __( 'In attesa di approvazione', 'gd-b2b' ),
		);
		$roles = GD_B2B_Plugin::instance()->roles;
		foreach ( $roles->get_trade_slugs() as $slug ) {
			$out[ (string) $slug ] = $roles->get_label( $slug );
		}
		return $out;
	}

	/**
	 * Access the settings sub-component.
	 *
	 * @return GD_B2B_Admin_Settings
	 */
	public function get_settings(): GD_B2B_Admin_Settings {
		return $this->settings;
	}

	/**
	 * Access the AJAX sub-component.
	 *
	 * @return GD_B2B_Admin_Ajax
	 */
	public function get_ajax(): GD_B2B_Admin_Ajax {
		return $this->ajax;
	}
}
