<?php

declare(strict_types=1);

namespace ArDesign\Reporting\Infrastructure\Database;

final class Tables
{
	public function auditLog(): string
	{
		global $wpdb;

		return $wpdb->prefix . 'ard_audit_log';
	}

	public function orderProcessing(): string
	{
		global $wpdb;

		return $wpdb->prefix . 'ard_order_processing';
	}

	public function orderArchive(): string
	{
		global $wpdb;

		return $wpdb->prefix . 'ard_order_archive';
	}

	public function orderSnapshot(): string
	{
		return $this->orderArchive();
	}

	public function orderFlags(): string
	{
		global $wpdb;

		return $wpdb->prefix . 'ard_order_flags';
	}

	public function emailReports(): string
	{
		global $wpdb;

		return $wpdb->prefix . 'ard_email_reports';
	}

	/**
	 * @return array<string, string>
	 */
	public function all(): array
	{
		return array(
			'audit_log'        => $this->auditLog(),
			'order_processing' => $this->orderProcessing(),
			'order_archive'    => $this->orderArchive(),
			'order_flags'      => $this->orderFlags(),
			'email_reports'    => $this->emailReports(),
		);
	}
}
