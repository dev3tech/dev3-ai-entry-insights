<?php
/**
 * Loads WPForms Lite and Contact Form 7 (free, installed in wp-env) and the Gravity Forms stubs
 * (Gravity Forms is commercial) before the plugin.
 *
 * @package Dev3\AiEntryInsights
 */

require_once __DIR__ . '/stubs/gravityforms.php';

tests_add_filter(
	'dev3ei_test_dependencies',
	static function () {
		$deps = array();
		foreach ( array( 'wpforms-lite/wpforms.php', 'contact-form-7/wp-contact-form-7.php' ) as $dev3ei_dep ) {
			if ( file_exists( WP_PLUGIN_DIR . '/' . $dev3ei_dep ) ) {
				$deps[] = $dev3ei_dep;
			}
		}
		return $deps;
	}
);

tests_add_filter(
	'wp_mail_from',
	static function () {
		return 'site@example.org';
	}
);
