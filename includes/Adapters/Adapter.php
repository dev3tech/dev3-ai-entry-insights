<?php
/**
 * Base class for form-plugin adapters.
 *
 * @package Dev3\AiEntryInsights
 */

namespace Dev3\AiEntryInsights\Adapters;

defined( 'ABSPATH' ) || exit;

/**
 * Adapter contract.
 */
abstract class Adapter {

	/**
	 * Adapter id (also the entry "source").
	 *
	 * @return string
	 */
	abstract public function id();

	/**
	 * Display name.
	 *
	 * @return string
	 */
	abstract public function label();

	/**
	 * Is the form plugin active?
	 *
	 * @return bool
	 */
	abstract public function is_active();

	/**
	 * Form plugin version ('' when unknown).
	 *
	 * @return string
	 */
	abstract public function version();

	/**
	 * Forms: [ { id, title, edit_url } ].
	 *
	 * @return array
	 */
	abstract public function list_forms();

	/**
	 * Hooks into the form plugin (only called when active and enabled).
	 */
	abstract public function register();

	/**
	 * Where to get the plugin.
	 *
	 * @return string
	 */
	public function plugin_url() {
		return '';
	}

	/**
	 * Does the form plugin keep its own entries we can import?
	 *
	 * @return bool
	 */
	public function can_import() {
		return false;
	}

	/**
	 * Why import is not available ('' when it is).
	 *
	 * @return string
	 */
	public function import_note() {
		return '';
	}

	/**
	 * Source entry references for a form, newest first.
	 *
	 * @param int $form_id Form id.
	 * @param int $limit   Max.
	 * @return string[]
	 */
	public function import_refs( $form_id, $limit = 200 ) {
		unset( $form_id, $limit );
		return array();
	}

	/**
	 * Imports one stored entry.
	 *
	 * @param int    $form_id Form id.
	 * @param string $ref     Source entry id.
	 * @param bool   $analyze Queue for AI.
	 * @return int|\WP_Error Entry id.
	 */
	public function import_one( $form_id, $ref, $analyze = false ) {
		unset( $form_id, $ref, $analyze );
		return new \WP_Error( 'dev3ei_no_import', __( 'This form plugin does not store entries.', 'dev3-ai-entry-insights' ) );
	}

	/**
	 * Link to the entry (or the form) in the form plugin.
	 *
	 * @param array $entry Entry.
	 * @return string
	 */
	public function entry_url( array $entry ) {
		unset( $entry );
		return '';
	}

	/**
	 * Form title by id.
	 *
	 * @param int $form_id Form id.
	 * @return string
	 */
	public function form_title( $form_id ) {
		foreach ( $this->list_forms() as $form ) {
			if ( (int) $form['id'] === (int) $form_id ) {
				return (string) $form['title'];
			}
		}
		return '';
	}
}
