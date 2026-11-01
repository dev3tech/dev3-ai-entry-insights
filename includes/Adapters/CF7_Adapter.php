<?php
/**
 * Contact Form 7 (bonus adapter). CF7 stores nothing, so valid submissions are captured from
 * `wpcf7_mail_sent` and `wpcf7_mail_failed` (a broken mailer should not lose the lead).
 * Spam submissions never reach these hooks.
 *
 * @package Dev3\AiEntryInsights
 */

namespace Dev3\AiEntryInsights\Adapters;

use Dev3\AiEntryInsights\Insights\Capture;
use Dev3\AiEntryInsights\Insights\Text;

defined( 'ABSPATH' ) || exit;

/**
 * CF7 adapter.
 */
class CF7_Adapter extends Adapter {

	const TYPES = array(
		'text'       => 'text',
		'email'      => 'email',
		'tel'        => 'phone',
		'url'        => 'url',
		'number'     => 'number',
		'range'      => 'number',
		'date'       => 'date',
		'textarea'   => 'textarea',
		'select'     => 'choice',
		'checkbox'   => 'choice',
		'radio'      => 'choice',
		'acceptance' => 'consent',
		'file'       => 'file',
		'hidden'     => 'hidden',
		'quiz'       => 'captcha',
		'captchar'   => 'captcha',
		'submit'     => 'submit',
	);

	/**
	 * Captured submissions this request (both hooks can fire in odd setups).
	 *
	 * @var array
	 */
	private $done = array();

	/**
	 * Id.
	 *
	 * @return string
	 */
	public function id() {
		return 'cf7';
	}

	/**
	 * Label.
	 *
	 * @return string
	 */
	public function label() {
		return 'Contact Form 7';
	}

	/**
	 * Active?
	 *
	 * @return bool
	 */
	public function is_active() {
		return class_exists( 'WPCF7_ContactForm' ) && defined( 'WPCF7_VERSION' );
	}

	/**
	 * Version.
	 *
	 * @return string
	 */
	public function version() {
		return defined( 'WPCF7_VERSION' ) ? (string) WPCF7_VERSION : '';
	}

	/**
	 * Plugin URL.
	 *
	 * @return string
	 */
	public function plugin_url() {
		return 'https://wordpress.org/plugins/contact-form-7/';
	}

	/**
	 * Note.
	 *
	 * @return string
	 */
	public function import_note() {
		return __( 'Contact Form 7 does not store submissions. New submissions are captured from now on.', 'dev3-ai-entry-insights' );
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
		foreach ( \WPCF7_ContactForm::find( array( 'posts_per_page' => 100 ) ) as $form ) {
			$out[] = array(
				'id'       => (int) $form->id(),
				'title'    => (string) $form->title(),
				'edit_url' => admin_url( 'admin.php?page=wpcf7&post=' . (int) $form->id() . '&action=edit' ),
			);
		}
		return $out;
	}

	/**
	 * Hooks.
	 */
	public function register() {
		add_action( 'wpcf7_mail_sent', array( $this, 'mail_done' ) );
		add_action( 'wpcf7_mail_failed', array( $this, 'mail_done' ) );
	}

	/**
	 * `wpcf7_mail_sent` / `wpcf7_mail_failed`.
	 *
	 * @param \WPCF7_ContactForm $form Form.
	 * @return int Entry id.
	 */
	public function mail_done( $form ) {
		if ( ! is_object( $form ) || ! method_exists( $form, 'id' ) || ! class_exists( 'WPCF7_Submission' ) ) {
			return 0;
		}
		$submission = \WPCF7_Submission::get_instance();
		if ( ! $submission || ( method_exists( $submission, 'is' ) && $submission->is( 'spam' ) ) ) {
			return 0;
		}
		$page = method_exists( $submission, 'get_meta' ) ? (string) $submission->get_meta( 'url' ) : '';
		return $this->capture_posted( $form, (array) $submission->get_posted_data(), $page );
	}

	/**
	 * Captures posted data for a form (also used by tests).
	 *
	 * @param \WPCF7_ContactForm $form   Form.
	 * @param array              $posted Posted data.
	 * @param string             $page   Page URL.
	 * @return int
	 */
	public function capture_posted( $form, array $posted, $page = '' ) {
		$form_id = (int) $form->id();
		if ( isset( $this->done[ $form_id ] ) ) {
			return $this->done[ $form_id ];
		}
		$id                     = Capture::record(
			$this->id(),
			$form_id,
			(string) $form->title(),
			self::normalize( $form, $posted ),
			array( 'page_url' => $page )
		);
		$this->done[ $form_id ] = $id;
		return $id;
	}

	/**
	 * Posted data → normalised fields, with labels from the form template.
	 *
	 * @param \WPCF7_ContactForm $form   Form.
	 * @param array              $posted Posted data.
	 * @return array
	 */
	public static function normalize( $form, array $posted ) {
		$labels = self::labels_from_template( (string) $form->prop( 'form' ) );
		$types  = array();
		foreach ( (array) $form->scan_form_tags() as $tag ) {
			if ( '' !== (string) $tag->name ) {
				$types[ (string) $tag->name ] = (string) $tag->basetype;
			}
		}
		$out = array();
		foreach ( $posted as $name => $value ) {
			$name = (string) $name;
			if ( 0 === strpos( $name, '_wpcf7' ) || 0 === strpos( $name, '_' ) ) {
				continue;
			}
			$base = isset( $types[ $name ] ) ? $types[ $name ] : 'text';
			$type = isset( self::TYPES[ $base ] ) ? self::TYPES[ $base ] : 'text';
			if ( 'text' === $type && preg_match( '/(^|[-_])name($|[-_])/', $name ) && ! preg_match( '/company|business/', $name ) ) {
				$type = 'name';
			}
			$label = isset( $labels[ $name ] ) ? $labels[ $name ] : ucwords( trim( str_replace( array( 'your-', '-', '_' ), array( '', ' ', ' ' ), $name ) ) );
			$row   = Text::field( $name, $label, $type, $value );
			if ( $row ) {
				$out[] = $row;
			}
		}
		return $out;
	}

	/**
	 * Field labels from `<label>Text [tag name]</label>` (or the text right before a tag).
	 * Ported from DEV3 AI Form Autofill & Spam Shield.
	 *
	 * @param string $template Form template.
	 * @return array name => label
	 */
	public static function labels_from_template( $template ) {
		$labels = array();
		$tag_re = '/\[\s*[a-z_]+\*?\s+([A-Za-z][A-Za-z0-9_\-:.]*)[^\]]*\]/';
		if ( preg_match_all( '#<label[^>]*>(.*?)</label>#is', $template, $blocks ) ) {
			foreach ( $blocks[1] as $body ) {
				if ( ! preg_match_all( $tag_re, $body, $names ) ) {
					continue;
				}
				$text = trim( (string) preg_replace( '/\s+/', ' ', wp_strip_all_tags( (string) preg_replace( '/\[[^\]]*\]/', ' ', $body ) ) ) );
				foreach ( $names[1] as $name ) {
					if ( '' !== $text && ! isset( $labels[ $name ] ) ) {
						$labels[ $name ] = trim( $text, " \t:*" );
					}
				}
			}
		}
		$prev = '';
		foreach ( (array) preg_split( '/\R/', $template ) as $line ) {
			if ( preg_match_all( $tag_re, $line, $names ) ) {
				$inline = trim( wp_strip_all_tags( (string) preg_replace( '/\[[^\]]*\]/', ' ', $line ) ) );
				foreach ( $names[1] as $name ) {
					$text = '' !== $inline ? $inline : $prev;
					if ( ! isset( $labels[ $name ] ) && '' !== $text ) {
						$labels[ $name ] = trim( (string) preg_replace( '/\s+/', ' ', $text ), " \t:*" );
					}
				}
				$prev = '';
			} else {
				$plain = trim( wp_strip_all_tags( $line ) );
				$prev  = '' !== $plain ? $plain : $prev;
			}
		}
		return $labels;
	}

	/**
	 * Link to the form editor.
	 *
	 * @param array $entry Entry.
	 * @return string
	 */
	public function entry_url( array $entry ) {
		return admin_url( 'admin.php?page=wpcf7&post=' . (int) $entry['form_id'] . '&action=edit' );
	}
}
