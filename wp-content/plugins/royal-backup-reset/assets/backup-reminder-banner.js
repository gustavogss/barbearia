/**
 * Backup Reminder Banner JavaScript
 *
 * Handles user interactions with the backup reminder banner.
 *
 * @package RoyalBackupReset
 * @since   1.0.0
 */

jQuery( document ).ready( function( $ ) {

	// Dismiss button (X)
	$( document ).on( 'click', '.royalbr-backup-reminder-banner .notice-dismiss', function() {
		$( '.royalbr-backup-reminder-banner' ).fadeOut();
		$.post( {
			url: ajaxurl,
			data: {
				action: 'royalbr_backup_reminder_banner_dismiss',
				nonce: royalbrBackupReminderBanner.nonce
			}
		} );
	} );

	// Remind Me Later
	$( document ).on( 'click', '.royalbr-backup-reminder-banner-later', function( e ) {
		e.preventDefault();
		$( '.royalbr-backup-reminder-banner' ).slideUp();
		$.post( {
			url: ajaxurl,
			data: {
				action: 'royalbr_backup_reminder_banner_later',
				nonce: royalbrBackupReminderBanner.nonce
			}
		} );
	} );

} );
