<?php
/**
 * ROYALBR SQL Helpers Trait
 *
 * @package RoyalBackupReset
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * SQL Helper Methods Trait.
 *
 * Provides reusable SQL string manipulation and escaping utilities.
 * Used by database utility classes for consistent SQL identifier handling.
 *
 * @since 1.0.0
 */
trait ROYALBR_SQL_Helpers {

	/**
	 * Substitute first occurrence of needle in haystack.
	 *
	 * @since  1.0.0
	 * @param  string $needle      String to locate.
	 * @param  string $replacement Substitute text.
	 * @param  string $haystack    Text to modify.
	 * @return string Modified text.
	 */
	public static function replace_first_occurrence( $needle, $replacement, $haystack ) {
		$location = strpos( $haystack, $needle );
		if ( false !== $location ) {
			return substr_replace( $haystack, $replacement, $location, strlen( $needle ) );
		}
		return $haystack;
	}

	/**
	 * Enclose SQL identifier with backticks for safe usage.
	 *
	 * @since  1.0.0
	 * @param  string $name SQL identifier name.
	 * @return string Backtick-enclosed identifier.
	 */
	public static function backquote( $name ) {
		return '`' . str_replace( '`', '``', $name ) . '`';
	}

	/**
	 * Substitute last occurrence of needle in haystack.
	 *
	 * @since  1.0.0
	 * @param  string $needle      String to locate.
	 * @param  string $replacement Substitute text.
	 * @param  string $haystack    Text to modify.
	 * @return string Modified text.
	 */
	public static function replace_last_occurrence( $needle, $replacement, $haystack ) {
		$location = strrpos( $haystack, $needle );
		if ( false !== $location ) {
			return substr_replace( $haystack, $replacement, $location, strlen( $needle ) );
		}
		return $haystack;
	}

	/**
	 * Escape special characters for LIKE pattern matching.
	 *
	 * @since  1.0.0
	 * @param  string $input The unescaped text.
	 * @return string Text formatted for LIKE clause.
	 */
	public static function esc_like( $input ) {
		return function_exists( 'esc_like' ) ? esc_like( $input ) : addcslashes( $input, '_%\\' );
	}
}
