<?php
/**
 * WPForms (Lite and Pro).
 *
 * WPForms Lite does not store entries, so every successful submission is captured into the
 * plugin's own table from `wpforms_process_complete( $fields, $entry, $form_data, $entry_id )`
 * (Pro passes its entry id, kept as the source reference). Spam-marked submissions are skipped.
 * With Pro, existing entries can be imported through `wpforms()->obj( 'entry' )`.
 *
 * @package Dev3\AiEntryInsights
 */

namespace Dev3\AiEntryInsights\Adapters;

use Dev3\AiEntryInsights\Insights\Capture;
use Dev3\AiEntryInsights\Insights\Text;

defined( 'ABSPATH' ) || exit;

/**
 * WPForms adapter.
 */
class WPForms_Adapter extends Adapter {

	/**
	 * WPForms field type → normalised type.
	 */
	const TYPES = array(
		'name'               => 'name',
		'email'              => 'email',
		'phone'              => 'phone',
		'text'               => 'text',
		'textarea'           => 'textarea',
		'richtext'           => 'textarea',
		'number'             => 'number',
		'number-slider'      => 'number',
		'url'                => 'url',
		'select'             => 'choice',
		'radio'              => 'choice',
		'checkbox'           => 'choice',
		'rating'             => 'number',
		'likert_scale'       => 'choice',
		'net_promoter_score' => 'number',
		'address'            => 'address',
		'date-time'          => 'date',
		'file-upload'        => 'file',
		'hidden'             => 'hidden',
		'password'           => 'password',
		'gdpr-checkbox'      => 'consent',
		'signature'          => 'signature',
		'html'               => 'html',
		'divider'            => 'divider',
		'pagebreak'          => 'page',
		'captcha'            => 'captcha',
		'payment-total'      => 'number',
		'payment-single'     => 'number',
		'payment-select'     => 'choice',
		'payment-multiple'   => 'choice',
		'payment-checkbox'   => 'choice',
		'stripe-credit-card' => 'creditcard',
		'authorize_net'      => 'creditcard',
		'square'             => 'creditcard',
		'paypal-commerce'    => 'creditcard',
	);

	/**
	 * Id.
	 *
	 * @return string
	 */
	public function id() {
		return 'wpforms';
	}

	/**
	 * Label.
	 *
	 * @return string
	 */
	public function label() {
		return 'WPForms';
	}

	/**
	 * Active?
	 *
	 * @return bool
	 */
	public function is_active() {
		return function_exists( 'wpforms' ) && defined( 'WPFORMS_VERSION' );
	}

	/**
	 * Version (+ Lite/Pro).
	 *
	 * @return string
	 */
	public function version() {
		if ( ! defined( 'WPFORMS_VERSION' ) ) {
			return '';
		}
		return WPFORMS_VERSION . ( $this->is_pro() ? ' Pro' : ' Lite' );
	}

	/**
	 * Plugin URL.
	 *
	 * @return string
	 */
	public function plugin_url() {
		return 'https://wordpress.org/plugins/wpforms-lite/';
	}

	/**
	 * Pro with the entries API?
	 *
	 * @return bool
	 */
	public function is_pro() {
		return $this->is_active() && method_exists( wpforms(), 'is_pro' ) && wpforms()->is_pro() && null !== $this->entry_handler();
	}

	/**
	 * WPForms Pro entry handler.
	 *
	 * @return object|null
	 */
	private function entry_handler() {
		if ( ! function_exists( 'wpforms' ) || ! method_exists( wpforms(), 'obj' ) ) {
			return null;
		}
		$handler = wpforms()->obj( 'entry' );
		return is_object( $handler ) && method_exists( $handler, 'get_entries' ) ? $handler : null;
	}

	/**
	 * Forms.
	 *
	 * @return array
	 */
	public function list_forms() {
		if ( ! $this->is_active() ) {
			return array();
		}
		$posts = get_posts(
			array(
				'post_type'      => 'wpforms',
				'post_status'    => 'publish',
				'posts_per_page' => 100,
				'orderby'        => 'title',
				'order'          => 'ASC',
			)
		);
		$out   = array();
		foreach ( $posts as $post ) {
			$out[] = array(
				'id'       => (int) $post->ID,
				'title'    => get_the_title( $post ),
				'edit_url' => admin_url( 'admin.php?page=wpforms-builder&view=fields&form_id=' . (int) $post->ID ),
			);
		}
		return $out;
	}

	/**
	 * Hooks.
	 */
	public function register() {
		add_action( 'wpforms_process_complete', array( $this, 'process_complete' ), 20, 4 );
	}

	/**
	 * `wpforms_process_complete`: capture.
	 *
	 * @param array $fields    Formatted fields.
	 * @param array $entry     Raw entry.
	 * @param array $form_data Form data.
	 * @param int   $entry_id  Pro entry id (0 in Lite).
	 * @return int Entry id.
	 */
	public function process_complete( $fields, $entry = array(), $form_data = array(), $entry_id = 0 ) {
		$form_id = isset( $form_data['id'] ) ? (int) $form_data['id'] : ( isset( $entry['id'] ) ? (int) $entry['id'] : 0 );
		if ( ! $form_id || ! empty( $form_data['spam_reason'] ) ) {
			return 0;
		}
		$process = function_exists( 'wpforms' ) && method_exists( wpforms(), 'obj' ) ? wpforms()->obj( 'process' ) : null;
		if ( is_object( $process ) && ! empty( $process->spam_reason ) ) {
			return 0;
		}
		$title = ! empty( $form_data['settings']['form_title'] ) ? (string) $form_data['settings']['form_title'] : get_the_title( $form_id );
		$page  = isset( $entry['page_url'] ) ? (string) $entry['page_url'] : Capture::referer();
		return Capture::record(
			$this->id(),
			$form_id,
			$title,
			self::normalize( (array) $fields ),
			array(
				'ref'      => $entry_id ? (string) (int) $entry_id : '',
				'page_url' => $page,
			)
		);
	}

	/**
	 * Formatted WPForms fields → normalised fields.
	 *
	 * @param array $fields Formatted fields (field id => { name, value, id, type, … }).
	 * @return array
	 */
	public static function normalize( array $fields ) {
		$out = array();
		foreach ( $fields as $fid => $field ) {
			if ( ! is_array( $field ) ) {
				continue;
			}
			$raw_type = isset( $field['type'] ) ? (string) $field['type'] : 'text';
			$type     = isset( self::TYPES[ $raw_type ] ) ? self::TYPES[ $raw_type ] : 'text';
			$value    = isset( $field['value'] ) ? (string) $field['value'] : '';
			if ( 'name' === $raw_type && '' === $value ) {
				$value = trim( implode( ' ', array_filter( array( isset( $field['first'] ) ? $field['first'] : '', isset( $field['middle'] ) ? $field['middle'] : '', isset( $field['last'] ) ? $field['last'] : '' ) ) ) );
			}
			if ( 'choice' === $type || 'address' === $type ) {
				$value = str_replace( array( "\r\n", "\n" ), ', ', $value );
			}
			$row = Text::field( 'f' . ( isset( $field['id'] ) ? (int) $field['id'] : (int) $fid ), isset( $field['name'] ) ? (string) $field['name'] : '', $type, $value );
			if ( $row ) {
				$out[] = $row;
			}
		}
		return $out;
	}

	/**
	 * Import is available with WPForms Pro only.
	 *
	 * @return bool
	 */
	public function can_import() {
		return $this->is_pro();
	}

	/**
	 * Note for Lite.
	 *
	 * @return string
	 */
	public function import_note() {
		return $this->is_pro() ? '' : __( 'WPForms Lite does not store entries. New submissions are captured from now on.', 'dev3-ai-entry-insights' );
	}

	/**
	 * Pro entry ids.
	 *
	 * @param int $form_id Form id.
	 * @param int $limit   Max.
	 * @return string[]
	 */
	public function import_refs( $form_id, $limit = 200 ) {
		$handler = $this->entry_handler();
		if ( ! $handler || ! $this->is_pro() ) {
			return array();
		}
		$rows = (array) $handler->get_entries(
			array(
				'form_id' => (int) $form_id,
				'number'  => (int) $limit,
				'orderby' => 'entry_id',
				'order'   => 'DESC',
			)
		);
		$out  = array();
		foreach ( $rows as $row ) {
			$row = (object) $row;
			if ( isset( $row->entry_id ) && ( ! isset( $row->status ) || ! in_array( $row->status, array( 'spam', 'trash' ), true ) ) ) {
				$out[] = (string) (int) $row->entry_id;
			}
		}
		return $out;
	}

	/**
	 * Imports one Pro entry.
	 *
	 * @param int    $form_id Form id.
	 * @param string $ref     Entry id.
	 * @param bool   $analyze Queue for AI.
	 * @return int|\WP_Error
	 */
	public function import_one( $form_id, $ref, $analyze = false ) {
		$handler = $this->entry_handler();
		$row     = $handler && method_exists( $handler, 'get' ) ? $handler->get( (int) $ref ) : null;
		if ( ! $row ) {
			return new \WP_Error( 'dev3ei_not_found', __( 'Entry not found.', 'dev3-ai-entry-insights' ) );
		}
		$row    = (object) $row;
		$fields = json_decode( isset( $row->fields ) ? (string) $row->fields : '', true );
		$id     = Capture::record(
			$this->id(),
			(int) $form_id,
			$this->form_title( (int) $form_id ),
			self::normalize( is_array( $fields ) ? $fields : array() ),
			array(
				'ref'        => (string) (int) $ref,
				'created_at' => isset( $row->date ) ? $row->date . ' UTC' : '',
				'import'     => true,
				'analyze'    => (bool) $analyze,
			)
		);
		return $id ? $id : new \WP_Error( 'dev3ei_skipped', __( 'Entry skipped (empty or capture off).', 'dev3-ai-entry-insights' ) );
	}

	/**
	 * Link to the entry (Pro) or the form builder.
	 *
	 * @param array $entry Entry.
	 * @return string
	 */
	public function entry_url( array $entry ) {
		if ( '' !== (string) $entry['source_ref'] ) {
			return admin_url( 'admin.php?page=wpforms-entries&view=details&entry_id=' . (int) $entry['source_ref'] );
		}
		return admin_url( 'admin.php?page=wpforms-builder&view=fields&form_id=' . (int) $entry['form_id'] );
	}
}
