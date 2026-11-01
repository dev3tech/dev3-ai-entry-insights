<?php
/**
 * Auto-tag rules (no AI): "when <condition> then add <tag>".
 *
 * @package Dev3\AiEntryInsights
 */

namespace Dev3\AiEntryInsights\Insights;

use Dev3\AiEntryInsights\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Tag rules.
 */
class Tagger {

	/**
	 * Tags the rules add for an entry.
	 *
	 * @param array $entry Entry-like array: fields, source, form_id, sentiment, category, urgency, lead_score.
	 * @param array $rules Rules (default: settings).
	 * @return string[]
	 */
	public static function tags_for( array $entry, $rules = null ) {
		$rules = null === $rules ? (array) Settings::get( 'tag_rules', array() ) : (array) $rules;
		$text  = null;
		$out   = array();
		foreach ( $rules as $rule ) {
			if ( ! is_array( $rule ) || empty( $rule['tag'] ) || ! isset( $rule['when'], $rule['value'] ) ) {
				continue;
			}
			$value = strtolower( trim( (string) $rule['value'] ) );
			$match = false;
			switch ( $rule['when'] ) {
				case 'keyword':
					if ( null === $text ) {
						$text = Text::haystack( isset( $entry['fields'] ) ? (array) $entry['fields'] : array() );
					}
					foreach ( array_filter( array_map( 'trim', explode( ',', $value ) ) ) as $word ) {
						if ( false !== mb_strpos( $text, mb_strtolower( $word ) ) ) {
							$match = true;
							break;
						}
					}
					break;
				case 'category':
				case 'sentiment':
				case 'urgency':
				case 'source':
					$match = isset( $entry[ $rule['when'] ] ) && strtolower( (string) $entry[ $rule['when'] ] ) === $value;
					break;
				case 'score':
					$match = isset( $entry['lead_score'] ) && (int) $entry['lead_score'] >= (int) $value;
					break;
				case 'form':
					$match = isset( $entry['source'], $entry['form_id'] ) && Settings::form_key( $entry['source'], $entry['form_id'] ) === Settings::form_key_clean( $value );
					break;
			}
			if ( $match ) {
				$tag = Settings::tag( $rule['tag'] );
				if ( '' !== $tag && ! in_array( $tag, $out, true ) ) {
					$out[] = $tag;
				}
			}
		}
		/**
		 * Tags added by rules for an entry.
		 *
		 * @param string[] $out   Tags.
		 * @param array    $entry Entry.
		 */
		return (array) apply_filters( 'dev3ei_rule_tags', $out, $entry );
	}

	/**
	 * Merges tag lists.
	 *
	 * @param array ...$lists Lists.
	 * @return string[]
	 */
	public static function merge( ...$lists ) {
		$out = array();
		foreach ( $lists as $list ) {
			foreach ( (array) $list as $tag ) {
				$tag = Settings::tag( $tag );
				if ( '' !== $tag && ! in_array( $tag, $out, true ) ) {
					$out[] = $tag;
				}
			}
		}
		return $out;
	}
}
