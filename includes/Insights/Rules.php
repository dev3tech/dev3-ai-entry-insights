<?php
/**
 * Rule-based insights (no AI): lead score from configurable signals and keyword rules, lexicon
 * sentiment, urgency words and keyword categories. Ported and extended from DEV3 AI Form Builder
 * (Entry_Insights::rule_based). Used when AI is off, fails, or as the "rules" half of blend mode.
 *
 * @package Dev3\AiEntryInsights
 */

namespace Dev3\AiEntryInsights\Insights;

use Dev3\AiEntryInsights\Ai\Prompts_Library;
use Dev3\AiEntryInsights\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Rules engine.
 */
class Rules {

	const FREE_MAIL = array( 'gmail.com', 'googlemail.com', 'yahoo.com', 'hotmail.com', 'outlook.com', 'live.com', 'icloud.com', 'me.com', 'aol.com', 'gmx.de', 'gmx.net', 'web.de', 'proton.me', 'protonmail.com', 'mail.com', 'yandex.com', 'zoho.com', 'msn.com' );

	/**
	 * Category keyword patterns, checked in order.
	 *
	 * @return array category => regex
	 */
	public static function category_patterns() {
		$patterns = array(
			'spam'            => '/\b(casino|crypto ?currency|bitcoin|viagra|backlinks?|seo (services|agency)|guest post|rank your (site|website)|loan offer)\b/u',
			'complaint'       => '/\b(refund|broken|angry|terrible|worst|complain(t|ing)?|disappointed|unacceptable|scam|never again|still waiting)\b/u',
			'quote_request'   => '/\b(quote|quotation|estimate|pricing|price list|proposal|how much)\b/u',
			'booking'         => '/\b(book(ing)?|appointment|reservation|reserve|schedule a|table for|availability)\b/u',
			'job_application' => '/\b(resume|cv|job|position|vacanc(y|ies)|apply(ing)? for|internship|hiring)\b/u',
			'support'         => '/\b(help|issue|error|problem|not working|bug|can ?not log ?in|can\'t log ?in|password reset|crash(es|ed)?)\b/u',
			'partnership'     => '/\b(partner(ship)?|collaborat\w*|affiliate|sponsor\w*|reseller|wholesale)\b/u',
			'sales_lead'      => '/\b(buy|purchase|demo|interested in|hire you|our project|we need|looking for)\b/u',
			'newsletter'      => '/\b(subscribe|newsletter|mailing list)\b/u',
			'feedback'        => '/\b(feedback|suggestion|idea|love (it|your)|great (job|service)|thank you|thanks)\b/u',
		);
		/**
		 * Keyword patterns used to guess a category without AI.
		 *
		 * @param array $patterns category => regex.
		 */
		return (array) apply_filters( 'dev3ei_rule_category_patterns', $patterns );
	}

	/**
	 * Analyses fields with the rules.
	 *
	 * @param array $fields Normalised fields.
	 * @return array Insights { summary, sentiment, intent, category, lead_score, lead_reason, urgency, language, tags, source }.
	 */
	public static function analyze( array $fields ) {
		$signals = wp_parse_args( (array) Settings::get( 'signals', array() ), Settings::SIGNALS );
		$score   = (int) Settings::get( 'score_base', 20 );
		$reasons = array();
		$text    = Text::haystack( $fields );
		$add     = static function ( $key, $reason ) use ( &$score, &$reasons, $signals ) {
			$points = isset( $signals[ $key ] ) ? (int) $signals[ $key ] : 0;
			if ( 0 === $points ) {
				return;
			}
			$score    += $points;
			$reasons[] = $reason;
		};
		$seen    = array();
		foreach ( $fields as $field ) {
			$probe = strtolower( $field['label'] . ' ' . $field['key'] );
			if ( 'phone' === $field['type'] && empty( $seen['phone'] ) ) {
				$seen['phone'] = true;
				$add( 'phone', __( 'left a phone number', 'dev3-ai-entry-insights' ) );
			}
			if ( 'email' === $field['type'] && empty( $seen['email'] ) ) {
				$seen['email'] = true;
				$domain        = strtolower( (string) substr( (string) strrchr( (string) $field['value'], '@' ), 1 ) );
				if ( '' !== $domain && ! in_array( $domain, self::FREE_MAIL, true ) ) {
					$add( 'company_email', __( 'uses a company email', 'dev3-ai-entry-insights' ) );
				}
			}
			if ( empty( $seen['company'] ) && preg_match( '/(company|organi[sz]ation|business|employer)/', $probe ) ) {
				$seen['company'] = true;
				$add( 'company', __( 'named a company', 'dev3-ai-entry-insights' ) );
			}
			if ( empty( $seen['budget'] ) && preg_match( '/(budget|price|quote|amount|spend)/', $probe ) ) {
				$seen['budget'] = true;
				$add( 'budget', __( 'shared a budget', 'dev3-ai-entry-insights' ) );
			}
		}
		if ( preg_match( '/\b(quote|pricing|price|buy|purchase|order|proposal|budget|demo|hire|contract|project)\b/u', $text ) ) {
			$add( 'buying', __( 'mentions buying intent', 'dev3-ai-entry-insights' ) );
		}
		$urgency = self::urgency( $text );
		if ( 'high' === $urgency ) {
			$add( 'urgent', __( 'has a tight timeline', 'dev3-ai-entry-insights' ) );
		}
		if ( Text::longest_text( $fields ) >= 280 ) {
			$add( 'long_message', __( 'wrote a detailed message', 'dev3-ai-entry-insights' ) );
		}
		$sentiment = self::sentiment( $text );
		if ( 'negative' === $sentiment ) {
			$add( 'negative', __( 'sounds unhappy', 'dev3-ai-entry-insights' ) );
		}
		foreach ( (array) Settings::get( 'keyword_rules', array() ) as $rule ) {
			$keyword = isset( $rule['keyword'] ) ? mb_strtolower( (string) $rule['keyword'] ) : '';
			if ( '' !== $keyword && false !== mb_strpos( $text, $keyword ) && 0 !== (int) $rule['points'] ) {
				$score += (int) $rule['points'];
				/* translators: %s: keyword */
				$reasons[] = sprintf( __( 'mentions "%s"', 'dev3-ai-entry-insights' ), $rule['keyword'] );
			}
		}
		$category = self::category( $text );
		if ( 'spam' === $category ) {
			$score     = min( $score, 5 );
			$reasons[] = __( 'looks like spam', 'dev3-ai-entry-insights' );
		}
		$reasons = array_values( array_unique( $reasons ) );
		return array(
			'summary'     => '',
			'sentiment'   => $sentiment,
			'intent'      => '',
			'category'    => $category,
			'lead_score'  => max( 0, min( 100, $score ) ),
			'lead_reason' => $reasons ? mb_substr( ucfirst( implode( ', ', $reasons ) ) . '.', 0, 250 ) : __( 'Little buying signal in the answers.', 'dev3-ai-entry-insights' ),
			'urgency'     => $urgency,
			'language'    => '',
			'tags'        => array(),
			'source'      => 'rules',
		);
	}

	/**
	 * Lexicon sentiment.
	 *
	 * @param string $text Lowercase text.
	 * @return string positive|neutral|negative
	 */
	public static function sentiment( $text ) {
		$negative = preg_match_all( '/\b(refund|broken|angry|terrible|awful|worst|complain\w*|disappointed|cancel\w*|unacceptable|scam|rude|useless|frustrat\w*|annoy\w*|never again|still waiting|bad)\b/u', $text );
		$positive = preg_match_all( '/\b(thanks|thank you|great|love|excellent|amazing|happy|awesome|fantastic|wonderful|pleased|recommend|perfect|excited)\b/u', $text );
		if ( $negative > $positive ) {
			return 'negative';
		}
		return $positive > $negative ? 'positive' : 'neutral';
	}

	/**
	 * Urgency words.
	 *
	 * @param string $text Lowercase text.
	 * @return string low|medium|high
	 */
	public static function urgency( $text ) {
		if ( preg_match( '/\b(asap|urgent(ly)?|immediately|right away|today|tonight|emergency|outage|deadline)\b/u', $text ) ) {
			return 'high';
		}
		if ( preg_match( '/\b(tomorrow|this week|next week|soon|by (monday|tuesday|wednesday|thursday|friday)|end of (the )?month)\b/u', $text ) ) {
			return 'medium';
		}
		return 'low';
	}

	/**
	 * Keyword category.
	 *
	 * @param string $text Lowercase text.
	 * @return string
	 */
	public static function category( $text ) {
		foreach ( self::category_patterns() as $category => $pattern ) {
			if ( in_array( $category, Prompts_Library::CATEGORIES, true ) && @preg_match( $pattern, $text ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- filtered patterns may be invalid.
				return $category;
			}
		}
		return 'other';
	}
}
