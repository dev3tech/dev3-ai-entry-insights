<?php
/**
 * Gravity Forms (commercial). Built against the documented API only:
 * - capture: `gform_after_submission( $entry, $form )` (spam/trash entries skipped);
 * - import: `GFAPI::get_entries()` / `GFAPI::get_entry()`;
 * - entry screen: `gform_entry_detail_meta_boxes` meta box with the insights;
 * - "Add summary as note": `GFAPI::add_note()`;
 * - `gform_delete_entry` removes the captured copy.
 *
 * @package Dev3\AiEntryInsights
 */

namespace Dev3\AiEntryInsights\Adapters;

use Dev3\AiEntryInsights\Data\Entries;
use Dev3\AiEntryInsights\Insights\Alerts;
use Dev3\AiEntryInsights\Insights\Capture;
use Dev3\AiEntryInsights\Insights\Text;

defined( 'ABSPATH' ) || exit;

/**
 * Gravity Forms adapter.
 */
class Gravity_Forms_Adapter extends Adapter {

	/**
	 * GF field type → normalised type.
	 */
	const TYPES = array(
		'name'              => 'name',
		'email'             => 'email',
		'phone'             => 'phone',
		'text'              => 'text',
		'textarea'          => 'textarea',
		'post_content'      => 'textarea',
		'post_title'        => 'text',
		'post_excerpt'      => 'textarea',
		'number'            => 'number',
		'website'           => 'url',
		'select'            => 'choice',
		'radio'             => 'choice',
		'checkbox'          => 'choice',
		'multiselect'       => 'choice',
		'survey'            => 'choice',
		'poll'              => 'choice',
		'quiz'              => 'choice',
		'likert'            => 'choice',
		'rank'              => 'choice',
		'rating'            => 'choice',
		'address'           => 'address',
		'date'              => 'date',
		'time'              => 'date',
		'list'              => 'text',
		'fileupload'        => 'file',
		'post_image'        => 'file',
		'hidden'            => 'hidden',
		'password'          => 'password',
		'consent'           => 'consent',
		'signature'         => 'signature',
		'creditcard'        => 'creditcard',
		'stripe_creditcard' => 'creditcard',
		'captcha'           => 'captcha',
		'honeypot'          => 'captcha',
		'html'              => 'html',
		'section'           => 'section',
		'page'              => 'page',
		'product'           => 'choice',
		'total'             => 'number',
		'option'            => 'choice',
		'shipping'          => 'choice',
		'quantity'          => 'number',
	);

	/**
	 * Id.
	 *
	 * @return string
	 */
	public function id() {
		return 'gravityforms';
	}

	/**
	 * Label.
	 *
	 * @return string
	 */
	public function label() {
		return 'Gravity Forms';
	}

	/**
	 * Active?
	 *
	 * @return bool
	 */
	public function is_active() {
		return class_exists( 'GFForms' ) && class_exists( 'GFAPI' );
	}

	/**
	 * Version.
	 *
	 * @return string
	 */
	public function version() {
		if ( class_exists( 'GFCommon' ) && isset( \GFCommon::$version ) ) {
			return (string) \GFCommon::$version;
		}
		return class_exists( 'GFForms' ) && isset( \GFForms::$version ) ? (string) \GFForms::$version : '';
	}

	/**
	 * Plugin URL.
	 *
	 * @return string
	 */
	public function plugin_url() {
		return 'https://www.gravityforms.com/';
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
		$out = array();
		foreach ( (array) \GFAPI::get_forms() as $form ) {
			if ( ! is_array( $form ) || empty( $form['id'] ) ) {
				continue;
			}
			$out[] = array(
				'id'       => (int) $form['id'],
				'title'    => isset( $form['title'] ) ? (string) $form['title'] : '#' . (int) $form['id'],
				'edit_url' => admin_url( 'admin.php?page=gf_edit_forms&id=' . (int) $form['id'] ),
			);
		}
		return $out;
	}

	/**
	 * Hooks.
	 */
	public function register() {
		add_action( 'gform_after_submission', array( $this, 'after_submission' ), 20, 2 );
		add_action( 'gform_delete_entry', array( $this, 'delete_entry' ) );
		add_filter( 'gform_entry_detail_meta_boxes', array( $this, 'meta_boxes' ), 10, 3 );
	}

	/**
	 * Field property (GF_Field object or array).
	 *
	 * @param mixed  $field    Field.
	 * @param string $key      Key.
	 * @param mixed  $fallback Fallback.
	 * @return mixed
	 */
	private static function prop( $field, $key, $fallback = null ) {
		if ( is_array( $field ) ) {
			return array_key_exists( $key, $field ) ? $field[ $key ] : $fallback;
		}
		return is_object( $field ) && isset( $field->$key ) ? $field->$key : $fallback;
	}

	/**
	 * Entry value for a key ("3" or "1.3").
	 *
	 * @param array  $entry Entry.
	 * @param string $key   Key.
	 * @return string
	 */
	private static function value( array $entry, $key ) {
		return isset( $entry[ $key ] ) && is_scalar( $entry[ $key ] ) ? (string) $entry[ $key ] : '';
	}

	/**
	 * GF form + entry → normalised fields.
	 *
	 * @param array $form  Form.
	 * @param array $entry Entry.
	 * @return array
	 */
	public static function normalize( array $form, array $entry ) {
		$out = array();
		foreach ( isset( $form['fields'] ) ? (array) $form['fields'] : array() as $field ) {
			$id         = (string) self::prop( $field, 'id', '' );
			$type       = (string) self::prop( $field, 'type', '' );
			$input_type = (string) self::prop( $field, 'inputType', '' );
			if ( '' !== $input_type && in_array( $type, array( 'post_custom_field', 'quiz', 'poll', 'survey', 'product', 'option', 'shipping' ), true ) && isset( self::TYPES[ $input_type ] ) && 'choice' !== self::TYPES[ $input_type ] ) {
				$type = $input_type;
			}
			if ( '' === $id ) {
				continue;
			}
			$norm   = isset( self::TYPES[ $type ] ) ? self::TYPES[ $type ] : 'text';
			$label  = (string) self::prop( $field, 'adminLabel', '' );
			$label  = '' !== $label ? $label : (string) self::prop( $field, 'label', '' );
			$inputs = self::prop( $field, 'inputs', null );
			$value  = '';
			if ( is_array( $inputs ) && $inputs ) {
				$parts = array();
				foreach ( $inputs as $input ) {
					$iid = is_array( $input ) && isset( $input['id'] ) ? (string) $input['id'] : '';
					if ( '' === $iid || ! empty( $input['isHidden'] ) ) {
						continue;
					}
					$v = trim( self::value( $entry, $iid ) );
					if ( '' !== $v ) {
						$parts[] = $v;
					}
				}
				$value = implode( 'name' === $norm ? ' ' : ', ', $parts );
			} else {
				$value = self::value( $entry, $id );
				if ( 'multiselect' === $type && '' !== $value && '[' === $value[0] ) {
					$decoded = json_decode( $value, true );
					$value   = is_array( $decoded ) ? implode( ', ', $decoded ) : $value;
				} elseif ( 'list' === $type && '' !== $value ) {
					$list  = maybe_unserialize( $value );
					$value = is_array( $list ) ? $list : $value;
				}
			}
			if ( is_string( $value ) && 'choice' === $norm && false !== strpos( $value, '|' ) && in_array( $type, array( 'product', 'option', 'shipping' ), true ) ) {
				$value = strtok( $value, '|' ); // "Label|price" pricing values.
			}
			$row = Text::field( 'f' . str_replace( '.', '_', $id ), $label, $norm, $value );
			if ( $row ) {
				$out[] = $row;
			}
		}
		return $out;
	}

	/**
	 * `gform_after_submission`.
	 *
	 * @param array $entry Entry.
	 * @param array $form  Form.
	 * @return int Entry id.
	 */
	public function after_submission( $entry, $form ) {
		if ( ! is_array( $entry ) || ! is_array( $form ) || empty( $form['id'] ) ) {
			return 0;
		}
		if ( isset( $entry['status'] ) && in_array( $entry['status'], array( 'spam', 'trash' ), true ) ) {
			return 0;
		}
		return $this->capture( $form, $entry, false, null );
	}

	/**
	 * Captures a GF entry.
	 *
	 * @param array     $form    Form.
	 * @param array     $entry   Entry.
	 * @param bool      $import  Import mode.
	 * @param bool|null $analyze Queue for AI (null = settings).
	 * @return int
	 */
	private function capture( array $form, array $entry, $import, $analyze ) {
		$meta = array(
			'ref'      => isset( $entry['id'] ) ? (string) (int) $entry['id'] : '',
			'page_url' => isset( $entry['source_url'] ) ? (string) $entry['source_url'] : '',
			'import'   => $import,
		);
		if ( $import && ! empty( $entry['date_created'] ) ) {
			$meta['created_at'] = $entry['date_created'] . ' UTC';
		}
		if ( null !== $analyze ) {
			$meta['analyze'] = (bool) $analyze;
		}
		return Capture::record( $this->id(), (int) $form['id'], isset( $form['title'] ) ? (string) $form['title'] : '', self::normalize( $form, $entry ), $meta );
	}

	/**
	 * `gform_delete_entry`: drop our copy.
	 *
	 * @param int $entry_id GF entry id.
	 */
	public function delete_entry( $entry_id ) {
		$entry = Entries::find_ref( $this->id(), (string) (int) $entry_id );
		if ( $entry ) {
			Entries::delete( $entry['id'] );
		}
	}

	/**
	 * Import available.
	 *
	 * @return bool
	 */
	public function can_import() {
		return $this->is_active() && method_exists( 'GFAPI', 'get_entries' );
	}

	/**
	 * Active entry ids for a form, newest first.
	 *
	 * @param int $form_id Form id.
	 * @param int $limit   Max.
	 * @return string[]
	 */
	public function import_refs( $form_id, $limit = 200 ) {
		if ( ! $this->can_import() ) {
			return array();
		}
		$entries = \GFAPI::get_entries(
			(int) $form_id,
			array( 'status' => 'active' ),
			array(
				'key'       => 'date_created',
				'direction' => 'DESC',
			),
			array(
				'offset'    => 0,
				'page_size' => (int) $limit,
			)
		);
		if ( is_wp_error( $entries ) ) {
			return array();
		}
		$out = array();
		foreach ( (array) $entries as $entry ) {
			if ( isset( $entry['id'] ) ) {
				$out[] = (string) (int) $entry['id'];
			}
		}
		return $out;
	}

	/**
	 * Imports one GF entry.
	 *
	 * @param int    $form_id Form id.
	 * @param string $ref     Entry id.
	 * @param bool   $analyze Queue for AI.
	 * @return int|\WP_Error
	 */
	public function import_one( $form_id, $ref, $analyze = false ) {
		$entry = method_exists( 'GFAPI', 'get_entry' ) ? \GFAPI::get_entry( (int) $ref ) : null;
		$form  = \GFAPI::get_form( (int) $form_id );
		if ( ! is_array( $entry ) || ! is_array( $form ) ) {
			return new \WP_Error( 'dev3ei_not_found', __( 'Entry not found.', 'dev3-ai-entry-insights' ) );
		}
		$id = $this->capture( $form, $entry, true, (bool) $analyze );
		return $id ? $id : new \WP_Error( 'dev3ei_skipped', __( 'Entry skipped (empty or capture off).', 'dev3-ai-entry-insights' ) );
	}

	/**
	 * Link to the GF entry.
	 *
	 * @param array $entry Entry.
	 * @return string
	 */
	public function entry_url( array $entry ) {
		if ( '' === (string) $entry['source_ref'] ) {
			return admin_url( 'admin.php?page=gf_entries&id=' . (int) $entry['form_id'] );
		}
		return admin_url( 'admin.php?page=gf_entries&view=entry&id=' . (int) $entry['form_id'] . '&lid=' . (int) $entry['source_ref'] );
	}

	/**
	 * Adds the insights as a GF entry note (admin click).
	 *
	 * @param array $entry Our entry.
	 * @return true|\WP_Error
	 */
	public function add_note( array $entry ) {
		if ( ! $this->is_active() || ! method_exists( 'GFAPI', 'add_note' ) || '' === (string) $entry['source_ref'] ) {
			return new \WP_Error( 'dev3ei_no_note', __( 'This entry is not linked to a Gravity Forms entry.', 'dev3-ai-entry-insights' ), array( 'status' => 400 ) );
		}
		$user = wp_get_current_user();
		$cats = Alerts::category_labels();
		$note = sprintf(
			/* translators: 1: summary, 2: score, 3: reason, 4: category, 5: sentiment, 6: urgency */
			__( "DEV3 AI Entry Insights\n%1\$s\nLead score: %2\$d (%3\$s)\nCategory: %4\$s · Sentiment: %5\$s · Urgency: %6\$s", 'dev3-ai-entry-insights' ),
			'' !== $entry['summary'] ? $entry['summary'] : $entry['intent'],
			$entry['lead_score'],
			$entry['lead_reason'],
			isset( $cats[ $entry['category'] ] ) ? $cats[ $entry['category'] ] : $entry['category'],
			$entry['sentiment'],
			$entry['urgency']
		);
		$result = \GFAPI::add_note( (int) $entry['source_ref'], (int) $user->ID, $user->display_name ? $user->display_name : 'DEV3 AI', $note, 'dev3ei', 'success' );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		Entries::log( $entry['id'], 'gf_note', __( 'Summary added as a Gravity Forms note.', 'dev3-ai-entry-insights' ) );
		return true;
	}

	/**
	 * `gform_entry_detail_meta_boxes`: insights box on the GF entry screen.
	 *
	 * @param array $boxes Boxes.
	 * @param array $entry GF entry.
	 * @param array $form  Form.
	 * @return array
	 */
	public function meta_boxes( $boxes, $entry = array(), $form = array() ) {
		unset( $form );
		$boxes['dev3ei_insights'] = array(
			'title'    => esc_html__( 'DEV3 AI Entry Insights', 'dev3-ai-entry-insights' ),
			'callback' => array( $this, 'render_meta_box' ),
			'context'  => 'side',
		);
		unset( $entry );
		return $boxes;
	}

	/**
	 * Meta box body.
	 *
	 * @param array $args { form, entry, mode }.
	 */
	public function render_meta_box( $args ) {
		$gf_entry = isset( $args['entry'] ) && is_array( $args['entry'] ) ? $args['entry'] : array();
		$entry    = isset( $gf_entry['id'] ) ? Entries::find_ref( $this->id(), (string) (int) $gf_entry['id'] ) : null;
		echo wp_kses_post( self::meta_box_html( $entry ) );
	}

	/**
	 * Meta box HTML.
	 *
	 * @param array|null $entry Our entry.
	 * @return string
	 */
	public static function meta_box_html( $entry ) {
		if ( ! $entry ) {
			return '<p>' . esc_html__( 'This entry was submitted before DEV3 AI Entry Insights was active. Import it from the Forms screen.', 'dev3-ai-entry-insights' ) . '</p>';
		}
		$cats = Alerts::category_labels();
		$html = '<div class="dev3ei-gf-box">';
		if ( '' !== $entry['summary'] ) {
			$html .= '<p>' . esc_html( $entry['summary'] ) . '</p>';
		}
		$html .= '<p><strong>' . esc_html__( 'Lead score', 'dev3-ai-entry-insights' ) . ':</strong> ' . (int) $entry['lead_score'] . ' / 100';
		$html .= '' !== $entry['lead_reason'] ? '<br><em>' . esc_html( $entry['lead_reason'] ) . '</em>' : '';
		$html .= '</p><p>';
		$html .= '<strong>' . esc_html__( 'Category', 'dev3-ai-entry-insights' ) . ':</strong> ' . esc_html( isset( $cats[ $entry['category'] ] ) ? $cats[ $entry['category'] ] : $entry['category'] ) . '<br>';
		$html .= '<strong>' . esc_html__( 'Sentiment', 'dev3-ai-entry-insights' ) . ':</strong> ' . esc_html( $entry['sentiment'] ) . '<br>';
		$html .= '<strong>' . esc_html__( 'Urgency', 'dev3-ai-entry-insights' ) . ':</strong> ' . esc_html( $entry['urgency'] );
		if ( '' !== $entry['intent'] ) {
			$html .= '<br><strong>' . esc_html__( 'Intent', 'dev3-ai-entry-insights' ) . ':</strong> ' . esc_html( $entry['intent'] );
		}
		$html .= '</p>';
		if ( $entry['tags'] ) {
			$html .= '<p>' . esc_html( '#' . implode( ' #', $entry['tags'] ) ) . '</p>';
		}
		$html .= '<p><a class="button" href="' . esc_url( admin_url( 'admin.php?page=' . DEV3EI_SLUG . '#/entries/' . (int) $entry['id'] ) ) . '">' . esc_html__( 'Open in DEV3 AI Entry Insights', 'dev3-ai-entry-insights' ) . '</a></p>';
		return $html . '</div>';
	}
}
