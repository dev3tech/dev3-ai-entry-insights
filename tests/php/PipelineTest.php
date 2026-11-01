<?php
/**
 * Capture → rules → AI queue → analysis (Gemini mocked) → tags → alerts.
 *
 * @package Dev3\AiEntryInsights
 */

require_once __DIR__ . '/Dev3ei_Base_Case.php';

use Dev3\AiEntryInsights\Core3\Core\Jobs;
use Dev3\AiEntryInsights\Data\Entries;
use Dev3\AiEntryInsights\Insights\Analyzer;
use Dev3\AiEntryInsights\Insights\Capture;

class PipelineTest extends Dev3ei_Base_Case {

	public function test_capture_without_ai_stores_rule_insights() {
		$id = Capture::record( 'wpforms', 12, 'Contact us', $this->fields(), array( 'page_url' => 'https://example.org/contact/' ) );
		$e  = Entries::get( $id );
		$this->assertSame( 'new', $e['status'] );
		$this->assertSame( 'rules', $e['insight_src'] );
		$this->assertSame( 'none', $e['ai_state'] );
		$this->assertSame( 90, $e['lead_score'] );
		$this->assertSame( 'anna@northwind.com', $e['email'] );
		$this->assertSame( 'Anna Berg', $e['name'] );
		$this->assertSame( 'https://example.org/contact/', $e['page_url'] );
		$this->assertContains( 'hot-lead', $e['tags'], 'Default tag rule: score >= 70' );
		$this->assertSame( 'captured', $e['history'][0]['event'] );
		$this->assertCount( 0, $this->requests, 'No network during capture' );
	}

	public function test_capture_with_ai_queues_and_cron_analyses() {
		$this->ai_on();
		$this->fake_insights();
		$id = Capture::record( 'gravityforms', 1, 'Request a quote', $this->fields() );
		$this->assertSame( 'pending', Entries::get( $id )['ai_state'] );
		$this->assertNotFalse( wp_next_scheduled( Analyzer::QUEUE_HOOK ) );
		$this->assertCount( 0, $this->sent( 'generateContent' ), 'AI never runs inside the submit request' );

		$this->assertSame( 1, Analyzer::process_queue( 5 ) );
		$e = Entries::get( $id );
		$this->assertSame( 'done', $e['ai_state'] );
		$this->assertSame( 'ai', $e['insight_src'] );
		$this->assertSame( 88, $e['lead_score'] );
		$this->assertSame( 90, $e['rule_score'] );
		$this->assertSame( 'Wants a quote for a new website with booking.', $e['summary'] );
		$this->assertSame( 'Quote for a website', $e['intent'] );
		$this->assertSame( 'positive', $e['sentiment'] );
		$this->assertSame( array( 'website', 'booking' ), $e['suggested_tags'], 'AI tags wait for acceptance' );
		$this->assertNotContains( 'website', $e['tags'] );
		$this->assertSame( 'gemini-2.5-flash', $e['model'] );

		$prompt = $this->prompt_of( $this->sent( 'generateContent' )[0] );
		$this->assertStringContainsString( 'Email domain: northwind.com', $prompt );
		$this->assertStringNotContainsString( 'anna@northwind.com', $prompt );
		$this->assertStringNotContainsString( '555 0100', $prompt );
		$this->assertStringNotContainsString( 'secret-campaign', $prompt );
		$this->assertStringContainsString( 'Request a quote', $prompt );
	}

	public function test_auto_apply_tags_and_score_modes() {
		$this->ai_on( array( 'auto_apply_ai_tags' => true, 'score_mode' => 'blend' ) );
		$this->fake_insights( array( 'lead_score' => 50 ) );
		$id = Capture::record( 'cf7', 3, 'Contact form 1', $this->fields(), array( 'analyze' => false ) );
		$e  = Analyzer::analyze( $id );
		$this->assertSame( 70, $e['lead_score'], 'Blend = (50 + 90) / 2' );
		$this->assertContains( 'website', $e['tags'] );
		$this->assertContains( 'hot-lead', $e['tags'] );
		$this->assertSame( array(), $e['suggested_tags'] );

		$this->settings( array( 'score_mode' => 'rules' ) );
		\Dev3\AiEntryInsights\Core3\Core\Cache::flush();
		$e = Analyzer::analyze( $id );
		$this->assertSame( 90, $e['lead_score'] );
		$this->assertSame( 'Wants a quote for a new website with booking.', $e['summary'], 'Rules mode still keeps the AI summary' );
	}

	public function test_stale_rule_tags_are_dropped_but_manual_tags_stay() {
		$this->ai_on();
		$id = Capture::record( 'wpforms', 12, 'Contact us', $this->fields(), array( 'analyze' => false ) );
		$this->assertContains( 'hot-lead', Entries::get( $id )['tags'] );
		Entries::update( $id, array( 'tags' => array_merge( Entries::get( $id )['tags'], array( 'vip' ) ) ) );
		$this->fake_insights( array( 'lead_score' => 30 ) );
		$e = Analyzer::analyze( $id );
		$this->assertNotContains( 'hot-lead', $e['tags'], 'Score fell below the rule threshold' );
		$this->assertContains( 'vip', $e['tags'] );
	}

	public function test_ai_failure_keeps_rules_and_records_error() {
		$this->ai_on();
		$this->http[':generateContent'] = array( 400, array( 'error' => array( 'code' => 400, 'message' => 'API key not valid.', 'status' => 'INVALID_ARGUMENT' ) ) );
		$id                             = Capture::record( 'wpforms', 12, 'Contact us', $this->fields(), array( 'analyze' => false ) );
		$result                         = Analyzer::analyze( $id );
		$this->assertWPError( $result );
		$e = Entries::get( $id );
		$this->assertSame( 'failed', $e['ai_state'] );
		$this->assertSame( 'rules', $e['insight_src'] );
		$this->assertSame( 90, $e['lead_score'] );
		$this->assertNotSame( '', $e['ai_error'] );
	}

	public function test_invalid_ai_values_are_clamped() {
		$n = Analyzer::normalize( array( 'sentiment' => 'ecstatic', 'category' => 'weird', 'urgency' => 'now', 'lead_score' => 180, 'tags' => array( 'A B', 'a-b', '', array( 'x' ) ), 'language' => 'en-US!!' ) );
		$this->assertSame( 'neutral', $n['sentiment'] );
		$this->assertSame( 'other', $n['category'] );
		$this->assertSame( 'low', $n['urgency'] );
		$this->assertSame( 100, $n['lead_score'] );
		$this->assertSame( array( 'a-b' ), $n['tags'] );
		$this->assertSame( 'en-us', $n['language'] );
	}

	public function test_per_form_and_source_switches() {
		$this->settings( array( 'forms' => array( 'wpforms:12' => array( 'capture' => false, 'analyze' => true ) ) ) );
		$this->assertSame( 0, Capture::record( 'wpforms', 12, 'Contact us', $this->fields() ) );
		$this->assertGreaterThan( 0, Capture::record( 'wpforms', 13, 'Other', $this->fields() ) );
		$this->settings( array( 'source_cf7' => false ) );
		$this->assertSame( 0, Capture::record( 'cf7', 1, 'CF7', $this->fields() ) );

		$this->ai_on( array( 'forms' => array( 'gravityforms:2' => array( 'capture' => true, 'analyze' => false ) ) ) );
		$id = Capture::record( 'gravityforms', 2, 'No AI form', $this->fields() );
		$this->assertSame( 'none', Entries::get( $id )['ai_state'], 'Form with AI off is not queued' );
	}

	public function test_source_refs_are_deduplicated() {
		$a = Capture::record( 'gravityforms', 1, 'Quote', $this->fields(), array( 'ref' => '55' ) );
		$b = Capture::record( 'gravityforms', 1, 'Quote', $this->fields(), array( 'ref' => '55' ) );
		$this->assertSame( $a, $b );
		$this->assertSame( 1, Entries::query()['total'] );
	}

	public function test_alerts_for_hot_leads_once() {
		$this->settings( array( 'alerts_enabled' => true, 'alert_emails' => 'sales@example.org, bad-address' ) );
		$id = Capture::record( 'wpforms', 12, 'Contact us', $this->fields() );
		$mails = $this->mails();
		$this->assertCount( 1, $mails );
		$this->assertSame( 'sales@example.org', $mails[0]['to'][0][0] );
		$this->assertStringContainsString( 'Hot lead', $mails[0]['subject'] );
		$this->assertStringContainsString( '#/entries/' . $id, $mails[0]['body'] );
		$this->assertTrue( Entries::get( $id )['alerted'] );
		Analyzer::analyze( $id, false );
		$this->assertCount( 1, $this->mails(), 'Only one alert per entry' );

		Capture::record( 'wpforms', 12, 'Contact us', $this->fields( array( 'email' => 'x@gmail.com', 'phone' => '', 'company' => '', 'budget' => '', 'message' => 'Just a question about opening times.' ) ) );
		$this->assertCount( 1, $this->mails(), 'Cold entries do not alert' );
	}

	public function test_alerts_wait_for_ai_when_queued() {
		$this->ai_on( array( 'alerts_enabled' => true ) );
		$this->fake_insights( array( 'urgency' => 'high', 'lead_score' => 30 ) );
		Capture::record( 'wpforms', 12, 'Contact us', $this->fields() );
		$this->assertCount( 0, $this->mails() );
		Analyzer::process_queue( 5 );
		$this->assertCount( 1, $this->mails() );
		$this->assertStringContainsString( 'Urgent', $this->mails()[0]['subject'] );
	}

	public function test_bulk_job_analyses_entries() {
		$this->ai_on();
		$this->fake_insights();
		$ids = array(
			Capture::record( 'wpforms', 12, 'A', $this->fields(), array( 'analyze' => false ) ),
			Capture::record( 'wpforms', 12, 'B', $this->fields( array( 'message' => 'Another request for a quote.' ) ), array( 'analyze' => false ) ),
		);
		$this->admin();
		$job = Jobs::create( Analyzer::JOB, $ids, array( 'ai' => true ), 5 );
		$run = Jobs::run( $job );
		$this->assertSame( 'done', $run['status'] );
		$this->assertSame( 2, $run['done'] );
		foreach ( $ids as $id ) {
			$this->assertSame( 'ai', Entries::get( $id )['insight_src'] );
		}
	}

	public function test_catch_up_runs_stale_queue_inline() {
		$this->ai_on();
		$this->fake_insights();
		$id = Capture::record( 'wpforms', 12, 'A', $this->fields() );
		$this->assertSame( 0, Analyzer::catch_up(), 'Fresh entries wait for cron' );
		global $wpdb;
		$wpdb->update( $wpdb->prefix . 'dev3ei_entries', array( 'updated_at' => gmdate( 'Y-m-d H:i:s', time() - 120 ) ), array( 'id' => $id ) ); // phpcs:ignore
		$this->assertSame( 1, Analyzer::catch_up() );
		$this->assertSame( 'done', Entries::get( $id )['ai_state'] );
	}

	public function test_ai_off_switch_skips_pending() {
		$this->ai_on();
		$id = Capture::record( 'wpforms', 12, 'A', $this->fields() );
		$this->settings( array( 'ai_enabled' => false ) );
		Analyzer::process_queue( 5 );
		$this->assertSame( 'skipped', Entries::get( $id )['ai_state'] );
		$this->assertCount( 0, $this->sent( 'generateContent' ) );
	}
}
