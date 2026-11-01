<?php
/**
 * Minimal in-memory Gravity Forms stubs (GFForms / GFCommon / GF_Field / GFAPI) for unit tests.
 * Gravity Forms is commercial and not installed in wp-env; the adapter only uses these documented APIs:
 * GFAPI::get_forms, get_form, get_entries, get_entry, add_note, and the gform_* hooks.
 *
 * @package Dev3\AiEntryInsights
 */

// phpcs:ignoreFile

if ( ! class_exists( 'GFForms' ) ) {
	class GFForms {
		public static $version = '2.9.0-stub';
	}

	class GFCommon {
		public static $version = '2.9.0-stub';
	}

	class GF_Field {
		public function __construct( $data = array() ) {
			foreach ( $data as $k => $v ) {
				$this->$k = $v;
			}
		}
	}

	class GFAPI {
		public static $forms   = array();
		public static $entries = array();
		public static $notes   = array();

		public static function reset() {
			self::$forms   = array();
			self::$entries = array();
			self::$notes   = array();
		}

		public static function add_form( $form ) {
			$id             = count( self::$forms ) + 1;
			$form['id']     = $id;
			$form['fields'] = array_map(
				static function ( $f ) {
					return is_array( $f ) ? new GF_Field( $f ) : $f;
				},
				isset( $form['fields'] ) ? $form['fields'] : array()
			);
			self::$forms[ $id ] = $form;
			return $id;
		}

		public static function get_forms() {
			return array_values( self::$forms );
		}

		public static function get_form( $id ) {
			return isset( self::$forms[ $id ] ) ? self::$forms[ $id ] : false;
		}

		public static function add_entry( $entry ) {
			$id                    = count( self::$entries ) + 100;
			$entry['id']           = (string) $id;
			$entry                += array( 'status' => 'active', 'date_created' => gmdate( 'Y-m-d H:i:s' ), 'source_url' => 'https://example.org/quote/' );
			self::$entries[ $id ] = $entry;
			return $id;
		}

		public static function get_entries( $form_id, $search = array(), $sorting = null, $paging = null ) {
			$out = array();
			foreach ( array_reverse( self::$entries, true ) as $entry ) {
				if ( (int) $entry['form_id'] !== (int) $form_id ) {
					continue;
				}
				if ( ! empty( $search['status'] ) && $entry['status'] !== $search['status'] ) {
					continue;
				}
				$out[] = $entry;
			}
			$size = isset( $paging['page_size'] ) ? (int) $paging['page_size'] : 20;
			return array_slice( $out, isset( $paging['offset'] ) ? (int) $paging['offset'] : 0, $size );
		}

		public static function get_entry( $id ) {
			return isset( self::$entries[ $id ] ) ? self::$entries[ $id ] : new WP_Error( 'not_found', 'Entry not found' );
		}

		public static function add_note( $entry_id, $user_id, $user_name, $note, $note_type = 'user', $sub_type = null ) {
			self::$notes[] = compact( 'entry_id', 'user_id', 'user_name', 'note', 'note_type', 'sub_type' );
			return count( self::$notes );
		}
	}
}
