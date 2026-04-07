<?php
/**
 * Plugin Name:       Return Guard for WooCommerce
 * Plugin URI:        https://example.com/return-guard-wc
 * Description:       Detects and manages return/refund abuse in WooCommerce. Labels customers as Safe, Suspicious, or Abuser based on configurable thresholds.
 * Version:           1.0.0
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * Author:            Return Guard
 * Author URI:        https://example.com
 * License:           GPL-2.0+
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       return-guard-wc
 * Domain Path:       /languages
 * WC requires at least: 6.0
 * WC tested up to:      8.0
 *
 * @package Return_Guard_WC
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// ─── Constants ───────────────────────────────────────────────────────────────

define( 'RG_VERSION',     '1.0.0' );
define( 'RG_PLUGIN_FILE', __FILE__ );
define( 'RG_PLUGIN_DIR',  plugin_dir_path( __FILE__ ) );
define( 'RG_PLUGIN_URL',  plugin_dir_url( __FILE__ ) );

// ─── Activation / Deactivation Hooks ─────────────────────────────────────────

function rg_activate_plugin() {
	require_once RG_PLUGIN_DIR . 'includes/class-rg-activator.php';
	RG_Activator::activate();
}
register_activation_hook( __FILE__, 'rg_activate_plugin' );

function rg_deactivate_plugin() {
	require_once RG_PLUGIN_DIR . 'includes/class-rg-deactivator.php';
	RG_Deactivator::deactivate();
}
register_deactivation_hook( __FILE__, 'rg_deactivate_plugin' );

// ─── Requirement Checks ───────────────────────────────────────────────────────

/**
 * Displays an admin notice when requirements are not met.
 *
 * @since 1.0.0
 * @param string $message The notice message.
 * @return void
 */
function rg_admin_notice_requirements( $message ) {
	echo '<div class="notice notice-error"><p>' . esc_html( $message ) . '</p></div>';
}

/**
 * Checks PHP, WordPress, and WooCommerce version requirements.
 * Deactivates the plugin and shows an admin notice if unmet.
 *
 * @since 1.0.0
 * @return bool True if all requirements are met.
 */
function rg_check_requirements() {
	// PHP version check.
	if ( version_compare( PHP_VERSION, '7.4', '<' ) ) {
		add_action(
			'admin_notices',
			function () {
				rg_admin_notice_requirements(
					sprintf(
						/* translators: %s: current PHP version */
						__( 'Return Guard for WooCommerce requires PHP 7.4 or higher. Your site is running PHP %s.', 'return-guard-wc' ),
						PHP_VERSION
					)
				);
			}
		);
		deactivate_plugins( plugin_basename( __FILE__ ) );
		return false;
	}

	// WooCommerce check.
	if ( ! class_exists( 'WooCommerce' ) ) {
		add_action(
			'admin_notices',
			function () {
				rg_admin_notice_requirements(
					__( 'Return Guard for WooCommerce requires WooCommerce 6.0 or higher to be installed and active.', 'return-guard-wc' )
				);
			}
		);
		deactivate_plugins( plugin_basename( __FILE__ ) );
		return false;
	}

	// WooCommerce minimum version check.
	if ( defined( 'WC_VERSION' ) && version_compare( WC_VERSION, '6.0', '<' ) ) {
		add_action(
			'admin_notices',
			function () {
				rg_admin_notice_requirements(
					sprintf(
						/* translators: %s: current WooCommerce version */
						__( 'Return Guard for WooCommerce requires WooCommerce 6.0 or higher. Your site is running WooCommerce %s.', 'return-guard-wc' ),
						WC_VERSION
					)
				);
			}
		);
		deactivate_plugins( plugin_basename( __FILE__ ) );
		return false;
	}

	return true;
}

// ─── Plugin Initialisation ────────────────────────────────────────────────────

/**
 * Loads plugin files and instantiates core classes.
 *
 * Fired on 'plugins_loaded' to ensure WooCommerce is available.
 *
 * @since 1.0.0
 * @return void
 */
function rg_init_plugin() {
	// Run requirement checks first.
	if ( ! rg_check_requirements() ) {
		return;
	}

	// Load text domain for translations.
	load_plugin_textdomain( 'return-guard-wc', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );

	// Load included class files.
	require_once RG_PLUGIN_DIR . 'includes/class-rg-activator.php';
	require_once RG_PLUGIN_DIR . 'includes/class-rg-deactivator.php';
	require_once RG_PLUGIN_DIR . 'includes/class-rg-detector.php';
	require_once RG_PLUGIN_DIR . 'includes/class-rg-tracker.php';
	require_once RG_PLUGIN_DIR . 'includes/class-rg-actions.php';
	require_once RG_PLUGIN_DIR . 'includes/class-rg-cod-blocker.php';

	// Load admin class files.
	require_once RG_PLUGIN_DIR . 'admin/class-rg-admin.php';
	require_once RG_PLUGIN_DIR . 'admin/class-rg-dashboard.php';
	require_once RG_PLUGIN_DIR . 'admin/class-rg-settings.php';
	require_once RG_PLUGIN_DIR . 'admin/class-rg-order-metabox.php';

	// Instantiate and initialise core plugin classes.
	new RG_Admin();
	new RG_Tracker();
	new RG_Actions();
	new RG_COD_Blocker();
	new RG_Order_Metabox();
}
add_action( 'plugins_loaded', 'rg_init_plugin' );

// ─── HPOS Compatibility Declaration ──────────────────────────────────────────

add_action(
	'before_woocommerce_init',
	function () {
		if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
				'custom_order_tables',
				__FILE__,
				true
			);
		}
	}
);
