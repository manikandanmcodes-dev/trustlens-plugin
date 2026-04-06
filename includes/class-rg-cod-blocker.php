<?php
/**
 * Class RG_COD_Blocker
 *
 * Hooks into the WooCommerce payment gateway filter and removes the
 * Cash on Delivery gateway for customers who have been flagged as blocked.
 *
 * A static variable caches the DB lookup result so the query runs
 * at most once per page request.
 *
 * @package Return_Guard_WC
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class RG_COD_Blocker {

	/**
	 * Constructor. Registers the payment gateway filter.
	 *
	 * @since 1.0.0
	 */
	public function __construct() {
		add_filter( 'woocommerce_available_payment_gateways', array( $this, 'maybe_remove_cod' ), 10, 1 );
	}

	/**
	 * Removes the COD gateway from the checkout for blocked customers.
	 *
	 * Short-circuits if:
	 *   - COD blocking is disabled in settings.
	 *   - The current user is not logged in (guests cannot be blocked by ID).
	 *   - The customer is not flagged as COD-blocked in the DB.
	 *
	 * @since  1.0.0
	 * @param  array $available_gateways Associative array of gateway ID => WC_Payment_Gateway.
	 * @return array Filtered gateway list.
	 */
	public function maybe_remove_cod( $available_gateways ) {
		// 1. Check if the feature is enabled in settings.
		if ( 'yes' !== get_option( 'rg_enable_cod_blocking', 'yes' ) ) {
			return $available_gateways;
		}

		// 2. Only applies to logged-in users — guests cannot be blocked by user ID.
		if ( ! is_user_logged_in() ) {
			return $available_gateways;
		}

		$user_id = get_current_user_id();

		// 3. Perform a single cached DB lookup per request.
		if ( $this->is_customer_cod_blocked( $user_id ) ) {
			unset( $available_gateways['cod'] );
		}

		return $available_gateways;
	}

	/**
	 * Checks whether a customer is flagged for COD blocking.
	 *
	 * Uses a static cache so the DB is queried at most once per request,
	 * even if the filter runs multiple times.
	 *
	 * @since  1.0.0
	 * @param  int  $user_id The WP user ID.
	 * @return bool          True if the customer is COD-blocked.
	 */
	private function is_customer_cod_blocked( $user_id ) {
		// Static cache: keyed by user ID.
		static $cache = array();

		if ( isset( $cache[ $user_id ] ) ) {
			return $cache[ $user_id ];
		}

		global $wpdb;

		$result = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT is_cod_blocked
				 FROM {$wpdb->prefix}rg_customer_risk
				 WHERE customer_id = %d
				   AND is_cod_blocked = 1
				 LIMIT 1",
				$user_id
			)
		);

		$cache[ $user_id ] = ( '1' === (string) $result );

		return $cache[ $user_id ];
	}
}
