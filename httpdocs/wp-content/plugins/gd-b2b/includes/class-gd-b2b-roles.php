<?php
/**
 * Centralized B2B role management.
 *
 * Replaces scattered role helpers previously in functions-core.php and
 * duplicated label arrays in class-gd-b2b-clients-list-table.php.
 *
 * @package GD_B2B
 * @since   2.0.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class GD_B2B_Roles
 *
 * Single source of truth for trade-role slugs, labels, buyer-meta
 * operations, and cached user-ID queries.
 */
class GD_B2B_Roles {

	/**
	 * Transient keys managed by this class.
	 *
	 * @var string[]
	 */
	private const CACHE_KEYS = array(
		'gd_b2b_pending_ids',
		'gd_b2b_trade_ids',
		'gd_b2b_client_ids',
	);

	/**
	 * Cache lifetime in seconds.
	 *
	 * @var int
	 */
	private const CACHE_TTL = 5 * MINUTE_IN_SECONDS;

	/**
	 * Register hooks that keep transient caches fresh.
	 *
	 * @return void
	 */
	public function register_cache_hooks(): void {
		add_action( 'set_user_role', array( $this, 'invalidate_user_cache' ), 5 );
		add_action( 'add_user_role', array( $this, 'invalidate_user_cache' ), 5 );
		add_action( 'updated_user_meta', array( $this, 'maybe_invalidate_on_meta' ), 10, 3 );
		add_action( 'deleted_user_meta', array( $this, 'maybe_invalidate_on_meta' ), 10, 3 );
	}

	/**
	 * Invalidate cache only when B2B-relevant meta changes.
	 *
	 * @param int    $meta_id  Meta ID (unused).
	 * @param int    $user_id  User ID (unused).
	 * @param string $meta_key Meta key that was changed.
	 * @return void
	 */
	public function maybe_invalidate_on_meta( $meta_id, $user_id, $meta_key ): void {
		if ( GD_B2B_META_STATUS === $meta_key || GD_B2B_LEGACY_META_STATUS === $meta_key ) {
			$this->invalidate_user_cache();
		}
	}

	/* ------------------------------------------------------------------
	 * Slugs
	 * ----------------------------------------------------------------*/

	/**
	 * Hard-coded default B2B role slugs.
	 *
	 * @return string[]
	 */
	public function get_default_slugs(): array {
		return array(
			'rivenditore',
			'azienda_sc_base',
			'azienda_sc_premium',
			'azienda_sc_gold',
		);
	}

	/**
	 * Configured trade-role slugs from settings, with filter.
	 *
	 * @return string[]
	 */
	public function get_trade_slugs(): array {
		$defaults = $this->get_default_slugs();
		$opt      = get_option( GD_B2B_OPTION_SETTINGS );
		$slugs    = $defaults;

		if ( is_array( $opt ) && ! empty( $opt['approvable_roles'] ) && is_array( $opt['approvable_roles'] ) ) {
			$parsed = array_values( array_filter( array_map( 'sanitize_key', $opt['approvable_roles'] ) ) );
			if ( array() !== $parsed ) {
				$slugs = $parsed;
			}
		}

		$filtered = apply_filters( 'gd_b2b_trade_role_slugs', $slugs );

		if ( ! is_array( $filtered ) || array() === $filtered ) {
			return $defaults;
		}

		return $filtered;
	}

	/**
	 * Whether a slug is one of the configured trade roles.
	 *
	 * @param string $slug Role slug.
	 * @return bool
	 */
	public function is_trade_role( string $slug ): bool {
		return in_array( $slug, $this->get_trade_slugs(), true );
	}

	/**
	 * Whether a user holds at least one configured trade role.
	 *
	 * @param \WP_User|int|null $user_or_id User object, ID, or null for current user.
	 * @return bool
	 */
	public function user_has_trade_role( $user_or_id = null ): bool {
		if ( null === $user_or_id ) {
			$user = wp_get_current_user();
		} elseif ( is_numeric( $user_or_id ) ) {
			$user = get_userdata( (int) $user_or_id );
		} else {
			$user = $user_or_id;
		}

		if ( ! $user instanceof \WP_User ) {
			return false;
		}

		$trade = $this->get_trade_slugs();

		foreach ( $trade as $slug ) {
			if ( isset( $user->caps[ $slug ] ) && $user->caps[ $slug ] ) {
				return true;
			}
		}

		return (bool) array_intersect( $trade, (array) $user->roles );
	}

	/* ------------------------------------------------------------------
	 * Labels  (Improvement #19)
	 * ----------------------------------------------------------------*/

	/**
	 * Full label for a role slug (admin / settings context).
	 *
	 * @param string $slug Role slug.
	 * @return string
	 */
	public function get_label( string $slug ): string {
		$labels = $this->build_full_labels();

		return $labels[ $slug ] ?? $slug;
	}

	/**
	 * Short badge label for the first matching trade role of a user.
	 *
	 * @param \WP_User $user User object.
	 * @return string Empty string if no trade role found.
	 */
	public function get_badge_label( \WP_User $user ): string {
		$badge_labels = array(
			'rivenditore'        => __( 'Rivenditore', 'gd-b2b' ),
			'azienda_sc_base'    => __( 'Azienda Base', 'gd-b2b' ),
			'azienda_sc_premium' => __( 'Azienda Premium', 'gd-b2b' ),
			'azienda_sc_gold'    => __( 'Azienda Gold', 'gd-b2b' ),
		);

		/** @var string[] $badge_labels */
		$badge_labels = apply_filters( 'gd_b2b_role_badge_labels', $badge_labels );

		foreach ( $this->get_trade_slugs() as $slug ) {
			if ( isset( $user->caps[ $slug ] ) && $user->caps[ $slug ] ) {
				return $badge_labels[ $slug ] ?? $slug;
			}
			if ( in_array( $slug, (array) $user->roles, true ) ) {
				return $badge_labels[ $slug ] ?? $slug;
			}
		}

		return '';
	}

	/**
	 * All full labels keyed by slug for every configured trade role.
	 *
	 * @return array<string, string>
	 */
	public function get_all_labels(): array {
		return $this->build_full_labels();
	}

	/**
	 * First configured trade-role slug that the user holds.
	 *
	 * @param \WP_User $user User object.
	 * @return string|null
	 */
	public function get_first_slug( \WP_User $user ): ?string {
		foreach ( $this->get_trade_slugs() as $slug ) {
			if ( isset( $user->caps[ $slug ] ) && $user->caps[ $slug ] ) {
				return $slug;
			}
			if ( in_array( $slug, (array) $user->roles, true ) ) {
				return $slug;
			}
		}

		return null;
	}

	/* ------------------------------------------------------------------
	 * User meta helpers
	 * ----------------------------------------------------------------*/

	/**
	 * Read buyer status from new or legacy meta.
	 *
	 * @param int $user_id User ID.
	 * @return string 'pending' or empty string.
	 */
	public function read_buyer_meta( int $user_id ): string {
		if ( GD_B2B_PENDING_VALUE === get_user_meta( $user_id, GD_B2B_META_STATUS, true ) ) {
			return GD_B2B_PENDING_VALUE;
		}

		if ( GD_B2B_PENDING_VALUE === get_user_meta( $user_id, GD_B2B_LEGACY_META_STATUS, true ) ) {
			return GD_B2B_PENDING_VALUE;
		}

		return '';
	}

	/**
	 * Mark a user as pending B2B buyer.
	 *
	 * @param int $user_id User ID.
	 * @return void
	 */
	public function set_pending_meta( int $user_id ): void {
		update_user_meta( $user_id, GD_B2B_META_STATUS, GD_B2B_PENDING_VALUE );
		delete_user_meta( $user_id, GD_B2B_LEGACY_META_STATUS );

		if ( '' === get_user_meta( $user_id, GD_B2B_META_REQUEST_DATE, true ) ) {
			update_user_meta( $user_id, GD_B2B_META_REQUEST_DATE, current_time( 'mysql' ) );
		}
	}

	/**
	 * Remove pending status (new and legacy meta).
	 *
	 * @param int $user_id User ID.
	 * @return void
	 */
	public function clear_buyer_meta( int $user_id ): void {
		delete_user_meta( $user_id, GD_B2B_META_STATUS );
		delete_user_meta( $user_id, GD_B2B_LEGACY_META_STATUS );
	}

	/**
	 * Whether a user is a pending buyer (no trade role, not admin/manager).
	 *
	 * @param int|null $user_id User ID, or null for current user.
	 * @return bool
	 */
	public function is_pending_buyer( ?int $user_id = null ): bool {
		if ( null === $user_id ) {
			$user_id = get_current_user_id();
		}

		if ( ! $user_id ) {
			return false;
		}

		$user = get_userdata( $user_id );

		if ( ! $user ) {
			return false;
		}

		if ( $this->user_has_trade_role( $user ) ) {
			return false;
		}

		foreach ( array( 'administrator', 'shop_manager' ) as $bypass ) {
			if ( in_array( $bypass, (array) $user->roles, true ) ) {
				return false;
			}
		}

		return GD_B2B_PENDING_VALUE === $this->read_buyer_meta( $user_id );
	}

	/**
	 * Whether a user would appear in the «Richiesta B2B» column:
	 * pending meta OR at least one trade role.
	 *
	 * @param int $user_id User ID.
	 * @return bool
	 */
	public function user_matches_b2b_column( int $user_id ): bool {
		if ( $user_id <= 0 ) {
			return false;
		}

		if ( GD_B2B_PENDING_VALUE === $this->read_buyer_meta( $user_id ) ) {
			return true;
		}

		$user = get_userdata( $user_id );

		return $user instanceof \WP_User && $this->user_has_trade_role( $user );
	}

	/* ------------------------------------------------------------------
	 * Cached query helpers  (Improvement #7)
	 * ----------------------------------------------------------------*/

	/**
	 * User IDs with a pending B2B request.
	 *
	 * @return int[]
	 */
	public function get_pending_user_ids(): array {
		$cached = get_transient( 'gd_b2b_pending_ids' );
		if ( false !== $cached ) {
			return (array) $cached;
		}

		global $wpdb;

		$sql = $wpdb->prepare(
			"SELECT DISTINCT user_id
			   FROM {$wpdb->usermeta}
			  WHERE ( meta_key = %s AND meta_value = %s )
			     OR ( meta_key = %s AND meta_value = %s )",
			GD_B2B_META_STATUS,
			GD_B2B_PENDING_VALUE,
			GD_B2B_LEGACY_META_STATUS,
			GD_B2B_PENDING_VALUE
		);

		$ids = array_values( array_unique( array_map( 'intval', (array) $wpdb->get_col( $sql ) ) ) );

		set_transient( 'gd_b2b_pending_ids', $ids, self::CACHE_TTL );

		return $ids;
	}

	/**
	 * User IDs holding at least one configured trade role.
	 *
	 * @return int[]
	 */
	public function get_trade_role_user_ids(): array {
		$cached = get_transient( 'gd_b2b_trade_ids' );
		if ( false !== $cached ) {
			return (array) $cached;
		}

		global $wpdb;

		$slugs = $this->get_trade_slugs();
		if ( array() === $slugs ) {
			return array();
		}

		$cap_key = $wpdb->get_blog_prefix() . 'capabilities';
		$parts   = array();

		foreach ( $slugs as $slug ) {
			$s = sanitize_key( $slug );
			if ( '' === $s ) {
				continue;
			}
			$like    = '%' . $wpdb->esc_like( '"' . $s . '"' ) . '%';
			$parts[] = $wpdb->prepare( 'meta_value LIKE %s', $like );
		}

		if ( array() === $parts ) {
			return array();
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $parts built with $wpdb->prepare above.
		$sql = $wpdb->prepare(
			"SELECT DISTINCT user_id FROM {$wpdb->usermeta} WHERE meta_key = %s AND (" . implode( ' OR ', $parts ) . ')',
			$cap_key
		);

		$ids = array_values( array_unique( array_map( 'intval', (array) $wpdb->get_col( $sql ) ) ) );

		set_transient( 'gd_b2b_trade_ids', $ids, self::CACHE_TTL );

		return $ids;
	}

	/**
	 * Union of pending and trade-role user IDs (B2B clients list).
	 *
	 * For small sites the PHP fallback scans every user for accuracy;
	 * for large sites it merges the two SQL queries and optionally
	 * reconciles via the fallback.
	 *
	 * @return int[]
	 */
	public function get_b2b_client_ids(): array {
		$cached = get_transient( 'gd_b2b_client_ids' );
		if ( false !== $cached ) {
			return (array) $cached;
		}

		$counts     = count_users();
		$total      = isset( $counts['total_users'] ) ? (int) $counts['total_users'] : 0;
		$simple_max = (int) apply_filters( 'gd_b2b_clients_list_simple_scan_max_users', 15000 );

		$pending = $this->get_pending_user_ids();
		$trade   = $this->get_trade_role_user_ids();

		if ( $total > 0 && $total <= $simple_max ) {
			$merged = $this->php_fallback_user_ids( true );
		} else {
			$merged = array_values( array_unique( array_filter( array_map( 'intval', array_merge( $pending, $trade ) ) ) ) );

			$max        = (int) apply_filters( 'gd_b2b_clients_list_php_fallback_max_site_users', 3500 );
			$auto_union = ( $total > 0 && $total <= $max );
			$forced     = apply_filters( 'gd_b2b_clients_list_always_union_php_fallback', null );
			$do_union   = ( array() === $merged ) || ( null !== $forced ? (bool) $forced : $auto_union );

			if ( $do_union ) {
				$extra = $this->php_fallback_user_ids( false );
				if ( is_array( $extra ) && array() !== $extra ) {
					$merged = array_values( array_unique( array_merge( $merged, $extra ) ) );
				}
			}

			if ( array() === $merged ) {
				$rescue = $this->php_fallback_user_ids( true );
				if ( is_array( $rescue ) && array() !== $rescue ) {
					$merged = $rescue;
				}
			}
		}

		$before_filter = $merged;
		$merged        = apply_filters( 'gd_b2b_clients_list_user_ids', $merged, $pending, $trade );

		if ( ! is_array( $merged ) ) {
			$merged = $before_filter;
		} elseif ( array() === $merged && array() !== $before_filter ) {
			$merged = $before_filter;
		}

		set_transient( 'gd_b2b_client_ids', $merged, self::CACHE_TTL );

		return $merged;
	}

	/**
	 * Delete all transient caches managed by this class.
	 *
	 * Hooked to set_user_role, add_user_role, and relevant meta changes.
	 *
	 * @return void
	 */
	public function invalidate_user_cache(): void {
		foreach ( self::CACHE_KEYS as $key ) {
			delete_transient( $key );
		}
	}

	/* ------------------------------------------------------------------
	 * WC_Customer helper
	 * ----------------------------------------------------------------*/

	/**
	 * Build a WC_Customer for admin list-table display.
	 *
	 * Falls back to raw usermeta when WC's read() does not populate the
	 * object (e.g. HPOS-only stores without matching customer rows).
	 *
	 * @param int $user_id User ID.
	 * @return \WC_Customer|null
	 */
	public function wc_customer_for_admin( int $user_id ): ?\WC_Customer {
		if ( $user_id <= 0 || ! class_exists( 'WC_Customer' ) ) {
			return null;
		}

		try {
			$c = new \WC_Customer( $user_id );
			if ( $user_id === (int) $c->get_id() ) {
				return $c;
			}
		} catch ( \Throwable $e ) {
			unset( $e );
		}

		$c = new \WC_Customer( 0 );
		$c->set_id( $user_id );

		$fields = array(
			'billing_first_name',
			'billing_last_name',
			'billing_company',
			'billing_email',
			'billing_address_1',
			'billing_address_2',
			'billing_postcode',
			'billing_city',
			'billing_state',
			'billing_country',
			'billing_phone',
			'shipping_first_name',
			'shipping_last_name',
			'shipping_company',
			'shipping_address_1',
			'shipping_address_2',
			'shipping_postcode',
			'shipping_city',
			'shipping_state',
			'shipping_country',
		);

		foreach ( $fields as $key ) {
			$raw = get_user_meta( $user_id, $key, true );
			if ( '' === $raw || null === $raw ) {
				continue;
			}
			$m = 'set_' . $key;
			if ( is_callable( array( $c, $m ) ) ) {
				$c->{ $m }( is_string( $raw ) ? $raw : (string) $raw );
			}
		}

		$ud = get_userdata( $user_id );
		if ( $ud && ! $c->get_billing_email() ) {
			$c->set_billing_email( (string) $ud->user_email );
		}

		return $c;
	}

	/* ------------------------------------------------------------------
	 * Static utilities
	 * ----------------------------------------------------------------*/

	/**
	 * Unicode-safe strtolower with mbstring fallback.
	 *
	 * @param string $s Input string.
	 * @return string
	 */
	public static function safe_strtolower( string $s ): string {
		if ( function_exists( 'mb_strtolower' ) ) {
			return mb_strtolower( $s, 'UTF-8' );
		}

		return strtolower( $s );
	}

	/* ------------------------------------------------------------------
	 * Private helpers
	 * ----------------------------------------------------------------*/

	/**
	 * Build filterable full-label map for all configured trade roles.
	 *
	 * @return array<string, string>
	 */
	private function build_full_labels(): array {
		$defaults = array(
			'rivenditore'        => __( 'Rivenditore', 'gd-b2b' ),
			'azienda_sc_base'    => __( 'Azienda sconto Base', 'gd-b2b' ),
			'azienda_sc_premium' => __( 'Azienda sconto Premium', 'gd-b2b' ),
			'azienda_sc_gold'    => __( 'Azienda sconto Gold', 'gd-b2b' ),
		);

		/** @var array<string, string> $labels */
		$labels = apply_filters( 'gd_b2b_role_labels', $defaults );

		if ( ! is_array( $labels ) || array() === $labels ) {
			$labels = $defaults;
		}

		$slugs = $this->get_trade_slugs();
		$out   = array();

		foreach ( $slugs as $slug ) {
			$out[ $slug ] = $labels[ $slug ] ?? $slug;
		}

		return $out;
	}

	/**
	 * PHP fallback: scan every user and test with user_matches_b2b_column().
	 *
	 * Guarded by configurable site-size thresholds to prevent overload.
	 *
	 * @param bool $ignore_user_count_limit Bypass normal max (still respects absolute_max).
	 * @return int[]
	 */
	private function php_fallback_user_ids( bool $ignore_user_count_limit ): array {
		$max_site_users = (int) apply_filters( 'gd_b2b_clients_list_php_fallback_max_site_users', 3500 );
		$absolute_max   = (int) apply_filters( 'gd_b2b_clients_list_php_fallback_absolute_max', 25000 );

		if ( ! $ignore_user_count_limit && $max_site_users <= 0 ) {
			return array();
		}

		$counts = count_users();
		$total  = isset( $counts['total_users'] ) ? (int) $counts['total_users'] : 0;

		if ( $total <= 0 ) {
			return array();
		}

		if ( $ignore_user_count_limit && $total > $absolute_max ) {
			return array();
		}

		if ( ! $ignore_user_count_limit && $total > $max_site_users ) {
			return array();
		}

		$out = array();

		foreach ( get_users(
			array(
				'fields'  => 'ID',
				'number'  => -1,
				'orderby' => 'ID',
				'order'   => 'ASC',
			)
		) as $uid ) {
			$uid = (int) $uid;
			if ( $this->user_matches_b2b_column( $uid ) ) {
				$out[] = $uid;
			}
		}

		return array_values( array_unique( array_map( 'intval', $out ) ) );
	}
}
