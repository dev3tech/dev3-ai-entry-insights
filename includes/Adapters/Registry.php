<?php
/**
 * Adapter registry (filter dev3ei_adapters lets developers add one).
 *
 * @package Dev3\AiEntryInsights
 */

namespace Dev3\AiEntryInsights\Adapters;

use Dev3\AiEntryInsights\Core3\Core\Jobs;
use Dev3\AiEntryInsights\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Registry.
 */
class Registry {

	const IMPORT_JOB = 'dev3ei_import';

	/**
	 * Instances.
	 *
	 * @var Adapter[]|null
	 */
	private static $adapters = null;

	/**
	 * All adapters keyed by id.
	 *
	 * @return Adapter[]
	 */
	public static function all() {
		if ( null === self::$adapters ) {
			$list = array( new Gravity_Forms_Adapter(), new WPForms_Adapter(), new CF7_Adapter() );
			/**
			 * Form-plugin adapters. Each must extend Dev3\AiEntryInsights\Adapters\Adapter.
			 *
			 * @param Adapter[] $list Adapters.
			 */
			$list           = (array) apply_filters( 'dev3ei_adapters', $list );
			self::$adapters = array();
			foreach ( $list as $adapter ) {
				if ( $adapter instanceof Adapter ) {
					self::$adapters[ $adapter->id() ] = $adapter;
				}
			}
		}
		return self::$adapters;
	}

	/**
	 * One adapter.
	 *
	 * @param string $id Id.
	 * @return Adapter|null
	 */
	public static function get( $id ) {
		$all = self::all();
		return isset( $all[ $id ] ) ? $all[ $id ] : null;
	}

	/**
	 * Resets (tests).
	 */
	public static function reset() {
		self::$adapters = null;
	}

	/**
	 * Hooks every active, enabled adapter (on plugins_loaded, after the form plugins loaded).
	 */
	public static function boot() {
		Jobs::register( self::IMPORT_JOB, array( __CLASS__, 'run_import_chunk' ) );
		foreach ( self::all() as $adapter ) {
			if ( $adapter->is_active() && Settings::get( 'source_' . $adapter->id(), true ) ) {
				$adapter->register();
			}
		}
	}

	/**
	 * Labels by id (for the UI), active or not.
	 *
	 * @return array
	 */
	public static function summary() {
		$out = array();
		foreach ( self::all() as $adapter ) {
			$out[] = array(
				'id'          => $adapter->id(),
				'label'       => $adapter->label(),
				'active'      => $adapter->is_active(),
				'enabled'     => (bool) Settings::get( 'source_' . $adapter->id(), true ),
				'version'     => $adapter->version(),
				'can_import'  => $adapter->is_active() && $adapter->can_import(),
				'import_note' => $adapter->import_note(),
				'plugin_url'  => $adapter->plugin_url(),
			);
		}
		return $out;
	}

	/**
	 * Import job handler: items are "form_id|ref".
	 *
	 * @param array $items Items.
	 * @param array $job   Job.
	 * @return array
	 */
	public static function run_import_chunk( array $items, array $job ) {
		$adapter = self::get( isset( $job['args']['source'] ) ? (string) $job['args']['source'] : '' );
		$analyze = ! empty( $job['args']['analyze'] );
		$out     = array();
		foreach ( $items as $item ) {
			list( $form_id, $ref ) = array_pad( explode( '|', (string) $item, 2 ), 2, '' );
			if ( ! $adapter || ! $adapter->is_active() ) {
				$out[ $item ] = __( 'The form plugin is not active.', 'dev3-ai-entry-insights' );
				continue;
			}
			$result       = $adapter->import_one( (int) $form_id, $ref, $analyze );
			$out[ $item ] = is_wp_error( $result ) ? $result->get_error_message() : true;
		}
		return $out;
	}
}
