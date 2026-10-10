<?php
/**
 * Options Handler Class
 *
 * Handles plugin settings storage and retrieval.
 *
 * @package RoyalBackupReset
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * ROYALBR Options Management Class
 *
 * Static wrapper methods for WordPress options API with defaults support.
 *
 * @since 1.0.0
 */
class ROYALBR_Options {

	/**
	 * Get ROYALBR option with default value support.
	 *
	 * @since  1.0.0
	 * @param  string $option  Option name (will be prefixed with 'royalbr_').
	 * @param  mixed  $default Default value if option doesn't exist.
	 * @return mixed Option value or default.
	 */
	public static function get_royalbr_option( $option, $default = null ) {
		$ret = get_option( $option, $default );
		return apply_filters( 'royalbr_get_option', $ret, $option, $default );
	}

	/**
	 * Update ROYALBR option.
	 *
	 * @since  1.0.0
	 * @param  string $option   Option name.
	 * @param  mixed  $value    Option value.
	 * @param  bool   $use_cache Whether to use cache (compatibility param).
	 * @param  string $autoload Whether to autoload option ('yes' or 'no').
	 * @return bool True on success, false on failure.
	 */
	public static function update_royalbr_option( $option, $value, $use_cache = true, $autoload = 'yes' ) {
		return update_option( $option, apply_filters( 'royalbr_update_option', $value, $option, $use_cache ), $autoload );
	}

	/**
	 * Delete ROYALBR option.
	 *
	 * @since  1.0.0
	 * @param  string $option Option name.
	 * @return bool True on success, false on failure.
	 */
	public static function delete_royalbr_option( $option ) {
		return delete_option( $option );
	}

	/**
	 * Register all plugin settings.
	 *
	 * Called during admin_init action.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public static function register_settings() {
		// Backup defaults
		register_setting( 'royalbr-options-group', 'royalbr_backup_include_db', array( __CLASS__, 'sanitize_checkbox' ) );
		register_setting( 'royalbr-options-group', 'royalbr_backup_include_files', array( __CLASS__, 'sanitize_checkbox' ) );
		register_setting( 'royalbr-options-group', 'royalbr_backup_include_wpcore', array( __CLASS__, 'sanitize_checkbox' ) );

		// Restore defaults
		register_setting( 'royalbr-options-group', 'royalbr_restore_db', array( __CLASS__, 'sanitize_checkbox' ) );
		register_setting( 'royalbr-options-group', 'royalbr_restore_plugins', array( __CLASS__, 'sanitize_checkbox' ) );
		register_setting( 'royalbr-options-group', 'royalbr_restore_themes', array( __CLASS__, 'sanitize_checkbox' ) );
		register_setting( 'royalbr-options-group', 'royalbr_restore_uploads', array( __CLASS__, 'sanitize_checkbox' ) );
		register_setting( 'royalbr-options-group', 'royalbr_restore_others', array( __CLASS__, 'sanitize_checkbox' ) );

		// Reset defaults
		register_setting( 'royalbr-options-group', 'royalbr_reactivate_theme', array( __CLASS__, 'sanitize_checkbox' ) );
		register_setting( 'royalbr-options-group', 'royalbr_reactivate_plugins', array( __CLASS__, 'sanitize_checkbox' ) );
		register_setting( 'royalbr-options-group', 'royalbr_keep_royalbr_active', array( __CLASS__, 'sanitize_checkbox' ) );
		register_setting( 'royalbr-options-group', 'royalbr_clear_uploads', array( __CLASS__, 'sanitize_checkbox' ) );
	}

	/**
	 * Sanitize checkbox value.
	 *
	 * Ensures checkbox values are stored as boolean.
	 *
	 * @since  1.0.0
	 * @param  mixed $value Input value.
	 * @return bool Sanitized boolean value.
	 */
	public static function sanitize_checkbox( $value ) {
		return (bool) $value;
	}

	/**
	 * Get all default option values.
	 *
	 * Central place to define defaults for reference.
	 * Note: These are also specified inline when calling get_royalbr_option()
	 *
	 * @since  1.0.0
	 * @return array Array of option_name => default_value pairs.
	 */
	public static function get_defaults() {
		return array(
			// Backup defaults
			'royalbr_backup_include_db'     => true,
			'royalbr_backup_include_files'  => true,
			'royalbr_backup_include_wpcore' => false,

			// Restore defaults (only db checked by default)
			'royalbr_restore_db'           => true,
			'royalbr_restore_plugins'      => false,
			'royalbr_restore_themes'       => false,
			'royalbr_restore_uploads'      => false,
			'royalbr_restore_others'       => false,

			// Reset defaults (only keep ROYALBR active by default)
			'royalbr_reactivate_theme'     => false,
			'royalbr_reactivate_plugins'   => false,
			'royalbr_keep_royalbr_active'      => true,
			'royalbr_clear_uploads'        => false,

			// Backup location defaults
			'royalbr_backup_loc_local'     => true,
			'royalbr_backup_loc_gdrive'    => false,
			'royalbr_backup_loc_dropbox'   => false,
			'royalbr_backup_loc_s3'        => false,
		);
	}
}
