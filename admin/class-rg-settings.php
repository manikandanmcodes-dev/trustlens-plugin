<?php
/**
 * Class RG_Settings
 *
 * Registers the Return Guard settings using the WordPress Settings API.
 * Handles 5 threshold options and the COD blocking toggle.
 *
 * Also registers a "Reset to Defaults" form action (handled via admin_init).
 *
 * @package Return_Guard_WC
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class RG_Settings {

	/**
	 * Constructor. Hooks into admin_init for Settings API registration
	 * and the Reset Defaults form handler.
	 *
	 * @since 1.0.0
	 */
	public function __construct() {
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_init', array( $this, 'handle_reset_defaults' ) );
	}

	// ─────────────────────────────────────────────────────────────────────────
	// Settings API Registration
	// ─────────────────────────────────────────────────────────────────────────

	/**
	 * Registers all settings, sections, and fields via the Settings API.
	 *
	 * @since  1.0.0
	 * @return void
	 */
	public function register_settings() {

		// ── Option Registrations ─────────────────────────────────────────────

		register_setting( 'rg_settings', 'rg_abuser_rate_threshold',      array( 'sanitize_callback' => 'absint' ) );
		register_setting( 'rg_settings', 'rg_abuser_count_threshold',     array( 'sanitize_callback' => 'absint' ) );
		register_setting( 'rg_settings', 'rg_suspicious_rate_threshold',  array( 'sanitize_callback' => 'absint' ) );
		register_setting( 'rg_settings', 'rg_suspicious_count_threshold', array( 'sanitize_callback' => 'absint' ) );
		register_setting(
			'rg_settings',
			'rg_suspicious_value_threshold',
			array(
				'sanitize_callback' => function ( $value ) {
					return (float) $value;
				},
			)
		);
		register_setting(
			'rg_settings',
			'rg_enable_cod_blocking',
			array(
				'sanitize_callback' => function ( $value ) {
					return ( 'yes' === sanitize_text_field( $value ) ) ? 'yes' : 'no';
				},
			)
		);

		// ── Section: Abuser Thresholds ────────────────────────────────────────

		add_settings_section(
			'rg_section_abuser',
			__( 'Abuser Thresholds', 'return-guard-wc' ),
			array( $this, 'render_section_abuser' ),
			'rg_settings'
		);

		add_settings_field(
			'rg_abuser_rate_threshold',
			__( 'Abuser Return Rate (%)', 'return-guard-wc' ),
			array( $this, 'render_field_number' ),
			'rg_settings',
			'rg_section_abuser',
			array(
				'option'      => 'rg_abuser_rate_threshold',
				'default'     => 50,
				'description' => __( 'A customer whose return rate meets or exceeds this percentage AND has enough refunds will be labeled <strong>Abuser</strong>.', 'return-guard-wc' ),
				'min'         => 1,
				'max'         => 100,
				'step'        => 1,
			)
		);

		add_settings_field(
			'rg_abuser_count_threshold',
			__( 'Abuser Refund Count', 'return-guard-wc' ),
			array( $this, 'render_field_number' ),
			'rg_settings',
			'rg_section_abuser',
			array(
				'option'      => 'rg_abuser_count_threshold',
				'default'     => 3,
				'description' => __( 'Minimum number of refunds a customer must have, combined with the rate threshold, to be labeled <strong>Abuser</strong>.', 'return-guard-wc' ),
				'min'         => 1,
				'max'         => 999,
				'step'        => 1,
			)
		);

		// ── Section: Suspicious Thresholds ───────────────────────────────────

		add_settings_section(
			'rg_section_suspicious',
			__( 'Suspicious Thresholds', 'return-guard-wc' ),
			array( $this, 'render_section_suspicious' ),
			'rg_settings'
		);

		add_settings_field(
			'rg_suspicious_rate_threshold',
			__( 'Suspicious Return Rate (%)', 'return-guard-wc' ),
			array( $this, 'render_field_number' ),
			'rg_settings',
			'rg_section_suspicious',
			array(
				'option'      => 'rg_suspicious_rate_threshold',
				'default'     => 30,
				'description' => __( 'If a customer\'s return rate meets or exceeds this value (but is below the Abuser rate), they are labeled <strong>Suspicious</strong>.', 'return-guard-wc' ),
				'min'         => 1,
				'max'         => 100,
				'step'        => 1,
			)
		);

		add_settings_field(
			'rg_suspicious_count_threshold',
			__( 'Suspicious Refund Count', 'return-guard-wc' ),
			array( $this, 'render_field_number' ),
			'rg_settings',
			'rg_section_suspicious',
			array(
				'option'      => 'rg_suspicious_count_threshold',
				'default'     => 2,
				'description' => __( 'If a customer has this many or more refunds, they become <strong>Suspicious</strong> (even if the rate is low).', 'return-guard-wc' ),
				'min'         => 1,
				'max'         => 999,
				'step'        => 1,
			)
		);

		add_settings_field(
			'rg_suspicious_value_threshold',
			__( 'Suspicious Total Value ($)', 'return-guard-wc' ),
			array( $this, 'render_field_number' ),
			'rg_settings',
			'rg_section_suspicious',
			array(
				'option'      => 'rg_suspicious_value_threshold',
				'default'     => 100,
				'description' => __( 'If the cumulative refund value for a customer meets or exceeds this amount, they become <strong>Suspicious</strong>.', 'return-guard-wc' ),
				'min'         => 0,
				'max'         => 99999,
				'step'        => 0.01,
			)
		);

		// ── Section: COD Blocking ────────────────────────────────────────────

		add_settings_section(
			'rg_section_cod',
			__( 'COD Blocking', 'return-guard-wc' ),
			array( $this, 'render_section_cod' ),
			'rg_settings'
		);

		add_settings_field(
			'rg_enable_cod_blocking',
			__( 'Enable COD Blocking', 'return-guard-wc' ),
			array( $this, 'render_field_checkbox' ),
			'rg_settings',
			'rg_section_cod',
			array(
				'option'      => 'rg_enable_cod_blocking',
				'description' => __( 'When enabled, customers who have been manually blocked will not see Cash on Delivery as a payment option at checkout.', 'return-guard-wc' ),
			)
		);
	}

	// ─────────────────────────────────────────────────────────────────────────
	// Section Descriptions
	// ─────────────────────────────────────────────────────────────────────────

	/**
	 * Renders the Abuser section description.
	 *
	 * @since 1.0.0
	 */
	public function render_section_abuser() {
		echo '<p>' . esc_html__( 'A customer is labeled Abuser when BOTH conditions below are met at the same time.', 'return-guard-wc' ) . '</p>';
	}

	/**
	 * Renders the Suspicious section description.
	 *
	 * @since 1.0.0
	 */
	public function render_section_suspicious() {
		echo '<p>' . esc_html__( 'A customer is labeled Suspicious when ANY ONE of the following conditions is met (but they do not qualify as an Abuser).', 'return-guard-wc' ) . '</p>';
	}

	/**
	 * Renders the COD section description.
	 *
	 * @since 1.0.0
	 */
	public function render_section_cod() {
		echo '<p>' . esc_html__( 'Control whether the COD blocking feature is active system-wide.', 'return-guard-wc' ) . '</p>';
	}

	// ─────────────────────────────────────────────────────────────────────────
	// Field Renderers
	// ─────────────────────────────────────────────────────────────────────────

	/**
	 * Renders a number input field.
	 *
	 * @since  1.0.0
	 * @param  array $args Field arguments.
	 * @return void
	 */
	public function render_field_number( $args ) {
		$option  = $args['option'];
		$value   = get_option( $option, $args['default'] );
		$min     = $args['min'] ?? 0;
		$max     = $args['max'] ?? 99999;
		$step    = $args['step'] ?? 1;
		?>
		<input
			type="number"
			id="<?php echo esc_attr( $option ); ?>"
			name="<?php echo esc_attr( $option ); ?>"
			value="<?php echo esc_attr( $value ); ?>"
			min="<?php echo esc_attr( $min ); ?>"
			max="<?php echo esc_attr( $max ); ?>"
			step="<?php echo esc_attr( $step ); ?>"
			class="small-text"
		>
		<?php if ( ! empty( $args['description'] ) ) : ?>
			<p class="description"><?php echo wp_kses_post( $args['description'] ); ?></p>
		<?php endif;
	}

	/**
	 * Renders a checkbox field.
	 *
	 * @since  1.0.0
	 * @param  array $args Field arguments.
	 * @return void
	 */
	public function render_field_checkbox( $args ) {
		$option = $args['option'];
		$value  = get_option( $option, 'yes' );
		?>
		<label for="<?php echo esc_attr( $option ); ?>">
			<input
				type="checkbox"
				id="<?php echo esc_attr( $option ); ?>"
				name="<?php echo esc_attr( $option ); ?>"
				value="yes"
				<?php checked( 'yes', $value ); ?>
			>
			<?php esc_html_e( 'Enabled', 'return-guard-wc' ); ?>
		</label>
		<?php if ( ! empty( $args['description'] ) ) : ?>
			<p class="description"><?php echo wp_kses_post( $args['description'] ); ?></p>
		<?php endif;
	}

	// ─────────────────────────────────────────────────────────────────────────
	// Page Render
	// ─────────────────────────────────────────────────────────────────────────

	/**
	 * Outputs the full settings page HTML.
	 *
	 * @since  1.0.0
	 * @return void
	 */
	public function render_page() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'return-guard-wc' ) );
		}
		?>
		<div class="wrap rg-wrap">
			<h1><?php esc_html_e( 'Return Guard — Settings', 'return-guard-wc' ); ?></h1>

			<?php /* ── HOW THRESHOLDS WORK HELP BOX ── */ ?>
			<div class="rg-help-box">
				<h3><?php esc_html_e( '📖 How Thresholds Work', 'return-guard-wc' ); ?></h3>
				<p><?php esc_html_e( 'Return Guard uses a simple rule engine to label each customer. Rules run in order and the first match wins:', 'return-guard-wc' ); ?></p>
				<ol>
					<li><?php esc_html_e( 'Allowlisted customers are always Safe — no rules apply.', 'return-guard-wc' ); ?></li>
					<li><?php esc_html_e( 'Manually flagged customers are always Suspicious.', 'return-guard-wc' ); ?></li>
					<li><strong><?php esc_html_e( 'Abuser:', 'return-guard-wc' ); ?></strong> <?php esc_html_e( 'return rate ≥ Abuser Rate AND refund count ≥ Abuser Count.', 'return-guard-wc' ); ?></li>
					<li><strong><?php esc_html_e( 'Suspicious:', 'return-guard-wc' ); ?></strong> <?php esc_html_e( 'return rate ≥ Suspicious Rate, OR refund count ≥ Suspicious Count, OR total refunded ≥ Suspicious Value.', 'return-guard-wc' ); ?></li>
					<li><?php esc_html_e( 'Everyone else is Safe.', 'return-guard-wc' ); ?></li>
				</ol>
			</div>

			<?php /* ── SETTINGS FORM ── */ ?>
			<form method="post" action="options.php">
				<?php
				settings_fields( 'rg_settings' );
				do_settings_sections( 'rg_settings' );
				submit_button( __( 'Save Settings', 'return-guard-wc' ) );
				?>
			</form>

			<hr>

			<?php /* ── RESET TO DEFAULTS ── */ ?>
			<h2><?php esc_html_e( 'Reset to Defaults', 'return-guard-wc' ); ?></h2>
			<p><?php esc_html_e( 'This will restore all threshold settings to their original default values. Your customer risk data will not be affected.', 'return-guard-wc' ); ?></p>
			<form method="post" action="">
				<?php wp_nonce_field( 'rg_reset_defaults', 'rg_reset_nonce' ); ?>
				<input type="hidden" name="rg_action" value="reset_defaults">
				<?php submit_button( __( 'Reset to Defaults', 'return-guard-wc' ), 'secondary', 'rg_reset_submit' ); ?>
			</form>

		</div>
		<?php
	}

	// ─────────────────────────────────────────────────────────────────────────
	// Reset Defaults Handler
	// ─────────────────────────────────────────────────────────────────────────

	/**
	 * Handles the Reset to Defaults form submission.
	 *
	 * Verifies nonce and capability before overwriting all threshold options.
	 *
	 * @since  1.0.0
	 * @return void
	 */
	public function handle_reset_defaults() {
		if (
			! isset( $_POST['rg_action'] ) ||
			'reset_defaults' !== $_POST['rg_action']
		) {
			return;
		}

		check_admin_referer( 'rg_reset_defaults', 'rg_reset_nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'return-guard-wc' ) );
		}

		$defaults = array(
			'rg_abuser_rate_threshold'      => 50,
			'rg_abuser_count_threshold'     => 3,
			'rg_suspicious_rate_threshold'  => 30,
			'rg_suspicious_count_threshold' => 2,
			'rg_suspicious_value_threshold' => 100,
			'rg_enable_cod_blocking'        => 'yes',
		);

		foreach ( $defaults as $option => $value ) {
			update_option( $option, $value );
		}

		// Redirect to show an admin success notice.
		wp_safe_redirect(
			add_query_arg(
				array(
					'page'    => 'return-guard-settings',
					'rg_msg'  => 'reset_ok',
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}
}
