<?php
/**
 * Return Guard for WooCommerce — Uninstall Handler
 *
 * This file runs when the plugin is deleted through the WordPress admin.
 * It removes ALL plugin data: the custom DB table and all options.
 *
 * Data is intentionally preserved on deactivation — only a full uninstall
 * triggers this cleanup.
 *
 * @package Return_Guard_WC
 * @since   1.0.0
 */

// Block direct access AND ensure this is a legitimate WordPress uninstall call.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

// ── Drop the custom risk table ───────────────────────────────────────────────

$table_name = $wpdb->prefix . 'rg_customer_risk';

// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
$wpdb->query( "DROP TABLE IF EXISTS {$table_name}" );

// ── Delete all plugin options from wp_options ────────────────────────────────

$options_to_delete = array(
	'rg_version',
	'rg_abuser_rate_threshold',
	'rg_abuser_count_threshold',
	'rg_suspicious_rate_threshold',
	'rg_suspicious_count_threshold',
	'rg_suspicious_value_threshold',
	'rg_enable_cod_blocking',
);

foreach ( $options_to_delete as $option ) {
	delete_option( $option );
}

// ── Multisite: remove options from all sub-sites ─────────────────────────────

if ( is_multisite() ) {
	$blog_ids = $wpdb->get_col( "SELECT blog_id FROM {$wpdb->blogs}" );

	foreach ( $blog_ids as $blog_id ) {
		switch_to_blog( $blog_id );

		$site_table = $wpdb->prefix . 'rg_customer_risk';
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "DROP TABLE IF EXISTS {$site_table}" );

		foreach ( $options_to_delete as $option ) {
			delete_option( $option );
		}

		restore_current_blog();
	}
}
