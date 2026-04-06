<?php
/**
 * Class RG_Admin
 *
 * Registers the plugin's admin menu pages (dashboard + settings)
 * and enqueues admin-only CSS and JavaScript assets.
 *
 * @package Return_Guard_WC
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class RG_Admin {

	/**
	 * Constructor. Hooks into WordPress admin actions.
	 *
	 * @since 1.0.0
	 */
	public function __construct() {
		add_action( 'admin_menu',    array( $this, 'register_menus' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
	}

	// ─────────────────────────────────────────────────────────────────────────
	// Menu Registration
	// ─────────────────────────────────────────────────────────────────────────

	/**
	 * Registers the top-level Return Guard menu and the Settings submenu.
	 *
	 * The dashboard is the default landing page; settings are a submenu.
	 *
	 * @since  1.0.0
	 * @return void
	 */
	public function register_menus() {
		// Top-level menu — points to the dashboard.
		add_menu_page(
			__( 'Return Guard', 'return-guard-wc' ),       // Page title.
			__( 'Return Guard', 'return-guard-wc' ),       // Menu label.
			'manage_woocommerce',                           // Capability.
			'return-guard',                                 // Menu slug.
			array( $this, 'render_dashboard_page' ),       // Callback.
			'dashicons-shield-alt',                         // Icon.
			56                                              // Position (after WooCommerce).
		);

		// Submenu: Dashboard (explicit, so the label is "Dashboard" not "Return Guard").
		add_submenu_page(
			'return-guard',
			__( 'Return Guard — Dashboard', 'return-guard-wc' ),
			__( 'Dashboard', 'return-guard-wc' ),
			'manage_woocommerce',
			'return-guard',
			array( $this, 'render_dashboard_page' )
		);

		// Submenu: Settings.
		add_submenu_page(
			'return-guard',
			__( 'Return Guard — Settings', 'return-guard-wc' ),
			__( 'Settings', 'return-guard-wc' ),
			'manage_woocommerce',
			'return-guard-settings',
			array( $this, 'render_settings_page' )
		);
	}

	// ─────────────────────────────────────────────────────────────────────────
	// Page Render Callbacks
	// ─────────────────────────────────────────────────────────────────────────

	/**
	 * Renders the main dashboard page by delegating to RG_Dashboard.
	 *
	 * @since  1.0.0
	 * @return void
	 */
	public function render_dashboard_page() {
		require_once RG_PLUGIN_DIR . 'admin/class-rg-dashboard.php';
		$dashboard = new RG_Dashboard();
		$dashboard->render();
	}

	/**
	 * Renders the settings page by delegating to RG_Settings.
	 *
	 * @since  1.0.0
	 * @return void
	 */
	public function render_settings_page() {
		require_once RG_PLUGIN_DIR . 'admin/class-rg-settings.php';
		$settings = new RG_Settings();
		$settings->render_page();
	}

	// ─────────────────────────────────────────────────────────────────────────
	// Asset Enqueuing
	// ─────────────────────────────────────────────────────────────────────────

	/**
	 * Enqueues Return Guard CSS and JS — only on plugin admin pages.
	 *
	 * Also localises the JS file with the AJAX URL and a fresh nonce.
	 *
	 * @since  1.0.0
	 * @param  string $hook The current admin page hook suffix.
	 * @return void
	 */
	public function enqueue_assets( $hook ) {
		// Only load on Return Guard pages.
		$rg_pages = array(
			'toplevel_page_return-guard',
			'return-guard_page_return-guard-settings',
		);

		// Also load on WooCommerce order edit pages for the meta box.
		$is_order_page = (
			'post.php' === $hook &&
			isset( $_GET['post'] ) &&
			'shop_order' === get_post_type( absint( $_GET['post'] ) )
		) || (
			// HPOS order page.
			'woocommerce_page_wc-orders' === $hook
		);

		if ( ! in_array( $hook, $rg_pages, true ) && ! $is_order_page ) {
			return;
		}

		// Stylesheet.
		wp_enqueue_style(
			'rg-admin',
			RG_PLUGIN_URL . 'assets/css/rg-admin.css',
			array(),
			RG_VERSION
		);

		// Script.
		wp_enqueue_script(
			'rg-admin',
			RG_PLUGIN_URL . 'assets/js/rg-admin.js',
			array( 'jquery' ),
			RG_VERSION,
			true // Load in footer.
		);

		// Localise data for the JS file.
		wp_localize_script(
			'rg-admin',
			'rgAdmin',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'rg_action_nonce' ),
				'strings' => array(
					'confirm_block_cod'  => __( 'Block this customer from paying with Cash on Delivery?', 'return-guard-wc' ),
					'confirm_mark_risky' => __( 'Manually mark this customer as risky (Suspicious)?', 'return-guard-wc' ),
					'confirm_allowlist'  => __( 'Remove all flags from this customer and mark them as Safe?', 'return-guard-wc' ),
					'error_generic'      => __( 'Something went wrong. Please try again.', 'return-guard-wc' ),
				),
			)
		);
	}
}
