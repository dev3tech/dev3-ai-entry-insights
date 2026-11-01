<?php
/**
 * Plugin table (captured entries + insights), added to the core dbDelta run.
 *
 * @package Dev3\AiEntryInsights
 */

namespace Dev3\AiEntryInsights\Data;

defined( 'ABSPATH' ) || exit;

/**
 * Table names + schema.
 */
class Tables {

	const ALL = array( 'entries' );

	/**
	 * Table name.
	 *
	 * @param string $name entries.
	 * @return string
	 */
	public static function name( $name = 'entries' ) {
		global $wpdb;
		return $wpdb->prefix . 'dev3ei_' . $name;
	}

	/**
	 * Adds CREATE TABLE statements (dev3ei_schema_sql).
	 *
	 * @param array  $sql     Statements.
	 * @param string $charset Charset collate.
	 * @return array
	 */
	public static function schema( $sql, $charset ) {
		$e     = self::name( 'entries' );
		$sql[] = "CREATE TABLE {$e} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			source varchar(30) NOT NULL DEFAULT '',
			form_id bigint(20) unsigned NOT NULL DEFAULT 0,
			form_title varchar(190) NOT NULL DEFAULT '',
			source_ref varchar(40) NOT NULL DEFAULT '',
			fields longtext NULL,
			email varchar(190) NOT NULL DEFAULT '',
			name varchar(190) NOT NULL DEFAULT '',
			page_url varchar(255) NOT NULL DEFAULT '',
			ip_hash char(40) NOT NULL DEFAULT '',
			content_hash char(32) NOT NULL DEFAULT '',
			summary varchar(500) NOT NULL DEFAULT '',
			sentiment varchar(10) NOT NULL DEFAULT 'neutral',
			intent varchar(120) NOT NULL DEFAULT '',
			category varchar(30) NOT NULL DEFAULT 'other',
			urgency varchar(8) NOT NULL DEFAULT 'low',
			lead_score tinyint(3) unsigned NOT NULL DEFAULT 0,
			rule_score tinyint(3) unsigned NOT NULL DEFAULT 0,
			lead_reason varchar(255) NOT NULL DEFAULT '',
			language varchar(10) NOT NULL DEFAULT '',
			tags text NULL,
			tags_index varchar(500) NOT NULL DEFAULT '',
			rule_tags text NULL,
			suggested_tags text NULL,
			insight_src varchar(8) NOT NULL DEFAULT 'rules',
			ai_state varchar(10) NOT NULL DEFAULT 'none',
			ai_error varchar(255) NOT NULL DEFAULT '',
			model varchar(80) NOT NULL DEFAULT '',
			status varchar(10) NOT NULL DEFAULT 'new',
			starred tinyint(1) unsigned NOT NULL DEFAULT 0,
			note text NULL,
			history longtext NULL,
			alerted tinyint(1) unsigned NOT NULL DEFAULT 0,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			analyzed_at datetime NULL,
			replied_at datetime NULL,
			PRIMARY KEY  (id),
			KEY source_form (source,form_id),
			KEY created (created_at),
			KEY status_created (status,created_at),
			KEY lead_score (lead_score),
			KEY ai_state (ai_state),
			KEY email (email),
			KEY source_ref (source,source_ref)
		) {$charset};";
		return $sql;
	}
}
