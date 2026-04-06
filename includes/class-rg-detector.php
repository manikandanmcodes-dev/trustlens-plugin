<?php
/**
 * Class RG_Detector
 *
 * Stateless rule engine that computes a customer's risk label
 * based on their refund statistics and configured thresholds.
 *
 * Rules are applied in order — the first matching rule wins:
 *   R1: return_rate >= abuser_rate  AND total_refunds >= abuser_count  → 'abuser'
 *   R2: return_rate >= susp_rate    OR  total_refunds >= susp_count
 *       OR total_refund_amount >= susp_value                           → 'suspicious'
 *   R3: (default)                                                      → 'safe'
 *
 * Overrides (checked before rules):
 *   - is_allowlisted = 1    → always 'safe'
 *   - is_manually_flagged = 1 → always 'suspicious'
 *
 * @package Return_Guard_WC
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class RG_Detector {

	/**
	 * Returns the configured threshold values from WordPress options.
	 *
	 * Falls back to hardcoded defaults if the option has not been set.
	 *
	 * @since  1.0.0
	 * @return array {
	 *     @type int   $abuser_rate_threshold      Abuser return rate (%).
	 *     @type int   $abuser_count_threshold     Abuser minimum refund count.
	 *     @type int   $suspicious_rate_threshold  Suspicious return rate (%).
	 *     @type int   $suspicious_count_threshold Suspicious minimum refund count.
	 *     @type float $suspicious_value_threshold Suspicious total refund value ($).
	 * }
	 */
	public static function get_thresholds() {
		return array(
			'abuser_rate_threshold'      => (int) get_option( 'rg_abuser_rate_threshold', 50 ),
			'abuser_count_threshold'     => (int) get_option( 'rg_abuser_count_threshold', 3 ),
			'suspicious_rate_threshold'  => (int) get_option( 'rg_suspicious_rate_threshold', 30 ),
			'suspicious_count_threshold' => (int) get_option( 'rg_suspicious_count_threshold', 2 ),
			'suspicious_value_threshold' => (float) get_option( 'rg_suspicious_value_threshold', 100 ),
		);
	}

	/**
	 * Computes the risk label for the given customer statistics.
	 *
	 * This is a pure function — it does not touch the database.
	 *
	 * @since  1.0.0
	 * @param  array $stats {
	 *     @type int   $total_refunds       Number of refunds issued.
	 *     @type float $total_refund_amount Total refund value in store currency.
	 *     @type float $return_rate         Percentage: (refunds / orders) * 100.
	 *     @type int   $is_allowlisted      1 if customer is on the allowlist.
	 *     @type int   $is_manually_flagged 1 if customer was manually flagged.
	 * }
	 * @return string One of 'safe', 'suspicious', or 'abuser'.
	 */
	public static function compute_label( array $stats ) {
		$total_refunds       = (int) $stats['total_refunds'];
		$total_refund_amount = (float) $stats['total_refund_amount'];
		$return_rate         = (float) $stats['return_rate'];
		$is_allowlisted      = (int) $stats['is_allowlisted'];
		$is_manually_flagged = (int) $stats['is_manually_flagged'];

		// Override: allowlisted customers are always safe.
		if ( 1 === $is_allowlisted ) {
			return 'safe';
		}

		// Override: manually flagged customers are always suspicious.
		if ( 1 === $is_manually_flagged ) {
			return 'suspicious';
		}

		$thresholds = self::get_thresholds();

		// Rule R1 — Abuser (both conditions must be true).
		if (
			$return_rate >= $thresholds['abuser_rate_threshold'] &&
			$total_refunds >= $thresholds['abuser_count_threshold']
		) {
			return 'abuser';
		}

		// Rule R2 — Suspicious (any one condition is enough).
		if (
			$return_rate >= $thresholds['suspicious_rate_threshold'] ||
			$total_refunds >= $thresholds['suspicious_count_threshold'] ||
			$total_refund_amount >= $thresholds['suspicious_value_threshold']
		) {
			return 'suspicious';
		}

		// Rule R3 — Default: Safe.
		return 'safe';
	}

	/**
	 * Fetches a customer's current stats from the DB, computes the label,
	 * and writes it back to the rg_customer_risk table.
	 *
	 * @since  1.0.0
	 * @param  int $customer_id The WP user ID.
	 * @return string|false     The computed label, or false if no record found.
	 */
	public static function compute_and_save_label( $customer_id ) {
		global $wpdb;

		$customer_id = absint( $customer_id );
		$table_name  = $wpdb->prefix . 'rg_customer_risk';

		// Fetch current stats for this customer.
		$record = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT total_refunds, total_refund_amount, return_rate, is_allowlisted, is_manually_flagged
				 FROM {$table_name}
				 WHERE customer_id = %d
				 LIMIT 1",
				$customer_id
			),
			ARRAY_A
		);

		if ( null === $record ) {
			return false;
		}

		// Compute the label using the pure rule engine.
		$label = self::compute_label( $record );

		// Persist the computed label.
		$wpdb->update(
			$table_name,
			array( 'risk_label' => $label ),
			array( 'customer_id' => $customer_id ),
			array( '%s' ),
			array( '%d' )
		);

		return $label;
	}
}
