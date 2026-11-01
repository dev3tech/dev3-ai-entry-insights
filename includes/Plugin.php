<?php
/**
 * Bootstrap and wiring.
 *
 * @package Dev3\AiEntryInsights
 */

namespace Dev3\AiEntryInsights;

use Dev3\AiEntryInsights\Adapters\Registry;
use Dev3\AiEntryInsights\Core3\Core\Assets;
use Dev3\AiEntryInsights\Core3\Core\Capabilities;
use Dev3\AiEntryInsights\Core3\Core\Privacy;
use Dev3\AiEntryInsights\Core3\Core\Schema;
use Dev3\AiEntryInsights\Data\Entries;
use Dev3\AiEntryInsights\Data\Tables;
use Dev3\AiEntryInsights\Export\Csv;
use Dev3\AiEntryInsights\Insights\Analyzer;

defined( 'ABSPATH' ) || exit;

/**
 * Singleton entry point.
 */
final class Plugin {

	const CRON_HOOK = 'dev3ei_daily';

	/**
	 * Instance.
	 *
	 * @var Plugin|null
	 */
	private static $instance = null;

	/**
	 * Boots once.
	 *
	 * @return Plugin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
			self::$instance->boot();
		}
		return self::$instance;
	}

	/**
	 * Activation.
	 */
	public static function activate() {
		self::register_schema();
		Schema::install( DEV3EI_DB_VERSION );
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CRON_HOOK );
		}
	}

	/**
	 * Deactivation.
	 */
	public static function deactivate() {
		wp_clear_scheduled_hook( self::CRON_HOOK );
		wp_clear_scheduled_hook( Analyzer::QUEUE_HOOK );
	}

	/**
	 * Plugin tables / settings defaults are registered here so activation and runtime agree.
	 */
	public static function register_schema() {
		if ( ! has_filter( 'dev3ei_settings_defaults', array( Settings::class, 'defaults' ) ) ) {
			add_filter( 'dev3ei_settings_defaults', array( Settings::class, 'defaults' ) );
			add_filter( 'dev3ei_settings_sanitize', array( Settings::class, 'sanitize' ), 10, 3 );
			add_filter( 'dev3ei_schema_sql', array( Tables::class, 'schema' ), 10, 2 );
		}
	}

	/**
	 * Hooks.
	 */
	private function boot() {
		self::register_schema();
		add_action( 'init', array( $this, 'textdomain' ), 1 );
		add_action( 'init', array( Ai\Prompts_Library::class, 'register' ), 5 );
		add_action( 'admin_init', static fn() => Schema::maybe_install( DEV3EI_DB_VERSION ) );
		add_action( self::CRON_HOOK, array( $this, 'daily' ) );
		add_action( 'rest_api_init', array( $this, 'rest' ) );
		Privacy::init();
		add_action( 'init', array( $this, 'privacy' ) );
		// Job types and the queue hook must be registered on every request.
		Analyzer::register();
		// Adapters hook in after every form plugin has loaded.
		add_action( 'plugins_loaded', array( Registry::class, 'boot' ), 20 );
		Csv::register();
		add_action( 'admin_init', array( $this, 'admin_assets' ) );
		if ( is_admin() ) {
			( new Admin\Admin_Page() )->register();
		}
	}

	/**
	 * Daily housekeeping: core prune + entry retention.
	 */
	public function daily() {
		Schema::prune_usage();
		Entries::prune( (int) Settings::get( 'retention_days', 365 ) );
	}

	/**
	 * Admin bundle (registered once; Assets::register adds the boot script on every call).
	 */
	public function admin_assets() {
		if ( wp_script_is( Assets::handle( 'admin' ), 'registered' ) ) {
			return;
		}
		Assets::register(
			array( 'admin' ),
			array(
				'siteName'    => wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES ),
				'canGenerate' => Capabilities::can_generate(),
				'exportUrl'   => Csv::url(),
				'adapters'    => Registry::summary(),
				'adminEmail'  => (string) get_option( 'admin_email' ),
			)
		);
	}

	/**
	 * GDPR exporter/eraser for captured entries (registered on init so the label is translated).
	 */
	public function privacy() {
		Privacy::add_exporter( __( 'Form entries', 'dev3-ai-entry-insights' ), array( Entries::class, 'export_personal_data' ) );
		Privacy::add_eraser( __( 'Form entries', 'dev3-ai-entry-insights' ), array( Entries::class, 'erase_personal_data' ) );
	}

	/**
	 * Translations (on init, as WP 6.7 expects).
	 */
	public function textdomain() {
		load_plugin_textdomain( 'dev3-ai-entry-insights', false, dirname( plugin_basename( DEV3EI_FILE ) ) . '/languages' );
	}

	/**
	 * REST routes.
	 */
	public function rest() {
		( new Core3\Rest\Core_Controller() )->register_routes();
		( new Rest\Admin_Controller() )->register_routes();
	}
}
