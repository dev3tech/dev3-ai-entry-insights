<?php
/**
 * Adapters: WPForms Lite and Contact Form 7 run for real (installed in wp-env); Gravity Forms uses
 * in-memory stubs of its documented API (tests/php/stubs/gravityforms.php).
 *
 * @package Dev3\AiEntryInsights
 */

require_once __DIR__ . '/Dev3ei_Base_Case.php';

use Dev3\AiEntryInsights\Adapters\CF7_Adapter;
use Dev3\AiEntryInsights\Adapters\Gravity_Forms_Adapter;
use Dev3\AiEntryInsights\Adapters\Registry;
use Dev3\AiEntryInsights\Adapters\WPForms_Adapter;
use Dev3\AiEntryInsights\Core3\Core\Jobs;
use Dev3\AiEntryInsights\Data\Entries;

class AdaptersTest extends Dev3ei_Base_Case {

	/**
	 * A GF form with compound and private fields.
	 *
	 * @return array
	 */
	private function gf_form() {
		$id = GFAPI::add_form(
			array(
				'title'  => 'Request a quote',
				'fields' => array(
					array( 'id' => 1, 'type' => 'name', 'label' => 'Name', 'inputs' => array( array( 'id' => '1.3', 'label' => 'First' ), array( 'id' => '1.6', 'label' => 'Last' ), array( 'id' => '1.2', 'label' => 'Prefix', 'isHidden' => true ) ) ),
					array( 'id' => 2, 'type' => 'email', 'label' => 'Email' ),
					array( 'id' => 3, 'type' => 'phone', 'label' => 'Phone' ),
					array( 'id' => 4, 'type' => 'textarea', 'label' => 'Project details', 'adminLabel' => 'Details' ),
					array( 'id' => 5, 'type' => 'checkbox', 'label' => 'Services', 'inputs' => array( array( 'id' => '5.1' ), array( 'id' => '5.2' ), array( 'id' => '5.3' ) ) ),
					array( 'id' => 6, 'type' => 'consent', 'label' => 'I agree', 'inputs' => array( array( 'id' => '6.1' ) ) ),
					array( 'id' => 7, 'type' => 'hidden', 'label' => 'UTM' ),
					array( 'id' => 8, 'type' => 'section', 'label' => 'About you' ),
					array( 'id' => 9, 'type' => 'multiselect', 'label' => 'Pages' ),
				),
			)
		);
		return GFAPI::get_form( $id );
	}

	/**
	 * A GF entry for the form.
	 *
	 * @param array $form  Form.
	 * @param array $extra Extra values.
	 * @return array
	 */
	private function gf_entry( array $form, array $extra = array() ) {
		$id = GFAPI::add_entry(
			$extra + array(
				'form_id' => $form['id'],
				'1.3'     => 'Maria',
				'1.6'     => 'Lopez',
				'2'       => 'maria@lopez-design.es',
				'3'       => '+34 600 000 000',
				'4'       => 'We need a quote for a shop redesign, budget 8k, deadline end of month.',
				'5.1'     => 'Design',
				'5.3'     => 'SEO',
				'6.1'     => '1',
				'7'       => 'utm=spring',
				'9'       => '["Home","Shop"]',
			)
		);
		return GFAPI::get_entry( $id );
	}

	public function test_registry_lists_all_adapters() {
		$ids = wp_list_pluck( Registry::summary(), 'id' );
		$this->assertSame( array( 'gravityforms', 'wpforms', 'cf7' ), $ids );
		$this->assertTrue( Registry::get( 'gravityforms' )->is_active() );
	}

	public function test_gravity_forms_submission_is_captured_with_compound_fields() {
		$form  = $this->gf_form();
		$entry = $this->gf_entry( $form );
		do_action( 'gform_after_submission', $entry, $form );
		$rows = Entries::query()['items'];
		$this->assertCount( 1, $rows );
		$e = $rows[0];
		$this->assertSame( 'gravityforms', $e['source'] );
		$this->assertSame( (string) $entry['id'], $e['source_ref'] );
		$this->assertSame( 'Request a quote', $e['form_title'] );
		$this->assertSame( 'Maria Lopez', $e['name'] );
		$this->assertSame( 'maria@lopez-design.es', $e['email'] );
		$by_label = wp_list_pluck( $e['fields'], 'value', 'label' );
		$this->assertSame( 'Design, SEO', $by_label['Services'] );
		$this->assertSame( 'Home, Shop', $by_label['Pages'] );
		$this->assertArrayHasKey( 'Details', $by_label, 'Admin label wins' );
		$this->assertArrayNotHasKey( 'About you', $by_label, 'Sections are not stored' );
		$this->assertSame( 'consent', wp_list_pluck( $e['fields'], 'type', 'label' )['I agree'] );
		$this->assertSame( 'https://example.org/quote/', $e['page_url'] );
	}

	public function test_gravity_forms_spam_is_skipped_and_delete_syncs() {
		$form = $this->gf_form();
		do_action( 'gform_after_submission', $this->gf_entry( $form, array( 'status' => 'spam' ) ), $form );
		$this->assertSame( 0, Entries::query()['total'] );
		$entry = $this->gf_entry( $form );
		do_action( 'gform_after_submission', $entry, $form );
		$this->assertSame( 1, Entries::query()['total'] );
		do_action( 'gform_delete_entry', $entry['id'] );
		$this->assertSame( 0, Entries::query()['total'] );
	}

	public function test_gravity_forms_import_job_meta_box_and_note() {
		$form = $this->gf_form();
		$this->gf_entry( $form );
		$this->gf_entry( $form, array( '4' => 'Second request, please call me.' ) );
		$this->gf_entry( $form, array( 'status' => 'trash' ) );
		$this->admin();
		$res = $this->rest( 'POST', 'forms/import', array( 'key' => 'gravityforms:' . $form['id'], 'limit' => 50 ) );
		$this->assertSame( 201, $res->get_status() );
		$job = Jobs::run( $res->get_data()['job']['id'] );
		$this->assertSame( 2, $job['done'] );
		$items = Entries::query( array( 'view' => 'any' ) )['items'];
		$this->assertCount( 2, $items );
		$this->assertSame( 'read', $items[0]['status'], 'Imported entries are not "new"' );
		$this->assertTrue( $items[0]['alerted'], 'Imports never send alerts' );
		$again = $this->rest( 'POST', 'forms/import', array( 'key' => 'gravityforms:' . $form['id'] ) );
		$this->assertNull( $again->get_data()['job'], 'Already imported' );

		$adapter = Registry::get( 'gravityforms' );
		$html    = Gravity_Forms_Adapter::meta_box_html( $items[0] );
		$this->assertStringContainsString( 'Lead score', $html );
		$this->assertStringContainsString( '#/entries/' . $items[0]['id'], $html );
		$this->assertStringContainsString( 'Import it', Gravity_Forms_Adapter::meta_box_html( null ) );
		$boxes = $adapter->meta_boxes( array(), array(), $form );
		$this->assertArrayHasKey( 'dev3ei_insights', $boxes );

		$note = $this->rest( 'POST', 'entries/' . $items[0]['id'] . '/gf-note' );
		$this->assertSame( 200, $note->get_status() );
		$this->assertCount( 1, GFAPI::$notes );
		$this->assertSame( (int) $items[0]['source_ref'], GFAPI::$notes[0]['entry_id'] );
		$this->assertStringContainsString( 'Lead score', GFAPI::$notes[0]['note'] );
		$this->assertStringContainsString( 'lid=' . $items[0]['source_ref'], $adapter->entry_url( $items[0] ) );
	}

	public function test_wpforms_lite_submission_is_captured() {
		$adapter = new WPForms_Adapter();
		if ( ! $adapter->is_active() ) {
			$this->markTestSkipped( 'WPForms Lite is not installed.' );
		}
		$form_id   = self::factory()->post->create(
			array(
				'post_type'    => 'wpforms',
				'post_title'   => 'Simple Contact Form',
				'post_content' => wp_json_encode( array( 'id' => 0, 'settings' => array( 'form_title' => 'Simple Contact Form' ), 'fields' => array() ) ),
			)
		);
		$form_data = array(
			'id'       => $form_id,
			'settings' => array( 'form_title' => 'Simple Contact Form' ),
		);
		$fields    = array(
			0 => array( 'name' => 'Name', 'value' => 'Tom Jones', 'id' => 0, 'type' => 'name', 'first' => 'Tom', 'last' => 'Jones' ),
			1 => array( 'name' => 'Email', 'value' => 'tom@jones.co.uk', 'id' => 1, 'type' => 'email' ),
			2 => array( 'name' => 'Comment or Message', 'value' => "Our booking site is down today!\nPlease help.", 'id' => 2, 'type' => 'textarea' ),
			3 => array( 'name' => 'Topics', 'value' => "Hosting\nSupport", 'id' => 3, 'type' => 'checkbox' ),
			4 => array( 'name' => 'Tracking', 'value' => 'abc', 'id' => 4, 'type' => 'hidden' ),
			5 => array( 'name' => 'Card', 'value' => '4242', 'id' => 5, 'type' => 'stripe-credit-card' ),
		);
		do_action( 'wpforms_process_complete', $fields, array( 'id' => $form_id ), $form_data, 0 );
		$e = Entries::query()['items'][0];
		$this->assertSame( 'wpforms', $e['source'] );
		$this->assertSame( $form_id, $e['form_id'] );
		$this->assertSame( '', $e['source_ref'], 'Lite has no entry id' );
		$this->assertSame( 'Tom Jones', $e['name'] );
		$this->assertSame( 'high', $e['urgency'] );
		$by_label = wp_list_pluck( $e['fields'], 'value', 'label' );
		$this->assertSame( 'Hosting, Support', $by_label['Topics'] );
		$this->assertArrayNotHasKey( 'Card', $by_label, 'Card fields are never stored' );
		$this->assertStringContainsString( 'form_id=' . $form_id, $adapter->entry_url( $e ) );
		$this->assertContains( $form_id, wp_list_pluck( $adapter->list_forms(), 'id' ) );
		$this->assertFalse( $adapter->can_import(), 'Lite stores no entries' );

		do_action( 'wpforms_process_complete', $fields, array(), $form_data + array( 'spam_reason' => 'Akismet' ), 0 );
		$this->assertSame( 1, Entries::query()['total'], 'Spam is skipped' );
	}

	public function test_contact_form_7_submission_is_captured_with_template_labels() {
		if ( ! class_exists( 'WPCF7_ContactForm' ) ) {
			$this->markTestSkipped( 'Contact Form 7 is not installed.' );
		}
		$form = WPCF7_ContactForm::get_template( array( 'title' => 'Contact form 1' ) );
		$form->set_properties( array( 'form' => "<label> Your name\n [text* your-name] </label>\n<label> Your email\n [email* your-email] </label>\nWhat do you need?\n[textarea your-message]\n[tel your-phone]\n[acceptance terms]I agree[/acceptance]\n[submit \"Send\"]" ) );
		$form->save();
		$adapter = new CF7_Adapter();
		$this->assertTrue( $adapter->is_active() );
		$id = $adapter->capture_posted(
			$form,
			array(
				'_wpcf7'       => (string) $form->id(),
				'your-name'    => 'Li Wei',
				'your-email'   => 'li@wei.cn',
				'your-message' => 'I am disappointed, my order is still waiting. I want a refund.',
				'your-phone'   => '123',
				'terms'        => '1',
			),
			'https://example.org/contact/'
		);
		$e  = Entries::get( $id );
		$this->assertSame( 'cf7', $e['source'] );
		$this->assertSame( 'Contact form 1', $e['form_title'] );
		$this->assertSame( 'Li Wei', $e['name'] );
		$this->assertSame( 'negative', $e['sentiment'] );
		$this->assertSame( 'complaint', $e['category'] );
		$labels = wp_list_pluck( $e['fields'], 'label', 'key' );
		$this->assertSame( 'Your name', $labels['your-name'] );
		$this->assertSame( 'What do you need?', $labels['your-message'] );
		$types = wp_list_pluck( $e['fields'], 'type', 'key' );
		$this->assertSame( 'phone', $types['your-phone'] );
		$this->assertSame( 'consent', $types['terms'] );
		$this->assertArrayNotHasKey( '_wpcf7', $labels );
		$this->assertContains( 'needs-care', $e['tags'], 'Default tag rule: complaints' );
		$this->assertContains( (int) $form->id(), wp_list_pluck( $adapter->list_forms(), 'id' ) );
	}

	public function test_labels_from_template() {
		$labels = CF7_Adapter::labels_from_template( "<label>Company *\n[text company]</label>\nBudget:\n[select budget \"1k\" \"5k\"]" );
		$this->assertSame( array( 'company' => 'Company', 'budget' => 'Budget' ), $labels );
	}
}
