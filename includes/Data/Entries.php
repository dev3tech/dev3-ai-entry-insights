<?php
/**
 * Entries repository: captured submissions from every adapter, with their insights.
 *
 * @package Dev3\AiEntryInsights
 */

namespace Dev3\AiEntryInsights\Data;

use Dev3\AiEntryInsights\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * CRUD + queries.
 */
class Entries {

	const STATUSES = array( 'new', 'read', 'replied', 'archived' );

	const VIEWS = array( 'all', 'new', 'hot', 'attention', 'starred', 'replied', 'archived' );

	const JSON = array( 'fields', 'tags', 'suggested_tags', 'rule_tags', 'history' );

	const MAX_HISTORY = 30;

	/**
	 * Current UTC time in MySQL format.
	 *
	 * @return string
	 */
	public static function now() {
		return gmdate( 'Y-m-d H:i:s' );
	}

	/**
	 * Inserts an entry.
	 *
	 * @param array $data Columns.
	 * @return int Id (0 on failure).
	 */
	public static function insert( array $data ) {
		global $wpdb;
		$now  = self::now();
		$data = self::encode( $data ) + array(
			'created_at' => $now,
			'updated_at' => $now,
		);
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$ok = $wpdb->insert( Tables::name(), $data );
		return $ok ? (int) $wpdb->insert_id : 0;
	}

	/**
	 * Updates columns.
	 *
	 * @param int   $id   Id.
	 * @param array $data Columns.
	 * @return bool
	 */
	public static function update( $id, array $data ) {
		global $wpdb;
		$data['updated_at'] = self::now();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return false !== $wpdb->update( Tables::name(), self::encode( $data ), array( 'id' => (int) $id ) );
	}

	/**
	 * Deletes an entry.
	 *
	 * @param int $id Id.
	 * @return bool
	 */
	public static function delete( $id ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (bool) $wpdb->delete( Tables::name(), array( 'id' => (int) $id ) );
	}

	/**
	 * One entry.
	 *
	 * @param int $id Id.
	 * @return array|null
	 */
	public static function get( $id ) {
		global $wpdb;
		$table = Tables::name();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", (int) $id ), ARRAY_A );
		return $row ? self::hydrate( $row ) : null;
	}

	/**
	 * Entry captured from a source reference (GF / WPForms Pro entry id).
	 *
	 * @param string $source Source.
	 * @param string $ref    Reference.
	 * @return array|null
	 */
	public static function find_ref( $source, $ref ) {
		if ( '' === (string) $ref ) {
			return null;
		}
		global $wpdb;
		$table = Tables::name();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE source = %s AND source_ref = %s LIMIT 1", (string) $source, (string) $ref ), ARRAY_A );
		return $row ? self::hydrate( $row ) : null;
	}

	/**
	 * Appends a history row (keeps the last 30).
	 *
	 * @param int    $id     Id.
	 * @param string $event  Event code.
	 * @param string $detail Detail.
	 * @param string $by     rules|ai|system|user:<id>.
	 */
	public static function log( $id, $event, $detail = '', $by = '' ) {
		$entry = self::get( $id );
		if ( ! $entry ) {
			return;
		}
		$history   = (array) $entry['history'];
		$history[] = array(
			'event'  => sanitize_key( $event ),
			'detail' => mb_substr( (string) $detail, 0, 500 ),
			'by'     => '' !== $by ? $by : ( get_current_user_id() ? 'user:' . get_current_user_id() : 'system' ),
			'at'     => gmdate( 'c' ),
		);
		self::update( $id, array( 'history' => array_slice( $history, -self::MAX_HISTORY ) ) );
	}

	/**
	 * Encodes JSON columns and the tag index.
	 *
	 * @param array $data Columns.
	 * @return array
	 */
	private static function encode( array $data ) {
		if ( array_key_exists( 'tags', $data ) ) {
			$tags               = array_values( array_unique( array_filter( array_map( array( Settings::class, 'tag' ), (array) $data['tags'] ) ) ) );
			$data['tags']       = array_slice( $tags, 0, 20 );
			$data['tags_index'] = $data['tags'] ? ',' . implode( ',', $data['tags'] ) . ',' : '';
		}
		foreach ( self::JSON as $col ) {
			if ( array_key_exists( $col, $data ) && ! is_string( $data[ $col ] ) ) {
				$data[ $col ] = wp_json_encode( $data[ $col ] );
			}
		}
		return $data;
	}

	/**
	 * Decodes a DB row.
	 *
	 * @param array $row Row.
	 * @return array
	 */
	public static function hydrate( array $row ) {
		foreach ( self::JSON as $col ) {
			$decoded     = isset( $row[ $col ] ) ? json_decode( (string) $row[ $col ], true ) : null;
			$row[ $col ] = is_array( $decoded ) ? $decoded : array();
		}
		foreach ( array( 'id', 'form_id', 'lead_score', 'rule_score' ) as $int ) {
			$row[ $int ] = (int) $row[ $int ];
		}
		$row['starred'] = (bool) $row['starred'];
		$row['alerted'] = (bool) $row['alerted'];
		$row['note']    = (string) $row['note'];
		unset( $row['tags_index'] );
		return $row;
	}

	/**
	 * WHERE clauses for a filter set.
	 *
	 * @param array $args Filters.
	 * @return array [ sql, params ]
	 */
	private static function where( array $args ) {
		global $wpdb;
		$where  = array( '1=1' );
		$params = array();
		$view   = isset( $args['view'] ) && ( 'any' === $args['view'] || in_array( $args['view'], self::VIEWS, true ) ) ? $args['view'] : 'all';
		$hot    = (int) Settings::get( 'hot_threshold', 70 );
		switch ( $view ) {
			case 'all':
				$where[] = "status <> 'archived'";
				break;
			case 'new':
				$where[] = "status = 'new'";
				break;
			case 'hot':
				$where[]  = "status <> 'archived' AND lead_score >= %d";
				$params[] = $hot;
				break;
			case 'attention':
				$where[]  = "status IN ('new','read') AND (urgency = 'high' OR sentiment = 'negative' OR lead_score >= %d)";
				$params[] = $hot;
				break;
			case 'starred':
				$where[] = 'starred = 1';
				break;
			case 'replied':
			case 'archived':
				$where[]  = 'status = %s';
				$params[] = $view;
				break;
		}
		if ( ! empty( $args['source'] ) ) {
			$where[]  = 'source = %s';
			$params[] = sanitize_key( $args['source'] );
		}
		if ( ! empty( $args['form'] ) ) {
			$key = Settings::form_key_clean( $args['form'] );
			if ( '' !== $key ) {
				list( $source, $form_id ) = explode( ':', $key );
				$where[]                  = 'source = %s AND form_id = %d';
				$params[]                 = $source;
				$params[]                 = (int) $form_id;
			}
		}
		foreach ( array( 'sentiment', 'category', 'urgency' ) as $col ) {
			if ( ! empty( $args[ $col ] ) ) {
				$where[]  = "{$col} = %s";
				$params[] = sanitize_key( $args[ $col ] );
			}
		}
		if ( ! empty( $args['tag'] ) ) {
			$where[]  = 'tags_index LIKE %s';
			$params[] = '%,' . $wpdb->esc_like( Settings::tag( $args['tag'] ) ) . ',%';
		}
		if ( isset( $args['min_score'] ) && '' !== $args['min_score'] && null !== $args['min_score'] ) {
			$where[]  = 'lead_score >= %d';
			$params[] = min( 100, max( 0, (int) $args['min_score'] ) );
		}
		if ( ! empty( $args['from'] ) && preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $args['from'] ) ) {
			$where[]  = 'created_at >= %s';
			$params[] = $args['from'] . ' 00:00:00';
		}
		if ( ! empty( $args['to'] ) && preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $args['to'] ) ) {
			$where[]  = 'created_at <= %s';
			$params[] = $args['to'] . ' 23:59:59';
		}
		if ( ! empty( $args['since'] ) ) {
			$where[]  = 'created_at >= %s';
			$params[] = (string) $args['since'];
		}
		if ( ! empty( $args['search'] ) ) {
			$like     = '%' . $wpdb->esc_like( mb_substr( (string) $args['search'], 0, 100 ) ) . '%';
			$where[]  = '(name LIKE %s OR email LIKE %s OR summary LIKE %s OR intent LIKE %s OR fields LIKE %s OR form_title LIKE %s)';
			$params[] = $like;
			$params[] = $like;
			$params[] = $like;
			$params[] = $like;
			$params[] = $like;
			$params[] = $like;
		}
		if ( ! empty( $args['ids'] ) && is_array( $args['ids'] ) ) {
			$ids     = array_filter( array_map( 'intval', $args['ids'] ) );
			$where[] = $ids ? 'id IN (' . implode( ',', $ids ) . ')' : '0=1';
		}
		return array( implode( ' AND ', $where ), $params );
	}

	/**
	 * Prepares SQL only when there are params.
	 *
	 * @param string $sql    SQL.
	 * @param array  $params Params.
	 * @return string
	 */
	private static function prepare( $sql, array $params ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return $params ? $wpdb->prepare( $sql, $params ) : $sql;
	}

	/**
	 * Filtered, paged list.
	 *
	 * @param array $args Filters + orderby, order, page, per_page.
	 * @return array { items, total }
	 */
	public static function query( array $args = array() ) {
		global $wpdb;
		$table                  = Tables::name();
		list( $where, $params ) = self::where( $args );
		$orderby                = isset( $args['orderby'] ) && in_array( $args['orderby'], array( 'created_at', 'lead_score', 'urgency', 'name', 'form_title' ), true ) ? $args['orderby'] : 'created_at';
		$order                  = isset( $args['order'] ) && 'asc' === strtolower( (string) $args['order'] ) ? 'ASC' : 'DESC';
		$per_page               = isset( $args['per_page'] ) ? min( 200, max( 1, (int) $args['per_page'] ) ) : 20;
		$page                   = isset( $args['page'] ) ? max( 1, (int) $args['page'] ) : 1;
		$offset                 = ( $page - 1 ) * $per_page;
		$order_sql              = 'urgency' === $orderby ? "FIELD(urgency,'high','medium','low') " . ( 'DESC' === $order ? 'ASC' : 'DESC' ) : "{$orderby} {$order}";
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$total = (int) $wpdb->get_var( self::prepare( "SELECT COUNT(*) FROM {$table} WHERE {$where}", $params ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = (array) $wpdb->get_results( self::prepare( "SELECT * FROM {$table} WHERE {$where} ORDER BY {$order_sql}, id DESC LIMIT %d OFFSET %d", array_merge( $params, array( $per_page, $offset ) ) ), ARRAY_A );
		return array(
			'items' => array_map( array( __CLASS__, 'hydrate' ), $rows ),
			'total' => $total,
		);
	}

	/**
	 * Ids matching filters (bulk / export / jobs).
	 *
	 * @param array $args  Filters.
	 * @param int   $limit Max.
	 * @return int[]
	 */
	public static function ids( array $args, $limit = 5000 ) {
		global $wpdb;
		$table                  = Tables::name();
		list( $where, $params ) = self::where( $args );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return array_map( 'intval', (array) $wpdb->get_col( self::prepare( "SELECT id FROM {$table} WHERE {$where} ORDER BY id DESC LIMIT %d", array_merge( $params, array( (int) $limit ) ) ) ) );
	}

	/**
	 * Counts per view (for the tabs).
	 *
	 * @param array $args Base filters (source/form…), view ignored.
	 * @return array
	 */
	public static function counts( array $args = array() ) {
		$out = array();
		foreach ( self::VIEWS as $view ) {
			global $wpdb;
			$table                  = Tables::name();
			list( $where, $params ) = self::where( array( 'view' => $view ) + $args );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$out[ $view ] = (int) $wpdb->get_var( self::prepare( "SELECT COUNT(*) FROM {$table} WHERE {$where}", $params ) );
		}
		return $out;
	}

	/**
	 * Ids waiting for AI analysis (oldest first).
	 *
	 * @param int $limit Max.
	 * @return int[]
	 */
	public static function pending( $limit = 10 ) {
		global $wpdb;
		$table = Tables::name();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$table} WHERE ai_state = 'pending' ORDER BY id ASC LIMIT %d", (int) $limit ) ) );
	}

	/**
	 * Oldest pending entry time (UTC) or ''.
	 *
	 * @return string
	 */
	public static function oldest_pending() {
		global $wpdb;
		$table = Tables::name();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (string) $wpdb->get_var( "SELECT MIN(updated_at) FROM {$table} WHERE ai_state = 'pending'" );
	}

	/**
	 * Entry counts per form key.
	 *
	 * @return array "source:id" => { total, new, avg_score, hot }
	 */
	public static function per_form() {
		global $wpdb;
		$table = Tables::name();
		$hot   = (int) Settings::get( 'hot_threshold', 70 );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = (array) $wpdb->get_results( $wpdb->prepare( "SELECT source, form_id, MAX(form_title) AS title, COUNT(*) AS total, SUM(status = 'new') AS unread, AVG(lead_score) AS avg_score, SUM(lead_score >= %d) AS hot, SUM(ai_state = 'pending') AS pending, MAX(created_at) AS last_at FROM {$table} GROUP BY source, form_id", $hot ), ARRAY_A );
		$out  = array();
		foreach ( $rows as $row ) {
			$out[ Settings::form_key( $row['source'], $row['form_id'] ) ] = array(
				'title'     => (string) $row['title'],
				'total'     => (int) $row['total'],
				'unread'    => (int) $row['unread'],
				'avg_score' => (int) round( (float) $row['avg_score'] ),
				'hot'       => (int) $row['hot'],
				'pending'   => (int) $row['pending'],
				'last_at'   => (string) $row['last_at'],
			);
		}
		return $out;
	}

	/**
	 * All tags in use with counts.
	 *
	 * @param int $limit Max.
	 * @return array tag => count
	 */
	public static function tag_counts( $limit = 50 ) {
		global $wpdb;
		$table = Tables::name();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$col    = (array) $wpdb->get_col( "SELECT tags_index FROM {$table} WHERE tags_index <> '' ORDER BY id DESC LIMIT 5000" );
		$counts = array();
		foreach ( $col as $index ) {
			foreach ( array_filter( explode( ',', (string) $index ) ) as $tag ) {
				$counts[ $tag ] = isset( $counts[ $tag ] ) ? $counts[ $tag ] + 1 : 1;
			}
		}
		arsort( $counts );
		return array_slice( $counts, 0, $limit, true );
	}

	/**
	 * Deletes entries older than N days (0 = keep forever).
	 *
	 * @param int $days Days.
	 * @return int Rows deleted.
	 */
	public static function prune( $days ) {
		$days = (int) $days;
		if ( $days <= 0 ) {
			return 0;
		}
		global $wpdb;
		$table = Tables::name();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE created_at < %s", gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS ) ) );
	}

	/**
	 * GDPR exporter.
	 *
	 * @param string $email Email.
	 * @param int    $page  Page.
	 * @return array
	 */
	public static function export_personal_data( $email, $page = 1 ) {
		global $wpdb;
		$table = Tables::name();
		$per   = 50;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE email = %s ORDER BY id ASC LIMIT %d OFFSET %d", (string) $email, $per, ( max( 1, (int) $page ) - 1 ) * $per ), ARRAY_A );
		$data = array();
		foreach ( $rows as $row ) {
			$row   = self::hydrate( $row );
			$items = array(
				array(
					'name'  => __( 'Form', 'dev3-ai-entry-insights' ),
					'value' => $row['form_title'],
				),
				array(
					'name'  => __( 'Submitted', 'dev3-ai-entry-insights' ),
					'value' => $row['created_at'],
				),
			);
			foreach ( $row['fields'] as $field ) {
				$items[] = array(
					'name'  => isset( $field['label'] ) ? (string) $field['label'] : '',
					'value' => isset( $field['value'] ) ? (string) $field['value'] : '',
				);
			}
			$items[] = array(
				'name'  => __( 'Summary (AI)', 'dev3-ai-entry-insights' ),
				'value' => $row['summary'],
			);
			$data[]  = array(
				'group_id'    => 'dev3ei-entries',
				'group_label' => __( 'Form entries (DEV3 AI Entry Insights)', 'dev3-ai-entry-insights' ),
				'item_id'     => 'dev3ei-entry-' . $row['id'],
				'data'        => $items,
			);
		}
		return array(
			'data' => $data,
			'done' => count( $rows ) < $per,
		);
	}

	/**
	 * GDPR eraser: deletes the person's captured entries.
	 *
	 * @param string $email Email.
	 * @param int    $page  Page.
	 * @return array
	 */
	public static function erase_personal_data( $email, $page = 1 ) {
		unset( $page );
		global $wpdb;
		$table = Tables::name();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$deleted = (int) $wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE email = %s", (string) $email ) );
		return array(
			'items_removed'  => $deleted > 0,
			'items_retained' => false,
			'messages'       => array(),
			'done'           => true,
		);
	}
}
