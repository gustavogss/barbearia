<?php
/**
 * Premium Plugin Detection & Auto-Deactivation
 *
 * If premium version is active, auto-deactivate free version.
 *
 * @package RoyalBackupReset
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Deactivate free version if premium version is active
add_action( 'admin_init', function () {
	// This returns e.g.:
	// - "royal-backup-reset/royal-backup-reset.php"      (free)
	// - "royal-backup-reset-pro/royal-backup-reset.php"  (pro)
	$this_plugin = plugin_basename( __FILE__ );

	// Only run this logic for the Pro build (folder with "-pro")
	if ( strpos( $this_plugin, 'royal-backup-reset-pro/' ) === false ) {
		// We're in the free plugin → do nothing
		return;
	}

	// Slug of the free plugin we want to deactivate
	$free_plugin = 'royal-backup-reset/royal-backup-reset.php';

	require_once ABSPATH . 'wp-admin/includes/plugin.php';

	if ( is_plugin_active( $free_plugin ) ) {
		// Deactivate and set flag for notice
		deactivate_plugins( $free_plugin, true );
		update_option( 'royalbr_free_version_deactivated', true );
	}
});

// Show admin notice after free version is deactivated
add_action( 'admin_notices', function() {
	if ( get_option( 'royalbr_free_version_deactivated' ) ) {
		delete_option( 'royalbr_free_version_deactivated' );
		?>
		<div class="notice notice-info is-dismissible">
			<p><?php echo wp_kses_post( __( 'The Free Version of Royal Backup & Reset has been <strong>deactivated</strong> because the Premium version is now active. You can safely <strong>delete the Free Version</strong>.', 'royal-backup-reset' ) ); ?></p>
		</div>
		<?php
	}
});