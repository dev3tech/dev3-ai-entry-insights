<?php
/**
 * Entry intelligence: summary, sentiment, intent, category, urgency, lead score, language and
 * suggested tags per entry. Ported from DEV3 AI Form Builder (AI/Entry_Insights.php) and made
 * form-plugin agnostic. Rule-based values are always computed first, so nothing is ever empty.
 *
 * Sent to the AI: site name, form title and "Label: value" lines of text answers (phones,
 * passwords, hidden, file, signature and consent fields are left out; emails become their domain;
 * names are reduced to the first name).
 *
 * @package Dev3\AiEntryInsights
 */

namespace Dev3\AiEntryInsights\Insights;

use Dev3\AiEntryInsights\Ai\Prompts_Library;
use Dev3\AiEntryInsights\Core3\Api\Ai;
use Dev3\AiEntryInsights\Core3\Api\Prompts;
use Dev3\AiEntryInsights\Core3\Core\Jobs;
use Dev3\AiEntryInsights\Data\Entries;
use Dev3\AiEntryInsights\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Analyzer + background queue.
 */
class Analyzer {

	const JOB = 'dev3ei_analyze';

	const QUEUE_HOOK = 'dev3ei_process_queue';

	const LOCK = 'dev3ei_queue_lock';

	/**
	 * Registers the job type and the queue cron hook (every request).
	 */
	public static function register() {
		Jobs::register( self::JOB, array( __CLASS__, 'run_chunk' ) );
		add_action( self::QUEUE_HOOK, array( __CLASS__, 'process_queue' ) );
	}

	/**
	 * Applies rules + tag rules to a freshly captured entry (sync, no network).
	 *
	 * @param int $id Entry id.
	 * @return array|null Updated entry.
	 */
	public static function apply_rules( $id ) {
		$entry = Entries::get( $id );
		if ( ! $entry ) {
			return null;
		}
		$rules = Rules::analyze( $entry['fields'] );
		$cols  = self::columns( $entry, $rules, null );
		Entries::update( $id, $cols );
		return Entries::get( $id );
	}

	/**
	 * Queues an entry for AI analysis and schedules the queue runner.
	 *
	 * @param int $id Entry id.
	 */
	public static function enqueue( $id ) {
		Entries::update( (int) $id, array( 'ai_state' => 'pending' ) );
		self::schedule();
	}

	/**
	 * Schedules a queue run soon (WP-Cron; Action Scheduler not needed for a few items).
	 */
	public static function schedule() {
		if ( ! wp_next_scheduled( self::QUEUE_HOOK ) ) {
			wp_schedule_single_event( time() + 5, self::QUEUE_HOOK );
		}
	}

	/**
	 * Cron: analyses pending entries for up to ~20 s.
	 *
	 * @param int $budget Seconds.
	 * @return int Entries processed.
	 */
	public static function process_queue( $budget = 20 ) {
		if ( get_transient( self::LOCK ) ) {
			return 0;
		}
		set_transient( self::LOCK, 1, 60 );
		$start = microtime( true );
		$done  = 0;
		try {
			while ( microtime( true ) - $start < (float) $budget ) {
				$ids = Entries::pending( 5 );
				if ( ! $ids ) {
					break;
				}
				foreach ( $ids as $id ) {
					self::analyze( $id, true, 'cron' );
					++$done;
					if ( microtime( true ) - $start >= (float) $budget ) {
						break;
					}
				}
			}
		} finally {
			delete_transient( self::LOCK );
		}
		if ( Entries::pending( 1 ) ) {
			self::schedule();
		}
		return $done;
	}

	/**
	 * When WP-Cron is not running (loopback blocked, DISABLE_WP_CRON), the admin screens run a
	 * short slice inline once the oldest pending entry waited 30 s.
	 *
	 * @return int Processed.
	 */
	public static function catch_up() {
		$oldest = Entries::oldest_pending();
		if ( '' === $oldest || strtotime( $oldest . ' UTC' ) > time() - (int) apply_filters( 'dev3ei_queue_stale_after', 30 ) ) {
			return 0;
		}
		return self::process_queue( 6 );
	}

	/**
	 * Analyses one entry and stores the result.
	 *
	 * @param int    $id      Entry id.
	 * @param bool   $use_ai  Use AI (else rules only).
	 * @param string $context Usage context.
	 * @return array|\WP_Error Updated entry, or the AI error (rule values are still saved).
	 */
	public static function analyze( $id, $use_ai = true, $context = 'admin' ) {
		$entry = Entries::get( $id );
		if ( ! $entry ) {
			return new \WP_Error( 'dev3ei_not_found', __( 'Entry not found.', 'dev3-ai-entry-insights' ), array( 'status' => 404 ) );
		}
		$rules  = Rules::analyze( $entry['fields'] );
		$ai     = null;
		$error  = null;
		$config = Settings::form_config( $entry['source'], $entry['form_id'] );
		if ( $use_ai && Ai::enabled( $context ) && ( 'admin' === $context || $config['analyze'] ) ) {
			$ai = self::ai( $entry, $context );
			if ( is_wp_error( $ai ) ) {
				$error = $ai;
				$ai    = null;
			}
		}
		$cols = self::columns( $entry, $rules, $ai );
		if ( $error ) {
			$cols['ai_state'] = 'failed';
			$cols['ai_error'] = mb_substr( $error->get_error_message(), 0, 250 );
		} elseif ( $ai ) {
			$cols['ai_state']    = 'done';
			$cols['ai_error']    = '';
			$cols['analyzed_at'] = Entries::now();
		} elseif ( 'pending' === $entry['ai_state'] ) {
			$cols['ai_state'] = $use_ai ? 'skipped' : 'none';
		}
		Entries::update( $id, $cols );
		if ( $ai ) {
			Entries::log( $id, 'analyzed', sprintf( 'Score %d, %s, %s urgency.', $cols['lead_score'], $cols['sentiment'], $cols['urgency'] ), 'ai' );
		} elseif ( $error ) {
			Entries::log( $id, 'ai_failed', $error->get_error_message(), 'ai' );
		}
		$updated = Entries::get( $id );
		Alerts::maybe_send( $updated );
		/**
		 * Fires after an entry was analysed.
		 *
		 * @param array      $updated Entry.
		 * @param array|null $ai      AI insights (null when rules only).
		 */
		do_action( 'dev3ei_entry_analyzed', $updated, $ai );
		return $error ? $error : $updated;
	}

	/**
	 * Builds the stored columns from rule + AI insights.
	 *
	 * @param array      $entry Entry.
	 * @param array      $rules Rule insights.
	 * @param array|null $ai    AI insights.
	 * @return array
	 */
	public static function columns( array $entry, array $rules, $ai ) {
		$mode = (string) Settings::get( 'score_mode', 'ai' );
		$base = $ai ? $ai : $rules;
		if ( $ai && 'rules' === $mode ) {
			$score = $rules['lead_score'];
		} elseif ( $ai && 'blend' === $mode ) {
			$score = (int) round( ( $ai['lead_score'] + $rules['lead_score'] ) / 2 );
		} else {
			$score = $base['lead_score'];
		}
		$cols      = array(
			'summary'     => $base['summary'],
			'sentiment'   => $base['sentiment'],
			'intent'      => $base['intent'],
			'category'    => $base['category'],
			'urgency'     => $base['urgency'],
			'lead_score'  => $score,
			'rule_score'  => $rules['lead_score'],
			'lead_reason' => 'rules' === $mode && $ai ? $rules['lead_reason'] : $base['lead_reason'],
			'language'    => $base['language'],
			'insight_src' => $ai ? 'ai' : 'rules',
			'model'       => $ai && isset( $ai['model'] ) ? $ai['model'] : $entry['model'],
		);
		$probe     = array_merge( $entry, $cols );
		$rule_tags = Tagger::tags_for( $probe );
		// Tags a rule added earlier but that no longer match are dropped; tags people added stay.
		$kept       = array_values( array_diff( (array) $entry['tags'], isset( $entry['rule_tags'] ) ? (array) $entry['rule_tags'] : array() ) );
		$tags       = Tagger::merge( $kept, $rule_tags );
		$suggestion = array();
		if ( $ai && ! empty( $ai['tags'] ) ) {
			if ( Settings::get( 'auto_apply_ai_tags', false ) ) {
				$tags = Tagger::merge( $tags, $ai['tags'] );
			} else {
				$suggestion = array_values( array_diff( Tagger::merge( $ai['tags'] ), $tags ) );
			}
		} elseif ( ! $ai ) {
			$suggestion = array_values( array_diff( (array) $entry['suggested_tags'], $tags ) );
		}
		$cols['tags']           = $tags;
		$cols['rule_tags']      = $rule_tags;
		$cols['suggested_tags'] = $suggestion;
		return $cols;
	}

	/**
	 * AI analysis.
	 *
	 * @param array  $entry   Entry.
	 * @param string $context Context.
	 * @return array|\WP_Error|null Null when there is nothing to analyse.
	 */
	public static function ai( array $entry, $context = 'admin' ) {
		$text = Text::compose( $entry['fields'] );
		if ( '' === trim( $text ) ) {
			return null;
		}
		$known  = array_slice( array_keys( Entries::tag_counts( 30 ) ), 0, 30 );
		$result = Prompts::run(
			'entry_insights',
			array(
				'site'           => wp_strip_all_tags( get_bloginfo( 'name' ) ),
				'form'           => $entry['form_title'],
				'entry'          => $text,
				'admin_language' => Prompts_Library::language_name(),
				'known_tags'     => $known ? implode( ', ', $known ) : 'none yet',
			),
			$context,
			array( 'max_tokens' => 1500 )
		);
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		$out          = self::normalize( isset( $result['data'] ) && is_array( $result['data'] ) ? $result['data'] : array() );
		$out['model'] = isset( $result['model'] ) ? (string) $result['model'] : '';
		return $out;
	}

	/**
	 * Clamps AI output to the allowed values.
	 *
	 * @param array $data Data.
	 * @return array
	 */
	public static function normalize( array $data ) {
		$sentiment = isset( $data['sentiment'] ) ? strtolower( (string) $data['sentiment'] ) : 'neutral';
		$category  = isset( $data['category'] ) ? strtolower( (string) $data['category'] ) : 'other';
		$urgency   = isset( $data['urgency'] ) ? strtolower( (string) $data['urgency'] ) : 'low';
		$tags      = array();
		foreach ( isset( $data['tags'] ) && is_array( $data['tags'] ) ? $data['tags'] : array() as $tag ) {
			$tag = Settings::tag( $tag );
			if ( '' !== $tag && ! in_array( $tag, $tags, true ) ) {
				$tags[] = $tag;
			}
		}
		return array(
			'summary'     => mb_substr( sanitize_text_field( isset( $data['summary'] ) ? (string) $data['summary'] : '' ), 0, 400 ),
			'sentiment'   => in_array( $sentiment, Prompts_Library::SENTIMENTS, true ) ? $sentiment : 'neutral',
			'intent'      => mb_substr( sanitize_text_field( isset( $data['intent'] ) ? (string) $data['intent'] : '' ), 0, 80 ),
			'category'    => in_array( $category, Prompts_Library::CATEGORIES, true ) ? $category : 'other',
			'lead_score'  => max( 0, min( 100, (int) round( isset( $data['lead_score'] ) ? (float) $data['lead_score'] : 0 ) ) ),
			'lead_reason' => mb_substr( sanitize_text_field( isset( $data['lead_reason'] ) ? (string) $data['lead_reason'] : '' ), 0, 200 ),
			'urgency'     => in_array( $urgency, Prompts_Library::URGENCY, true ) ? $urgency : 'low',
			'language'    => substr( strtolower( (string) preg_replace( '/[^A-Za-z\-]/', '', isset( $data['language'] ) ? (string) $data['language'] : '' ) ), 0, 8 ),
			'tags'        => array_slice( $tags, 0, 5 ),
			'source'      => 'ai',
		);
	}

	/**
	 * Background job handler (bulk + form-wide analysis).
	 *
	 * @param array $items Entry ids.
	 * @param array $job   Job.
	 * @return array
	 */
	public static function run_chunk( array $items, array $job ) {
		$use_ai = ! isset( $job['args']['ai'] ) || (bool) $job['args']['ai'];
		$out    = array();
		foreach ( $items as $id ) {
			$result     = self::analyze( (int) $id, $use_ai, 'admin' );
			$out[ $id ] = is_wp_error( $result ) ? $result->get_error_message() : true;
		}
		return $out;
	}
}
