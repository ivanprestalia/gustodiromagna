<?php
/**
 * WP_List_Table for B2B clients with optimized pagination.
 *
 * @package GD_B2B
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * Lists users with pending B2B request or an assigned trade role.
 *
 * Optimization: batch-loads meta for sort/filter, only hydrates
 * WP_User + WC_Customer for the current page slice.
 */
class GD_B2B_Clients_List_Table extends WP_List_Table {

	/**
	 * Batch meta cache: [ user_id => [ meta_key => meta_value ] ].
	 *
	 * @var array<int,array<string,string>>
	 */
	private array $meta_cache = array();

	public function __construct() {
		$screen = 'toplevel_page_gd-b2b-clients';
		if ( function_exists( 'get_current_screen' ) ) {
			$cs = get_current_screen();
			if ( $cs && $cs->id ) {
				$screen = $cs->id;
			}
		}
		parent::__construct(
			array(
				'singular' => 'gd_b2b_client',
				'plural'   => 'gd_b2b_clients',
				'screen'   => $screen,
				'ajax'     => false,
			)
		);
	}

	/**
	 * @return array<string,string>
	 */
	public function get_columns() {
		return array(
			'cb'              => '<input type="checkbox" />',
			'ragione_sociale' => __( 'Ragione sociale', 'gd-b2b' ),
			'email'           => __( 'Email', 'gd-b2b' ),
			'stato'           => __( 'Stato', 'gd-b2b' ),
			'data_richiesta'  => __( 'Data richiesta', 'gd-b2b' ),
			'cambio_stato'    => __( 'Cambio stato', 'gd-b2b' ),
			'azioni'          => __( 'Azioni', 'gd-b2b' ),
		);
	}

	/**
	 * @return array<string,array>
	 */
	protected function get_sortable_columns() {
		return array(
			'ragione_sociale' => array( 'ragione_sociale', false ),
			'email'           => array( 'email', false ),
			'stato'           => array( 'stato', false ),
			'data_richiesta'  => array( 'data_richiesta', true ),
		);
	}

	/**
	 * @return array<string,string>
	 */
	protected function get_bulk_actions() {
		return array(
			'bulk_assign_role' => __( 'Assegna ruolo', 'gd-b2b' ),
			'bulk_send_email'  => __( 'Invia email attivazione', 'gd-b2b' ),
		);
	}

	/**
	 * Checkbox column for bulk actions.
	 *
	 * @param array<string,mixed> $item Row data.
	 * @return string
	 */
	protected function column_cb( $item ) {
		$uid = (int) $item['user']->ID;
		return '<input type="checkbox" name="gd_b2b_users[]" value="' . esc_attr( (string) $uid ) . '" />';
	}

	/**
	 * @return string
	 */
	protected function get_primary_column_name() {
		$columns = $this->get_columns();
		$default = 'ragione_sociale';
		$column  = apply_filters( 'list_table_primary_column', $default, $this->screen->id );
		if ( empty( $column ) || ! isset( $columns[ $column ] ) ) {
			$column = $default;
		}
		return $column;
	}

	/**
	 * @return array
	 */
	protected function get_column_info() {
		if (
			isset( $this->_column_headers ) &&
			is_array( $this->_column_headers )
		) {
			if ( 4 === count( $this->_column_headers ) ) {
				return $this->_column_headers;
			}
			$column_headers = array( array(), array(), array(), $this->get_primary_column_name() );
			foreach ( $this->_column_headers as $key => $value ) {
				$column_headers[ $key ] = $value;
			}
			$this->_column_headers = $column_headers;
			return $this->_column_headers;
		}

		$columns  = $this->get_columns();
		$hidden   = get_hidden_columns( $this->screen );
		$sortable = array();

		$_sortable = apply_filters( "manage_{$this->screen->id}_sortable_columns", $this->get_sortable_columns() );
		foreach ( $_sortable as $id => $data ) {
			if ( empty( $data ) ) {
				continue;
			}
			$data = (array) $data;
			if ( ! isset( $data[1] ) ) {
				$data[1] = false;
			}
			if ( ! isset( $data[2] ) ) {
				$data[2] = '';
			}
			if ( ! isset( $data[3] ) ) {
				$data[3] = false;
			}
			if ( ! isset( $data[4] ) ) {
				$data[4] = false;
			}
			$sortable[ $id ] = $data;
		}

		$primary               = $this->get_primary_column_name();
		$this->_column_headers = array( $columns, $hidden, $sortable, $primary );

		return $this->_column_headers;
	}

	/**
	 * Prepare items with optimized SQL batch meta + paginated hydration.
	 */
	public function prepare_items() {
		if ( ! class_exists( 'WC_Customer' ) ) {
			$this->items = array();
			$this->set_pagination_args( array( 'total_items' => 0, 'per_page' => 20 ) );
			return;
		}

		$per_page = 20;
		$paged    = isset( $_REQUEST['paged'] ) ? max( 1, absint( $_REQUEST['paged'] ) ) : 1;

		$orderby_request = isset( $_GET['orderby'] ) ? sanitize_key( wp_unslash( $_GET['orderby'] ) ) : 'data_richiesta';
		$order           = isset( $_GET['order'] ) && 'asc' === strtolower( sanitize_text_field( wp_unslash( $_GET['order'] ) ) ) ? 'ASC' : 'DESC';
		if ( ! in_array( $orderby_request, array( 'ragione_sociale', 'email', 'stato', 'data_richiesta' ), true ) ) {
			$orderby_request = 'data_richiesta';
		}

		$plugin = GD_B2B_Plugin::instance();
		$ids    = $plugin->roles->get_b2b_client_ids();

		if ( array() === $ids ) {
			$this->items = array();
			$this->set_pagination_args( array( 'total_items' => 0, 'per_page' => $per_page ) );
			return;
		}

		$this->batch_load_meta( $ids );

		$rows = $this->build_lightweight_rows( $ids, $plugin );

		$rows = $this->apply_filters_to_rows( $rows );

		$sort_key = $this->resolve_sort_key( $orderby_request );
		uasort(
			$rows,
			static function ( $a, $b ) use ( $sort_key, $order ) {
				$va = $a[ $sort_key ] ?? '';
				$vb = $b[ $sort_key ] ?? '';
				if ( $va === $vb ) {
					return 0;
				}
				$cmp = $va < $vb ? -1 : 1;
				return 'ASC' === $order ? $cmp : -$cmp;
			}
		);

		$total       = count( $rows );
		$rows        = array_values( $rows );
		$total_pages = max( 1, (int) ceil( $total / $per_page ) );
		if ( $paged > $total_pages ) {
			$paged = $total_pages;
		}
		$page_ids = array_slice( $rows, ( $paged - 1 ) * $per_page, $per_page );

		$this->items = $this->hydrate_page_items( $page_ids, $plugin );

		$this->set_pagination_args( array(
			'total_items' => $total,
			'per_page'    => $per_page,
			'total_pages' => $total_pages,
		) );
	}

	/**
	 * Batch-load meta for sorting/filtering without per-user queries.
	 *
	 * @param int[] $ids User IDs.
	 */
	private function batch_load_meta( array $ids ): void {
		global $wpdb;

		if ( array() === $ids ) {
			return;
		}

		$id_list   = implode( ',', array_map( 'intval', $ids ) );
		$meta_keys = array(
			'billing_company',
			'billing_first_name',
			'billing_last_name',
			GD_B2B_META_REQUEST_DATE,
			GD_B2B_META_STATUS,
			GD_B2B_LEGACY_META_STATUS,
			GD_B2B_META_ACTIVATION_EMAIL_SENT,
		);

		$placeholders = implode( ',', array_fill( 0, count( $meta_keys ), '%s' ) );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$sql = $wpdb->prepare(
			"SELECT user_id, meta_key, meta_value FROM {$wpdb->usermeta} WHERE user_id IN ({$id_list}) AND meta_key IN ({$placeholders})",
			...$meta_keys
		);

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$meta_rows = $wpdb->get_results( $sql );

		$this->meta_cache = array();
		if ( is_array( $meta_rows ) ) {
			foreach ( $meta_rows as $row ) {
				$uid = (int) $row->user_id;
				if ( ! isset( $this->meta_cache[ $uid ] ) ) {
					$this->meta_cache[ $uid ] = array();
				}
				$this->meta_cache[ $uid ][ $row->meta_key ] = $row->meta_value;
			}
		}
	}

	/**
	 * Get cached meta value.
	 *
	 * @param int    $uid User ID.
	 * @param string $key Meta key.
	 * @return string
	 */
	private function get_cached_meta( int $uid, string $key ): string {
		return $this->meta_cache[ $uid ][ $key ] ?? '';
	}

	/**
	 * Build lightweight row data from meta cache (no WC_Customer hydration).
	 *
	 * @param int[]          $ids    User IDs.
	 * @param GD_B2B_Plugin  $plugin Plugin instance.
	 * @return array<int,array<string,mixed>>
	 */
	private function build_lightweight_rows( array $ids, GD_B2B_Plugin $plugin ): array {
		$roles = $plugin->roles;
		$rows  = array();

		foreach ( $ids as $uid ) {
			$uid = (int) $uid;

			$is_pending = ( GD_B2B_PENDING_VALUE === $this->get_cached_meta( $uid, GD_B2B_META_STATUS ) )
				|| ( GD_B2B_PENDING_VALUE === $this->get_cached_meta( $uid, GD_B2B_LEGACY_META_STATUS ) );

			$company    = $this->get_cached_meta( $uid, 'billing_company' );
			$first_name = $this->get_cached_meta( $uid, 'billing_first_name' );
			$last_name  = $this->get_cached_meta( $uid, 'billing_last_name' );

			$req_raw   = $this->get_cached_meta( $uid, GD_B2B_META_REQUEST_DATE );
			$sort_date = ( '' !== $req_raw ) ? (int) strtotime( $req_raw ) : 0;

			$sort_ragione = GD_B2B_Roles::safe_strtolower( '' !== $company ? $company : $first_name . ' ' . $last_name );
			$sort_stato   = $is_pending ? '0_pending' : '1_z';

			$rows[ $uid ] = array(
				'uid'          => $uid,
				'pending'      => $is_pending,
				'company'      => $company,
				'first_name'   => $first_name,
				'last_name'    => $last_name,
				'sort_ragione' => $sort_ragione,
				'sort_email'   => '',
				'sort_stato'   => $sort_stato,
				'sort_date'    => $sort_date,
			);
		}

		return $rows;
	}

	/**
	 * Apply search/filter from GET parameters.
	 *
	 * @param array<int,array<string,mixed>> $rows Lightweight rows.
	 * @return array<int,array<string,mixed>>
	 */
	private function apply_filters_to_rows( array $rows ): array {
		$search_g = isset( $_REQUEST['gd_b2b_search'] ) ? trim( sanitize_text_field( wp_unslash( $_REQUEST['gd_b2b_search'] ) ) ) : '';
		$f_rs     = isset( $_REQUEST['gd_b2b_f_ragione'] ) ? trim( sanitize_text_field( wp_unslash( $_REQUEST['gd_b2b_f_ragione'] ) ) ) : '';
		$f_em     = isset( $_REQUEST['gd_b2b_f_email'] ) ? trim( sanitize_text_field( wp_unslash( $_REQUEST['gd_b2b_f_email'] ) ) ) : '';
		$f_st     = isset( $_REQUEST['gd_b2b_f_stato'] ) ? sanitize_key( wp_unslash( $_REQUEST['gd_b2b_f_stato'] ) ) : '';

		if ( '' !== $f_st ) {
			$admin   = GD_B2B_Plugin::instance()->admin;
			$st_opts = $admin->get_stato_filter_options();
			if ( ! array_key_exists( $f_st, $st_opts ) ) {
				$f_st = '';
			}
		}

		if ( '' === $search_g && '' === $f_rs && '' === $f_em && '' === $f_st ) {
			return $rows;
		}

		$lower = static function ( string $s ): string {
			return GD_B2B_Roles::safe_strtolower( $s );
		};

		foreach ( $rows as $uid => &$row ) {
			$user = get_userdata( $uid );
			if ( ! $user instanceof \WP_User ) {
				unset( $rows[ $uid ] );
				continue;
			}
			$row['sort_email'] = $lower( $user->user_email );
		}
		unset( $row );

		return array_filter( $rows, static function ( $row ) use ( $search_g, $f_rs, $f_em, $f_st, $lower ) {
			$uid     = $row['uid'];
			$user    = get_userdata( $uid );
			$company = (string) $row['company'];
			$email   = $user ? $user->user_email : '';
			$display = $user ? $user->display_name : '';
			$refn    = trim( $row['first_name'] . ' ' . $row['last_name'] );

			if ( '' !== $search_g ) {
				$qg   = $lower( $search_g );
				$blob = $lower( $company . ' ' . $email . ' ' . $display . ' ' . $refn );
				if ( false === strpos( $blob, $qg ) ) {
					return false;
				}
			}
			if ( '' !== $f_rs && false === strpos( $lower( $company ), $lower( $f_rs ) ) ) {
				return false;
			}
			if ( '' !== $f_em && false === strpos( $lower( $email ), $lower( $f_em ) ) ) {
				return false;
			}
			if ( '' !== $f_st ) {
				if ( 'pending' === $f_st ) {
					if ( empty( $row['pending'] ) ) {
						return false;
					}
				} else {
					if ( ! empty( $row['pending'] ) ) {
						return false;
					}
					$roles = GD_B2B_Plugin::instance()->roles;
					if ( $user && $roles->get_first_slug( $user ) !== $f_st ) {
						return false;
					}
				}
			}
			return true;
		} );
	}

	/**
	 * Resolve the sort key from the orderby request value.
	 *
	 * @param string $orderby_request Requested orderby column.
	 * @return string
	 */
	private function resolve_sort_key( string $orderby_request ): string {
		$map = array(
			'ragione_sociale' => 'sort_ragione',
			'email'           => 'sort_email',
			'stato'           => 'sort_stato',
			'data_richiesta'  => 'sort_date',
		);
		return $map[ $orderby_request ] ?? 'sort_date';
	}

	/**
	 * Hydrate full item data only for the current page.
	 *
	 * @param array<int,array<string,mixed>> $page_rows Lightweight rows for the page.
	 * @param GD_B2B_Plugin                  $plugin    Plugin instance.
	 * @return array<int,array<string,mixed>>
	 */
	private function hydrate_page_items( array $page_rows, GD_B2B_Plugin $plugin ): array {
		$roles = $plugin->roles;
		$items = array();

		foreach ( $page_rows as $row ) {
			$uid  = (int) $row['uid'];
			$user = get_userdata( $uid );
			if ( ! $user instanceof \WP_User ) {
				continue;
			}

			$c = $roles->wc_customer_for_admin( $uid );
			if ( ! $c instanceof \WC_Customer ) {
				continue;
			}

			$pending     = ! empty( $row['pending'] );
			$trade_label = '';
			if ( ! $pending && $roles->user_has_trade_role( $user ) ) {
				$trade_label = $roles->get_badge_label( $user );
			}

			$mail_n = max( 0, (int) $this->get_cached_meta( $uid, GD_B2B_META_ACTIVATION_EMAIL_SENT ) );

			$items[] = array(
				'user'                  => $user,
				'customer'              => $c,
				'company'               => (string) $c->get_billing_company(),
				'pending'               => $pending,
				'trade_label'           => $trade_label,
				'ref_name'              => trim( $c->get_billing_first_name() . ' ' . $c->get_billing_last_name() ),
				'activation_mail_count' => $mail_n,
			);
		}

		return $items;
	}

	public function no_items() {
		esc_html_e( 'Nessun cliente B2B trovato.', 'gd-b2b' );
	}

	/**
	 * @param array<string,mixed> $item       Row data.
	 * @param string              $column_name Column ID.
	 * @return string
	 */
	protected function column_default( $item, $column_name ) {
		return '';
	}

	/**
	 * @param array<string,mixed> $item Row data.
	 * @return string
	 */
	protected function column_ragione_sociale( $item ) {
		/** @var \WC_Customer $c */
		$c       = $item['customer'];
		$company = (string) $c->get_billing_company();
		$line    = '<strong>' . esc_html( '' !== $company ? $company : '—' ) . '</strong>';
		$name    = trim( $c->get_billing_first_name() . ' ' . $c->get_billing_last_name() );
		if ( '' !== $name ) {
			$line .= '<br /><span class="description">' . esc_html( $name ) . '</span>';
		}
		return $line;
	}

	/**
	 * @param array<string,mixed> $item Row data.
	 * @return string
	 */
	protected function column_email( $item ) {
		$user = $item['user'];
		return '<a href="mailto:' . esc_attr( $user->user_email ) . '">' . esc_html( $user->user_email ) . '</a>';
	}

	/**
	 * @param array<string,mixed> $item Row data.
	 * @return string
	 */
	protected function column_stato( $item ) {
		if ( ! empty( $item['pending'] ) ) {
			return '<span class="gd-b2b-badge gd-b2b-badge--pending">' . esc_html__( 'In attesa di approvazione', 'gd-b2b' ) . '</span>';
		}
		$lb = $item['trade_label'] ?? '';
		return '' !== $lb
			? '<span class="gd-b2b-badge gd-b2b-badge--ok">' . esc_html( $lb ) . '</span>'
			: '—';
	}

	/**
	 * @param array<string,mixed> $item Row data.
	 * @return string
	 */
	protected function column_data_richiesta( $item ) {
		$user    = $item['user'];
		$req_raw = get_user_meta( $user->ID, GD_B2B_META_REQUEST_DATE, true );
		if ( is_string( $req_raw ) && '' !== $req_raw ) {
			$t = mysql2date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $req_raw, true );
			return esc_html( $t );
		}
		return esc_html( mysql2date( get_option( 'date_format' ), $user->user_registered, true ) );
	}

	/**
	 * @param array<string,mixed> $item Row data.
	 * @return string
	 */
	protected function column_cambio_stato( $item ) {
		$user    = $item['user'];
		$uid     = (int) $user->ID;
		$pending = ! empty( $item['pending'] );
		$roles   = GD_B2B_Plugin::instance()->roles;
		$slugs   = $roles->get_trade_slugs();
		$current = $pending ? '' : $roles->get_first_slug( $user );

		ob_start();
		echo '<div class="gd-b2b-role-cell">';
		echo '<label class="screen-reader-text" for="gd-b2b-role-' . esc_attr( (string) $uid ) . '">' . esc_html__( 'Assegna ruolo', 'gd-b2b' ) . '</label>';
		echo '<select class="gd-b2b-role-select" id="gd-b2b-role-' . esc_attr( (string) $uid ) . '" data-user-id="' . esc_attr( (string) $uid ) . '" data-nonce="' . esc_attr( wp_create_nonce( 'gd_b2b_admin' ) ) . '">';
		echo '<option value="__pending__"' . selected( $pending || '' === $current, true, false ) . '>' . esc_html__( 'In attesa di approvazione', 'gd-b2b' ) . '</option>';
		foreach ( $slugs as $slug ) {
			$label = $roles->get_label( $slug );
			echo '<option value="' . esc_attr( $slug ) . '"' . selected( $slug, $current, false ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select>';
		echo '<span class="spinner gd-b2b-role-spinner" style="float:none;vertical-align:middle;"></span>';
		echo '</div>';
		return (string) ob_get_clean();
	}

	/**
	 * @param array<string,mixed> $item Row data.
	 * @return string
	 */
	protected function column_azioni( $item ) {
		$user   = $item['user'];
		$uid    = (int) $user->ID;
		$sent_n = isset( $item['activation_mail_count'] ) ? max( 0, (int) $item['activation_mail_count'] ) : 0;
		$nonce  = wp_create_nonce( 'gd_b2b_admin' );

		ob_start();
		echo '<div class="gd-b2b-actions-cell">';
		echo '<div class="gd-b2b-actions-row">';
		echo '<button type="button" class="button gd-b2b-toggle-detail" data-user-id="' . esc_attr( (string) $uid ) . '">';
		esc_html_e( 'Dati cliente', 'gd-b2b' );
		echo '</button>';
		echo '</div>';
		echo '<div class="gd-b2b-resend-stack">';
		echo '<div class="gd-b2b-resend-line1">';
		echo '<span class="gd-b2b-mail-count" aria-hidden="true">[' . esc_html( (string) $sent_n ) . ']</span>';
		echo '<button type="button" class="button gd-b2b-resend-activation" data-user-id="' . esc_attr( (string) $uid ) . '" data-nonce="' . esc_attr( $nonce ) . '">';
		esc_html_e( 'Invia nuovamente email', 'gd-b2b' );
		echo '</button>';
		echo '</div>';
		echo '<div class="gd-b2b-mail-feedback" role="status"></div>';
		echo '</div></div>';
		return (string) ob_get_clean();
	}

	/**
	 * @param array<string,mixed> $item Row data.
	 */
	public function single_row( $item ) {
		$user = $item['user'];
		echo '<tr>';
		$this->single_row_columns( $item );
		echo '</tr>';
		echo '<tr class="gd-b2b-detail-row" id="gd-b2b-detail-' . esc_attr( (string) $user->ID ) . '" style="display:none;"><td colspan="' . (int) count( $this->get_columns() ) . '" class="gd-b2b-detail-cell">';
		$this->render_detail_panel( $item );
		echo '</td></tr>';
	}

	/**
	 * Render the expandable detail/edit form.
	 *
	 * @param array<string,mixed> $item Row data.
	 */
	protected function render_detail_panel( array $item ): void {
		$user = $item['user'];
		/** @var \WC_Customer $c */
		$c   = $item['customer'];
		$uid = (int) $user->ID;

		$piva     = function_exists( 'gd_b2b_get_additional_field_for_customer' )
			? gd_b2b_get_additional_field_for_customer( $uid, GD_B2B_FIELD_NS . '/piva', 'other' )
			: '';
		$sdi      = function_exists( 'gd_b2b_get_additional_field_for_customer' )
			? gd_b2b_get_additional_field_for_customer( $uid, GD_B2B_FIELD_NS . '/codice-sdi', 'other' )
			: '';
		$pec      = function_exists( 'gd_b2b_get_additional_field_for_customer' )
			? gd_b2b_get_additional_field_for_customer( $uid, GD_B2B_FIELD_NS . '/pec', 'other' )
			: '';
		$rs_extra = function_exists( 'gd_b2b_get_additional_field_for_customer' )
			? gd_b2b_get_additional_field_for_customer( $uid, GD_B2B_FIELD_NS . '/ragione-sociale', 'other' )
			: '';

		$countries = array( 'IT' => 'Italia' );
		if ( function_exists( 'WC' ) && WC() && WC()->countries ) {
			$countries = WC()->countries->get_allowed_countries();
		}

		echo '<div class="gd-b2b-detail-panel"><form class="gd-b2b-client-form" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" data-user-id="' . esc_attr( (string) $uid ) . '">';
		wp_nonce_field( 'gd_b2b_save_client_' . $uid, '_gd_b2b_nonce' );
		echo '<input type="hidden" name="user_id" value="' . esc_attr( (string) $uid ) . '" />';
		echo '<input type="hidden" name="action" value="gd_b2b_save_client" />';

		echo '<h4>' . esc_html__( 'Dati anagrafici e fatturazione', 'gd-b2b' ) . '</h4>';
		echo '<div class="gd-b2b-fields-grid">';

		$this->render_field_text( __( 'Email (login)', 'gd-b2b' ), 'billing_email', $c->get_billing_email(), 'email', false );
		$this->render_field_text( __( 'Ragione sociale', 'gd-b2b' ), 'billing_company', $c->get_billing_company(), 'organization', false );
		$this->render_field_text(
			__( 'Ragione sociale (campo checkout)', 'gd-b2b' ),
			'gd_b2b_rs_extra',
			'' !== $rs_extra ? $rs_extra : $c->get_billing_company(),
			'organization',
			false
		);
		$this->render_field_text( __( 'Nome', 'gd-b2b' ), 'billing_first_name', $c->get_billing_first_name(), 'given-name', false );
		$this->render_field_text( __( 'Cognome', 'gd-b2b' ), 'billing_last_name', $c->get_billing_last_name(), 'family-name', false );
		$this->render_field_text( __( 'Indirizzo', 'gd-b2b' ), 'billing_address_1', $c->get_billing_address_1(), 'address-line1', false );
		$this->render_field_text( __( 'Interno', 'gd-b2b' ), 'billing_address_2', $c->get_billing_address_2(), 'address-line2', false );
		$this->render_field_text( __( 'CAP', 'gd-b2b' ), 'billing_postcode', $c->get_billing_postcode(), 'postal-code', false );
		$this->render_field_text( __( 'Città', 'gd-b2b' ), 'billing_city', $c->get_billing_city(), 'address-level2', false );
		$this->render_field_text( __( 'Provincia (sigla)', 'gd-b2b' ), 'billing_state', $c->get_billing_state(), 'address-level1', false );

		echo '<p class="gd-b2b-field"><label>' . esc_html__( 'Paese', 'gd-b2b' ) . '</label><select name="billing_country">';
		$bc = $c->get_billing_country() ?: 'IT';
		foreach ( $countries as $code => $name ) {
			echo '<option value="' . esc_attr( $code ) . '"' . selected( $bc, $code, false ) . '>' . esc_html( $name ) . '</option>';
		}
		echo '</select></p>';

		$this->render_field_text( __( 'Telefono', 'gd-b2b' ), 'billing_phone', $c->get_billing_phone(), 'tel', false );

		echo '<h4>' . esc_html__( 'Fatturazione elettronica', 'gd-b2b' ) . '</h4>';
		$this->render_field_text( __( 'Partita IVA', 'gd-b2b' ), 'gd_b2b_myaccount_piva', $piva, 'off', false );
		$this->render_field_text( __( 'Codice SDI', 'gd-b2b' ), 'gd_b2b_myaccount_sdi', $sdi, 'off', false );
		$this->render_field_text( __( 'PEC', 'gd-b2b' ), 'gd_b2b_myaccount_pec', $pec, 'email', false );

		echo '</div>';
		echo '<p class="submit">';
		submit_button( __( 'Salva modifiche', 'gd-b2b' ), 'primary', 'submit', false );
		echo '</p>';
		echo '</form></div>';
	}

	/**
	 * Render a text field in the detail panel.
	 *
	 * @param string $label        Field label.
	 * @param string $name         Input name.
	 * @param string $value        Current value.
	 * @param string $autocomplete Autocomplete attribute.
	 * @param bool   $required     Whether the field is required.
	 */
	private function render_field_text( string $label, string $name, string $value, string $autocomplete, bool $required ): void {
		$req = $required ? ' required' : '';
		echo '<p class="gd-b2b-field"><label for="gd_b2b_' . esc_attr( $name ) . '">' . esc_html( $label );
		if ( $required ) {
			echo ' <span class="required">*</span>';
		}
		echo '</label>';
		$type = ( 'gd_b2b_myaccount_pec' === $name || 'billing_email' === $name ) ? 'email' : 'text';
		echo '<input type="' . esc_attr( $type ) . '" class="regular-text" id="gd_b2b_' . esc_attr( $name ) . '" name="' . esc_attr( $name ) . '" value="' . esc_attr( $value ) . '" autocomplete="' . esc_attr( $autocomplete ) . '"' . $req . ' /></p>';
	}
}
