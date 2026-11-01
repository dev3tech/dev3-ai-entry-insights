<?php
/**
 * AI trend digest of a period (draft until the admin pins it to the dashboard).
 * Input: aggregated statistics + one-line AI summaries; no names, emails or raw answers.
 *
 * @package Dev3\AiEntryInsights
 */

namespace Dev3\AiEntryInsights\Insights;

use Dev3\AiEntryInsights\Ai\Prompts_Library;
use Dev3\AiEntryInsights\Core3\Api\Prompts;
use Dev3\AiEntryInsights\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Digest.
 */
class Digest {

	const OPTION = 'dev3ei_digest';

	/**
	 * Generates a digest draft.
	 *
	 * @param int    $days Days.
	 * @param string $form Form key ('' = all forms).
	 * @return array|\WP_Error { digest, days, form, model, tokens, cached }
	 */
	public static function generate( $days = 30, $form = '' ) {
		$days  = min( 365, max( 1, (int) $days ) );
		$form  = Settings::form_key_clean( $form );
		$stats = Stats::get( $days, '', $form );
		if ( $stats['total'] < 3 ) {
			return new \WP_Error( 'dev3ei_digest_thin', __( 'At least 3 entries in the period are needed for a trend digest.', 'dev3-ai-entry-insights' ), array( 'status' => 400 ) );
		}
		$lines   = Stats::summary_lines( $days, $form );
		$compact = array(
			'entries'          => $stats['total'],
			'previous_period'  => $stats['prev_total'],
			'avg_lead_score'   => $stats['avg_score'],
			'hot_leads'        => $stats['hot'],
			'negative_percent' => $stats['negative_pct'],
			'reply_rate'       => $stats['reply_rate'],
			'sentiment'        => $stats['sentiment'],
			'categories'       => $stats['categories'],
			'urgency'          => $stats['urgency'],
			'top_intents'      => wp_list_pluck( $stats['intents'], 'count', 'label' ),
			'top_tags'         => $stats['tags'],
			'forms'            => array_map(
				static function ( $f ) {
					return array(
						'form'      => $f['title'],
						'entries'   => $f['total'],
						'avg_score' => $f['avg_score'],
						'hot'       => $f['hot'],
					);
				},
				array_slice( $stats['forms'], 0, 8 )
			),
		);
		$result  = Prompts::run(
			'trend_digest',
			array(
				'site'           => wp_strip_all_tags( get_bloginfo( 'name' ) ),
				'days'           => $days,
				'scope'          => '' !== $form && $stats['forms'] ? $stats['forms'][0]['title'] : 'all forms',
				'stats'          => wp_json_encode( $compact ),
				'summaries'      => $lines ? implode( "\n", $lines ) : 'none (entries were scored by rules only)',
				'admin_language' => Prompts_Library::language_name(),
			),
			'admin',
			count( $lines ) > 150 ? array(
				'profile'    => 'heavy',
				'max_tokens' => 4000,
			) : array( 'max_tokens' => 3000 )
		);
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return array(
			'digest' => self::normalize( isset( $result['data'] ) && is_array( $result['data'] ) ? $result['data'] : array() ),
			'days'   => $days,
			'form'   => $form,
			'model'  => isset( $result['model'] ) ? (string) $result['model'] : '',
			'tokens' => isset( $result['usage']['total'] ) ? (int) $result['usage']['total'] : 0,
			'cached' => ! empty( $result['cached'] ),
		);
	}

	/**
	 * Clamps a digest.
	 *
	 * @param array $data Raw.
	 * @return array
	 */
	public static function normalize( array $data ) {
		$list   = static function ( $items, $max, $len ) {
			$out = array();
			foreach ( is_array( $items ) ? $items : array() as $item ) {
				$item = mb_substr( sanitize_text_field( is_scalar( $item ) ? (string) $item : '' ), 0, $len );
				if ( '' !== $item ) {
					$out[] = $item;
				}
			}
			return array_slice( $out, 0, $max );
		};
		$themes = array();
		foreach ( isset( $data['themes'] ) && is_array( $data['themes'] ) ? $data['themes'] : array() as $theme ) {
			if ( ! is_array( $theme ) || empty( $theme['label'] ) ) {
				continue;
			}
			$themes[] = array(
				'label'     => mb_substr( sanitize_text_field( (string) $theme['label'] ), 0, 60 ),
				'share'     => round( min( 1, max( 0, isset( $theme['share'] ) ? (float) $theme['share'] : 0 ) ), 2 ),
				'sentiment' => round( min( 1, max( -1, isset( $theme['sentiment'] ) ? (float) $theme['sentiment'] : 0 ) ), 2 ),
			);
		}
		return array(
			'headline'      => mb_substr( sanitize_text_field( isset( $data['headline'] ) ? (string) $data['headline'] : '' ), 0, 160 ),
			'summary'       => mb_substr( sanitize_textarea_field( isset( $data['summary'] ) ? (string) $data['summary'] : '' ), 0, 900 ),
			'themes'        => array_slice( $themes, 0, 6 ),
			'opportunities' => $list( isset( $data['opportunities'] ) ? $data['opportunities'] : array(), 5, 200 ),
			'risks'         => $list( isset( $data['risks'] ) ? $data['risks'] : array(), 5, 200 ),
			'actions'       => $list( isset( $data['actions'] ) ? $data['actions'] : array(), 5, 200 ),
		);
	}

	/**
	 * Pins a reviewed digest.
	 *
	 * @param array  $digest Digest.
	 * @param int    $days   Days.
	 * @param string $form   Form key.
	 * @return array Pinned record.
	 */
	public static function pin( array $digest, $days, $form = '' ) {
		$record = array(
			'digest'    => self::normalize( $digest ),
			'days'      => min( 365, max( 1, (int) $days ) ),
			'form'      => Settings::form_key_clean( $form ),
			'pinned_at' => gmdate( 'c' ),
			'pinned_by' => get_current_user_id(),
		);
		update_option( self::OPTION, $record, false );
		return $record;
	}

	/**
	 * Pinned digest or null.
	 *
	 * @return array|null
	 */
	public static function pinned() {
		$record = get_option( self::OPTION, null );
		return is_array( $record ) && isset( $record['digest'] ) ? $record : null;
	}

	/**
	 * Unpins.
	 */
	public static function unpin() {
		delete_option( self::OPTION );
	}
}
