<?php
/**
 * Class RG_Deactivator
 *
 * Handles plugin deactivation tasks. Data and table are preserved
 * on deactivation — they are only removed on full uninstall via uninstall.php.
 *
 * @package Return_Guard_WC
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class RG_Deactivator {

	/**
	 * Runs on plugin deactivation.
	 *
	 * Currently a no-op. Future deactivation tasks (e.g. clearing scheduled
	 * cron events) can be added here.
	 *
	 * @since  1.0.0
	 * @return void
	 */
	public static function deactivate() {
		// Intentionally left blank.
		// All data is preserved on deactivation (only removed on uninstall).
	}
}
