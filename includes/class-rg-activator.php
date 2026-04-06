<?php
/**
 * Class RG_Activator
 *
 * Handles plugin activation: creates the custom database table and
 * sets default option values for all threshold settings.
 *
 * @package Return_Guard_WC
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class RG_Activator {

	/**
	 * Runs on plugin activation.
	 *
	 * Creates the rg_customer_risk table using dbDelta(), stores the plugin
	 * version, and sets default threshold options if they don't already exist.
	 *
	 * @since  1.0.0
	 * @return void
	 */
	public static function activate() {
		self::create_table();
		self::set_defaults();
		update_option( 'rg_version', RG_VERSION );
	}

	/**
	 * Creates the rg_customer_risk custom database table.
	 *
	 * Uses dbDelta() so the table is safely created or upgraded
	 * without data loss on re-activation.
	 *
	 * @since  1.0.0
	 * @return void
	 */
	private static function create_table() {
		global $wpdb;

		$table_name      = $wpdb->prefix . 'rg_customer_risk';
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table_name} (
			id                  BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			customer_id         BIGINT(20) UNSIGNED NOT NULL,
			customer_email      VARCHAR(100)        NOT NULL DEFAULT '',
			total_orders        INT(11) UNSIGNED    NOT NULL DEFAULT 0,
			total_refunds       INT(11) UNSIGNED    NOT NULL DEFAULT 0,
			total_refund_amount DECIMAL(12,2)       NOT NULL DEFAULT 0.00,
			return_rate         DECIMAL(5,2)        NOT NULL DEFAULT 0.00,
			risk_label          VARCHAR(20)         NOT NULL DEFAULT 'safe',
			is_cod_blocked      TINYINT(1)          NOT NULL DEFAULT 0,
			is_allowlisted      TINYINT(1)          NOT NULL DEFAULT 0,
			is_manually_flagged TINYINT(1)          NOT NULL DEFAULT 0,
			last_refund_date    DATETIME            DEFAULT NULL,
			updated_at          DATETIME            NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			UNIQUE KEY   customer_id (customer_id),
			KEY          risk_label (risk_label),
			KEY          is_cod_blocked (is_cod_blocked)
		) ENGINE=InnoDB {$charset_collate};";

		// dbDelta requires upgrade.php to be loaded first.
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	/**
	 * Sets default plugin options on first activation.
	 *
	 * Uses add_option() so existing values are never overwritten
	 * on subsequent activations.
	 *
	 * @since  1.0.0
	 * @return void
	 */
	private static function set_defaults() {
		$defaults = array(
			'rg_abuser_rate_threshold'      => 50,
			'rg_abuser_count_threshold'     => 3,
			'rg_suspicious_rate_threshold'  => 30,
			'rg_suspicious_count_threshold' => 2,
			'rg_suspicious_value_threshold' => 100,
			'rg_enable_cod_blocking'        => 'yes',
		);

		foreach ( $defaults as $option_name => $default_value ) {
			// add_option() is a no-op if the option already exists — safe to call on every activation.
			add_option( $option_name, $default_value );
		}
	}
}
