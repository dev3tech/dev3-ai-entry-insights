<?php
/**
 * Plugin Name:       DEV3 AI Entry Insights for Gravity Forms & WPForms
 * Plugin URI:        https://dev3.tech/item/dev3-ai-entry-insights
 * Description:       AI summary, sentiment, intent, urgency and lead score for every Gravity Forms, WPForms and Contact Form 7 entry, with reply drafts, auto-tagging and an insights dashboard.
 * Version:           1.0.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            DEV3 Technologies
 * Author URI:        https://dev3.tech
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       dev3-ai-entry-insights
 * Domain Path:       /languages
 *
 * @package Dev3\AiEntryInsights
 */

defined( 'ABSPATH' ) || exit;

define( 'DEV3EI_VERSION', '1.0.0' );
define( 'DEV3EI_FILE', __FILE__ );
define( 'DEV3EI_PATH', plugin_dir_path( __FILE__ ) );
define( 'DEV3EI_URL', plugin_dir_url( __FILE__ ) );
define( 'DEV3EI_SLUG', 'dev3-ai-entry-insights' );
define( 'DEV3EI_NAME', 'DEV3 AI Entry Insights for Gravity Forms & WPForms' );
define( 'DEV3EI_MIN_PHP', '7.4' );
define( 'DEV3EI_MIN_WP', '6.0' );
define( 'DEV3EI_DB_VERSION', '1.0.1' );

if ( version_compare( PHP_VERSION, DEV3EI_MIN_PHP, '<' ) ) {
	return;
}

spl_autoload_register(
	static function ( $class_name ) {
		$prefix = 'Dev3\AiEntryInsights\\';
		if ( 0 !== strpos( $class_name, $prefix ) ) {
			return;
		}
		$file = DEV3EI_PATH . 'includes/' . str_replace( '\\', '/', substr( $class_name, strlen( $prefix ) ) ) . '.php';
		if ( is_readable( $file ) ) {
			require_once $file;
		}
	}
);

register_activation_hook( __FILE__, array( '\Dev3\AiEntryInsights\Plugin', 'activate' ) );
register_deactivation_hook( __FILE__, array( '\Dev3\AiEntryInsights\Plugin', 'deactivate' ) );
add_action( 'plugins_loaded', array( '\Dev3\AiEntryInsights\Plugin', 'instance' ) );
