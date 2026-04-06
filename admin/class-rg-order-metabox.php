<?php
/**
 * Class RG_Order_Metabox
 *
 * Adds a "Return Guard — Customer Risk" meta box to the WooCommerce
 * order edit screen. Supports both classic (post-based) and HPOS orders.
 *
 * Displays the customer's risk label, stats, and action buttons.
 * Only visible to users with the manage_woocommerce capability.
 *
 * @package Return_Guard_WC
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class RG_Order_Metabox {

	/**
	 * Constructor. Registers the meta box hooks for both classic and HPOS order pages.
	 *
	 * @since 1.0.0
	 */
	public function __construct() {
		// Classic post-based orders.
		add_action( 'add_meta_boxes', array( $this, 'register_meta_box' ) );

		// HPOS-based orders (WooCommerce 7.1+).
		add_action( 'add_meta_boxes_woocommerce_page_wc-orders', array( $this, 'register_meta_box' ) );
	}

	/**
	 * Registers the Return Guard risk panel meta box on WooCommerce order screens.
	 *
	 * @since  1.0.0
	 * @return void
	 */
	public function register_meta_box() {
		// Classic orders: post type is 'shop_order'.
		add_meta_box(
			'rg-customer-risk',
			__( 'Return Guard — Customer Risk', 'return-guard-wc' ),
			array( $this, 'render' ),
			'shop_order',
			'side',
			'high'
		);

		// HPOS orders: post type is 'woocommerce_page_wc-orders'.
		if ( $this->is_hpos_enabled() ) {
			add_meta_box(
				'rg-customer-risk',
				__( 'Return Guard — Customer Risk', 'return-guard-wc' ),
				array( $this, 'render' ),
				'woocommerce_page_wc-orders',
				'side',
				'high'
			);
		}
	}

	/**
	 * Renders the meta box content.
	 *
	 * Accepts either a WP_Post or a WC_Order depending on storage mode.
	 *
	 * @since  1.0.0
	 * @param  WP_Post|WC_Order $post_or_order The post or order object.
	 * @return void
	 */
	public function render( $post_or_order ) {
		// Resolve the WC_Order from either a WP_Post or a WC_Order directly.
		if ( $post_or_order instanceof WC_Order ) {
			$order = $post_or_order;
		} elseif ( $post_or_order instanceof WP_Post ) {
			$order = wc_get_order( $post_or_order->ID );
		} else {
			// Could not resolve — bail gracefully.
			echo '<p>' . esc_html__( 'Could not load order details.', 'return-guard-wc' ) . '</p>';
			return;
		}

		if ( ! $order ) {
			echo '<p>' . esc_html__( 'Could not load order details.', 'return-guard-wc' ) . '</p>';
			return;
		}

		// Capability check — only admins and shop managers see this panel.
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		$customer_id    = (int) $order->get_customer_id();
		$customer_email = sanitize_email( $order->get_billing_email() );
		$is_guest       = ( 0 === $customer_id );

		// Fetch the customer's risk record.
		$record = $this->get_risk_record( $customer_id, $customer_email );

		// Nonce field for AJAX calls from this meta box.
		wp_nonce_field( 'rg_action_nonce', 'rg_metabox_nonce' );
		?>

		<div class="rg-meta-box">

			<?php /* ── Customer header ── */ ?>
			<div class="rg-meta-header">
				<?php if ( $customer_id > 0 ) : ?>
					<a href="<?php echo esc_url( get_edit_user_link( $customer_id ) ); ?>" class="rg-customer-link">
						<?php echo esc_html( $order->get_formatted_billing_full_name() ); ?>
					</a>
				<?php else : ?>
					<span><?php echo esc_html( $order->get_formatted_billing_full_name() ); ?></span>
				<?php endif; ?>
				<small class="rg-customer-email"><?php echo esc_html( $customer_email ); ?></small>
			</div>

			<?php /* ── No record yet ── */ ?>
			<?php if ( ! $record ) : ?>

				<p class="rg-empty-state">
					<?php esc_html_e( 'No refund history — this customer appears safe.', 'return-guard-wc' ); ?>
				</p>

			<?php else : ?>

				<?php /* ── Risk label badge ── */ ?>
				<div class="rg-meta-label-row">
					<?php $label = $record->risk_label; ?>
					<?php
					$label_map = array(
						'safe'       => __( 'Safe', 'return-guard-wc' ),
						'suspicious' => __( 'Suspicious', 'return-guard-wc' ),
						'abuser'     => __( 'Abuser', 'return-guard-wc' ),
					);
					$label_text = $label_map[ $label ] ?? ucfirst( $label );
					?>
					<span class="rg-badge rg-badge--<?php echo esc_attr( $label ); ?>">
						<?php echo esc_html( $label_text ); ?>
					</span>
				</div>

				<?php /* ── Stats strip ── */ ?>
				<div class="rg-stats-row">
					<span class="rg-stat-item">
						<strong><?php echo esc_html( $record->total_refunds ); ?></strong>
						<?php esc_html_e( 'refunds', 'return-guard-wc' ); ?>
					</span>
					<span class="rg-stat-sep">|</span>
					<span class="rg-stat-item">
						<strong><?php echo wp_kses_post( wc_price( $record->total_refund_amount ) ); ?></strong>
						<?php esc_html_e( 'refunded', 'return-guard-wc' ); ?>
					</span>
					<span class="rg-stat-sep">|</span>
					<span class="rg-stat-item">
						<strong><?php echo esc_html( $record->return_rate ); ?>%</strong>
						<?php esc_html_e( 'rate', 'return-guard-wc' ); ?>
					</span>
				</div>

				<?php /* ── Guest notice ── */ ?>
				<?php if ( $is_guest ) : ?>
					<p class="rg-guest-notice">
						<?php esc_html_e( 'Guest order — COD blocking is unavailable (requires a registered account).', 'return-guard-wc' ); ?>
					</p>
				<?php endif; ?>

				<?php /* ── Action buttons ── */ ?>
				<div class="rg-actions">

					<?php $nonce = wp_create_nonce( 'rg_action_nonce' ); ?>

					<?php // Block COD. ?>
					<button
						class="button rg-btn rg-btn--danger rg-btn-block-cod"
						data-customer-id="<?php echo esc_attr( $customer_id ); ?>"
						data-nonce="<?php echo esc_attr( $nonce ); ?>"
						<?php echo ( $is_guest || '1' === (string) $record->is_cod_blocked ) ? 'disabled' : ''; ?>
					>
						<?php
						echo ( '1' === (string) $record->is_cod_blocked )
							? esc_html__( 'COD Blocked', 'return-guard-wc' )
							: esc_html__( 'Block COD', 'return-guard-wc' );
						?>
					</button>

					<?php // Mark Risky. ?>
					<button
						class="button rg-btn rg-btn--warning rg-btn-mark-risky"
						data-customer-id="<?php echo esc_attr( $customer_id ); ?>"
						data-nonce="<?php echo esc_attr( $nonce ); ?>"
						<?php echo ( '1' === (string) $record->is_manually_flagged ) ? 'disabled' : ''; ?>
					>
						<?php
						echo ( '1' === (string) $record->is_manually_flagged )
							? esc_html__( 'Marked Risky', 'return-guard-wc' )
							: esc_html__( 'Mark Risky', 'return-guard-wc' );
						?>
					</button>

					<?php // Allowlist. ?>
					<button
						class="button rg-btn rg-btn--success rg-btn-allowlist"
						data-customer-id="<?php echo esc_attr( $customer_id ); ?>"
						data-nonce="<?php echo esc_attr( $nonce ); ?>"
						<?php echo ( '1' === (string) $record->is_allowlisted ) ? 'disabled' : ''; ?>
					>
						<?php
						echo ( '1' === (string) $record->is_allowlisted )
							? esc_html__( '✓ Allowlisted', 'return-guard-wc' )
							: esc_html__( 'Allowlist', 'return-guard-wc' );
						?>
					</button>

				</div>

				<span class="rg-notice-inline" aria-live="polite"></span>

			<?php endif; // end if record ?>

		</div><?php /* .rg-meta-box */ ?>
		<?php
	}

	// ─────────────────────────────────────────────────────────────────────────
	// Helpers
	// ─────────────────────────────────────────────────────────────────────────

	/**
	 * Fetches the risk record for a customer.
	 *
	 * Falls back to an email lookup for guests (customer_id = 0).
	 *
	 * @since  1.0.0
	 * @param  int    $customer_id    WP user ID.
	 * @param  string $customer_email Billing email.
	 * @return object|null            DB row or null if no record exists.
	 */
	private function get_risk_record( $customer_id, $customer_email ) {
		global $wpdb;
		$table = $wpdb->prefix . 'rg_customer_risk';

		if ( $customer_id > 0 ) {
			return $wpdb->get_row(
				$wpdb->prepare(
					"SELECT * FROM {$table} WHERE customer_id = %d LIMIT 1",
					$customer_id
				)
			);
		}

		// Guest: try by email.
		return $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE customer_email = %s AND customer_id = 0 LIMIT 1",
				$customer_email
			)
		);
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
