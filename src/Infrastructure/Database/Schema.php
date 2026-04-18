<?php

declare(strict_types=1);

namespace ArDesign\Reporting\Infrastructure\Database;

final class Schema
{
	private Tables $tables;

	public function __construct( Tables $tables )
	{
		$this->tables = $tables;
	}

	/**
	 * @return string[]
	 */
	public function getStatements(): array
	{
		global $wpdb;

		$charset_collate = $wpdb->get_charset_collate();

		return array(
			"CREATE TABLE {$this->tables->auditLog()} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				event_type varchar(50) NOT NULL,
				entity_type varchar(30) NOT NULL DEFAULT 'order',
				entity_id bigint(20) unsigned NOT NULL,
				order_id bigint(20) unsigned DEFAULT NULL,
				actor_user_id bigint(20) unsigned DEFAULT NULL,
				old_value_json longtext DEFAULT NULL,
				new_value_json longtext DEFAULT NULL,
				context_json longtext DEFAULT NULL,
				created_at_gmt datetime NOT NULL,
				PRIMARY KEY  (id),
				KEY entity_lookup (entity_type, entity_id),
				KEY order_created (order_id, created_at_gmt),
				KEY event_created (event_type, created_at_gmt),
				KEY actor_created (actor_user_id, created_at_gmt)
			) $charset_collate;",
			"CREATE TABLE {$this->tables->orderProcessing()} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				order_id bigint(20) unsigned NOT NULL,
				owner_user_id bigint(20) unsigned DEFAULT NULL,
				processing_mode varchar(30) NOT NULL DEFAULT 'standard',
				classification varchar(30) NOT NULL DEFAULT 'standard',
				started_at_gmt datetime DEFAULT NULL,
				finished_at_gmt datetime DEFAULT NULL,
				processing_seconds int(10) unsigned DEFAULT NULL,
				status varchar(30) NOT NULL DEFAULT 'new',
				is_kpi_included tinyint(1) NOT NULL DEFAULT 1,
				source_trigger varchar(50) DEFAULT NULL,
				created_at_gmt datetime NOT NULL,
				updated_at_gmt datetime NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY order_id (order_id),
				KEY owner_started (owner_user_id, started_at_gmt),
				KEY classification_kpi (classification, is_kpi_included),
				KEY status_updated (status, updated_at_gmt)
			) $charset_collate;",
			"CREATE TABLE {$this->tables->orderArchive()} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				order_id bigint(20) unsigned NOT NULL,
				archive_reason varchar(30) NOT NULL DEFAULT 'snapshot',
				snapshot_json longtext NOT NULL,
				actor_user_id bigint(20) unsigned DEFAULT NULL,
				created_at_gmt datetime NOT NULL,
				PRIMARY KEY  (id),
				KEY order_created (order_id, created_at_gmt),
				KEY reason_created (archive_reason, created_at_gmt)
			) $charset_collate;",
			"CREATE TABLE {$this->tables->orderFlags()} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				order_id bigint(20) unsigned NOT NULL,
				is_preorder tinyint(1) NOT NULL DEFAULT 0,
				is_custom_order tinyint(1) NOT NULL DEFAULT 0,
				classification_source varchar(50) NOT NULL DEFAULT 'manual',
				notes text DEFAULT NULL,
				updated_at_gmt datetime NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY order_id (order_id),
				KEY flag_lookup (is_preorder, is_custom_order)
			) $charset_collate;",
			"CREATE TABLE {$this->tables->emailReports()} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				report_type varchar(30) NOT NULL DEFAULT 'digest',
				recipient_email varchar(190) NOT NULL,
				schedule_key varchar(30) NOT NULL DEFAULT 'daily',
				filters_json longtext DEFAULT NULL,
				is_active tinyint(1) NOT NULL DEFAULT 1,
				last_sent_at_gmt datetime DEFAULT NULL,
				created_at_gmt datetime NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY recipient_schedule (recipient_email, schedule_key),
				KEY active_schedule (is_active, schedule_key)
			) $charset_collate;",
		);
	}
}
