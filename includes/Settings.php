<?php
/**
 * Plugin-specific settings (shared AI settings live in Core3\Core\Settings_Store).
 *
 * @package Dev3\AiEntryInsights
 */

namespace Dev3\AiEntryInsights;

use Dev3\AiEntryInsights\Core3\Core\Settings_Store;

defined( 'ABSPATH' ) || exit;

/**
 * Hooked into dev3ei_settings_defaults / dev3ei_settings_sanitize.
 */
class Settings {

	const SCORE_MODES = array( 'ai', 'rules', 'blend' );

	const TONES = array( 'friendly', 'professional', 'concise', 'apologetic', 'enthusiastic' );

	const RULE_WHEN = array( 'keyword', 'category', 'sentiment', 'urgency', 'score', 'form', 'source' );

	/**
	 * Built-in scoring signals and their default points.
	 */
	const SIGNALS = array(
		'phone'         => 15,
		'company_email' => 15,
		'company'       => 10,
		'budget'        => 15,
		'buying'        => 15,
		'urgent'        => 5,
		'long_message'  => 5,
		'negative'      => -10,
	);

	/**
	 * Current value of a setting.
	 *
	 * @param string $key      Key.
	 * @param mixed  $fallback Fallback.
	 * @return mixed
	 */
	public static function get( $key, $fallback = null ) {
		return Settings_Store::get( $key, $fallback );
	}

	/**
	 * Adds plugin defaults. No __() here: defaults can be read before init.
	 *
	 * @param array $defaults Shared defaults.
	 * @return array
	 */
	public static function defaults( $defaults ) {
		return array_merge(
			$defaults,
			array(
				'source_wpforms'      => true,
				'source_gravityforms' => true,
				'source_cf7'          => true,
				'forms'               => array(),
				'auto_analyze'        => true,
				'score_mode'          => 'ai',
				'hot_threshold'       => 70,
				'score_base'          => 20,
				'signals'             => self::SIGNALS,
				'keyword_rules'       => array(),
				'tag_rules'           => array(
					array(
						'when'  => 'score',
						'value' => '70',
						'tag'   => 'hot-lead',
					),
					array(
						'when'  => 'category',
						'value' => 'complaint',
						'tag'   => 'needs-care',
					),
				),
				'auto_apply_ai_tags'  => false,
				'voice_tone'          => 'friendly',
				'voice_guidelines'    => '',
				'reply_signature'     => '',
				'reply_from_name'     => '',
				'reply_from_email'    => '',
				'templates'           => array(
					array(
						'id'      => 'thanks',
						'name'    => 'Thanks, we will be in touch',
						'subject' => 'Thanks for contacting {site}',
						'body'    => "Hi {first_name},\n\nThanks for reaching out through our {form} form. We have your message and will get back to you within one business day.\n\nBest regards,\n{site}",
					),
					array(
						'id'      => 'call',
						'name'    => 'Book a call',
						'subject' => 'Next step: a quick call',
						'body'    => "Hi {first_name},\n\nThanks for the details. The quickest way forward is a 15-minute call. Reply with two times that suit you and we will confirm one.\n\nBest regards,\n{site}",
					),
				),
				'alerts_enabled'      => false,
				'alert_emails'        => '',
				'alert_hot'           => true,
				'alert_urgent'        => true,
				'alert_negative'      => false,
				'retention_days'      => 365,
				'store_page_url'      => true,
			)
		);
	}

	/**
	 * Sanitizes plugin keys.
	 *
	 * @param array $out     Sanitized so far.
	 * @param array $input   Raw input.
	 * @param array $current Current.
	 * @return array
	 */
	public static function sanitize( $out, $input, $current ) {
		unset( $current );
		foreach ( array( 'source_wpforms', 'source_gravityforms', 'source_cf7', 'auto_analyze', 'auto_apply_ai_tags', 'alerts_enabled', 'alert_hot', 'alert_urgent', 'alert_negative', 'store_page_url' ) as $flag ) {
			if ( isset( $input[ $flag ] ) ) {
				$out[ $flag ] = (bool) $input[ $flag ];
			}
		}
		if ( isset( $input['score_mode'] ) && in_array( $input['score_mode'], self::SCORE_MODES, true ) ) {
			$out['score_mode'] = $input['score_mode'];
		}
		if ( isset( $input['hot_threshold'] ) ) {
			$out['hot_threshold'] = min( 100, max( 1, (int) $input['hot_threshold'] ) );
		}
		if ( isset( $input['score_base'] ) ) {
			$out['score_base'] = min( 100, max( 0, (int) $input['score_base'] ) );
		}
		if ( isset( $input['retention_days'] ) ) {
			$out['retention_days'] = min( 3650, max( 0, (int) $input['retention_days'] ) );
		}
		if ( isset( $input['signals'] ) && is_array( $input['signals'] ) ) {
			$signals = array();
			foreach ( self::SIGNALS as $key => $default ) {
				$signals[ $key ] = isset( $input['signals'][ $key ] ) ? min( 100, max( -100, (int) $input['signals'][ $key ] ) ) : $default;
			}
			$out['signals'] = $signals;
		}
		if ( isset( $input['keyword_rules'] ) && is_array( $input['keyword_rules'] ) ) {
			$rules = array();
			foreach ( array_slice( $input['keyword_rules'], 0, 50 ) as $rule ) {
				$keyword = is_array( $rule ) && isset( $rule['keyword'] ) ? mb_substr( trim( sanitize_text_field( wp_unslash( (string) $rule['keyword'] ) ) ), 0, 60 ) : '';
				if ( '' === $keyword ) {
					continue;
				}
				$rules[] = array(
					'keyword' => $keyword,
					'points'  => min( 100, max( -100, isset( $rule['points'] ) ? (int) $rule['points'] : 0 ) ),
				);
			}
			$out['keyword_rules'] = $rules;
		}
		if ( isset( $input['tag_rules'] ) && is_array( $input['tag_rules'] ) ) {
			$rules = array();
			foreach ( array_slice( $input['tag_rules'], 0, 50 ) as $rule ) {
				if ( ! is_array( $rule ) || ! isset( $rule['when'], $rule['tag'] ) || ! in_array( $rule['when'], self::RULE_WHEN, true ) ) {
					continue;
				}
				$tag   = self::tag( $rule['tag'] );
				$value = mb_substr( trim( sanitize_text_field( wp_unslash( isset( $rule['value'] ) ? (string) $rule['value'] : '' ) ) ), 0, 80 );
				if ( '' === $tag || '' === $value ) {
					continue;
				}
				$rules[] = array(
					'when'  => $rule['when'],
					'value' => $value,
					'tag'   => $tag,
				);
			}
			$out['tag_rules'] = $rules;
		}
		if ( isset( $input['forms'] ) && is_array( $input['forms'] ) ) {
			$forms = array();
			foreach ( $input['forms'] as $key => $config ) {
				$key = self::form_key_clean( $key );
				if ( '' === $key || ! is_array( $config ) ) {
					continue;
				}
				$forms[ $key ] = array(
					'capture' => ! isset( $config['capture'] ) || (bool) $config['capture'],
					'analyze' => ! isset( $config['analyze'] ) || (bool) $config['analyze'],
				);
			}
			$out['forms'] = $forms;
		}
		if ( isset( $input['voice_tone'] ) && in_array( $input['voice_tone'], self::TONES, true ) ) {
			$out['voice_tone'] = $input['voice_tone'];
		}
		if ( isset( $input['voice_guidelines'] ) ) {
			$out['voice_guidelines'] = mb_substr( sanitize_textarea_field( wp_unslash( (string) $input['voice_guidelines'] ) ), 0, 2000 );
		}
		if ( isset( $input['reply_signature'] ) ) {
			$out['reply_signature'] = mb_substr( sanitize_textarea_field( wp_unslash( (string) $input['reply_signature'] ) ), 0, 300 );
		}
		if ( isset( $input['reply_from_name'] ) ) {
			$out['reply_from_name'] = mb_substr( sanitize_text_field( wp_unslash( (string) $input['reply_from_name'] ) ), 0, 80 );
		}
		if ( isset( $input['reply_from_email'] ) ) {
			$email                   = sanitize_email( wp_unslash( (string) $input['reply_from_email'] ) );
			$out['reply_from_email'] = is_email( $email ) ? $email : '';
		}
		if ( isset( $input['alert_emails'] ) ) {
			$out['alert_emails'] = implode( ', ', self::emails( (string) $input['alert_emails'] ) );
		}
		if ( isset( $input['templates'] ) && is_array( $input['templates'] ) ) {
			$templates = array();
			foreach ( array_slice( $input['templates'], 0, 30 ) as $i => $template ) {
				if ( ! is_array( $template ) ) {
					continue;
				}
				$name = mb_substr( sanitize_text_field( wp_unslash( isset( $template['name'] ) ? (string) $template['name'] : '' ) ), 0, 80 );
				$body = mb_substr( sanitize_textarea_field( wp_unslash( isset( $template['body'] ) ? (string) $template['body'] : '' ) ), 0, 5000 );
				if ( '' === $name || '' === trim( $body ) ) {
					continue;
				}
				$id          = isset( $template['id'] ) ? sanitize_key( (string) $template['id'] ) : '';
				$templates[] = array(
					'id'      => '' !== $id ? $id : 'tpl' . ( $i + 1 ),
					'name'    => $name,
					'subject' => mb_substr( sanitize_text_field( wp_unslash( isset( $template['subject'] ) ? (string) $template['subject'] : '' ) ), 0, 150 ),
					'body'    => $body,
				);
			}
			$out['templates'] = $templates;
		}
		return $out;
	}

	/**
	 * Normalised tag (lowercase slug, max 30 chars).
	 *
	 * @param mixed $tag Raw.
	 * @return string
	 */
	public static function tag( $tag ) {
		return substr( sanitize_title( is_scalar( $tag ) ? (string) $tag : '' ), 0, 30 );
	}

	/**
	 * Valid emails from a comma / newline separated list.
	 *
	 * @param string $items List.
	 * @return string[]
	 */
	public static function emails( $items ) {
		$out = array();
		foreach ( preg_split( '/[\s,;]+/', (string) $items ) as $email ) {
			$email = sanitize_email( $email );
			if ( is_email( $email ) && ! in_array( $email, $out, true ) ) {
				$out[] = $email;
			}
		}
		return array_slice( $out, 0, 10 );
	}

	/**
	 * Form key "<source>:<id>".
	 *
	 * @param string     $source  Source.
	 * @param int|string $form_id Form id.
	 * @return string
	 */
	public static function form_key( $source, $form_id ) {
		return sanitize_key( (string) $source ) . ':' . (int) $form_id;
	}

	/**
	 * Cleans a raw form key.
	 *
	 * @param mixed $key Raw key.
	 * @return string '' when invalid.
	 */
	public static function form_key_clean( $key ) {
		return preg_match( '/^([a-z0-9_-]{2,30}):(\d{1,10})$/', (string) $key, $m ) ? $m[1] . ':' . (int) $m[2] : '';
	}

	/**
	 * Per-form config (capture + analyze, both on by default).
	 *
	 * @param string     $source  Source.
	 * @param int|string $form_id Form id.
	 * @return array { capture, analyze }
	 */
	public static function form_config( $source, $form_id ) {
		$forms = (array) self::get( 'forms', array() );
		$key   = self::form_key( $source, $form_id );
		$cfg   = isset( $forms[ $key ] ) && is_array( $forms[ $key ] ) ? $forms[ $key ] : array();
		return array(
			'capture' => ! isset( $cfg['capture'] ) || (bool) $cfg['capture'],
			'analyze' => ! isset( $cfg['analyze'] ) || (bool) $cfg['analyze'],
		);
	}
}
