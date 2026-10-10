/**
 * Rating Notice JavaScript
 *
 * Handles user interactions with the plugin rating notice.
 *
 * @package RoyalBackupReset
 * @since   1.0.0
 */

jQuery( document ).ready( function( $ ) {

	// Dismiss button (X)
	$( document ).on( 'click', '.royalbr-rating-notice .notice-dismiss', function() {
		$( '.royalbr-rating-notice' ).fadeOut();
		$.post( {
			url: ajaxurl,
			data: {
				action: 'royalbr_rating_dismiss',
				nonce: royalbrRatingNotice.nonce
			}
		} );
	} );

	// Maybe Later
	$( document ).on( 'click', '.royalbr-maybe-later', function( e ) {
		e.preventDefault();
		$( '.royalbr-rating-notice' ).slideUp();
		$.post( {
			url: ajaxurl,
			data: {
				action: 'royalbr_rating_maybe_later',
				nonce: royalbrRatingNotice.nonce
			}
		} );
	} );

	// Already Rated
	$( document ).on( 'click', '.royalbr-already-rated', function( e ) {
		e.preventDefault();
		$( '.royalbr-rating-notice' ).slideUp();
		$.post( {
			url: ajaxurl,
			data: {
				action: 'royalbr_rating_already_rated',
				nonce: royalbrRatingNotice.nonce
			}
		} );
	} );

} );
