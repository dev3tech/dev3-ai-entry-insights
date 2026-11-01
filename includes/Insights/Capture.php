<?php
/**
 * Capture pipeline shared by every adapter: normalised fields → entry row → rules → AI queue.
 * Runs inside the form plugin's submit request, so it never calls the AI synchronously.
 *
 * @package Dev3\AiEntryInsights
 */

namespace Dev3\AiEntryInsights\Insights;

use Dev3\AiEntryInsights\Core3\Api\Ai;
use Dev3\AiEntryInsights\Core3\Core\Rate_Limiter;
use Dev3\AiEntryInsights\Data\Entries;
use Dev3\AiEntryInsights\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Capture.
 */
class Capture {

	/**
	 * Records a submission.
	 *
	 * @param string $source     Adapter id (wpforms, gravityforms, cf7…).
	 * @param int    $form_id    Form id.
	 * @param string $form_title Form title.
	 * @param array  $fields     Normalised fields (Text::field rows).
	 * @param array  $meta       { ref, page_url, created_at, import (bool), analyze (bool) }.
	 * @return int Entry id (0 when skipped).
	 */
	public static function record( $source, $form_id, $form_title, array $fields, array $meta = array() ) {
		$source  = sanitize_key( (string) $source );
		$form_id = (int) $form_id;
		$import  = ! empty( $meta['import'] );
		if ( ! Settings::get( 'source_' . $source, true ) ) {
			return 0;
		}
		$config = Settings::form_config( $source, $form_id );
		if ( ! $config['capture'] && ! $import ) {
			return 0;
		}
		/**
		 * Filters the normalised fields before an entry is stored.
		 *
		 * @param array  $fields  Fields [ { key, label, type, value } ].
		 * @param string $source  Adapter id.
		 * @param int    $form_id Form id.
		 */
		$fields = array_values( array_filter( (array) apply_filters( 'dev3ei_capture_fields', $fields, $source, $form_id ) ) );
		/**
		 * Whether to capture this submission.
		 *
		 * @param bool   $capture Capture.
		 * @param string $source  Adapter id.
		 * @param int    $form_id Form id.
		 * @param array  $fields  Fields.
		 */
		if ( ! $fields || ! apply_filters( 'dev3ei_should_capture', true, $source, $form_id, $fields ) ) {
			return 0;
		}
		$ref = isset( $meta['ref'] ) ? substr( (string) $meta['ref'], 0, 40 ) : '';
		if ( '' !== $ref ) {
			$existing = Entries::find_ref( $source, $ref );
			if ( $existing ) {
				return (int) $existing['id'];
			}
		}
		$page = '';
		if ( Settings::get( 'store_page_url', true ) && ! empty( $meta['page_url'] ) ) {
			$page = esc_url_raw( substr( (string) $meta['page_url'], 0, 255 ) );
		}
		$row = array(
			'source'       => $source,
			'form_id'      => $form_id,
			'form_title'   => mb_substr( sanitize_text_field( (string) $form_title ), 0, 190 ),
			'source_ref'   => $ref,
			'fields'       => $fields,
			'email'        => Text::email( $fields ),
			'name'         => Text::name( $fields ),
			'page_url'     => $page,
			'ip_hash'      => $import ? '' : sha1( Rate_Limiter::client_ip() . wp_salt( 'nonce' ) ),
			'content_hash' => Text::hash( $fields ),
			'tags'         => array(),
			'status'       => $import ? 'read' : 'new',
			'alerted'      => $import ? 1 : 0,
			'history'      => array(
				array(
					'event'  => $import ? 'imported' : 'captured',
					'detail' => $source,
					'by'     => 'system',
					'at'     => gmdate( 'c' ),
				),
			),
		);
		if ( ! empty( $meta['created_at'] ) && strtotime( (string) $meta['created_at'] ) ) {
			$row['created_at'] = gmdate( 'Y-m-d H:i:s', strtotime( (string) $meta['created_at'] ) );
		}
		$id = Entries::insert( $row );
		if ( ! $id ) {
			return 0;
		}
		$entry   = Analyzer::apply_rules( $id );
		$analyze = isset( $meta['analyze'] ) ? (bool) $meta['analyze'] : ( Settings::get( 'auto_analyze', true ) && $config['analyze'] );
		if ( $analyze && Ai::enabled( 'cron' ) ) {
			Analyzer::enqueue( $id );
		} else {
			Alerts::maybe_send( $entry );
		}
		/**
		 * Fires after an entry was captured (rules applied, AI queued).
		 *
		 * @param int    $id      Entry id.
		 * @param string $source  Adapter id.
		 * @param int    $form_id Form id.
		 */
		do_action( 'dev3ei_entry_captured', $id, $source, $form_id );
		return $id;
	}

	/**
	 * Page the form was submitted from (referer), when known.
	 *
	 * @return string
	 */
	public static function referer() {
		$ref = wp_get_raw_referer();
		return $ref ? (string) $ref : '';
	}
}
