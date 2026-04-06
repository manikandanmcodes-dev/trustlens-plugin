<?php
/**
 * Class RG_Dashboard
 *
 * Queries rg_customer_risk and renders the main Return Guard admin
 * dashboard: total revenue lost, top abusers, recent risky orders,
 * and a full flagged-customers table with action buttons.
 *
 * All output is escaped. No inline styles or scripts.
 *
 * @package Return_Guard_WC
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class RG_Dashboard {

	// ─────────────────────────────────────────────────────────────────────────
	// Data Queries
	// ─────────────────────────────────────────────────────────────────────────

	/**
	 * Returns the total refund amount across all customers.
	 *
	 * @since  1.0.0
	 * @return float
	 */
	public function get_total_loss() {
		global $wpdb;
		$result = $wpdb->get_var(
			"SELECT COALESCE(SUM(total_refund_amount), 0) FROM {$wpdb->prefix}rg_customer_risk"
		);
		return (float) $result;
	}

	/**
	 * Returns the top N customers ordered by total refund amount (Abuser label only).
	 *
	 * @since  1.0.0
	 * @param  int   $limit Maximum number of rows to return.
	 * @return array        Array of DB row objects.
	 */
	public function get_top_abusers( $limit = 5 ) {
		global $wpdb;
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}rg_customer_risk
				 WHERE risk_label = 'abuser'
				 ORDER BY total_refund_amount DESC
				 LIMIT %d",
				$limit
			)
		);
	}

	/**
	 * Returns recent orders (last 30 days) whose customer is Suspicious or Abuser.
	 *
	 * Supports both HPOS and classic posts.
	 *
	 * @since  1.0.0
	 * @param  int   $limit Maximum rows to return.
	 * @return array        Array of objects with order data.
	 */
	public function get_recent_risky_orders( $limit = 10 ) {
		global $wpdb;

		$since = gmdate( 'Y-m-d H:i:s', strtotime( '-30 days' ) );

		if ( $this->is_hpos_enabled() ) {
			$orders_table = $wpdb->prefix . 'wc_orders';
			$risk_table   = $wpdb->prefix . 'rg_customer_risk';

			return $wpdb->get_results(
				$wpdb->prepare(
					"SELECT o.id AS order_id,
					        o.billing_first_name,
					        o.billing_last_name,
					        o.billing_email,
					        o.date_created_gmt AS order_date,
					        r.total_refund_amount,
					        r.risk_label
					 FROM {$orders_table} o
					 INNER JOIN {$risk_table} r ON o.customer_id = r.customer_id
					 WHERE r.risk_label IN ('suspicious','abuser')
					   AND o.date_created_gmt >= %s
					   AND o.type = 'shop_order'
					 ORDER BY o.date_created_gmt DESC
					 LIMIT %d",
					$since,
					$limit
				)
			);
		}

		// Classic post-based orders.
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT p.ID AS order_id,
				        pm_first.meta_value  AS billing_first_name,
				        pm_last.meta_value   AS billing_last_name,
				        pm_email.meta_value  AS billing_email,
				        p.post_date          AS order_date,
				        r.total_refund_amount,
				        r.risk_label
				 FROM {$wpdb->posts} p
				 INNER JOIN {$wpdb->postmeta} pm_cust  ON p.ID = pm_cust.post_id
				   AND pm_cust.meta_key = '_customer_user'
				 INNER JOIN {$wpdb->prefix}rg_customer_risk r
				   ON CAST(pm_cust.meta_value AS UNSIGNED) = r.customer_id
				 LEFT JOIN {$wpdb->postmeta} pm_first ON p.ID = pm_first.post_id
				   AND pm_first.meta_key = '_billing_first_name'
				 LEFT JOIN {$wpdb->postmeta} pm_last  ON p.ID = pm_last.post_id
				   AND pm_last.meta_key = '_billing_last_name'
				 LEFT JOIN {$wpdb->postmeta} pm_email ON p.ID = pm_email.post_id
				   AND pm_email.meta_key = '_billing_email'
				 WHERE r.risk_label IN ('suspicious','abuser')
				   AND p.post_type = 'shop_order'
				   AND p.post_date >= %s
				 ORDER BY p.post_date DESC
				 LIMIT %d",
				$since,
				$limit
			)
		);
	}

	/**
	 * Returns all customers who are not labeled 'safe'.
	 *
	 * @since  1.0.0
	 * @return array Array of DB row objects.
	 */
	public function get_all_risky_customers() {
		global $wpdb;
		return $wpdb->get_results(
			"SELECT * FROM {$wpdb->prefix}rg_customer_risk
			 WHERE risk_label != 'safe'
			 ORDER BY total_refund_amount DESC"
		);
	}

	// ─────────────────────────────────────────────────────────────────────────
	// Render
	// ─────────────────────────────────────────────────────────────────────────

	/**
	 * Outputs the full dashboard HTML.
	 *
	 * @since  1.0.0
	 * @return void
	 */
	public function render() {
		$total_loss    = $this->get_total_loss();
		$top_abusers   = $this->get_top_abusers( 5 );
		$recent_orders = $this->get_recent_risky_orders( 10 );
		$all_risky     = $this->get_all_risky_customers();
		?>
		<div class="wrap rg-wrap">

			<h1 class="wp-heading-inline">
				<?php esc_html_e( 'Return Guard — Dashboard', 'return-guard-wc' ); ?>
			</h1>
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=return-guard-settings' ) ); ?>" class="page-title-action">
				<?php esc_html_e( 'Settings', 'return-guard-wc' ); ?>
			</a>
			<hr class="wp-header-end">

			<?php /* ── STAT BOX ── */ ?>
			<div class="rg-stat-box">
				<span class="rg-stat-label"><?php esc_html_e( 'Total Revenue Lost to Refunds', 'return-guard-wc' ); ?></span>
				<span class="rg-stat-amount"><?php echo wp_kses_post( wc_price( $total_loss ) ); ?></span>
			</div>

			<?php /* ── TWO-COLUMN ROW ── */ ?>
			<div class="rg-two-col">

				<?php /* ── TOP ABUSERS ── */ ?>
				<div class="rg-panel">
					<h2><?php esc_html_e( 'Top Abusers', 'return-guard-wc' ); ?></h2>
					<?php if ( empty( $top_abusers ) ) : ?>
						<p class="rg-empty"><?php esc_html_e( '🎉 No abusers detected yet.', 'return-guard-wc' ); ?></p>
					<?php else : ?>
						<table class="rg-table widefat striped">
							<thead>
								<tr>
									<th><?php esc_html_e( 'Customer', 'return-guard-wc' ); ?></th>
									<th><?php esc_html_e( 'Refunds', 'return-guard-wc' ); ?></th>
									<th><?php esc_html_e( 'Total Lost', 'return-guard-wc' ); ?></th>
									<th><?php esc_html_e( 'Label', 'return-guard-wc' ); ?></th>
								</tr>
							</thead>
							<tbody>
								<?php foreach ( $top_abusers as $row ) : ?>
									<tr>
										<td>
											<?php echo esc_html( $this->get_customer_display_name( $row->customer_id, $row->customer_email ) ); ?>
											<br><small><?php echo esc_html( $row->customer_email ); ?></small>
										</td>
										<td><?php echo esc_html( $row->total_refunds ); ?></td>
										<td><?php echo wp_kses_post( wc_price( $row->total_refund_amount ) ); ?></td>
										<td><?php echo $this->render_badge( $row->risk_label ); // phpcs:ignore WordPress.Security.EscapeOutput ?></td>
									</tr>
								<?php endforeach; ?>
							</tbody>
						</table>
					<?php endif; ?>
				</div>

				<?php /* ── RECENT RISKY ORDERS ── */ ?>
				<div class="rg-panel">
					<h2><?php esc_html_e( 'Recent Risky Orders (Last 30 Days)', 'return-guard-wc' ); ?></h2>
					<?php if ( empty( $recent_orders ) ) : ?>
						<p class="rg-empty"><?php esc_html_e( '🎉 No risky orders in the last 30 days.', 'return-guard-wc' ); ?></p>
					<?php else : ?>
						<table class="rg-table widefat striped">
							<thead>
								<tr>
									<th><?php esc_html_e( 'Order', 'return-guard-wc' ); ?></th>
									<th><?php esc_html_e( 'Customer', 'return-guard-wc' ); ?></th>
									<th><?php esc_html_e( 'Date', 'return-guard-wc' ); ?></th>
									<th><?php esc_html_e( 'Label', 'return-guard-wc' ); ?></th>
								</tr>
							</thead>
							<tbody>
								<?php foreach ( $recent_orders as $order ) : ?>
									<tr>
										<td>
											<a href="<?php echo esc_url( $this->get_order_edit_url( $order->order_id ) ); ?>">
												#<?php echo esc_html( $order->order_id ); ?>
											</a>
										</td>
										<td>
											<?php echo esc_html( trim( $order->billing_first_name . ' ' . $order->billing_last_name ) ); ?>
											<br><small><?php echo esc_html( $order->billing_email ); ?></small>
										</td>
										<td><?php echo esc_html( wp_date( get_option( 'date_format' ), strtotime( $order->order_date ) ) ); ?></td>
										<td><?php echo $this->render_badge( $order->risk_label ); // phpcs:ignore WordPress.Security.EscapeOutput ?></td>
									</tr>
								<?php endforeach; ?>
							</tbody>
						</table>
					<?php endif; ?>
				</div>

			</div><?php /* .rg-two-col */ ?>

			<?php /* ── ALL FLAGGED CUSTOMERS ── */ ?>
			<div class="rg-panel rg-panel--full">
				<h2><?php esc_html_e( 'All Flagged Customers', 'return-guard-wc' ); ?></h2>
				<?php if ( empty( $all_risky ) ) : ?>
					<p class="rg-empty"><?php esc_html_e( '🎉 Great news — no return abusers detected yet.', 'return-guard-wc' ); ?></p>
				<?php else : ?>
					<div class="rg-table-wrap">
						<table class="rg-table widefat striped">
							<thead>
								<tr>
									<th><?php esc_html_e( 'Customer', 'return-guard-wc' ); ?></th>
									<th><?php esc_html_e( 'Label', 'return-guard-wc' ); ?></th>
									<th><?php esc_html_e( '# Refunds', 'return-guard-wc' ); ?></th>
									<th><?php esc_html_e( 'Total Refunded', 'return-guard-wc' ); ?></th>
									<th><?php esc_html_e( 'Return Rate', 'return-guard-wc' ); ?></th>
									<th><?php esc_html_e( 'Actions', 'return-guard-wc' ); ?></th>
								</tr>
							</thead>
							<tbody>
								<?php foreach ( $all_risky as $row ) : ?>
									<tr id="rg-customer-row-<?php echo esc_attr( $row->customer_id ); ?>">
										<td>
											<?php if ( $row->customer_id > 0 ) : ?>
												<a href="<?php echo esc_url( get_edit_user_link( $row->customer_id ) ); ?>">
													<?php echo esc_html( $this->get_customer_display_name( $row->customer_id, $row->customer_email ) ); ?>
												</a>
											<?php else : ?>
												<?php echo esc_html( $this->get_customer_display_name( $row->customer_id, $row->customer_email ) ); ?>
											<?php endif; ?>
											<br><small><?php echo esc_html( $row->customer_email ); ?></small>
										</td>
										<td class="rg-label-cell">
											<?php echo $this->render_badge( $row->risk_label ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
										</td>
										<td><?php echo esc_html( $row->total_refunds ); ?></td>
										<td><?php echo wp_kses_post( wc_price( $row->total_refund_amount ) ); ?></td>
										<td><?php echo esc_html( $row->return_rate ); ?>%</td>
										<td>
											<?php echo $this->render_action_buttons( $row ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
										</td>
									</tr>
								<?php endforeach; ?>
							</tbody>
						</table>
					</div>
				<?php endif; ?>
			</div>

		</div><?php /* .wrap */ ?>
		<?php
	}

	// ─────────────────────────────────────────────────────────────────────────
	// Render Helpers
	// ─────────────────────────────────────────────────────────────────────────

	/**
	 * Returns a coloured risk badge span.
	 *
	 * All output is escaped internally.
	 *
	 * @since  1.0.0
	 * @param  string $label One of 'safe', 'suspicious', 'abuser'.
	 * @return string        Safe HTML string.
	 */
	public function render_badge( $label ) {
		$map = array(
			'safe'       => __( 'Safe', 'return-guard-wc' ),
			'suspicious' => __( 'Suspicious', 'return-guard-wc' ),
			'abuser'     => __( 'Abuser', 'return-guard-wc' ),
		);
		$text = $map[ $label ] ?? ucfirst( esc_html( $label ) );
		return '<span class="rg-badge rg-badge--' . esc_attr( $label ) . '">' . esc_html( $text ) . '</span>';
	}

	/**
	 * Renders the three action buttons for a customer row.
	 *
	 * Buttons are disabled if the relevant state is already applied.
	 *
	 * @since  1.0.0
	 * @param  object $row A rg_customer_risk DB row.
	 * @return string      Safe HTML string.
	 */
	private function render_action_buttons( $row ) {
		$customer_id   = absint( $row->customer_id );
		$is_blocked    = ( '1' === (string) $row->is_cod_blocked );
		$is_flagged    = ( '1' === (string) $row->is_manually_flagged );
		$is_allowlisted = ( '1' === (string) $row->is_allowlisted );

		$nonce = wp_create_nonce( 'rg_action_nonce' );

		$html  = '<div class="rg-actions">';

		// Block COD.
		$html .= sprintf(
			'<button class="button rg-btn rg-btn--danger rg-btn-block-cod" data-customer-id="%d" data-nonce="%s" %s>%s</button>',
			$customer_id,
			esc_attr( $nonce ),
			$is_blocked ? 'disabled' : '',
			$is_blocked
				? esc_html__( 'COD Blocked', 'return-guard-wc' )
				: esc_html__( 'Block COD', 'return-guard-wc' )
		);

		// Mark Risky.
		$html .= sprintf(
			'<button class="button rg-btn rg-btn--warning rg-btn-mark-risky" data-customer-id="%d" data-nonce="%s" %s>%s</button>',
			$customer_id,
			esc_attr( $nonce ),
			$is_flagged ? 'disabled' : '',
			$is_flagged
				? esc_html__( 'Marked Risky', 'return-guard-wc' )
				: esc_html__( 'Mark Risky', 'return-guard-wc' )
		);

		// Allowlist.
		$html .= sprintf(
			'<button class="button rg-btn rg-btn--success rg-btn-allowlist" data-customer-id="%d" data-nonce="%s" %s>%s</button>',
			$customer_id,
			esc_attr( $nonce ),
			$is_allowlisted ? 'disabled' : '',
			$is_allowlisted
				? esc_html__( '✓ Allowlisted', 'return-guard-wc' )
				: esc_html__( 'Allowlist', 'return-guard-wc' )
		);

		$html .= '<span class="rg-notice-inline" aria-live="polite"></span>';
		$html .= '</div>';

		return $html;
	}

	/**
	 * Returns the display name for a customer.
	 *
	 * Uses WP user data if available; falls back to email.
	 *
	 * @since  1.0.0
	 * @param  int    $customer_id    WP user ID.
	 * @param  string $customer_email Billing email.
	 * @return string
	 */
	private function get_customer_display_name( $customer_id, $customer_email ) {
		if ( $customer_id > 0 ) {
			$user = get_userdata( $customer_id );
			if ( $user ) {
				return $user->display_name;
			}
		}
		return $customer_email ?: __( 'Guest', 'return-guard-wc' );
	}

	/**
	 * Returns the admin edit URL for a WooCommerce order.
	 *
	 * @since  1.0.0
	 * @param  int $order_id The WC order ID.
	 * @return string        Edit URL.
	 */
	private function get_order_edit_url( $order_id ) {
		if ( $this->is_hpos_enabled() ) {
			return admin_url( 'admin.php?page=wc-orders&action=edit&id=' . absint( $order_id ) );
		}
		return get_edit_post_link( $order_id, 'raw' );
	}

	/**
	 * Checks whether WooCommerce HPOS is enabled.
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
