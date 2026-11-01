<?php
/**
 * Replies: canned templates (no AI), AI reply drafts (ported from DEV3 AI Form Builder's
 * Auto_Reply) that the admin edits, and sending the approved reply by email.
 *
 * @package Dev3\AiEntryInsights
 */

namespace Dev3\AiEntryInsights\Reply;

use Dev3\AiEntryInsights\Core3\Api\Prompts;
use Dev3\AiEntryInsights\Data\Entries;
use Dev3\AiEntryInsights\Insights\Text;
use Dev3\AiEntryInsights\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Replies.
 */
class Replies {

	/**
	 * Placeholder values for an entry.
	 *
	 * @param array $entry Entry.
	 * @return array
	 */
	public static function vars( array $entry ) {
		$name  = (string) $entry['name'];
		$first = '' !== $name ? (string) strtok( $name, ' ' ) : __( 'there', 'dev3-ai-entry-insights' );
		return array(
			'{name}'       => '' !== $name ? $name : $first,
			'{first_name}' => $first,
			'{form}'       => (string) $entry['form_title'],
			'{site}'       => wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES ),
			'{summary}'    => (string) $entry['summary'],
			'{email}'      => (string) $entry['email'],
		);
	}

	/**
	 * Fills a template for an entry.
	 *
	 * @param array  $entry       Entry.
	 * @param string $template_id Template id.
	 * @return array|\WP_Error { subject, body, to }
	 */
	public static function from_template( array $entry, $template_id ) {
		foreach ( (array) Settings::get( 'templates', array() ) as $template ) {
			if ( isset( $template['id'] ) && $template['id'] === $template_id ) {
				$vars = self::vars( $entry );
				return array(
					'subject' => strtr( (string) $template['subject'], $vars ),
					'body'    => strtr( (string) $template['body'], $vars ),
					'to'      => $entry['email'],
				);
			}
		}
		return new \WP_Error( 'dev3ei_no_template', __( 'Template not found.', 'dev3-ai-entry-insights' ), array( 'status' => 404 ) );
	}

	/**
	 * AI reply draft (nothing is sent).
	 *
	 * @param array  $entry Entry.
	 * @param string $tone  Tone.
	 * @param string $notes Owner notes for this reply.
	 * @return array|\WP_Error { subject, body, to, model, tokens, cached }
	 */
	public static function draft( array $entry, $tone = '', $notes = '' ) {
		if ( '' === $entry['email'] ) {
			return new \WP_Error( 'dev3ei_no_email', __( 'This entry has no email address to reply to.', 'dev3-ai-entry-insights' ), array( 'status' => 400 ) );
		}
		$tone      = in_array( $tone, Settings::TONES, true ) ? $tone : (string) Settings::get( 'voice_tone', 'friendly' );
		$site      = wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES );
		$signature = trim( (string) Settings::get( 'reply_signature', '' ) );
		$guides    = trim( (string) Settings::get( 'voice_guidelines', '' ) );
		$name      = '' !== $entry['name'] ? (string) strtok( $entry['name'], ' ' ) : 'unknown';
		$result    = Prompts::run(
			'reply_draft',
			array(
				'site'       => $site,
				'language'   => '' !== $entry['language'] ? 'the language with ISO code "' . $entry['language'] . '"' : 'the same language as the submission',
				'tone'       => $tone,
				'signature'  => '' !== $signature ? $signature : $site . ' team',
				'guidelines' => '' !== $guides ? mb_substr( $guides, 0, 2000 ) : 'none',
				'form'       => $entry['form_title'],
				'name'       => $name,
				'summary'    => '' !== $entry['summary'] ? $entry['summary'] : 'not analysed',
				'notes'      => '' !== trim( (string) $notes ) ? mb_substr( (string) $notes, 0, 2000 ) : 'none',
				'entry'      => Text::compose( $entry['fields'], 5000 ),
			),
			'admin',
			array( 'max_tokens' => 3000 )
		);
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		$data = isset( $result['data'] ) && is_array( $result['data'] ) ? $result['data'] : array();
		return array(
			'subject' => mb_substr( sanitize_text_field( isset( $data['subject'] ) ? (string) $data['subject'] : '' ), 0, 150 ),
			'body'    => mb_substr( sanitize_textarea_field( isset( $data['body'] ) ? (string) $data['body'] : '' ), 0, 3000 ),
			'to'      => $entry['email'],
			'model'   => isset( $result['model'] ) ? (string) $result['model'] : '',
			'tokens'  => isset( $result['usage']['total'] ) ? (int) $result['usage']['total'] : 0,
			'cached'  => ! empty( $result['cached'] ),
		);
	}

	/**
	 * Sends an approved reply.
	 *
	 * @param array  $entry   Entry.
	 * @param string $subject Subject.
	 * @param string $body    Plain-text body.
	 * @return true|\WP_Error
	 */
	public static function send( array $entry, $subject, $body ) {
		$to = sanitize_email( (string) $entry['email'] );
		if ( ! is_email( $to ) ) {
			return new \WP_Error( 'dev3ei_no_email', __( 'This entry has no email address to reply to.', 'dev3-ai-entry-insights' ), array( 'status' => 400 ) );
		}
		$subject = trim( sanitize_text_field( (string) $subject ) );
		$body    = trim( sanitize_textarea_field( (string) $body ) );
		if ( '' === $subject || '' === $body ) {
			return new \WP_Error( 'dev3ei_empty_reply', __( 'Add a subject and a message.', 'dev3-ai-entry-insights' ), array( 'status' => 400 ) );
		}
		$headers    = array( 'Content-Type: text/plain; charset=UTF-8' );
		$from_email = (string) Settings::get( 'reply_from_email', '' );
		$from_name  = (string) Settings::get( 'reply_from_name', '' );
		if ( is_email( $from_email ) ) {
			$name      = '' !== $from_name ? $from_name : wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES );
			$headers[] = 'From: ' . str_replace( array( "\r", "\n", '"' ), '', $name ) . ' <' . $from_email . '>';
		}
		/**
		 * Filters a reply before it is sent.
		 *
		 * @param array $mail  { to, subject, body, headers }.
		 * @param array $entry Entry.
		 */
		$mail = apply_filters(
			'dev3ei_reply_email',
			array(
				'to'      => $to,
				'subject' => $subject,
				'body'    => $body,
				'headers' => $headers,
			),
			$entry
		);
		if ( ! wp_mail( $mail['to'], $mail['subject'], $mail['body'], $mail['headers'] ) ) {
			Entries::log( $entry['id'], 'reply_failed', $to );
			return new \WP_Error( 'dev3ei_mail_failed', __( 'The email could not be sent. Check your site\'s mail settings.', 'dev3-ai-entry-insights' ), array( 'status' => 500 ) );
		}
		Entries::update(
			$entry['id'],
			array(
				'status'     => 'replied',
				'replied_at' => Entries::now(),
			)
		);
		Entries::log( $entry['id'], 'replied', $subject . "\n\n" . $body );
		return true;
	}
}
