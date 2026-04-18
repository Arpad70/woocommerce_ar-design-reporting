<?php

declare(strict_types=1);

namespace ArDesign\Reporting\Infrastructure\Database;

final class Migrator
{
	private Tables $tables;

	private Schema $schema;

	public function __construct( Tables $tables, Schema $schema )
	{
		$this->tables = $tables;
		$this->schema = $schema;
	}

	public function migrate(): void
	{
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$this->deduplicateEmailReports();

		foreach ( $this->schema->getStatements() as $statement ) {
			dbDelta( $statement );
		}

		update_option( 'ard_reporting_db_version', ARD_REPORTING_DB_VERSION );
	}

	public function hasTable( string $table_name ): bool
	{
		global $wpdb;

		$found_table = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name ) );

		return $found_table === $table_name;
	}

	/**
	 * @return string[]
	 */
	public function getMissingTables(): array
	{
		$missing_tables = array();

		foreach ( $this->tables->all() as $table_name ) {
			if ( ! $this->hasTable( $table_name ) ) {
				$missing_tables[] = $table_name;
			}
		}

		return $missing_tables;
	}

	private function deduplicateEmailReports(): void
	{
		global $wpdb;

		$table = $this->tables->emailReports();

		if ( ! $this->hasTable( $table ) ) {
			return;
		}

		$duplicates = $wpdb->get_results(
			"SELECT recipient_email, schedule_key, MAX(id) AS keep_id, COUNT(*) AS duplicates_count
			FROM {$table}
			GROUP BY recipient_email, schedule_key
			HAVING COUNT(*) > 1",
			ARRAY_A
		);

		if ( ! is_array( $duplicates ) || empty( $duplicates ) ) {
			return;
		}

		foreach ( $duplicates as $duplicate ) {
			$recipient_email = isset( $duplicate['recipient_email'] ) ? (string) $duplicate['recipient_email'] : '';
			$schedule_key    = isset( $duplicate['schedule_key'] ) ? (string) $duplicate['schedule_key'] : '';
			$keep_id         = isset( $duplicate['keep_id'] ) ? (int) $duplicate['keep_id'] : 0;

			if ( '' === $recipient_email || '' === $schedule_key || $keep_id <= 0 ) {
				continue;
			}

			$wpdb->query(
				$wpdb->prepare(
					"DELETE FROM {$table} WHERE recipient_email = %s AND schedule_key = %s AND id <> %d",
					$recipient_email,
					$schedule_key,
					$keep_id
				)
			);
		}
	}
}
