<?php
/**
 * CSV export of the (filtered) inbox with insights, via admin-post.php (nonce + capability).
 *
 * @package Dev3\AiEntryInsights
 */

namespace Dev3\AiEntryInsights\Export;

use Dev3\AiEntryInsights\Core3\Core\Capabilities;
use Dev3\AiEntryInsights\Data\Entries;
use Dev3\AiEntryInsights\Insights\Alerts;

defined( 'ABSPATH' ) || exit;

/**
 * CSV.
 */
class Csv {

	const ACTION = 'dev3ei_export';

	const FILTERS = array( 'view', 'source', 'form', 'sentiment', 'category', 'urgency', 'tag', 'min_score', 'from', 'to', 'search' );

	/**
	 * Hooks.
	 */
	public static function register() {
		add_action( 'admin_post_' . self::ACTION, array( __CLASS__, 'download' ) );
	}

	/**
	 * Download URL for filters.
	 *
	 * @param array $filters Filters.
	 * @return string
	 */
	public static function url( array $filters = array() ) {
		return add_query_arg(
			array_merge(
				array_intersect_key( $filters, array_flip( self::FILTERS ) ),
				array(
					'action'   => self::ACTION,
					'_wpnonce' => wp_create_nonce( self::ACTION ),
				)
			),
			admin_url( 'admin-post.php' )
		);
	}

	/**
	 * Neutralises spreadsheet formulas.
	 *
	 * @param mixed $value Value.
	 * @return string
	 */
	public static function cell( $value ) {
		$value = is_scalar( $value ) ? (string) $value : (string) wp_json_encode( $value );
		return preg_match( '/^[=+\-@\t\r]/', $value ) ? "'" . $value : $value;
	}

	/**
	 * Rows for filters.
	 *
	 * @param array $filters Filters.
	 * @return array[] Header row first.
	 */
	public static function rows( array $filters ) {
		$cats = Alerts::category_labels();
		$out  = array(
			array( 'ID', 'Date (UTC)', 'Source', 'Form', 'Name', 'Email', 'Status', 'Lead score', 'Lead reason', 'Sentiment', 'Urgency', 'Category', 'Intent', 'Summary', 'Language', 'Tags', 'Insights by', 'Answers' ),
		);
		$ids  = Entries::ids( $filters, 20000 );
		foreach ( array_chunk( $ids, 200 ) as $chunk ) {
			$batch = Entries::query(
				array(
					'view'     => 'any',
					'ids'      => $chunk,
					'per_page' => 200,
				)
			);
			foreach ( $batch['items'] as $e ) {
				$answers = array();
				foreach ( $e['fields'] as $f ) {
					$answers[] = $f['label'] . ': ' . $f['value'];
				}
				$out[] = array_map(
					array( __CLASS__, 'cell' ),
					array( $e['id'], $e['created_at'], $e['source'], $e['form_title'], $e['name'], $e['email'], $e['status'], $e['lead_score'], $e['lead_reason'], $e['sentiment'], $e['urgency'], isset( $cats[ $e['category'] ] ) ? $cats[ $e['category'] ] : $e['category'], $e['intent'], $e['summary'], $e['language'], implode( ', ', $e['tags'] ), $e['insight_src'], implode( ' | ', $answers ) )
				);
			}
		}
		return $out;
	}

	/**
	 * Streams the CSV.
	 */
	public static function download() {
		if ( ! Capabilities::can_manage() ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'dev3-ai-entry-insights' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( self::ACTION );
		$filters = array();
		foreach ( self::FILTERS as $key ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- checked above.
			if ( isset( $_GET[ $key ] ) && '' !== $_GET[ $key ] ) {
				// phpcs:ignore WordPress.Security.NonceVerification.Recommended
				$filters[ $key ] = sanitize_text_field( wp_unslash( $_GET[ $key ] ) );
			}
		}
		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=entry-insights-' . gmdate( 'Y-m-d' ) . '.csv' );
		$out = fopen( 'php://output', 'w' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		fwrite( $out, "\xEF\xBB\xBF" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
		foreach ( self::rows( $filters ) as $row ) {
			fputcsv( $out, $row );
		}
		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		exit;
	}
}
