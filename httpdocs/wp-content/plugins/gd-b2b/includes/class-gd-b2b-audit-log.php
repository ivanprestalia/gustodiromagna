<?php
/**
 * B2B audit-log storage and retrieval.
 *
 * @package GD_B2B
 * @since   2.0.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class GD_B2B_Audit_Log
 *
 * Records B2B administrative actions (role changes, registrations,
 * email sends, bulk operations) in a dedicated database table.
 */
class GD_B2B_Audit_Log {

	/**
	 * Action type: new B2B registration submitted.
	 *
	 * @var string
	 */
	public const ACTION_REGISTRATION = 'registration_submitted';

	/**
	 * Action type: trade role assigned to a user.
	 *
	 * @var string
	 */
	public const ACTION_ROLE_ASSIGNED = 'role_assigned';

	/**
	 * Action type: user set back to pending / role removed.
	 *
	 * @var string
	 */
	public const ACTION_ROLE_REMOVED = 'role_removed';

	/**
	 * Action type: activation email sent.
	 *
	 * @var string
	 */
	public const ACTION_EMAIL_SENT = 'activation_email_sent';

	/**
	 * Action type: client data updated from admin.
	 *
	 * @var string
	 */
	public const ACTION_DATA_UPDATED = 'client_data_updated';

	/**
	 * Action type: bulk role assignment.
	 *
	 * @var string
	 */
	public const ACTION_BULK_ROLE = 'bulk_role_assigned';

	/**
	 * Full table name including wpdb prefix (resolved lazily).
	 *
	 * @var string|null
	 */
	private ?string $table = null;

	/**
	 * Resolve and cache the full table name.
	 *
	 * @return string
	 */
	private function table(): string {
		if ( null === $this->table ) {
			global $wpdb;
			$this->table = $wpdb->prefix . 'gd_b2b_audit_log';
		}

		return $this->table;
	}

	/* ------------------------------------------------------------------
	 * Schema
	 * ----------------------------------------------------------------*/

	/**
	 * Create or upgrade the audit-log table.
	 *
	 * Intended to be called on plugin activation (or upgrade).
	 *
	 * @return void
	 */
	public static function create_table(): void {
		global $wpdb;

		$table   = $wpdb->prefix . 'gd_b2b_audit_log';
		$charset = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			actor_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			target_user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			action VARCHAR(50) NOT NULL,
			details TEXT,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY target_user_id (target_user_id),
			KEY action (action),
			KEY created_at (created_at)
		) {$charset};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	/* ------------------------------------------------------------------
	 * Write
	 * ----------------------------------------------------------------*/

	/**
	 * Record an audit-log entry.
	 *
	 * @param string   $action         Action identifier (use class constants).
	 * @param int      $target_user_id The user affected by the action.
	 * @param string   $details        Free-form detail string (JSON or plain text).
	 * @param int|null $actor_id       Who performed the action; null = current user.
	 * @return void
	 */
	public function log( string $action, int $target_user_id, string $details = '', ?int $actor_id = null ): void {
		global $wpdb;

		if ( null === $actor_id ) {
			$actor_id = get_current_user_id();
		}

		$wpdb->insert(
			$this->table(),
			array(
				'actor_id'       => $actor_id,
				'target_user_id' => $target_user_id,
				'action'         => sanitize_key( $action ),
				'details'        => sanitize_textarea_field( $details ),
				'created_at'     => current_time( 'mysql', true ),
			),
			array( '%d', '%d', '%s', '%s', '%s' )
		);
	}

	/* ------------------------------------------------------------------
	 * Read
	 * ----------------------------------------------------------------*/

	/**
	 * Retrieve log entries with optional filtering and pagination.
	 *
	 * @param array $args {
	 *     Optional query arguments.
	 *
	 *     @type int    $target_user_id Filter by affected user.
	 *     @type string $action         Filter by action type.
	 *     @type int    $per_page       Results per page (default 50).
	 *     @type int    $page           Page number (default 1).
	 *     @type string $orderby        Column to sort by (default 'created_at').
	 *     @type string $order          ASC or DESC (default 'DESC').
	 * }
	 * @return object[]
	 */
	public function get_logs( array $args = [] ): array {
		global $wpdb;

		$defaults = array(
			'target_user_id' => 0,
			'action'         => '',
			'per_page'       => 50,
			'page'           => 1,
			'orderby'        => 'created_at',
			'order'          => 'DESC',
		);

		$args = wp_parse_args( $args, $defaults );

		$where  = array();
		$values = array();

		if ( $args['target_user_id'] > 0 ) {
			$where[]  = 'target_user_id = %d';
			$values[] = (int) $args['target_user_id'];
		}

		if ( '' !== $args['action'] ) {
			$where[]  = 'action = %s';
			$values[] = sanitize_key( $args['action'] );
		}

		$where_sql = $where ? 'WHERE ' . implode( ' AND ', $where ) : '';
		$order_col = $this->sanitize_orderby( $args['orderby'] );
		$order_dir = 'ASC' === strtoupper( $args['order'] ) ? 'ASC' : 'DESC';
		$per_page  = max( 1, (int) $args['per_page'] );
		$offset    = max( 0, ( (int) $args['page'] - 1 ) * $per_page );

		$sql = "SELECT * FROM {$this->table()} {$where_sql} ORDER BY {$order_col} {$order_dir} LIMIT %d OFFSET %d";
		$values[] = $per_page;
		$values[] = $offset;

		if ( $values ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- dynamic WHERE built safely above.
			$sql = $wpdb->prepare( $sql, $values );
		}

		return (array) $wpdb->get_results( $sql );
	}

	/**
	 * Count log entries matching the given filters (for pagination).
	 *
	 * @param array $args Same filter keys as get_logs() (pagination keys ignored).
	 * @return int
	 */
	public function count_logs( array $args = [] ): int {
		global $wpdb;

		$where  = array();
		$values = array();

		if ( ! empty( $args['target_user_id'] ) && $args['target_user_id'] > 0 ) {
			$where[]  = 'target_user_id = %d';
			$values[] = (int) $args['target_user_id'];
		}

		if ( ! empty( $args['action'] ) && '' !== $args['action'] ) {
			$where[]  = 'action = %s';
			$values[] = sanitize_key( $args['action'] );
		}

		$where_sql = $where ? 'WHERE ' . implode( ' AND ', $where ) : '';
		$sql       = "SELECT COUNT(*) FROM {$this->table()} {$where_sql}";

		if ( $values ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$sql = $wpdb->prepare( $sql, $values );
		}

		return (int) $wpdb->get_var( $sql );
	}

	/**
	 * Shorthand: get the most recent logs for a single user.
	 *
	 * @param int $user_id Target user ID.
	 * @param int $limit   Max rows to return (default 20).
	 * @return object[]
	 */
	public function get_user_logs( int $user_id, int $limit = 20 ): array {
		return $this->get_logs(
			array(
				'target_user_id' => $user_id,
				'per_page'       => $limit,
				'page'           => 1,
			)
		);
	}

	/* ------------------------------------------------------------------
	 * Private helpers
	 * ----------------------------------------------------------------*/

	/**
	 * Whitelist the ORDER BY column to prevent injection.
	 *
	 * @param string $col Requested column name.
	 * @return string Safe column name.
	 */
	private function sanitize_orderby( string $col ): string {
		$allowed = array(
			'id',
			'actor_id',
			'target_user_id',
			'action',
			'created_at',
		);

		return in_array( $col, $allowed, true ) ? $col : 'created_at';
	}
}
