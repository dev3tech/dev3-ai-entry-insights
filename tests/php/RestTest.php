<?php
/**
 * Admin REST API: permissions, inbox, entry actions, replies (AI mocked + wp_mail), stats, digest, forms, CSV.
 *
 * @package Dev3\AiEntryInsights
 */

require_once __DIR__ . '/Dev3ei_Base_Case.php';

use Dev3\AiEntryInsights\Core3\Core\Jobs;
use Dev3\AiEntryInsights\Data\Entries;
use Dev3\AiEntryInsights\Export\Csv;
use Dev3\AiEntryInsights\Insights\Capture;
use Dev3\AiEntryInsights\Insights\Digest;

class RestTest extends Dev3ei_Base_Case {

	/**
	 * Captures a few entries.
	 *
	 * @return int[]
	 */
	private function seed() {
		return array(
			Capture::record( 'gravityforms', 1, 'Request a quote', $this->fields() ),
			Capture::record( 'wpforms', 12, 'Contact us', $this->fields( array( 'name' => 'Joe', 'email' => 'joe@gmail.com', 'phone' => '', 'company' => '', 'budget' => '', 'message' => 'Terrible service, I want a refund today.' ) ) ),
			Capture::record( 'cf7', 3, 'Newsletter', $this->fields( array( 'name' => 'Sam', 'email' => 'sam@yahoo.com', 'phone' => '', 'company' => '', 'budget' => '', 'message' => 'Please subscribe me to the newsletter, thanks!' ) ) ),
		);
	}

	public function test_permissions() {
		$this->seed();
		$this->assertSame( 401, $this->rest( 'GET', 'entries' )->get_status() );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		$this->assertSame( 403, $this->rest( 'GET', 'entries' )->get_status(), 'Editors cannot read entries' );
		$this->assertSame( 403, $this->rest( 'POST', 'entries/1/analyze' )->get_status(), 'AI routes need manage + generate' );
		$this->admin();
		$this->assertSame( 403, $this->rest( 'GET', 'entries', array(), false )->get_status(), 'Strict nonce' );
		$this->assertSame( 200, $this->rest( 'GET', 'entries' )->get_status() );
	}

	public function test_inbox_views_filters_and_search() {
		list( $hot, $angry, $news ) = $this->seed();
		$this->admin();
		$all = $this->rest( 'GET', 'entries' )->get_data();
		$this->assertSame( 3, $all['total'] );
		$this->assertSame( array( 'all' => 3, 'new' => 3, 'hot' => 1, 'attention' => 2, 'starred' => 0, 'replied' => 0, 'archived' => 0 ), $all['counts'] );
		$this->assertSame( $hot, $this->rest( 'GET', 'entries?view=hot' )->get_data()['items'][0]['id'] );
		$this->assertSame( $angry, $this->rest( 'GET', 'entries?sentiment=negative' )->get_data()['items'][0]['id'] );
		$this->assertSame( $news, $this->rest( 'GET', 'entries?category=newsletter' )->get_data()['items'][0]['id'] );
		$this->assertSame( 1, $this->rest( 'GET', 'entries?form=wpforms:12' )->get_data()['total'] );
		$this->assertSame( 1, $this->rest( 'GET', 'entries?search=refund' )->get_data()['total'] );
		$this->assertSame( 1, $this->rest( 'GET', 'entries?tag=hot-lead' )->get_data()['total'] );
		$this->assertSame( 1, $this->rest( 'GET', 'entries?min_score=60' )->get_data()['total'] );
		$sorted = $this->rest( 'GET', 'entries?orderby=lead_score&order=asc' )->get_data()['items'];
		$this->assertSame( $hot, end( $sorted )['id'] );
		$this->assertArrayNotHasKey( 'fields', $sorted[0], 'List rows are light' );
	}

	public function test_entry_detail_update_and_delete() {
		list( $hot ) = $this->seed();
		$this->admin();
		$e = $this->rest( 'GET', 'entries/' . $hot )->get_data();
		$this->assertSame( 'read', $e['status'], 'Opening marks it read' );
		$this->assertNotEmpty( $e['fields'] );
		$this->assertStringContainsString( 'gf_entries', $e['entry_url'] );
		$res = $this->rest( 'POST', 'entries/' . $hot, array( 'starred' => true, 'tags' => array( 'VIP Client', 'hot-lead' ), 'note' => 'Call on Monday' ) );
		$this->assertTrue( $res->get_data()['starred'] );
		$this->assertSame( array( 'vip-client', 'hot-lead' ), $res->get_data()['tags'] );
		$this->assertSame( 'Call on Monday', $res->get_data()['note'] );
		$this->assertSame( 400, $this->rest( 'POST', 'entries/' . $hot, array( 'status' => 'bogus' ) )->get_status() );
		$this->assertSame( 404, $this->rest( 'GET', 'entries/99999' )->get_status() );
		$this->assertTrue( $this->rest( 'DELETE', 'entries/' . $hot )->get_data()['deleted'] );
		$this->assertNull( Entries::get( $hot ) );
	}

	public function test_bulk_actions() {
		$ids = $this->seed();
		$this->admin();
		$this->assertSame( 3, $this->rest( 'POST', 'entries/bulk', array( 'ids' => $ids, 'action' => 'archive' ) )->get_data()['done'] );
		$this->assertSame( 3, $this->rest( 'GET', 'entries?view=archived' )->get_data()['total'] );
		$this->rest( 'POST', 'entries/bulk', array( 'ids' => $ids, 'action' => 'tag', 'tag' => 'Q4 Campaign' ) );
		$this->assertContains( 'q4-campaign', Entries::get( $ids[1] )['tags'] );
		$this->rest( 'POST', 'entries/bulk', array( 'ids' => array( $ids[1] ), 'action' => 'untag', 'tag' => 'q4-campaign' ) );
		$this->assertNotContains( 'q4-campaign', Entries::get( $ids[1] )['tags'] );
		$this->assertSame( 400, $this->rest( 'POST', 'entries/bulk', array( 'ids' => $ids, 'action' => 'explode' ) )->get_status() );
		$res = $this->rest( 'POST', 'entries/bulk', array( 'ids' => $ids, 'action' => 'rescore' ) );
		$this->assertSame( 201, $res->get_status() );
		$this->assertSame( 'done', Jobs::run( $res->get_data()['job']['id'] )['status'] );
		$this->rest( 'POST', 'entries/bulk', array( 'ids' => array( $ids[2] ), 'action' => 'delete' ) );
		$this->assertNull( Entries::get( $ids[2] ) );
	}

	public function test_analyze_route_and_tag_acceptance() {
		list( $hot ) = $this->seed();
		$this->admin();
		$rules = $this->rest( 'POST', 'entries/' . $hot . '/analyze' );
		$this->assertSame( 200, $rules->get_status() );
		$this->assertSame( 'rules', $rules->get_data()['insight_src'], 'No key: rules only' );
		$this->ai_on();
		$this->fake_insights();
		$res = $this->rest( 'POST', 'entries/' . $hot . '/analyze' );
		$this->assertSame( 200, $res->get_status() );
		$this->assertSame( 'ai', $res->get_data()['insight_src'] );
		$this->assertSame( array( 'website', 'booking' ), $res->get_data()['suggested_tags'] );
		$res = $this->rest( 'POST', 'entries/' . $hot . '/tags/accept', array( 'tags' => array( 'booking' ) ) );
		$this->assertContains( 'booking', $res->get_data()['tags'] );
		$this->assertSame( array( 'website' ), $res->get_data()['suggested_tags'] );
		$res = $this->rest( 'POST', 'entries/' . $hot . '/tags/accept', array( 'tags' => array( 'website' ), 'reject' => true ) );
		$this->assertNotContains( 'website', $res->get_data()['tags'] );
		$this->assertSame( array(), $res->get_data()['suggested_tags'] );
	}

	public function test_reply_draft_is_not_sent_until_approved() {
		list( $hot ) = $this->seed();
		$this->admin();
		$this->ai_on( array( 'reply_signature' => 'Anna at Northwind', 'voice_guidelines' => 'We start projects within 2 weeks.' ) );
		reset_phpmailer_instance();
		$this->http[':generateContent'] = $this->gemini( array( 'subject' => 'Your website quote', 'body' => "Hi Anna,\n\nThanks for the details." ) );
		$draft                          = $this->rest( 'POST', 'entries/' . $hot . '/reply-draft', array( 'tone' => 'professional', 'notes' => 'Price is $6,000' ) )->get_data();
		$this->assertSame( 'Your website quote', $draft['subject'] );
		$this->assertSame( 'anna@northwind.com', $draft['to'] );
		$this->assertCount( 0, $this->mails(), 'Drafts are never sent' );
		$prompt = $this->prompt_of( $this->sent( 'generateContent' )[0] );
		$this->assertStringContainsString( 'Tone: professional', $prompt );
		$this->assertStringContainsString( 'Price is $6,000', $prompt );
		$this->assertStringContainsString( 'We start projects within 2 weeks.', $prompt );
		$this->assertStringNotContainsString( 'anna@northwind.com', $prompt );

		$this->settings( array( 'reply_from_email' => 'hello@northwind.example', 'reply_from_name' => 'Northwind' ) );
		$sent = $this->rest( 'POST', 'entries/' . $hot . '/reply', array( 'subject' => 'Your website quote', 'body' => 'Edited body' ) );
		$this->assertSame( 200, $sent->get_status() );
		$this->assertSame( 'replied', $sent->get_data()['status'] );
		$mail = $this->mails()[0];
		$this->assertSame( 'anna@northwind.com', $mail['to'][0][0] );
		$this->assertStringContainsString( 'Edited body', $mail['body'] );
		// The test bootstrap's wp_mail_from filter wins over the address; the From name comes from the settings.
		$this->assertStringContainsString( 'From: Northwind <', $mail['header'] );
		$this->assertSame( 400, $this->rest( 'POST', 'entries/' . $hot . '/reply', array( 'subject' => '', 'body' => 'x' ) )->get_status() );
	}

	public function test_templates_fill_without_ai() {
		list( $hot ) = $this->seed();
		$this->admin();
		$res = $this->rest( 'POST', 'entries/' . $hot . '/template', array( 'template' => 'thanks' ) )->get_data();
		$this->assertStringContainsString( 'Hi Anna,', $res['body'] );
		$this->assertStringContainsString( 'Request a quote', $res['body'] );
		$this->assertSame( 404, $this->rest( 'POST', 'entries/' . $hot . '/template', array( 'template' => 'nope' ) )->get_status() );
		$no_email = Capture::record( 'cf7', 3, 'X', array( \Dev3\AiEntryInsights\Insights\Text::field( 'm', 'Message', 'textarea', 'hello' ) ) );
		$this->ai_on();
		$this->assertSame( 400, $this->rest( 'POST', 'entries/' . $no_email . '/reply-draft' )->get_status() );
	}

	public function test_stats_and_digest_flow() {
		$this->seed();
		$this->admin();
		$stats = $this->rest( 'GET', 'stats?days=30' )->get_data();
		$this->assertSame( 3, $stats['total'] );
		$this->assertSame( 1, $stats['hot'] );
		$this->assertSame( 33, $stats['negative_pct'] );
		$this->assertCount( 30, $stats['series'] );
		$this->assertSame( 3, array_sum( wp_list_pluck( $stats['scores'], 'count' ) ) );
		$this->assertArrayHasKey( 'complaint', $stats['categories'] );
		$this->assertArrayHasKey( 'hot-lead', $stats['tags'] );
		$this->assertArrayHasKey( 'needs-care', $stats['tags'] );
		$this->assertCount( 3, $stats['forms'] );
		$this->assertSame( 2, $stats['attention_total'] );
		$this->assertNull( $stats['digest'] );

		$this->ai_on();
		$this->http[':generateContent'] = $this->gemini(
			array(
				'headline'      => 'Quote requests lead, one angry refund case',
				'summary'       => 'Three entries.',
				'themes'        => array( array( 'label' => 'Websites', 'share' => 1.7, 'sentiment' => 0.4 ) ),
				'opportunities' => array( 'Package offer' ),
				'risks'         => array( 'Refund request' ),
				'actions'       => array( 'Reply today' ),
			)
		);
		$draft = $this->rest( 'POST', 'digest', array( 'days' => 30 ) )->get_data();
		$this->assertSame( 1.0, $draft['digest']['themes'][0]['share'], 'Clamped' );
		$this->assertNull( Digest::pinned(), 'Draft only' );
		$prompt = $this->prompt_of( $this->sent( 'generateContent' )[0] );
		$this->assertStringNotContainsString( 'anna@', $prompt );
		$this->assertStringNotContainsString( 'Anna', $prompt );
		$pinned = $this->rest( 'POST', 'digest/pin', array( 'digest' => $draft['digest'], 'days' => 30 ) )->get_data();
		$this->assertSame( 'Three entries.', $pinned['pinned']['digest']['summary'] );
		$this->assertNotNull( $this->rest( 'GET', 'stats' )->get_data()['digest'] );
		$this->rest( 'DELETE', 'digest' );
		$this->assertNull( Digest::pinned() );
	}

	public function test_digest_needs_three_entries() {
		Capture::record( 'wpforms', 12, 'A', $this->fields() );
		$this->admin();
		$this->ai_on();
		$this->assertSame( 400, $this->rest( 'POST', 'digest' )->get_status() );
	}

	public function test_forms_config_preview_and_analyze_all() {
		$this->seed();
		$this->admin();
		$forms = $this->rest( 'GET', 'forms' )->get_data();
		$this->assertCount( 3, $forms['adapters'] );
		$keys = wp_list_pluck( $forms['forms'], 'key' );
		$this->assertContains( 'wpforms:12', $keys, 'Forms with entries are listed even when the form is gone' );
		$res = $this->rest( 'POST', 'forms/config', array( 'key' => 'wpforms:12', 'analyze' => false ) );
		$this->assertSame( array( 'key' => 'wpforms:12', 'capture' => true, 'analyze' => false ), $res->get_data() );
		$this->assertSame( 400, $this->rest( 'POST', 'forms/config', array( 'key' => 'bad key' ) )->get_status() );
		$this->assertSame( 400, $this->rest( 'POST', 'forms/import', array( 'key' => 'cf7:3' ) )->get_status(), 'CF7 stores nothing to import' );

		$preview = $this->rest( 'POST', 'rules/preview', array( 'text' => 'We need a quote ASAP', 'email' => 'cto@bigco.com', 'phone' => '+1 555 0101' ) )->get_data();
		$this->assertSame( 'high', $preview['urgency'] );
		$this->assertSame( 'quote_request', $preview['category'] );
		$this->assertSame( 70, $preview['lead_score'] );
		$this->assertSame( array( 'hot-lead' ), $preview['tags'] );
		$this->assertSame( 400, $this->rest( 'POST', 'rules/preview', array() )->get_status() );

		$this->ai_on();
		$this->fake_insights();
		$res = $this->rest( 'POST', 'analyze-all', array( 'scope' => 'unanalyzed', 'ai' => true ) );
		$this->assertSame( 201, $res->get_status() );
		$this->assertSame( 3, $res->get_data()['job']['total'] );
		Jobs::run( $res->get_data()['job']['id'] );
		$this->assertNull( $this->rest( 'POST', 'analyze-all', array( 'scope' => 'unanalyzed', 'ai' => true ) )->get_data()['job'] );
	}

	public function test_csv_rows_escape_formulas() {
		Capture::record( 'cf7', 3, 'Contact', $this->fields( array( 'name' => '=HYPERLINK("x")', 'message' => 'Need a quote' ) ) );
		$rows = Csv::rows( array( 'view' => 'all' ) );
		$this->assertSame( 'Lead score', $rows[0][7] );
		$this->assertCount( 2, $rows );
		$this->assertSame( "'=HYPERLINK(\"x\")", $rows[1][4] );
		$this->assertStringContainsString( 'Message: Need a quote', $rows[1][17] );
		$this->assertStringContainsString( 'action=dev3ei_export', Csv::url( array( 'tag' => 'x', 'evil' => 'y' ) ) );
		$this->assertStringNotContainsString( 'evil', Csv::url( array( 'tag' => 'x', 'evil' => 'y' ) ) );
	}

	public function test_privacy_export_erase_and_retention() {
		$this->seed();
		$export = Entries::export_personal_data( 'anna@northwind.com' );
		$this->assertCount( 1, $export['data'] );
		$this->assertTrue( $export['done'] );
		$erase = Entries::erase_personal_data( 'anna@northwind.com' );
		$this->assertTrue( $erase['items_removed'] );
		$this->assertSame( 2, Entries::query()['total'] );
		global $wpdb;
		$wpdb->query( "UPDATE {$wpdb->prefix}dev3ei_entries SET created_at = '2020-01-01 00:00:00'" ); // phpcs:ignore
		$this->assertSame( 0, Entries::prune( 0 ), '0 keeps forever' );
		$this->assertSame( 2, Entries::prune( 30 ) );
		$exporters = apply_filters( 'wp_privacy_personal_data_exporters', array() );
		$this->assertNotEmpty( array_filter( array_keys( $exporters ), static fn( $k ) => false !== strpos( $k, 'form-entries' ) ) );
	}
}
