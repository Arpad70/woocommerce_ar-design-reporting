<?php

declare(strict_types=1);

namespace ArDesign\Reporting\Infrastructure\Repository;

use ArDesign\Reporting\Infrastructure\Database\Tables;

class EmailReportRepository
{
	private Tables $tables;

	public function __construct(Tables $tables)
	{
		$this->tables = $tables;
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	public function listConfigurations(): array
	{
		global $wpdb;

		$table = $this->tables->emailReports();
		$rows  = $wpdb->get_results("SELECT * FROM {$table} ORDER BY is_active DESC, schedule_key ASC, recipient_email ASC", ARRAY_A);

		return is_array($rows) ? $rows : array();
	}

	public function findIdByRecipientAndSchedule(string $email, string $schedule_key): int
	{
		global $wpdb;

		$table = $this->tables->emailReports();

		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$table} WHERE recipient_email = %s AND schedule_key = %s LIMIT 1",
				$email,
				$schedule_key
			)
		);
	}

	public function updateConfigurationById(int $id, string $email, string $schedule_key, bool $is_active): bool
	{
		global $wpdb;

		$updated = $wpdb->update(
			$this->tables->emailReports(),
			array(
				'recipient_email' => $email,
				'schedule_key'    => $schedule_key,
				'is_active'       => $is_active ? 1 : 0,
			),
			array('id' => $id),
			array('%s', '%s', '%d'),
			array('%d')
		);

		return false !== $updated;
	}

	public function insertConfiguration(string $email, string $schedule_key, bool $is_active): bool
	{
		global $wpdb;

		$inserted = $wpdb->insert(
			$this->tables->emailReports(),
			array(
				'report_type'    => 'digest',
				'recipient_email' => $email,
				'schedule_key'    => $schedule_key,
				'is_active'       => $is_active ? 1 : 0,
				'created_at_gmt'  => current_time('mysql', true),
			),
			array('%s', '%s', '%s', '%d', '%s')
		);

		return false !== $inserted;
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	public function listActiveBySchedule(string $schedule_key): array
	{
		global $wpdb;

		$table = $this->tables->emailReports();
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, recipient_email, schedule_key FROM {$table} WHERE is_active = 1 AND schedule_key = %s",
				$schedule_key
			),
			ARRAY_A
		);

		return is_array($rows) ? $rows : array();
	}

	public function updateLastSentAt(int $id, string $sent_at_gmt): bool
	{
		global $wpdb;

		$updated = $wpdb->update(
			$this->tables->emailReports(),
			array('last_sent_at_gmt' => $sent_at_gmt),
			array('id' => $id),
			array('%s'),
			array('%d')
		);

		return false !== $updated;
	}
}

