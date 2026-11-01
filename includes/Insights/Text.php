<?php
/**
 * Text helpers for normalised entry fields: [ { key, label, type, value } ].
 * compose() is ported from DEV3 AI Form Builder (Classifier::compose): what the AI may see.
 *
 * @package Dev3\AiEntryInsights
 */

namespace Dev3\AiEntryInsights\Insights;

defined( 'ABSPATH' ) || exit;

/**
 * Field text.
 */
class Text {

	/**
	 * Types that are never sent to the AI.
	 */
	const PRIVATE_TYPES = array( 'phone', 'password', 'hidden', 'signature', 'file', 'consent', 'creditcard', 'captcha', 'html' );

	/**
	 * Types stored but never shown in summaries.
	 */
	const SKIP_STORE = array( 'password', 'creditcard', 'captcha', 'html', 'section', 'page', 'divider', 'submit' );

	/**
	 * Normalises one field.
	 *
	 * @param string $key   Key.
	 * @param string $label Label.
	 * @param string $type  Type.
	 * @param mixed  $value Value (arrays are joined).
	 * @return array|null Null when the field is empty or must not be stored.
	 */
	public static function field( $key, $label, $type, $value ) {
		$type = sanitize_key( (string) $type );
		if ( in_array( $type, self::SKIP_STORE, true ) ) {
			return null;
		}
		if ( is_array( $value ) ) {
			$value = implode( ', ', array_filter( array_map( 'strval', self::flatten( $value ) ), 'strlen' ) );
		}
		$value = trim( sanitize_textarea_field( (string) $value ) );
		if ( '' === $value ) {
			return null;
		}
		$label = trim( wp_strip_all_tags( (string) $label ) );
		return array(
			'key'   => mb_substr( sanitize_text_field( (string) $key ), 0, 60 ),
			'label' => mb_substr( '' !== $label ? $label : (string) $key, 0, 120 ),
			'type'  => '' !== $type ? $type : 'text',
			'value' => mb_substr( $value, 0, 10000 ),
		);
	}

	/**
	 * Flattens nested arrays.
	 *
	 * @param array $value Value.
	 * @return array
	 */
	private static function flatten( array $value ) {
		$out = array();
		array_walk_recursive(
			$value,
			static function ( $v ) use ( &$out ) {
				if ( is_scalar( $v ) ) {
					$out[] = (string) $v;
				}
			}
		);
		return $out;
	}

	/**
	 * Text the AI sees: phones/passwords/hidden/files/consent removed, emails reduced to their domain.
	 *
	 * @param array $fields Fields.
	 * @param int   $max    Max characters.
	 * @return string
	 */
	public static function compose( array $fields, $max = 6000 ) {
		$lines = array();
		foreach ( $fields as $item ) {
			$type  = isset( $item['type'] ) ? (string) $item['type'] : 'text';
			$value = trim( isset( $item['value'] ) ? (string) $item['value'] : '' );
			if ( '' === $value || in_array( $type, self::PRIVATE_TYPES, true ) ) {
				continue;
			}
			if ( 'email' === $type || is_email( $value ) ) {
				$at = strrpos( $value, '@' );
				if ( false !== $at ) {
					$lines[] = 'Email domain: ' . strtolower( substr( $value, $at + 1 ) );
				}
				continue;
			}
			if ( 'name' === $type ) {
				// First name only: enough for tone, no full identity.
				$lines[] = $item['label'] . ': ' . strtok( $value, ' ' );
				continue;
			}
			$lines[] = $item['label'] . ': ' . preg_replace( '/\s+/', ' ', $value );
		}
		$text = implode( "\n", $lines );
		return mb_strlen( $text ) > $max ? mb_substr( $text, 0, $max ) : $text;
	}

	/**
	 * "Label: value" lines of every stored field except the given types.
	 *
	 * @param array $fields Fields.
	 * @param array $skip   Types to skip.
	 * @return string
	 */
	public static function plain( array $fields, array $skip = array() ) {
		$lines = array();
		foreach ( $fields as $item ) {
			if ( in_array( isset( $item['type'] ) ? $item['type'] : '', $skip, true ) ) {
				continue;
			}
			$lines[] = $item['label'] . ': ' . $item['value'];
		}
		return implode( "\n", $lines );
	}

	/**
	 * Lowercase haystack for keyword rules (labels + values).
	 *
	 * @param array $fields Fields.
	 * @return string
	 */
	public static function haystack( array $fields ) {
		return mb_strtolower( self::plain( $fields, array( 'password', 'hidden', 'file', 'signature' ) ) );
	}

	/**
	 * Longest free-text answer length (characters).
	 *
	 * @param array $fields Fields.
	 * @return int
	 */
	public static function longest_text( array $fields ) {
		$max = 0;
		foreach ( $fields as $item ) {
			if ( in_array( $item['type'], array( 'textarea', 'text' ), true ) ) {
				$max = max( $max, mb_strlen( (string) $item['value'] ) );
			}
		}
		return $max;
	}

	/**
	 * Sender email (first email field or email-looking value).
	 *
	 * @param array $fields Fields.
	 * @return string
	 */
	public static function email( array $fields ) {
		foreach ( $fields as $item ) {
			if ( 'email' === $item['type'] && is_email( $item['value'] ) ) {
				return sanitize_email( $item['value'] );
			}
		}
		foreach ( $fields as $item ) {
			if ( is_email( trim( (string) $item['value'] ) ) ) {
				return sanitize_email( trim( (string) $item['value'] ) );
			}
		}
		return '';
	}

	/**
	 * Sender name (name field, or text fields labelled name / first + last name).
	 *
	 * @param array $fields Fields.
	 * @return string
	 */
	public static function name( array $fields ) {
		foreach ( $fields as $item ) {
			if ( 'name' === $item['type'] ) {
				return mb_substr( $item['value'], 0, 190 );
			}
		}
		$first = '';
		$last  = '';
		foreach ( $fields as $item ) {
			$probe = strtolower( $item['label'] . ' ' . str_replace( array( '_', '-' ), ' ', $item['key'] ) );
			if ( 'text' !== $item['type'] ) {
				continue;
			}
			if ( preg_match( '/\b(first|given|fore)\s*name\b|\bfname\b/', $probe ) && '' === $first ) {
				$first = $item['value'];
			} elseif ( preg_match( '/\b(last|family|sur)\s*name\b|\blname\b|\bsurname\b/', $probe ) && '' === $last ) {
				$last = $item['value'];
			} elseif ( preg_match( '/\b(your\s+)?name\b/', $probe ) && '' === $first && ! preg_match( '/company|business|organi[sz]ation/', $probe ) ) {
				return mb_substr( $item['value'], 0, 190 );
			}
		}
		return mb_substr( trim( $first . ' ' . $last ), 0, 190 );
	}

	/**
	 * Hash of the answers (dedupe + cache key).
	 *
	 * @param array $fields Fields.
	 * @return string
	 */
	public static function hash( array $fields ) {
		return md5( self::plain( $fields ) );
	}
}
