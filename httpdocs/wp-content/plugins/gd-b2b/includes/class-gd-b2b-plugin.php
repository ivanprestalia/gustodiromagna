<?php
/**
 * Main plugin singleton — wires all modules together.
 *
 * @package GD_B2B
 */

defined( 'ABSPATH' ) || exit;

/**
 * GD_B2B_Plugin orchestrates instantiation and initialization of all sub-modules.
 */
class GD_B2B_Plugin {

	/** @var self|null */
	private static ?self $instance = null;

	/** @var GD_B2B_Roles */
	public GD_B2B_Roles $roles;

	/** @var GD_B2B_Email */
	public GD_B2B_Email $email;

	/** @var GD_B2B_PIVA_Validator */
	public GD_B2B_PIVA_Validator $piva_validator;

	/** @var GD_B2B_Audit_Log */
	public GD_B2B_Audit_Log $audit_log;

	/** @var GD_B2B_Registration */
	public GD_B2B_Registration $registration;

	/** @var GD_B2B_Checkout_Fields */
	public GD_B2B_Checkout_Fields $checkout_fields;

	/** @var GD_B2B_Account_UI */
	public GD_B2B_Account_UI $account_ui;

	/** @var GD_B2B_Cart_Blocker */
	public GD_B2B_Cart_Blocker $cart_blocker;

	/** @var GD_B2B_Admin|null */
	public ?GD_B2B_Admin $admin = null;

	/**
	 * Get or create the singleton instance.
	 *
	 * @return self
	 */
	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Private constructor — instantiate sub-modules.
	 */
	private function __construct() {
		$this->roles           = new GD_B2B_Roles();
		$this->email           = new GD_B2B_Email();
		$this->piva_validator  = new GD_B2B_PIVA_Validator();
		$this->audit_log       = new GD_B2B_Audit_Log();
		$this->registration    = new GD_B2B_Registration();
		$this->checkout_fields = new GD_B2B_Checkout_Fields();
		$this->account_ui      = new GD_B2B_Account_UI();
		$this->cart_blocker    = new GD_B2B_Cart_Blocker();
	}

	/**
	 * Initialize hooks for all modules.
	 */
	public function init(): void {
		$this->roles->register_cache_hooks();
		$this->registration->init();
		$this->checkout_fields->init();
		$this->account_ui->init();
		$this->cart_blocker->init();

		if ( is_admin() ) {
			if ( ! class_exists( 'WP_List_Table', false ) ) {
				require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
			}
			require_once GD_B2B_PATH . 'includes/admin/class-gd-b2b-admin.php';
			require_once GD_B2B_PATH . 'includes/admin/class-gd-b2b-admin-ajax.php';
			require_once GD_B2B_PATH . 'includes/admin/class-gd-b2b-admin-settings.php';
			require_once GD_B2B_PATH . 'includes/admin/class-gd-b2b-admin-email-templates.php';
			require_once GD_B2B_PATH . 'includes/admin/class-gd-b2b-clients-list-table.php';

			$this->admin = new GD_B2B_Admin();
			$this->admin->init();
		}
	}

	/**
	 * Prevent cloning.
	 */
	private function __clone() {}

	/**
	 * Prevent unserialization.
	 *
	 * @throws \Exception Always.
	 */
	public function __wakeup() {
		throw new \Exception( 'Cannot unserialize singleton' );
	}
}
