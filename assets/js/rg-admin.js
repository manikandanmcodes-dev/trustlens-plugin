/**
 * Return Guard for WooCommerce — Admin JavaScript
 *
 * Handles AJAX for the three customer action buttons:
 *   - Block COD       (.rg-btn-block-cod)
 *   - Mark Risky      (.rg-btn-mark-risky)
 *   - Allowlist       (.rg-btn-allowlist)
 *
 * Depends on: jQuery (loaded by WordPress)
 * Localised:  rgAdmin.ajaxUrl, rgAdmin.nonce, rgAdmin.strings
 *
 * @package Return_Guard_WC
 * @since   1.0.0
 */

/* global rgAdmin */

( function ( $ ) {
	'use strict';

	// ─────────────────────────────────────────────────────────────────────────
	// Utility helpers
	// ─────────────────────────────────────────────────────────────────────────

	/**
	 * Shows a spinner inside a button and disables all action buttons in the row.
	 *
	 * @param {jQuery} $btn The button that was clicked.
	 * @returns {jQuery} The sibling button group wrapper.
	 */
	function lockButtons( $btn ) {
		var $actions = $btn.closest( '.rg-actions' );
		// Disable all three buttons while the request is in flight.
		$actions.find( '.rg-btn' ).prop( 'disabled', true );
		// Show a spinner inside the clicked button.
		$btn.prepend( '<span class="rg-spinner" aria-hidden="true"></span>' );
		return $actions;
	}

	/**
	 * Removes the spinner and re-enables non-permanently-disabled buttons.
	 *
	 * @param {jQuery} $actions The .rg-actions wrapper.
	 * @param {Array}  permanentlyDisabled Array of button class names that should stay disabled.
	 */
	function unlockButtons( $actions, permanentlyDisabled ) {
		$actions.find( '.rg-spinner' ).remove();
		$actions.find( '.rg-btn' ).each( function () {
			var $b = $( this );
			var shouldStayDisabled = permanentlyDisabled.some( function ( cls ) {
				return $b.hasClass( cls );
			} );
			if ( ! shouldStayDisabled ) {
				$b.prop( 'disabled', false );
			}
		} );
	}

	/**
	 * Displays an inline success or error notice next to the buttons.
	 *
	 * Auto-hides after 4 seconds.
	 *
	 * @param {jQuery} $actions     The .rg-actions wrapper.
	 * @param {string} message      The message to display.
	 * @param {string} type         'success' or 'error'.
	 */
	function showNotice( $actions, message, type ) {
		var $notice = $actions.find( '.rg-notice-inline' );
		$notice
			.removeClass( 'rg-notice--success rg-notice--error' )
			.addClass( 'rg-notice--' + type )
			.text( message )
			.show();

		// Auto-clear after 4 seconds.
		clearTimeout( $notice.data( 'rg-timeout' ) );
		$notice.data( 'rg-timeout', setTimeout( function () {
			$notice.fadeOut( 300, function () {
				$( this ).removeClass( 'rg-notice--success rg-notice--error' ).text( '' ).show();
			} );
		}, 4000 ) );
	}

	/**
	 * Updates the risk label badge in the current row.
	 *
	 * Works for both dashboard rows and the order meta box.
	 *
	 * @param {jQuery} $actions  The .rg-actions wrapper.
	 * @param {string} newLabel  Slug: 'safe', 'suspicious', or 'abuser'.
	 * @param {string} labelText Human-readable label for screen readers.
	 */
	function updateBadge( $actions, newLabel, labelText ) {
		var $row  = $actions.closest( 'tr, .rg-meta-box' );
		var $cell = $row.find( '.rg-label-cell, .rg-meta-label-row' );
		$cell.find( '.rg-badge' )
			.removeClass( 'rg-badge--safe rg-badge--suspicious rg-badge--abuser' )
			.addClass( 'rg-badge--' + newLabel )
			.text( labelText );
	}

	// ─────────────────────────────────────────────────────────────────────────
	// AJAX dispatcher
	// ─────────────────────────────────────────────────────────────────────────

	/**
	 * Sends an AJAX action request to the WordPress admin-ajax endpoint.
	 *
	 * @param {string}   action       The wp_ajax action name (e.g. 'rg_block_cod').
	 * @param {number}   customerId   The WP user ID.
	 * @param {string}   nonce        The security nonce.
	 * @param {jQuery}   $actions     The .rg-actions wrapper.
	 * @param {Array}    disableAfter Button class names to keep disabled on success.
	 * @param {Function} onSuccess    Callback receiving the response data object.
	 */
	function sendAction( action, customerId, nonce, $actions, disableAfter, onSuccess ) {
		$.ajax( {
			url:    rgAdmin.ajaxUrl,
			type:   'POST',
			data:   {
				action:      action,
				customer_id: customerId,
				nonce:       nonce,
			},
			success: function ( response ) {
				unlockButtons( $actions, disableAfter );

				if ( response.success ) {
					onSuccess( response.data );
					showNotice( $actions, response.data.message, 'success' );
					if ( response.data.new_label ) {
						updateBadge( $actions, response.data.new_label, response.data.new_label_text );
					}
				} else {
					var errMsg = ( response.data && response.data.message )
						? response.data.message
						: rgAdmin.strings.error_generic;
					showNotice( $actions, errMsg, 'error' );
				}
			},
			error: function () {
				unlockButtons( $actions, [] );
				showNotice( $actions, rgAdmin.strings.error_generic, 'error' );
			},
		} );
	}

	// ─────────────────────────────────────────────────────────────────────────
	// Button click handlers
	// ─────────────────────────────────────────────────────────────────────────

	$( document ).ready( function () {

		// ── Block COD ────────────────────────────────────────────────────────
		$( document ).on( 'click', '.rg-btn-block-cod', function ( e ) {
			e.preventDefault();

			var $btn        = $( this );
			var customerId  = $btn.data( 'customer-id' );
			var nonce       = $btn.data( 'nonce' );

			if ( ! window.confirm( rgAdmin.strings.confirm_block_cod ) ) {
				return;
			}

			var $actions = lockButtons( $btn );

			sendAction(
				'rg_block_cod',
				customerId,
				nonce,
				$actions,
				[ 'rg-btn-block-cod' ],   // This button stays disabled after success.
				function ( data ) {
					// Update button label to reflect the new state.
					$actions.find( '.rg-btn-block-cod' )
						.text( 'COD Blocked' )
						.prop( 'disabled', true );
				}
			);
		} );

		// ── Mark Risky ───────────────────────────────────────────────────────
		$( document ).on( 'click', '.rg-btn-mark-risky', function ( e ) {
			e.preventDefault();

			var $btn        = $( this );
			var customerId  = $btn.data( 'customer-id' );
			var nonce       = $btn.data( 'nonce' );

			if ( ! window.confirm( rgAdmin.strings.confirm_mark_risky ) ) {
				return;
			}

			var $actions = lockButtons( $btn );

			sendAction(
				'rg_mark_risky',
				customerId,
				nonce,
				$actions,
				[ 'rg-btn-mark-risky' ],
				function ( data ) {
					$actions.find( '.rg-btn-mark-risky' )
						.text( 'Marked Risky' )
						.prop( 'disabled', true );
					// Clear allowlist button if it was previously disabled.
					$actions.find( '.rg-btn-allowlist' ).prop( 'disabled', false );
				}
			);
		} );

		// ── Allowlist ────────────────────────────────────────────────────────
		$( document ).on( 'click', '.rg-btn-allowlist', function ( e ) {
			e.preventDefault();

			var $btn        = $( this );
			var customerId  = $btn.data( 'customer-id' );
			var nonce       = $btn.data( 'nonce' );

			// No confirm dialog for allowlisting (non-destructive action).
			if ( ! window.confirm( rgAdmin.strings.confirm_allowlist ) ) {
				return;
			}

			var $actions = lockButtons( $btn );

			sendAction(
				'rg_allowlist',
				customerId,
				nonce,
				$actions,
				[ 'rg-btn-allowlist' ],
				function ( data ) {
					$actions.find( '.rg-btn-allowlist' )
						.text( '✓ Allowlisted' )
						.prop( 'disabled', true );
					// Re-enable the other two buttons so the admin can change their mind.
					$actions.find( '.rg-btn-block-cod, .rg-btn-mark-risky' )
						.prop( 'disabled', false );
				}
			);
		} );

	} ); // end document.ready

} )( jQuery );
