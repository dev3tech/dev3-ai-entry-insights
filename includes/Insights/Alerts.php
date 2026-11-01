<?php
/**
 * Opt-in email alerts for hot leads, urgent and negative entries (once per entry).
 *
 * @package Dev3\AiEntryInsights
 */

namespace Dev3\AiEntryInsights\Insights;

use Dev3\AiEntryInsights\Data\Entries;
use Dev3\AiEntryInsights\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Alerts.
 */
class Alerts {

	/**
	 * Category labels (also used in CSV and emails).
	 *
	 * @return array
	 */
	public static function category_labels() {
		return array(
			'sales_lead'      => __( 'Sales lead', 'dev3-ai-entry-insights' ),
			'quote_request'   => __( 'Quote request', 'dev3-ai-entry-insights' ),
			'support'         => __( 'Support', 'dev3-ai-entry-insights' ),
			'feedback'        => __( 'Feedback', 'dev3-ai-entry-insights' ),
			'complaint'       => __( 'Complaint', 'dev3-ai-entry-insights' ),
			'booking'         => __( 'Booking', 'dev3-ai-entry-insights' ),
			'job_application' => __( 'Job application', 'dev3-ai-entry-insights' ),
			'partnership'     => __( 'Partnership', 'dev3-ai-entry-insights' ),
			'newsletter'      => __( 'Newsletter', 'dev3-ai-entry-insights' ),
			'spam'            => __( 'Spam', 'dev3-ai-entry-insights' ),
			'other'           => __( 'Other', 'dev3-ai-entry-insights' ),
		);
	}

	/**
	 * Why an entry deserves an alert ('' = none).
	 *
	 * @param array $entry Entry.
	 * @return string
	 */
	public static function reason( array $entry ) {
		if ( 'archived' === $entry['status'] || 'spam' === $entry['category'] ) {
			return '';
		}
		if ( Settings::get( 'alert_hot', true ) && $entry['lead_score'] >= (int) Settings::get( 'hot_threshold', 70 ) ) {
			return 'hot';
		}
		if ( Settings::get( 'alert_urgent', true ) && 'high' === $entry['urgency'] ) {
			return 'urgent';
		}
		if ( Settings::get( 'alert_negative', false ) && 'negative' === $entry['sentiment'] ) {
			return 'negative';
		}
		return '';
	}

	/**
	 * Sends the alert when enabled, deserved and not sent before. Entries still waiting for AI are
	 * alerted after the analysis.
	 *
	 * @param array|null $entry Entry.
	 * @return bool Sent.
	 */
	public static function maybe_send( $entry ) {
		if ( ! $entry || ! Settings::get( 'alerts_enabled', false ) || $entry['alerted'] || 'pending' === $entry['ai_state'] ) {
			return false;
		}
		$reason = self::reason( $entry );
		if ( '' === $reason ) {
			return false;
		}
		$to = Settings::emails( (string) Settings::get( 'alert_emails', '' ) );
		if ( ! $to ) {
			$to = array( get_option( 'admin_email' ) );
		}
		$labels  = array(
			'hot'      => __( 'Hot lead', 'dev3-ai-entry-insights' ),
			'urgent'   => __( 'Urgent entry', 'dev3-ai-entry-insights' ),
			'negative' => __( 'Unhappy sender', 'dev3-ai-entry-insights' ),
		);
		$cats    = self::category_labels();
		$who     = '' !== $entry['name'] ? $entry['name'] : ( '' !== $entry['email'] ? $entry['email'] : __( 'Someone', 'dev3-ai-entry-insights' ) );
		$subject = sprintf(
			/* translators: 1: alert type, 2: score, 3: form, 4: sender */
			__( '[%1$s · %2$d] %3$s: %4$s', 'dev3-ai-entry-insights' ),
			$labels[ $reason ],
			$entry['lead_score'],
			$entry['form_title'],
			$who
		);
		$lines = array(
			'' !== $entry['summary'] ? $entry['summary'] : Text::plain( array_slice( $entry['fields'], 0, 6 ), array( 'password', 'hidden', 'file', 'signature' ) ),
			'',
			/* translators: %d: lead score */
			sprintf( __( 'Lead score: %d / 100', 'dev3-ai-entry-insights' ), $entry['lead_score'] ) . ( '' !== $entry['lead_reason'] ? ' (' . $entry['lead_reason'] . ')' : '' ),
			/* translators: %s: category */
			sprintf( __( 'Category: %s', 'dev3-ai-entry-insights' ), isset( $cats[ $entry['category'] ] ) ? $cats[ $entry['category'] ] : $entry['category'] ),
			/* translators: 1: urgency, 2: sentiment */
			sprintf( __( 'Urgency: %1$s · Sentiment: %2$s', 'dev3-ai-entry-insights' ), $entry['urgency'], $entry['sentiment'] ),
			'' !== $entry['email'] ? sprintf( /* translators: %s: email */ __( 'Reply to: %s', 'dev3-ai-entry-insights' ), $entry['email'] ) : '',
			'',
			/* translators: %s: URL */
			sprintf( __( 'Open the entry: %s', 'dev3-ai-entry-insights' ), admin_url( 'admin.php?page=' . DEV3EI_SLUG . '#/entries/' . $entry['id'] ) ),
		);
		/**
		 * Filters an alert email before it is sent.
		 *
		 * @param array $mail  { to, subject, body }.
		 * @param array $entry Entry.
		 */
		$mail = apply_filters(
			'dev3ei_alert_email',
			array(
				'to'      => $to,
				'subject' => $subject,
				'body'    => implode( "\n", $lines ),
			),
			$entry
		);
		$sent = wp_mail( $mail['to'], $mail['subject'], $mail['body'], array( 'Content-Type: text/plain; charset=UTF-8' ) );
		Entries::update( $entry['id'], array( 'alerted' => 1 ) );
		Entries::log( $entry['id'], 'alerted', $sent ? implode( ', ', (array) $mail['to'] ) : 'failed', 'system' );
		return (bool) $sent;
	}
}
