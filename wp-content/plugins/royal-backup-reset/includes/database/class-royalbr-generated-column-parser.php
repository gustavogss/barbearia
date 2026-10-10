<?php
/**
 * ROYALBR Generated Column Parser
 *
 * @package RoyalBackupReset
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Generated Column Statement Parser.
 *
 * Parses MySQL/MariaDB generated column definitions from DDL statements
 * and INSERT statements to handle virtual and stored columns properly
 * during backup and restore operations.
 *
 * @since 1.0.0
 */
class ROYALBR_Generated_Column_Parser {

	/**
	 * Check if INSERT statement contains generated columns.
	 *
	 * Examines an INSERT statement to determine if any of the specified
	 * columns are generated columns.
	 *
	 * @see https://regex101.com/r/JZiJqH/2
	 *
	 * @since 1.0.0
	 * @param string $insert_query    The INSERT statement to analyze.
	 * @param array  $generated_cols  List of known generated column names.
	 * @return bool|null True if generated columns found, false if not, null on parse failure.
	 */
	public function contains_generated_columns( $insert_query, $generated_cols ) {
		$result = null;

		if ( preg_match( '/\s*insert.+?into(?:\s*`(?:[^`]|`)+?`|[^\(]+)(?:\s*\((.+?)\))?\s*values.+/i', $insert_query, $pattern_matches ) ) {
			$extracted_columns = isset( $pattern_matches[1] )
				? preg_split( '/\`\s*,\s*\`/', preg_replace( '/\`((?:[^\`]|\`)+)\`/', '$1', trim( $pattern_matches[1] ) ) )
				: array();

			$result = ( false === $extracted_columns ) || ( true === array_intersect( $generated_cols, $extracted_columns ) );
		}

		return $result;
	}

	/**
	 * Extract generated column definition details.
	 *
	 * Parses a column definition to extract generated column information
	 * including type (virtual/stored/persistent), position, and syntax.
	 *
	 * @see https://regex101.com/r/Fy2Bkd/12
	 *
	 * @since 1.0.0
	 * @param string $column_definition The column definition to parse.
	 * @param int    $offset_position   Starting position in CREATE TABLE statement.
	 * @return array|false Parsed column data or false if not generated.
	 */
	public function extract_column_definition( $column_definition, $offset_position ) {
		$regex_pattern = '/^\s*\`((?:[^`]|``)+)\`([^,\'"]+?)(?:((?:GENERATED\s*ALWAYS\s*)?AS\s*\(.+\))([\w\s]*)(COMMENT\s*(?:\'(?:[^\']|\'\')*\'|\"(?:[^"]|"")*\"))([\w\s]*)|((?:GENERATED\s*ALWAYS\s*)?AS\s*\(.+\)([\w\s]*)))/i';

		if ( ! preg_match_all( $regex_pattern, $column_definition, $definition_matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE ) ) {
			return false;
		}

		if ( empty( $definition_matches ) ) {
			return false;
		}

		foreach ( $definition_matches as $match_set ) {
			$type_definition = ( ! empty( $match_set[4][0] ) ? $match_set[4][0] : '' )
				. ( ! empty( $match_set[6][0] ) ? $match_set[6][0] : '' )
				. ( ! empty( $match_set[8][0] ) ? $match_set[8][0] : '' );

			$is_virtual_type = preg_match( '/\bvirtual\b/i', $type_definition )
				|| ( ! preg_match( '/\bstored\b/i', $type_definition ) && ! preg_match( '/\bpersistent\b/i', $type_definition ) );

			$parsed_data = array(
				'column_definition'            => $match_set[0][0],
				'column_name'                  => $match_set[1][0],
				'column_data_type_definition'  => array(),
				'is_virtual'                   => $is_virtual_type,
			);

			if ( ! empty( $match_set[2] ) ) {
				$parsed_data['column_data_type_definition']['DATA_TYPE_TOKEN']    = $match_set[2];
				$parsed_data['column_data_type_definition']['DATA_TYPE_TOKEN'][1] = (int) $offset_position + (int) $parsed_data['column_data_type_definition']['DATA_TYPE_TOKEN'][1];
			}

			if ( ! empty( $match_set[3] ) ) {
				$parsed_data['column_data_type_definition']['GENERATED_ALWAYS_TOKEN'] = $match_set[3];
				if ( empty( $parsed_data['column_data_type_definition'][1] ) && ! empty( $match_set[7][0] ) ) {
					$parsed_data['column_data_type_definition']['GENERATED_ALWAYS_TOKEN'] = $match_set[7];
				}
				$parsed_data['column_data_type_definition']['GENERATED_ALWAYS_TOKEN'][1] = (int) $offset_position + (int) $parsed_data['column_data_type_definition']['GENERATED_ALWAYS_TOKEN'][1];
			}

			if ( ! empty( $match_set[4] ) ) {
				$parsed_data['column_data_type_definition'][2]    = $match_set[4];
				$parsed_data['column_data_type_definition'][2][1] = (int) $offset_position + (int) $parsed_data['column_data_type_definition'][2][1];
			}

			if ( ! empty( $match_set[5] ) ) {
				$parsed_data['column_data_type_definition']['COMMENT_TOKEN']    = $match_set[5];
				$parsed_data['column_data_type_definition']['COMMENT_TOKEN'][1] = (int) $offset_position + (int) $parsed_data['column_data_type_definition']['COMMENT_TOKEN'][1];
			}

			if ( ! empty( $match_set[6] ) ) {
				$parsed_data['column_data_type_definition'][4]    = $match_set[6];
				$parsed_data['column_data_type_definition'][4][1] = (int) $offset_position + (int) $parsed_data['column_data_type_definition'][4][1];
			}

			if ( ! empty( $match_set[8] ) ) {
				$parsed_data['column_data_type_definition'][5]    = $match_set[8];
				$parsed_data['column_data_type_definition'][5][1] = (int) $offset_position + (int) $parsed_data['column_data_type_definition'][5][1];
			}
		}

		return isset( $parsed_data ) ? $parsed_data : false;
	}
}
