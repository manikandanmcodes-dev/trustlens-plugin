<?php
/**
 * Class RG_Tracker
 *
 * Listens for WooCommerce refund events and writes customer risk data
 * to the rg_customer_risk table. After every update, it calls the
 * RG_Detector to recompute and persist the customer's risk label.
 *
 * @package Return_Guard_WC
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class RG_Tracker {

	/**
	 * Constructor. Registers WooCommerce refund hooks.
	 *
	 * @since 1.0.0
	 */
	public function __construct() {
		add_action( 'woocommerce_order_refunded', array( $this, 'on_refund_created' ), 10, 2 );
		add_action( 'woocommerce_refund_deleted',  array( $this, 'on_refund_deleted' ),  10, 2 );
	}

	// ─────────────────────────────────────────────────────────────────────────
	// Hook Callbacks
	// ─────────────────────────────────────────────────────────────────────────

	/**
	 * Triggered when a refund is created in WooCommerce.
	 *
	 * Fetches or creates the customer's risk record, recomputes all stats
	 * from live order data, and re-runs the detection rule engine.
	 *
	 * @since  1.0.0
	 * @param  int $order_id  The WooCommerce order ID.
	 * @param  int $refund_id The WooCommerce refund post ID.
	 * @return void
	 */
	public function on_refund_created( $order_id, $refund_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}

		$customer_id    = (int) $order->get_customer_id();
		$customer_email = sanitize_email( $order->get_billing_email() );

		// Ensure a row exists for this customer.
		$this->get_or_create_record( $customer_id, $customer_email );

		// Recompute stats from live WC data and persist.
		$this->recompute_stats( $customer_id, $customer_email );

		// Re-run the detection rule engine.
		RG_Detector::compute_and_save_label( $customer_id );
	}

	/**
	 * Triggered when a refund is deleted in WooCommerce.
	 *
	 * Recomputes all stats for the customer so the record stays accurate.
	 *
	 * @since  1.0.0
	 * @param  int $refund_id The WooCommerce refund post ID.
	 * @param  int $order_id  The parent order ID.
	 * @return void
	 */
	public function on_refund_deleted( $refund_id, $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}

		$customer_id    = (int) $order->get_customer_id();
		$customer_email = sanitize_email( $order->get_billing_email() );

		// A refund was deleted, so stats need to be re-derived from live data.
		$this->recompute_stats( $customer_id, $customer_email );

		// Re-run the detection rule engine.
		RG_Detector::compute_and_save_label( $customer_id );
	}

	// ─────────────────────────────────────────────────────────────────────────
	// Internal Helpers
	// ─────────────────────────────────────────────────────────────────────────

	/**
	 * Returns the existing risk record for a customer, or inserts a blank row.
	 *
	 * For guests (customer_id = 0), the row key falls back to customer_email.
	 *
	 * @since  1.0.0
	 * @param  int    $customer_id    The WP user ID (0 for guests).
	 * @param  string $customer_email The billing email address.
	 * @return object|false           The DB row object, or false on failure.
	 */
	public function get_or_create_record( $customer_id, $customer_email ) {
		global $wpdb;

		$table = $wpdb->prefix . 'rg_customer_risk';

		// Try to fetch an existing record.
		if ( $customer_id > 0 ) {
			$record = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT * FROM {$table} WHERE customer_id = %d LIMIT 1",
					$customer_id
				)
			);
		} else {
			// Guest: look up by email.
			$record = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT * FROM {$table} WHERE customer_email = %s AND customer_id = 0 LIMIT 1",
					$customer_email
				)
			);
		}

		if ( $record ) {
			return $record;
		}

		// No existing record — insert a blank placeholder row.
		$wpdb->insert(
			$table,
			array(
				'customer_id'    => $customer_id,
				'customer_email' => $customer_email,
			),
			array( '%d', '%s' )
		);

		return $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE id = %d LIMIT 1",
				$wpdb->insert_id
			)
		);
	}

	/**
	 * Recomputes total_orders, total_refunds, total_refund_amount, and
	 * return_rate for a customer by querying WooCommerce order data directly.
	 *
	 * Uses the HPOS-compatible wc_get_orders() approach where possible,
	 * falling back to a direct $wpdb query for performance on large stores.
	 *
	 * @since  1.0.0
	 * @param  int    $customer_id    The WP user ID (0 for guests).
	 * @param  string $customer_email The billing email address.
	 * @return void
	 */
	public function recompute_stats( $customer_id, $customer_email ) {
		global $wpdb;

		$table = $wpdb->prefix . 'rg_customer_risk';

		// ── Count completed orders ────────────────────────────────────────────
		// We query the WC orders table directly for performance.
		// Supports both HPOS and classic post-based storage.
		if ( $this->is_hpos_enabled() ) {
			$orders_table    = $wpdb->prefix . 'wc_orders';
			$meta_table      = $wpdb->prefix . 'wc_orders_meta';

			if ( $customer_id > 0 ) {
				$total_orders = (int) $wpdb->get_var(
					$wpdb->prepare(
						"SELECT COUNT(id) FROM {$orders_table}
						 WHERE customer_id = %d
						   AND status IN ('wc-completed','wc-processing','wc-refunded')",
						$customer_id
					)
				);

				$total_refunds = (int) $wpdb->get_var(
					$wpdb->prepare(
						"SELECT COUNT(id) FROM {$orders_table}
						 WHERE customer_id = %d
						   AND type = 'shop_order_refund'",
						$customer_id
					)
				);

				$total_refund_amount = (float) $wpdb->get_var(
					$wpdb->prepare(
						"SELECT COALESCE(SUM(ABS(total_amount)), 0)
						 FROM {$orders_table}
						 WHERE customer_id = %d
						   AND type = 'shop_order_refund'",
						$customer_id
					)
				);
			} else {
				// Guest lookup by email via meta.
				$total_orders = (int) $wpdb->get_var(
					$wpdb->prepare(
						"SELECT COUNT(o.id) FROM {$orders_table} o
						 INNER JOIN {$meta_table} m ON o.id = m.order_id
						 WHERE m.meta_key = '_billing_email' AND m.meta_value = %s
						   AND o.status IN ('wc-completed','wc-processing','wc-refunded')
						   AND o.type = 'shop_order'",
						$customer_email
					)
				);

				$total_refunds = 0;
				$total_refund_amount = 0.00;
				// Guest refund tracking is limited — flag but don't crash.
			}
		} else {
			// Classic post-based WooCommerce.
			if ( $customer_id > 0 ) {
				$total_orders = (int) $wpdb->get_var(
					$wpdb->prepare(
						"SELECT COUNT(p.ID) FROM {$wpdb->posts} p
						 INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
						 WHERE pm.meta_key = '_customer_user' AND pm.meta_value = %d
						   AND p.post_type = 'shop_order'
						   AND p.post_status IN ('wc-completed','wc-processing','wc-refunded')",
						$customer_id
					)
				);

				$total_refunds = (int) $wpdb->get_var(
					$wpdb->prepare(
						"SELECT COUNT(r.ID) FROM {$wpdb->posts} r
						 INNER JOIN {$wpdb->posts} o ON r.post_parent = o.ID
						 INNER JOIN {$wpdb->postmeta} pm ON o.ID = pm.post_id
						 WHERE pm.meta_key = '_customer_user' AND pm.meta_value = %d
						   AND r.post_type = 'shop_order_refund'
						   AND r.post_status = 'wc-completed'",
						$customer_id
					)
				);

				$total_refund_amount = (float) $wpdb->get_var(
					$wpdb->prepare(
						"SELECT COALESCE(SUM(ABS(pm2.meta_value)), 0)
						 FROM {$wpdb->posts} r
						 INNER JOIN {$wpdb->posts} o   ON r.post_parent = o.ID
						 INNER JOIN {$wpdb->postmeta} pm  ON o.ID = pm.post_id
						 INNER JOIN {$wpdb->postmeta} pm2 ON r.ID = pm2.post_id
						 WHERE pm.meta_key  = '_customer_user' AND pm.meta_value = %d
						   AND pm2.meta_key = '_refund_amount'
						   AND r.post_type = 'shop_order_refund'
						   AND r.post_status = 'wc-completed'",
						$customer_id
					)
				);
			} else {
				// Guest: look up by billing email.
				$total_orders = (int) $wpdb->get_var(
					$wpdb->prepare(
						"SELECT COUNT(p.ID) FROM {$wpdb->posts} p
						 INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
						 WHERE pm.meta_key = '_billing_email' AND pm.meta_value = %s
						   AND p.post_type = 'shop_order'
						   AND p.post_status IN ('wc-completed','wc-processing','wc-refunded')",
						$customer_email
					)
				);

				$total_refunds       = 0;
				$total_refund_amount = 0.00;
				// Guest COD blocking is unavailable anyway (requires user ID).
			}
		}

		// ── Compute return rate ───────────────────────────────────────────────
		$return_rate = ( $total_orders > 0 )
			? round( ( $total_refunds / $total_orders ) * 100, 2 )
			: 0.00;

		// ── Fetch last refund date ────────────────────────────────────────────
		$last_refund_date = current_time( 'mysql' );

		// ── Persist to rg_customer_risk ───────────────────────────────────────
		$this->update_risk_record(
			$customer_id,
			array(
				'total_orders'        => $total_orders,
				'total_refunds'       => $total_refunds,
				'total_refund_amount' => $total_refund_amount,
				'return_rate'         => $return_rate,
				'last_refund_date'    => $last_refund_date,
			)
		);
	}

	/**
	 * Updates the rg_customer_risk row for the given customer.
	 *
	 * @since  1.0.0
	 * @param  int   $customer_id The WP user ID.
	 * @param  array $data        Column => value pairs to update.
	 * @return void
	 */
	public function update_risk_record( $customer_id, array $data ) {
		global $wpdb;

		$wpdb->update(
			$wpdb->prefix . 'rg_customer_risk',
			$data,
			array( 'customer_id' => absint( $customer_id ) ),
			null,       // WP will auto-detect format from data types.
			array( '%d' )
		);
	}

	/**
	 * Checks whether WooCommerce High Performance Order Storage (HPOS) is enabled.
	 *
	 * @since  1.0.0
	 * @return bool
	 */
	private function is_hpos_enabled() {
		if ( class_exists( '\Automattic\WooCommerce\Utilities\OrderUtil' ) ) {
			return \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
		}
		return false;
	}
}
