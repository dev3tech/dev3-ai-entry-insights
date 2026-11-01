<?php
/**
 * Rule-based insights, auto-tag rules and the AI text minimisation (no AI involved).
 *
 * @package Dev3\AiEntryInsights
 */

require_once __DIR__ . '/Dev3ei_Base_Case.php';

use Dev3\AiEntryInsights\Insights\Rules;
use Dev3\AiEntryInsights\Insights\Tagger;
use Dev3\AiEntryInsights\Insights\Text;

class RulesTest extends Dev3ei_Base_Case {

	public function test_strong_lead_scores_high_with_reasons() {
		$r = Rules::analyze( $this->fields() );
		// 20 base + phone 15 + company email 15 + company 10 + budget field 15 + buying words 15 ("next week" is medium urgency: no points).
		$this->assertSame( 90, $r['lead_score'] );
		$this->assertSame( 'quote_request', $r['category'] );
		$this->assertSame( 'medium', $r['urgency'] );
		$this->assertStringContainsString( 'company email', $r['lead_reason'] );
		$this->assertSame( 'rules', $r['source'] );
	}

	public function test_free_mail_and_vague_message_score_low() {
		$fields = array_values(
			array_filter(
				array(
					Text::field( 'e', 'Email', 'email', 'joe@gmail.com' ),
					Text::field( 'm', 'Message', 'textarea', 'Just saying hello.' ),
				)
			)
		);
		$r      = Rules::analyze( $fields );
		$this->assertSame( 20, $r['lead_score'] );
		$this->assertSame( 'Little buying signal in the answers.', $r['lead_reason'] );
		$this->assertSame( 'neutral', $r['sentiment'] );
	}

	public function test_sentiment_urgency_and_categories() {
		$this->assertSame( 'negative', Rules::sentiment( 'my order arrived broken and i want a refund' ) );
		$this->assertSame( 'positive', Rules::sentiment( 'thanks, great service, love it' ) );
		$this->assertSame( 'high', Rules::urgency( 'our shop is in an outage, please help asap' ) );
		$this->assertSame( 'medium', Rules::urgency( 'could we talk next week' ) );
		$this->assertSame( 'low', Rules::urgency( 'no rush' ) );
		$this->assertSame( 'complaint', Rules::category( 'this is unacceptable, i want a refund' ) );
		$this->assertSame( 'job_application', Rules::category( 'i am applying for the developer position, cv attached' ) );
		$this->assertSame( 'booking', Rules::category( 'can i book an appointment on friday' ) );
		$this->assertSame( 'spam', Rules::category( 'we sell backlinks and seo services' ) );
		$this->assertSame( 'other', Rules::category( 'hello' ) );
	}

	public function test_spam_caps_the_score() {
		$fields = array( Text::field( 'm', 'Message', 'textarea', 'Buy backlinks today! Best SEO services, quote inside, project pricing.' ) );
		$r      = Rules::analyze( $fields );
		$this->assertSame( 'spam', $r['category'] );
		$this->assertLessThanOrEqual( 5, $r['lead_score'] );
	}

	public function test_signals_and_keyword_rules_come_from_settings() {
		$this->settings(
			array(
				'score_base'    => 10,
				'signals'       => array( 'phone' => 0, 'company_email' => 30, 'company' => 0, 'budget' => 0, 'buying' => 0, 'urgent' => 0, 'long_message' => 0, 'negative' => 0 ),
				'keyword_rules' => array(
					array( 'keyword' => 'Booking', 'points' => 25 ),
					array( 'keyword' => 'student', 'points' => -50 ),
				),
			)
		);
		$r = Rules::analyze( $this->fields() );
		$this->assertSame( 65, $r['lead_score'] );
		$this->assertStringContainsString( 'mentions "Booking"', $r['lead_reason'] );
		$this->assertSame( 0, Rules::analyze( $this->fields( array( 'message' => 'I am a student, no budget.' ) ) )['lead_score'], 'Scores are clamped at 0' );
	}

	public function test_tag_rules_match_every_condition_type() {
		$entry = array(
			'fields'     => $this->fields(),
			'source'     => 'wpforms',
			'form_id'    => 12,
			'sentiment'  => 'negative',
			'category'   => 'complaint',
			'urgency'    => 'high',
			'lead_score' => 75,
		);
		$rules = array(
			array( 'when' => 'keyword', 'value' => 'pizza, booking', 'tag' => 'Booking Request' ),
			array( 'when' => 'category', 'value' => 'complaint', 'tag' => 'needs-care' ),
			array( 'when' => 'sentiment', 'value' => 'negative', 'tag' => 'unhappy' ),
			array( 'when' => 'urgency', 'value' => 'high', 'tag' => 'urgent' ),
			array( 'when' => 'score', 'value' => '70', 'tag' => 'hot-lead' ),
			array( 'when' => 'score', 'value' => '90', 'tag' => 'vip' ),
			array( 'when' => 'form', 'value' => 'wpforms:12', 'tag' => 'contact-form' ),
			array( 'when' => 'source', 'value' => 'gravityforms', 'tag' => 'gf' ),
		);
		$this->assertSame( array( 'booking-request', 'needs-care', 'unhappy', 'urgent', 'hot-lead', 'contact-form' ), Tagger::tags_for( $entry, $rules ) );
		$this->assertSame( array( 'a', 'b' ), Tagger::merge( array( 'A', 'b' ), array( 'a', '' ) ) );
	}

	public function test_ai_text_leaves_out_private_fields() {
		$text = Text::compose( $this->fields() );
		$this->assertStringContainsString( 'Email domain: northwind.com', $text );
		$this->assertStringContainsString( 'Your name: Anna', $text );
		$this->assertStringNotContainsString( 'Berg', $text );
		$this->assertStringNotContainsString( 'anna@', $text );
		$this->assertStringNotContainsString( '555', $text );
		$this->assertStringNotContainsString( 'secret-campaign', $text );
		$this->assertNull( Text::field( 'p', 'Password', 'password', 'hunter2' ), 'Passwords are never stored' );
		$this->assertNull( Text::field( 'x', 'Empty', 'text', '   ' ) );
	}

	public function test_sender_detection() {
		$this->assertSame( 'anna@northwind.com', Text::email( $this->fields() ) );
		$this->assertSame( 'Anna Berg', Text::name( $this->fields() ) );
		$split = array( Text::field( 'a', 'First name', 'text', 'Li' ), Text::field( 'b', 'Last name', 'text', 'Wei' ), Text::field( 'c', 'Company name', 'text', 'Acme' ) );
		$this->assertSame( 'Li Wei', Text::name( $split ) );
		$this->assertSame( 'x@y.org', Text::email( array( Text::field( 'q', 'Contact', 'text', 'x@y.org' ) ) ) );
	}
}
