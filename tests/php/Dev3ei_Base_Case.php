<?php
/**
 * Shared set-up: clean table + settings, a fake Gemini (pre_http_request), REST + entry helpers.
 *
 * @package Dev3\AiEntryInsights
 */

use Dev3\AiEntryInsights\Adapters\Registry;
use Dev3\AiEntryInsights\Ai\Prompts_Library;
use Dev3\AiEntryInsights\Core3\Api\Ai;
use Dev3\AiEntryInsights\Core3\Core\Cache;
use Dev3\AiEntryInsights\Core3\Core\Schema;
use Dev3\AiEntryInsights\Core3\Core\Settings_Store;
use Dev3\AiEntryInsights\Core3\Data\Usage_Repository;
use Dev3\AiEntryInsights\Data\Tables;
use Dev3\AiEntryInsights\Insights\Text;

abstract class Dev3ei_Base_Case extends WP_UnitTestCase {

	/**
	 * Captured HTTP requests: [ url, body ].
	 *
	 * @var array
	 */
	protected $requests = array();

	/**
	 * Fake replies: url substring => [ status, body ] or callable( url, body ).
	 *
	 * @var array
	 */
	protected $http = array();

	public function set_up() {
		parent::set_up();
		Schema::install( DEV3EI_DB_VERSION );
		global $wpdb;
		foreach ( Tables::ALL as $t ) {
			$wpdb->query( 'DELETE FROM ' . Tables::name( $t ) ); // phpcs:ignore
		}
		$wpdb->query( "DELETE FROM {$wpdb->prefix}dev3ei_core_jobs" ); // phpcs:ignore
		update_option( Settings_Store::OPTION, array() );
		delete_option( 'dev3ei_digest' );
		Settings_Store::flush();
		Usage_Repository::flush();
		Cache::reset_memo();
		Cache::flush();
		Prompts_Library::register();
		Ai::$client     = null;
		$this->requests = array();
		$this->http     = array();
		GFAPI::reset();
		Registry::reset();
		delete_transient( 'dev3ei_queue_lock' );
		add_filter( 'pre_http_request', array( $this, 'fake_http' ), 10, 3 );
		add_filter( 'dev3ei_retry_delay_ms', '__return_zero' );
		add_filter( 'dev3ei_jobs_use_action_scheduler', '__return_false' );
		reset_phpmailer_instance();
		$_SERVER['REMOTE_ADDR'] = '203.0.113.7';
	}

	public function tear_down() {
		remove_filter( 'pre_http_request', array( $this, 'fake_http' ), 10 );
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	/**
	 * Saves a (fake) API key so AI is enabled.
	 *
	 * @param array $settings Extra settings.
	 */
	protected function ai_on( array $settings = array() ) {
		Settings_Store::update( array( 'api_key' => 'AIza-test-key-000000000000' ) + $settings );
		Settings_Store::flush();
	}

	/**
	 * Updates plugin settings.
	 *
	 * @param array $settings Settings.
	 */
	protected function settings( array $settings ) {
		Settings_Store::update( $settings );
		Settings_Store::flush();
	}

	/**
	 * Gemini generateContent reply carrying structured JSON.
	 *
	 * @param array $data Structured output.
	 * @return array
	 */
	protected function gemini( array $data ) {
		return array(
			200,
			array(
				'candidates'    => array(
					array(
						'content'      => array( 'parts' => array( array( 'text' => wp_json_encode( $data ) ) ) ),
						'finishReason' => 'STOP',
					),
				),
				'usageMetadata' => array(
					'promptTokenCount'     => 300,
					'candidatesTokenCount' => 60,
					'totalTokenCount'      => 360,
				),
			),
		);
	}

	/**
	 * Fakes the entry-insights call.
	 *
	 * @param array $data Overrides.
	 */
	protected function fake_insights( array $data = array() ) {
		$this->http[':generateContent'] = $this->gemini(
			$data + array(
				'summary'     => 'Wants a quote for a new website with booking.',
				'sentiment'   => 'positive',
				'intent'      => 'Quote for a website',
				'category'    => 'quote_request',
				'lead_score'  => 88,
				'lead_reason' => 'Company email, budget and deadline.',
				'urgency'     => 'medium',
				'language'    => 'en',
				'tags'        => array( 'Website', 'booking' ),
			)
		);
	}

	public function fake_http( $pre, $args, $url ) {
		$body             = isset( $args['body'] ) ? json_decode( (string) $args['body'], true ) : null;
		$this->requests[] = array(
			'url'  => $url,
			'body' => $body,
		);
		foreach ( $this->http as $needle => $reply ) {
			if ( false !== strpos( $url, $needle ) ) {
				if ( is_callable( $reply ) ) {
					$reply = $reply( $url, $body );
				}
				return array(
					'headers'  => array(),
					'body'     => is_string( $reply[1] ) ? $reply[1] : wp_json_encode( $reply[1] ),
					'response' => array(
						'code'    => $reply[0],
						'message' => 'x',
					),
					'cookies'  => array(),
				);
			}
		}
		return new WP_Error( 'http_blocked', 'No fake for ' . $url );
	}

	/**
	 * Requests sent to a URL fragment.
	 *
	 * @param string $needle Fragment.
	 * @return array
	 */
	protected function sent( $needle ) {
		return array_values( array_filter( $this->requests, static fn( $r ) => false !== strpos( $r['url'], $needle ) ) );
	}

	/**
	 * Prompt text of a captured Gemini request.
	 *
	 * @param array $request Request.
	 * @return string
	 */
	protected function prompt_of( array $request ) {
		return (string) ( $request['body']['contents'][0]['parts'][0]['text'] ?? '' ) . "\n" . (string) ( $request['body']['systemInstruction']['parts'][0]['text'] ?? '' );
	}

	/**
	 * Normalised fields for a typical quote request.
	 *
	 * @param array $over key => value overrides (message, email, phone, company, name).
	 * @return array
	 */
	protected function fields( array $over = array() ) {
		$v = $over + array(
			'name'    => 'Anna Berg',
			'email'   => 'anna@northwind.com',
			'phone'   => '+1 555 0100',
			'company' => 'Northwind Traders',
			'budget'  => '$5,000 - $10,000',
			'message' => 'Hi, we need a quote for a new website with online booking. We would like to launch by next week.',
		);
		return array_values(
			array_filter(
				array(
					Text::field( 'f1', 'Your name', 'name', $v['name'] ),
					Text::field( 'f2', 'Email', 'email', $v['email'] ),
					Text::field( 'f3', 'Phone', 'phone', $v['phone'] ),
					Text::field( 'f4', 'Company', 'text', $v['company'] ),
					Text::field( 'f5', 'Budget', 'choice', $v['budget'] ),
					Text::field( 'f6', 'Message', 'textarea', $v['message'] ),
					Text::field( 'f7', 'Referrer', 'hidden', 'utm=secret-campaign' ),
				)
			)
		);
	}

	/**
	 * REST call as the current user (strict nonce).
	 *
	 * @param string $method Method.
	 * @param string $route  Route below dev3ei/v1.
	 * @param array  $params Params.
	 * @param bool   $nonce  Send the nonce.
	 * @return WP_REST_Response
	 */
	protected function rest( $method, $route, array $params = array(), $nonce = true ) {
		$parts = explode( '?', $route, 2 );
		$req   = new WP_REST_Request( $method, '/dev3ei/v1/' . ltrim( $parts[0], '/' ) );
		if ( isset( $parts[1] ) ) {
			parse_str( $parts[1], $query );
			$req->set_query_params( $query );
		}
		if ( $nonce ) {
			$req->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
		}
		foreach ( $params as $k => $v ) {
			$req->set_param( $k, $v );
		}
		return rest_get_server()->dispatch( $req );
	}

	/**
	 * Logs in an administrator.
	 *
	 * @return int User id.
	 */
	protected function admin() {
		$id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $id );
		return $id;
	}

	/**
	 * Sent mails.
	 *
	 * @return array
	 */
	protected function mails() {
		return tests_retrieve_phpmailer_instance()->mock_sent;
	}
}
