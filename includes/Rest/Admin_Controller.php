<?php
/**
 * Admin REST routes (dev3ei/v1). Every route: strict nonce + capability.
 *
 * @package Dev3\AiEntryInsights
 */

namespace Dev3\AiEntryInsights\Rest;

use Dev3\AiEntryInsights\Adapters\Gravity_Forms_Adapter;
use Dev3\AiEntryInsights\Adapters\Registry;
use Dev3\AiEntryInsights\Core3\Core\Jobs;
use Dev3\AiEntryInsights\Core3\Core\Settings_Store;
use Dev3\AiEntryInsights\Core3\Rest\Base_Controller;
use Dev3\AiEntryInsights\Data\Entries;
use Dev3\AiEntryInsights\Insights\Analyzer;
use Dev3\AiEntryInsights\Insights\Digest;
use Dev3\AiEntryInsights\Insights\Rules;
use Dev3\AiEntryInsights\Insights\Stats;
use Dev3\AiEntryInsights\Insights\Tagger;
use Dev3\AiEntryInsights\Insights\Text;
use Dev3\AiEntryInsights\Reply\Replies;
use Dev3\AiEntryInsights\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Routes.
 */
class Admin_Controller extends Base_Controller {

	const BULK_ACTIONS = array( 'read', 'unread', 'archive', 'unarchive', 'star', 'unstar', 'delete', 'analyze', 'rescore', 'tag', 'untag' );

	/**
	 * Registers routes.
	 */
	public function register_routes() {
		$manage   = array( $this, 'can_manage' );
		$generate = array( $this, 'can_manage_ai' );
		$id       = '/entries/(?P<id>\d+)';
		$this->route( '/entries', 'GET', 'list_entries', $manage );
		$this->route( '/entries/bulk', 'POST', 'bulk', $manage );
		$this->route( $id, 'GET', 'get_entry', $manage );
		$this->route( $id, 'POST', 'update_entry', $manage );
		$this->route( $id, 'DELETE', 'delete_entry', $manage );
		$this->route( $id . '/analyze', 'POST', 'analyze', $generate );
		$this->route( $id . '/tags/accept', 'POST', 'accept_tags', $manage );
		$this->route( $id . '/reply-draft', 'POST', 'reply_draft', $generate );
		$this->route( $id . '/reply', 'POST', 'reply', $manage );
		$this->route( $id . '/template', 'POST', 'template', $manage );
		$this->route( $id . '/gf-note', 'POST', 'gf_note', $manage );
		$this->route( '/stats', 'GET', 'stats', $manage );
		$this->route( '/digest', 'GET', 'get_digest', $manage );
		$this->route( '/digest', 'POST', 'digest', $generate );
		$this->route( '/digest', 'DELETE', 'unpin_digest', $manage );
		$this->route( '/digest/pin', 'POST', 'pin_digest', $manage );
		$this->route( '/forms', 'GET', 'forms', $manage );
		$this->route( '/forms/config', 'POST', 'form_config', $manage );
		$this->route( '/forms/import', 'POST', 'import', $manage );
		$this->route( '/analyze-all', 'POST', 'analyze_all', $manage );
		$this->route( '/rules/preview', 'POST', 'preview', $manage );
	}

	/**
	 * AI routes read entries, so they need both the manage and the generate capability.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return bool|\WP_Error
	 */
	public function can_manage_ai( \WP_REST_Request $request ) {
		$ok = $this->can_manage( $request );
		return true === $ok ? $this->can_generate( $request ) : $ok;
	}

	/**
	 * Route helper.
	 *
	 * @param string   $path       Path.
	 * @param string   $method     Method.
	 * @param string   $callback   Method name.
	 * @param callable $permission Permission.
	 */
	private function route( $path, $method, $callback, $permission ) {
		register_rest_route(
			self::NS,
			$path,
			array(
				'methods'             => $method,
				'callback'            => array( $this, $callback ),
				'permission_callback' => $permission,
			)
		);
	}

	/**
	 * Entry for JSON.
	 *
	 * @param array $entry Entry.
	 * @param bool  $full  Include answers + history.
	 * @return array
	 */
	public static function shape( array $entry, $full = false ) {
		$excerpt = '';
		foreach ( $entry['fields'] as $f ) {
			if ( in_array( $f['type'], array( 'textarea', 'text' ), true ) && mb_strlen( $f['value'] ) > mb_strlen( $excerpt ) && ! is_email( $f['value'] ) && $f['value'] !== $entry['name'] ) {
				$excerpt = $f['value'];
			}
		}
		$out = array(
			'id'             => $entry['id'],
			'source'         => $entry['source'],
			'form_id'        => $entry['form_id'],
			'form_key'       => Settings::form_key( $entry['source'], $entry['form_id'] ),
			'form_title'     => $entry['form_title'],
			'name'           => $entry['name'],
			'email'          => $entry['email'],
			'excerpt'        => mb_substr( preg_replace( '/\s+/', ' ', $excerpt ), 0, 200 ),
			'summary'        => $entry['summary'],
			'sentiment'      => $entry['sentiment'],
			'intent'         => $entry['intent'],
			'category'       => $entry['category'],
			'urgency'        => $entry['urgency'],
			'lead_score'     => $entry['lead_score'],
			'rule_score'     => $entry['rule_score'],
			'lead_reason'    => $entry['lead_reason'],
			'language'       => $entry['language'],
			'tags'           => $entry['tags'],
			'suggested_tags' => $entry['suggested_tags'],
			'insight_src'    => $entry['insight_src'],
			'ai_state'       => $entry['ai_state'],
			'ai_error'       => $entry['ai_error'],
			'status'         => $entry['status'],
			'starred'        => $entry['starred'],
			'created_at'     => $entry['created_at'],
			'replied_at'     => $entry['replied_at'],
		);
		if ( $full ) {
			$adapter            = Registry::get( $entry['source'] );
			$out['fields']      = $entry['fields'];
			$out['history']     = array_reverse( $entry['history'] );
			$out['note']        = $entry['note'];
			$out['page_url']    = $entry['page_url'];
			$out['model']       = $entry['model'];
			$out['analyzed_at'] = $entry['analyzed_at'];
			$out['source_ref']  = $entry['source_ref'];
			$out['entry_url']   = $adapter ? $adapter->entry_url( $entry ) : '';
			$out['can_gf_note'] = 'gravityforms' === $entry['source'] && '' !== $entry['source_ref'] && $adapter && $adapter->is_active();
		}
		return $out;
	}

	/**
	 * Loads an entry or a 404.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return array|\WP_Error
	 */
	private function entry( \WP_REST_Request $request ) {
		$entry = Entries::get( (int) $request['id'] );
		return $entry ? $entry : new \WP_Error( 'dev3ei_not_found', __( 'Entry not found.', 'dev3-ai-entry-insights' ), array( 'status' => 404 ) );
	}

	/**
	 * Filters from a request.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return array
	 */
	private function filters( \WP_REST_Request $request ) {
		$out = array();
		foreach ( array( 'view', 'source', 'form', 'sentiment', 'category', 'urgency', 'tag', 'min_score', 'from', 'to', 'search', 'orderby', 'order', 'page', 'per_page' ) as $key ) {
			$value = $request->get_param( $key );
			if ( null !== $value && '' !== $value ) {
				$out[ $key ] = is_scalar( $value ) ? sanitize_text_field( (string) $value ) : '';
			}
		}
		return $out;
	}

	/**
	 * GET entries.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function list_entries( \WP_REST_Request $request ) {
		Analyzer::catch_up();
		$filters = $this->filters( $request );
		$result  = Entries::query( $filters );
		$base    = array_intersect_key( $filters, array_flip( array( 'source', 'form' ) ) );
		return $this->respond(
			array(
				'items'   => array_map( array( __CLASS__, 'shape' ), $result['items'] ),
				'total'   => $result['total'],
				'counts'  => Entries::counts( $base ),
				'pending' => Stats::pending_count(),
			)
		);
	}

	/**
	 * GET entries/<id> (marks new entries read).
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function get_entry( \WP_REST_Request $request ) {
		$entry = $this->entry( $request );
		if ( is_wp_error( $entry ) ) {
			return $this->respond( $entry );
		}
		if ( 'new' === $entry['status'] && false !== $request->get_param( 'mark_read' ) && 'false' !== $request->get_param( 'mark_read' ) ) {
			Entries::update( $entry['id'], array( 'status' => 'read' ) );
			$entry['status'] = 'read';
		}
		return $this->respond( self::shape( $entry, true ) );
	}

	/**
	 * POST entries/<id>: status, starred, tags, note.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function update_entry( \WP_REST_Request $request ) {
		$entry = $this->entry( $request );
		if ( is_wp_error( $entry ) ) {
			return $this->respond( $entry );
		}
		$cols = array();
		if ( null !== $request->get_param( 'status' ) ) {
			$status = sanitize_key( (string) $request->get_param( 'status' ) );
			if ( ! in_array( $status, Entries::STATUSES, true ) ) {
				return $this->respond( new \WP_Error( 'dev3ei_bad_status', __( 'Unknown status.', 'dev3-ai-entry-insights' ), array( 'status' => 400 ) ) );
			}
			$cols['status'] = $status;
		}
		if ( null !== $request->get_param( 'starred' ) ) {
			$cols['starred'] = rest_sanitize_boolean( $request->get_param( 'starred' ) ) ? 1 : 0;
		}
		if ( is_array( $request->get_param( 'tags' ) ) ) {
			$cols['tags']           = Tagger::merge( $request->get_param( 'tags' ) );
			$cols['suggested_tags'] = array_values( array_diff( $entry['suggested_tags'], $cols['tags'] ) );
		}
		if ( null !== $request->get_param( 'note' ) ) {
			$cols['note'] = mb_substr( sanitize_textarea_field( (string) $request->get_param( 'note' ) ), 0, 5000 );
		}
		if ( $cols ) {
			Entries::update( $entry['id'], $cols );
			if ( isset( $cols['status'] ) && $cols['status'] !== $entry['status'] ) {
				Entries::log( $entry['id'], 'status', $cols['status'] );
			}
			if ( isset( $cols['note'] ) && $cols['note'] !== $entry['note'] ) {
				Entries::log( $entry['id'], 'note', __( 'Internal note updated.', 'dev3-ai-entry-insights' ) );
			}
		}
		return $this->respond( self::shape( Entries::get( $entry['id'] ), true ) );
	}

	/**
	 * DELETE entries/<id> (our copy only; the form plugin's entry is untouched).
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function delete_entry( \WP_REST_Request $request ) {
		$entry = $this->entry( $request );
		if ( is_wp_error( $entry ) ) {
			return $this->respond( $entry );
		}
		Entries::delete( $entry['id'] );
		return $this->respond( array( 'deleted' => true ) );
	}

	/**
	 * POST entries/<id>/analyze (AI).
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function analyze( \WP_REST_Request $request ) {
		$entry = $this->entry( $request );
		if ( is_wp_error( $entry ) ) {
			return $this->respond( $entry );
		}
		$result = Analyzer::analyze( $entry['id'], true, 'admin' );
		return $this->respond( is_wp_error( $result ) ? $result : self::shape( $result, true ) );
	}

	/**
	 * POST entries/<id>/tags/accept { tags }: moves suggested tags into tags.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function accept_tags( \WP_REST_Request $request ) {
		$entry = $this->entry( $request );
		if ( is_wp_error( $entry ) ) {
			return $this->respond( $entry );
		}
		$accept = $request->get_param( 'tags' );
		$accept = is_array( $accept ) ? Tagger::merge( $accept ) : $entry['suggested_tags'];
		$reject = rest_sanitize_boolean( $request->get_param( 'reject' ) );
		$tags   = $reject ? $entry['tags'] : Tagger::merge( $entry['tags'], array_intersect( $accept, $entry['suggested_tags'] ) );
		Entries::update(
			$entry['id'],
			array(
				'tags'           => $tags,
				'suggested_tags' => array_values( array_diff( $entry['suggested_tags'], $accept ) ),
			)
		);
		Entries::log( $entry['id'], $reject ? 'tags_rejected' : 'tags_accepted', implode( ', ', $accept ) );
		return $this->respond( self::shape( Entries::get( $entry['id'] ), true ) );
	}

	/**
	 * POST entries/<id>/reply-draft (AI, nothing is sent).
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function reply_draft( \WP_REST_Request $request ) {
		$entry = $this->entry( $request );
		if ( is_wp_error( $entry ) ) {
			return $this->respond( $entry );
		}
		return $this->respond( Replies::draft( $entry, sanitize_key( (string) $request->get_param( 'tone' ) ), sanitize_textarea_field( (string) $request->get_param( 'notes' ) ) ) );
	}

	/**
	 * POST entries/<id>/reply { subject, body }: sends the approved reply.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function reply( \WP_REST_Request $request ) {
		$entry = $this->entry( $request );
		if ( is_wp_error( $entry ) ) {
			return $this->respond( $entry );
		}
		$sent = Replies::send( $entry, (string) $request->get_param( 'subject' ), (string) $request->get_param( 'body' ) );
		return $this->respond( is_wp_error( $sent ) ? $sent : self::shape( Entries::get( $entry['id'] ), true ) );
	}

	/**
	 * POST entries/<id>/template { template }: fills a canned reply.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function template( \WP_REST_Request $request ) {
		$entry = $this->entry( $request );
		if ( is_wp_error( $entry ) ) {
			return $this->respond( $entry );
		}
		return $this->respond( Replies::from_template( $entry, sanitize_key( (string) $request->get_param( 'template' ) ) ) );
	}

	/**
	 * POST entries/<id>/gf-note.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function gf_note( \WP_REST_Request $request ) {
		$entry = $this->entry( $request );
		if ( is_wp_error( $entry ) ) {
			return $this->respond( $entry );
		}
		$adapter = Registry::get( 'gravityforms' );
		if ( ! $adapter instanceof Gravity_Forms_Adapter || 'gravityforms' !== $entry['source'] ) {
			return $this->respond( new \WP_Error( 'dev3ei_no_note', __( 'This entry is not linked to a Gravity Forms entry.', 'dev3-ai-entry-insights' ), array( 'status' => 400 ) ) );
		}
		$result = $adapter->add_note( $entry );
		return $this->respond( is_wp_error( $result ) ? $result : array( 'ok' => true ) );
	}

	/**
	 * POST entries/bulk { ids, action, tag }.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function bulk( \WP_REST_Request $request ) {
		$action = sanitize_key( (string) $request->get_param( 'action' ) );
		$ids    = array_slice( array_filter( array_map( 'intval', (array) $request->get_param( 'ids' ) ) ), 0, 500 );
		if ( ! in_array( $action, self::BULK_ACTIONS, true ) || ! $ids ) {
			return $this->respond( new \WP_Error( 'dev3ei_bad_bulk', __( 'Pick entries and an action.', 'dev3-ai-entry-insights' ), array( 'status' => 400 ) ) );
		}
		if ( 'analyze' === $action || 'rescore' === $action ) {
			if ( 'analyze' === $action && ! \Dev3\AiEntryInsights\Core3\Core\Capabilities::can_generate() ) {
				return $this->respond( new \WP_Error( 'dev3ei_forbidden', __( 'You do not have permission to do that.', 'dev3-ai-entry-insights' ), array( 'status' => 403 ) ) );
			}
			$job = Jobs::create( Analyzer::JOB, $ids, array( 'ai' => 'analyze' === $action ), 5 );
			return $this->respond( is_wp_error( $job ) ? $job : array( 'job' => Jobs::get( $job ) ), 201 );
		}
		$tag  = Settings::tag( (string) $request->get_param( 'tag' ) );
		$done = 0;
		foreach ( $ids as $id ) {
			$entry = Entries::get( $id );
			if ( ! $entry ) {
				continue;
			}
			switch ( $action ) {
				case 'delete':
					Entries::delete( $id );
					break;
				case 'read':
				case 'unread':
				case 'archive':
				case 'unarchive':
					$map = array(
						'read'      => 'read',
						'unread'    => 'new',
						'archive'   => 'archived',
						'unarchive' => 'read',
					);
					Entries::update( $id, array( 'status' => $map[ $action ] ) );
					break;
				case 'star':
				case 'unstar':
					Entries::update( $id, array( 'starred' => 'star' === $action ? 1 : 0 ) );
					break;
				case 'tag':
				case 'untag':
					if ( '' === $tag ) {
						return $this->respond( new \WP_Error( 'dev3ei_no_tag', __( 'Enter a tag.', 'dev3-ai-entry-insights' ), array( 'status' => 400 ) ) );
					}
					Entries::update( $id, array( 'tags' => 'tag' === $action ? Tagger::merge( $entry['tags'], array( $tag ) ) : array_values( array_diff( $entry['tags'], array( $tag ) ) ) ) );
					break;
			}
			++$done;
		}
		return $this->respond( array( 'done' => $done ) );
	}

	/**
	 * GET stats.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function stats( \WP_REST_Request $request ) {
		Analyzer::catch_up();
		$stats           = Stats::get( (int) ( $request->get_param( 'days' ) ? $request->get_param( 'days' ) : 30 ), sanitize_key( (string) $request->get_param( 'source' ) ), (string) $request->get_param( 'form' ) );
		$stats['digest'] = Digest::pinned();
		return $this->respond( $stats );
	}

	/**
	 * GET digest (pinned).
	 *
	 * @return \WP_REST_Response
	 */
	public function get_digest() {
		return $this->respond( array( 'pinned' => Digest::pinned() ) );
	}

	/**
	 * POST digest { days, form } (AI draft).
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function digest( \WP_REST_Request $request ) {
		return $this->respond( Digest::generate( (int) ( $request->get_param( 'days' ) ? $request->get_param( 'days' ) : 30 ), (string) $request->get_param( 'form' ) ) );
	}

	/**
	 * POST digest/pin { digest, days, form }.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function pin_digest( \WP_REST_Request $request ) {
		$digest = $request->get_param( 'digest' );
		if ( ! is_array( $digest ) || empty( $digest['summary'] ) ) {
			return $this->respond( new \WP_Error( 'dev3ei_bad_digest', __( 'Nothing to pin.', 'dev3-ai-entry-insights' ), array( 'status' => 400 ) ) );
		}
		return $this->respond( array( 'pinned' => Digest::pin( $digest, (int) $request->get_param( 'days' ), (string) $request->get_param( 'form' ) ) ) );
	}

	/**
	 * DELETE digest.
	 *
	 * @return \WP_REST_Response
	 */
	public function unpin_digest() {
		Digest::unpin();
		return $this->respond( array( 'pinned' => null ) );
	}

	/**
	 * GET forms: adapters + forms with counts and per-form switches.
	 *
	 * @return \WP_REST_Response
	 */
	public function forms() {
		$counts = Entries::per_form();
		$forms  = array();
		$seen   = array();
		foreach ( Registry::all() as $adapter ) {
			if ( ! $adapter->is_active() ) {
				continue;
			}
			foreach ( $adapter->list_forms() as $form ) {
				$key          = Settings::form_key( $adapter->id(), $form['id'] );
				$seen[ $key ] = true;
				$forms[]      = $this->form_row( $key, $adapter->id(), $form['id'], $form['title'], $form['edit_url'], isset( $counts[ $key ] ) ? $counts[ $key ] : array(), true );
			}
		}
		// Forms that were deleted or whose plugin is inactive but still have entries.
		foreach ( $counts as $key => $count ) {
			if ( ! isset( $seen[ $key ] ) ) {
				list( $source, $form_id ) = explode( ':', $key );
				$forms[]                  = $this->form_row( $key, $source, (int) $form_id, $count['title'], '', $count, false );
			}
		}
		return $this->respond(
			array(
				'adapters' => Registry::summary(),
				'forms'    => $forms,
				'tags'     => array_keys( Entries::tag_counts( 100 ) ),
			)
		);
	}

	/**
	 * One forms row.
	 *
	 * @param string $key      Key.
	 * @param string $source   Source.
	 * @param int    $form_id  Id.
	 * @param string $title    Title.
	 * @param string $edit_url Edit URL.
	 * @param array  $count    Counts.
	 * @param bool   $live     Form exists.
	 * @return array
	 */
	private function form_row( $key, $source, $form_id, $title, $edit_url, array $count, $live ) {
		$config = Settings::form_config( $source, $form_id );
		return array(
			'key'       => $key,
			'source'    => $source,
			'id'        => (int) $form_id,
			'title'     => '' !== (string) $title ? (string) $title : '#' . (int) $form_id,
			'edit_url'  => $edit_url,
			'live'      => $live,
			'capture'   => $config['capture'],
			'analyze'   => $config['analyze'],
			'total'     => isset( $count['total'] ) ? $count['total'] : 0,
			'unread'    => isset( $count['unread'] ) ? $count['unread'] : 0,
			'avg_score' => isset( $count['avg_score'] ) ? $count['avg_score'] : 0,
			'hot'       => isset( $count['hot'] ) ? $count['hot'] : 0,
			'pending'   => isset( $count['pending'] ) ? $count['pending'] : 0,
			'last_at'   => isset( $count['last_at'] ) ? $count['last_at'] : '',
		);
	}

	/**
	 * POST forms/config { key, capture, analyze }.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function form_config( \WP_REST_Request $request ) {
		$key = Settings::form_key_clean( (string) $request->get_param( 'key' ) );
		if ( '' === $key ) {
			return $this->respond( new \WP_Error( 'dev3ei_bad_form', __( 'Unknown form.', 'dev3-ai-entry-insights' ), array( 'status' => 400 ) ) );
		}
		list( $source, $form_id ) = explode( ':', $key );
		$forms                    = (array) Settings::get( 'forms', array() );
		$current                  = Settings::form_config( $source, $form_id );
		foreach ( array( 'capture', 'analyze' ) as $flag ) {
			if ( null !== $request->get_param( $flag ) ) {
				$current[ $flag ] = rest_sanitize_boolean( $request->get_param( $flag ) );
			}
		}
		$forms[ $key ] = $current;
		Settings_Store::update( array( 'forms' => $forms ) );
		return $this->respond( array( 'key' => $key ) + Settings::form_config( $source, $form_id ) );
	}

	/**
	 * POST forms/import { key, limit, analyze } → job.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function import( \WP_REST_Request $request ) {
		$key = Settings::form_key_clean( (string) $request->get_param( 'key' ) );
		if ( '' === $key ) {
			return $this->respond( new \WP_Error( 'dev3ei_bad_form', __( 'Unknown form.', 'dev3-ai-entry-insights' ), array( 'status' => 400 ) ) );
		}
		list( $source, $form_id ) = explode( ':', $key );
		$adapter                  = Registry::get( $source );
		if ( ! $adapter || ! $adapter->is_active() || ! $adapter->can_import() ) {
			return $this->respond( new \WP_Error( 'dev3ei_no_import', $adapter ? $adapter->import_note() : __( 'Unknown form plugin.', 'dev3-ai-entry-insights' ), array( 'status' => 400 ) ) );
		}
		$limit = min( 2000, max( 1, (int) ( $request->get_param( 'limit' ) ? $request->get_param( 'limit' ) : 200 ) ) );
		$refs  = $adapter->import_refs( (int) $form_id, $limit );
		$todo  = array();
		foreach ( $refs as $ref ) {
			if ( ! Entries::find_ref( $source, $ref ) ) {
				$todo[] = (int) $form_id . '|' . $ref;
			}
		}
		if ( ! $todo ) {
			return $this->respond(
				array(
					'job'     => null,
					'message' => __( 'Everything is imported already.', 'dev3-ai-entry-insights' ),
				)
			);
		}
		$analyze = rest_sanitize_boolean( $request->get_param( 'analyze' ) ) && \Dev3\AiEntryInsights\Core3\Core\Capabilities::can_generate();
		$job     = Jobs::create( Registry::IMPORT_JOB, $todo, array( 'source' => $source, 'analyze' => $analyze ), 20 ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		return $this->respond( is_wp_error( $job ) ? $job : array( 'job' => Jobs::get( $job ) ), 201 );
	}

	/**
	 * POST analyze-all { scope: pending|unanalyzed|all, form, ai } → job.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function analyze_all( \WP_REST_Request $request ) {
		$scope = sanitize_key( (string) $request->get_param( 'scope' ) );
		$ai    = false !== $request->get_param( 'ai' ) && 'false' !== $request->get_param( 'ai' );
		if ( $ai && ! \Dev3\AiEntryInsights\Core3\Core\Capabilities::can_generate() ) {
			return $this->respond( new \WP_Error( 'dev3ei_forbidden', __( 'You do not have permission to do that.', 'dev3-ai-entry-insights' ), array( 'status' => 403 ) ) );
		}
		$filters = array( 'view' => 'any' );
		$form    = Settings::form_key_clean( (string) $request->get_param( 'form' ) );
		if ( '' !== $form ) {
			$filters['form'] = $form;
		}
		$ids = Entries::ids( $filters, 2000 );
		if ( 'all' !== $scope ) {
			$ids = array_values(
				array_filter(
					$ids,
					static function ( $id ) {
						$e = Entries::get( $id );
						return $e && 'done' !== $e['ai_state'];
					}
				)
			);
		}
		if ( ! $ids ) {
			return $this->respond(
				array(
					'job'     => null,
					'message' => __( 'No entries to analyse.', 'dev3-ai-entry-insights' ),
				)
			);
		}
		$job = Jobs::create( Analyzer::JOB, array_reverse( $ids ), array( 'ai' => $ai ), 5 );
		return $this->respond( is_wp_error( $job ) ? $job : array( 'job' => Jobs::get( $job ) ), 201 );
	}

	/**
	 * POST rules/preview { text, email, phone }: rule result for a sample (nothing saved).
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function preview( \WP_REST_Request $request ) {
		$fields = array_values(
			array_filter(
				array(
					Text::field( 'email', __( 'Email', 'dev3-ai-entry-insights' ), 'email', (string) $request->get_param( 'email' ) ),
					Text::field( 'phone', __( 'Phone', 'dev3-ai-entry-insights' ), 'phone', (string) $request->get_param( 'phone' ) ),
					Text::field( 'company', __( 'Company', 'dev3-ai-entry-insights' ), 'text', (string) $request->get_param( 'company' ) ),
					Text::field( 'message', __( 'Message', 'dev3-ai-entry-insights' ), 'textarea', mb_substr( (string) $request->get_param( 'text' ), 0, 5000 ) ),
				)
			)
		);
		if ( ! $fields ) {
			return $this->respond( new \WP_Error( 'dev3ei_empty', __( 'Type a sample message.', 'dev3-ai-entry-insights' ), array( 'status' => 400 ) ) );
		}
		$rules         = Rules::analyze( $fields );
		$rules['tags'] = Tagger::tags_for(
			$rules + array(
				'fields'  => $fields,
				'source'  => '',
				'form_id' => 0,
			)
		);
		return $this->respond( $rules );
	}
}
