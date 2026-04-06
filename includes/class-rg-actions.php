<?php
/**
 * Class RG_Actions
 *
 * Handles AJAX requests for the three customer management actions:
 * Block COD, Mark as Risky, and Allowlist.
 *
 * Every handler enforces nonce verification, capability checks,
 * input sanitisation, and record validation before touching the DB.
 *
 * @package Return_Guard_WC
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class RG_Actions {

	/**
	 * Constructor. Registers wp_ajax_ action hooks.
	 *
	 * @since 1.0.0
	 */
	public function __construct() {
		add_action( 'wp_ajax_rg_block_cod',   array( $this, 'handle_block_cod' ) );
		add_action( 'wp_ajax_rg_mark_risky',  array( $this, 'handle_mark_risky' ) );
		add_action( 'wp_ajax_rg_allowlist',   array( $this, 'handle_allowlist' ) );
	}

	// ─────────────────────────────────────────────────────────────────────────
	// AJAX Handlers
	// ─────────────────────────────────────────────────────────────────────────

	/**
	 * Blocks a customer from using Cash on Delivery at checkout.
	 *
	 * Sets is_cod_blocked = 1 in rg_customer_risk.
	 *
	 * @since  1.0.0
	 * @return void Sends JSON response and exits.
	 */
	public function handle_block_cod() {
		$this->verify_request();

		$customer_id = absint( $_POST['customer_id'] ?? 0 );
		$record      = $this->get_record_or_fail( $customer_id );

		global $wpdb;
		$wpdb->update(
			$wpdb->prefix . 'rg_customer_risk',
			array( 'is_cod_blocked' => 1 ),
			array( 'customer_id'    => $customer_id ),
			array( '%d' ),
			array( '%d' )
		);

		// Recompute label in case this affects the rule outcome.
		$new_label = RG_Detector::compute_and_save_label( $customer_id );

		$this->send_success(
			__( 'Customer has been blocked from Cash on Delivery.', 'return-guard-wc' ),
			array(
				'new_label'      => $new_label,
				'new_label_text' => $this->label_text( $new_label ),
			)
		);
	}

	/**
	 * Manually flags a customer as Suspicious regardless of thresholds.
	 *
	 * Sets is_manually_flagged = 1 and recomputes the label.
	 *
	 * @since  1.0.0
	 * @return void Sends JSON response and exits.
	 */
	public function handle_mark_risky() {
		$this->verify_request();

		$customer_id = absint( $_POST['customer_id'] ?? 0 );
		$record      = $this->get_record_or_fail( $customer_id );

		global $wpdb;
		$wpdb->update(
			$wpdb->prefix . 'rg_customer_risk',
			array(
				'is_manually_flagged' => 1,
				'is_allowlisted'      => 0, // Clear allowlist when manually flagging.
			),
			array( 'customer_id' => $customer_id ),
			array( '%d', '%d' ),
			array( '%d' )
		);

		$new_label = RG_Detector::compute_and_save_label( $customer_id );

		$this->send_success(
			__( 'Customer has been manually marked as risky.', 'return-guard-wc' ),
			array(
				'new_label'      => $new_label,
				'new_label_text' => $this->label_text( $new_label ),
			)
		);
	}

	/**
	 * Adds a customer to the allowlist, clearing all risk flags.
	 *
	 * Sets is_allowlisted = 1, clears is_manually_flagged, and forces
	 * the risk_label to 'safe'.
	 *
	 * @since  1.0.0
	 * @return void Sends JSON response and exits.
	 */
	public function handle_allowlist() {
		$this->verify_request();

		$customer_id = absint( $_POST['customer_id'] ?? 0 );
		$record      = $this->get_record_or_fail( $customer_id );

		global $wpdb;
		$wpdb->update(
			$wpdb->prefix . 'rg_customer_risk',
			array(
				'is_allowlisted'      => 1,
				'is_manually_flagged' => 0,
				'risk_label'          => 'safe',
			),
			array( 'customer_id' => $customer_id ),
			array( '%d', '%d', '%s' ),
			array( '%d' )
		);

		// compute_and_save_label will respect is_allowlisted and return 'safe'.
		$new_label = RG_Detector::compute_and_save_label( $customer_id );

		$this->send_success(
			__( 'Customer has been allowlisted. All flags cleared.', 'return-guard-wc' ),
			array(
				'new_label'      => $new_label,
				'new_label_text' => $this->label_text( $new_label ),
			)
		);
	}

	// ─────────────────────────────────────────────────────────────────────────
	// Shared Utilities
	// ─────────────────────────────────────────────────────────────────────────

	/**
	 * Verifies the AJAX nonce and capability.
	 *
	 * Sends a JSON error and exits if either check fails.
	 *
	 * @since  1.0.0
	 * @return void
	 */
	private function verify_request() {
		// Nonce check — nonce field name is 'nonce'.
		check_ajax_referer( 'rg_action_nonce', 'nonce' );

		// Capability check — only shop managers and admins may act.
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			$this->send_error( __( 'You do not have permission to perform this action.', 'return-guard-wc' ) );
		}
	}

	/**
	 * Fetches the customer's risk record, or sends an error and exits.
	 *
	 * @since  1.0.0
	 * @param  int          $customer_id The WP user ID.
	 * @return object|false              The DB row, or never returns on failure.
	 */
	private function get_record_or_fail( $customer_id ) {
		global $wpdb;

		if ( $customer_id <= 0 ) {
			$this->send_error( __( 'Invalid customer ID.', 'return-guard-wc' ) );
		}

		$record = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}rg_customer_risk WHERE customer_id = %d LIMIT 1",
				$customer_id
			)
		);

		if ( ! $record ) {
			$this->send_error( __( 'No risk record found for this customer.', 'return-guard-wc' ) );
		}

		return $record;
	}

	/**
	 * Sends a JSON success response and exits.
	 *
	 * @since  1.0.0
	 * @param  string $message Human-readable success message.
	 * @param  array  $data    Additional data to include in the response.
	 * @return void
	 */
	private function send_success( $message, array $data = array() ) {
		wp_send_json_success(
			array_merge( array( 'message' => $message ), $data )
		);
	}

	/**
	 * Sends a JSON error response and exits.
	 *
	 * @since  1.0.0
	 * @param  string $message Human-readable error message.
	 * @return void
	 */
	private function send_error( $message ) {
		wp_send_json_error( array( 'message' => $message ) );
	}

	/**
	 * Returns a human-readable label string for display.
	 *
	 * @since  1.0.0
	 * @param  string $label Internal label slug.
	 * @return string        Translated display label.
	 */
	private function label_text( $label ) {
		$map = array(
			'safe'       => __( 'Safe', 'return-guard-wc' ),
			'suspicious' => __( 'Suspicious', 'return-guard-wc' ),
			'abuser'     => __( 'Abuser', 'return-guard-wc' ),
		);
		return $map[ $label ] ?? ucfirst( $label );
	}
}
