<?php
/**
 * Royal Backup & Reset Plugin Constants
 *
 * @package RoyalBackupReset
 * @since   1.0.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Resolve main plugin file early (prefer a define from main plugin file).
 */
$royalbr_plugin_file = defined( 'ROYALBR_PLUGIN_FILE' )
	? ROYALBR_PLUGIN_FILE
	: ( defined( 'ROYALBR_PLUGIN_DIR' )
		? trailingslashit( ROYALBR_PLUGIN_DIR ) . 'royal-backup-reset.php'
		: dirname( __DIR__ ) . '/royal-backup-reset.php' );

/**
 * Ensure core path/url constants exist before use.
 * (Safe, prefixed, and minimal global footprint.)
 */
royalbr_define_constant( 'ROYALBR_PLUGIN_FILE', $royalbr_plugin_file );
royalbr_define_constant( 'ROYALBR_PLUGIN_DIR',  trailingslashit( plugin_dir_path( ROYALBR_PLUGIN_FILE ) ) );
royalbr_define_constant( 'ROYALBR_PLUGIN_URL',  trailingslashit( plugin_dir_url( ROYALBR_PLUGIN_FILE ) ) );

/**
 * Version from header (frontend-safe; no wp-admin include).
 */
$royalbr_file_meta = get_file_data( ROYALBR_PLUGIN_FILE, array( 'Version' => 'Version' ), 'plugin' );
royalbr_define_constant( 'ROYALBR_VERSION', ! empty( $royalbr_file_meta['Version'] ) ? $royalbr_file_meta['Version'] : '0.0.0' );

/**
 * Define the rest.
 */
$royalbr_constants_to_define = array(
	'ROYALBR_INCLUDES_DIR' => trailingslashit( ROYALBR_PLUGIN_DIR . 'includes' ),
	'ROYALBR_ASSETS_DIR'   => trailingslashit( ROYALBR_PLUGIN_DIR . 'assets' ),
	'ROYALBR_BACKUP_DIR'   => trailingslashit( WP_CONTENT_DIR ) . 'royal-backup-reset/',
	'ROYALBR_ASSETS_URL'   => trailingslashit( ROYALBR_PLUGIN_URL . 'assets' ),
	'ROYALBR_ADMIN_URL'    => admin_url( 'admin.php?page=royal-backup-reset' ),
);

// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Loop variables are acceptable
foreach ( $royalbr_constants_to_define as $name => $value ) {
	royalbr_define_constant( $name, $value );
}

/**
 * Define constant safely.
 *
 * @since 1.0.0
 * @param string $name  Constant name.
 * @param mixed  $value Constant value.
 */
function royalbr_define_constant( $name, $value ) {
	if ( ! defined( $name ) ) {
		define( $name, $value );
	}
}
