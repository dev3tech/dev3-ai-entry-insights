<?php
/**
 * Dashboard aggregation (period, source and form filters). Pure SQL + PHP, no AI.
 *
 * @package Dev3\AiEntryInsights
 */

namespace Dev3\AiEntryInsights\Insights;

use Dev3\AiEntryInsights\Ai\Prompts_Library;
use Dev3\AiEntryInsights\Data\Tables;
use Dev3\AiEntryInsights\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Stats.
 */
class Stats {

	const MAX_ROWS = 5000;

	/**
	 * Rows of a period.
	 *
	 * @param string $since  UTC datetime.
	 * @param string $until  UTC datetime.
	 * @param string $source Source filter.
	 * @param string $form   Form key filter.
	 * @return array
	 */
	private static function rows( $since, $until, $source = '', $form = '' ) {
		global $wpdb;
		$table  = Tables::name();
		$where  = 'created_at >= %s AND created_at < %s';
		$params = array( $since, $until );
		if ( '' !== $source ) {
			$where   .= ' AND source = %s';
			$params[] = $source;
		}
		$key = Settings::form_key_clean( $form );
		if ( '' !== $key ) {
			list( $src, $fid ) = explode( ':', $key );
			$where            .= ' AND source = %s AND form_id = %d';
			$params[]          = $src;
			$params[]          = (int) $fid;
		}
		$params[] = self::MAX_ROWS;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
		return (array) $wpdb->get_results( $wpdb->prepare( "SELECT id, source, form_id, form_title, name, email, summary, intent, sentiment, category, urgency, lead_score, tags_index, status, insight_src, ai_state, created_at FROM {$table} WHERE {$where} ORDER BY created_at DESC LIMIT %d", $params ), ARRAY_A );
	}

	/**
	 * Dashboard data.
	 *
	 * @param int    $days   Period (days).
	 * @param string $source Source.
	 * @param string $form   Form key.
	 * @return array
	 */
	public static function get( $days = 30, $source = '', $form = '' ) {
		$days   = min( 365, max( 1, (int) $days ) );
		$now    = time();
		$since  = gmdate( 'Y-m-d 00:00:00', $now - ( $days - 1 ) * DAY_IN_SECONDS );
		$until  = gmdate( 'Y-m-d H:i:s', $now + 1 );
		$prev   = gmdate( 'Y-m-d 00:00:00', strtotime( $since . ' UTC' ) - $days * DAY_IN_SECONDS );
		$source = sanitize_key( (string) $source );
		$rows   = self::rows( $since, $until, $source, (string) $form );
		$before = self::rows( $prev, $since, $source, (string) $form );
		$hot    = (int) Settings::get( 'hot_threshold', 70 );

		$series = array();
		for ( $i = $days - 1; $i >= 0; $i-- ) {
			$date            = gmdate( 'Y-m-d', $now - $i * DAY_IN_SECONDS );
			$series[ $date ] = array(
				'date'     => $date,
				'positive' => 0,
				'neutral'  => 0,
				'negative' => 0,
			);
		}
		$buckets    = array_fill( 0, 5, 0 );
		$categories = array_fill_keys( Prompts_Library::CATEGORIES, 0 );
		$urgency    = array_fill_keys( Prompts_Library::URGENCY, 0 );
		$sentiment  = array_fill_keys( Prompts_Library::SENTIMENTS, 0 );
		$intents    = array();
		$tags       = array();
		$forms      = array();
		$sources    = array();
		$sum        = 0;
		$hot_n      = 0;
		$replied    = 0;
		$ai_n       = 0;
		$attention  = array();
		foreach ( $rows as $row ) {
			$score = (int) $row['lead_score'];
			$sum  += $score;
			$date  = substr( $row['created_at'], 0, 10 );
			$mood  = in_array( $row['sentiment'], Prompts_Library::SENTIMENTS, true ) ? $row['sentiment'] : 'neutral';
			if ( isset( $series[ $date ] ) ) {
				++$series[ $date ][ $mood ];
			}
			++$sentiment[ $mood ];
			++$buckets[ min( 4, intdiv( $score, 20 ) ) ];
			if ( isset( $categories[ $row['category'] ] ) ) {
				++$categories[ $row['category'] ];
			}
			if ( isset( $urgency[ $row['urgency'] ] ) ) {
				++$urgency[ $row['urgency'] ];
			}
			if ( $score >= $hot ) {
				++$hot_n;
			}
			if ( 'replied' === $row['status'] ) {
				++$replied;
			}
			if ( 'ai' === $row['insight_src'] ) {
				++$ai_n;
			}
			$intent = trim( (string) $row['intent'] );
			if ( '' !== $intent ) {
				$k             = mb_strtolower( $intent );
				$intents[ $k ] = array(
					'label' => isset( $intents[ $k ] ) ? $intents[ $k ]['label'] : $intent,
					'count' => isset( $intents[ $k ] ) ? $intents[ $k ]['count'] + 1 : 1,
				);
			}
			foreach ( array_filter( explode( ',', (string) $row['tags_index'] ) ) as $tag ) {
				$tags[ $tag ] = isset( $tags[ $tag ] ) ? $tags[ $tag ] + 1 : 1;
			}
			$fkey = Settings::form_key( $row['source'], $row['form_id'] );
			if ( ! isset( $forms[ $fkey ] ) ) {
				$forms[ $fkey ] = array(
					'key'       => $fkey,
					'source'    => $row['source'],
					'title'     => $row['form_title'],
					'total'     => 0,
					'score_sum' => 0,
					'hot'       => 0,
					'positive'  => 0,
					'neutral'   => 0,
					'negative'  => 0,
				);
			}
			++$forms[ $fkey ]['total'];
			$forms[ $fkey ]['score_sum'] += $score;
			++$forms[ $fkey ][ $mood ];
			if ( $score >= $hot ) {
				++$forms[ $fkey ]['hot'];
			}
			$sources[ $row['source'] ] = isset( $sources[ $row['source'] ] ) ? $sources[ $row['source'] ] + 1 : 1;
			$open                      = in_array( $row['status'], array( 'new', 'read' ), true );
			if ( $open && ( 'high' === $row['urgency'] || 'negative' === $mood || $score >= $hot ) && count( $attention ) < 50 ) {
				$attention[] = array(
					'id'         => (int) $row['id'],
					'name'       => $row['name'],
					'form_title' => $row['form_title'],
					'summary'    => $row['summary'],
					'intent'     => $row['intent'],
					'sentiment'  => $mood,
					'urgency'    => $row['urgency'],
					'lead_score' => $score,
					'created_at' => $row['created_at'],
				);
			}
		}
		usort(
			$attention,
			static function ( $a, $b ) {
				$rank = array(
					'high'   => 0,
					'medium' => 1,
					'low'    => 2,
				);
				return array( $rank[ $a['urgency'] ] ?? 2, -$a['lead_score'] ) <=> array( $rank[ $b['urgency'] ] ?? 2, -$b['lead_score'] );
			}
		);
		foreach ( $forms as &$f ) {
			$f['avg_score'] = $f['total'] ? (int) round( $f['score_sum'] / $f['total'] ) : 0;
			unset( $f['score_sum'] );
		}
		unset( $f );
		usort(
			$forms,
			static function ( $a, $b ) {
				return $b['total'] <=> $a['total'];
			}
		);
		usort(
			$intents,
			static function ( $a, $b ) {
				return $b['count'] <=> $a['count'];
			}
		);
		arsort( $tags );
		arsort( $categories );
		$total      = count( $rows );
		$prev_total = count( $before );
		$prev_sum   = array_sum( array_map( 'intval', wp_list_pluck( $before, 'lead_score' ) ) );
		return array(
			'days'            => $days,
			'total'           => $total,
			'prev_total'      => $prev_total,
			'avg_score'       => $total ? (int) round( $sum / $total ) : 0,
			'prev_avg'        => $prev_total ? (int) round( $prev_sum / $prev_total ) : 0,
			'hot'             => $hot_n,
			'hot_threshold'   => $hot,
			'negative_pct'    => $total ? (int) round( $sentiment['negative'] / $total * 100 ) : 0,
			'reply_rate'      => $total ? (int) round( $replied / $total * 100 ) : 0,
			'ai_analyzed'     => $ai_n,
			'pending'         => self::pending_count(),
			'attention'       => array_slice( $attention, 0, 6 ),
			'attention_total' => count( $attention ),
			'sentiment'       => $sentiment,
			'series'          => array_values( $series ),
			'scores'          => array(
				array(
					'label' => '0–19',
					'count' => $buckets[0],
				),
				array(
					'label' => '20–39',
					'count' => $buckets[1],
				),
				array(
					'label' => '40–59',
					'count' => $buckets[2],
				),
				array(
					'label' => '60–79',
					'count' => $buckets[3],
				),
				array(
					'label' => '80–100',
					'count' => $buckets[4],
				),
			),
			'categories'      => array_filter( $categories ),
			'urgency'         => $urgency,
			'intents'         => array_slice( $intents, 0, 8 ),
			'tags'            => array_slice( $tags, 0, 12, true ),
			'forms'           => array_slice( $forms, 0, 20 ),
			'sources'         => $sources,
			'truncated'       => $total >= self::MAX_ROWS,
		);
	}

	/**
	 * Entries waiting for AI.
	 *
	 * @return int
	 */
	public static function pending_count() {
		global $wpdb;
		$table = Tables::name();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE ai_state = 'pending'" );
	}

	/**
	 * Lines for the trend digest: "category | sentiment | score | summary" (no personal data).
	 *
	 * @param int    $days  Days.
	 * @param string $form  Form key.
	 * @param int    $limit Max lines.
	 * @return string[]
	 */
	public static function summary_lines( $days, $form = '', $limit = 120 ) {
		$since = gmdate( 'Y-m-d 00:00:00', time() - ( max( 1, (int) $days ) - 1 ) * DAY_IN_SECONDS );
		$rows  = self::rows( $since, gmdate( 'Y-m-d H:i:s', time() + 1 ), '', (string) $form );
		$out   = array();
		foreach ( $rows as $row ) {
			$text = '' !== $row['summary'] ? $row['summary'] : $row['intent'];
			if ( '' === trim( (string) $text ) ) {
				continue;
			}
			$out[] = $row['category'] . ' | ' . $row['sentiment'] . ' | ' . (int) $row['lead_score'] . ' | ' . preg_replace( '/\s+/', ' ', (string) $text );
			if ( count( $out ) >= $limit ) {
				break;
			}
		}
		return $out;
	}
}
