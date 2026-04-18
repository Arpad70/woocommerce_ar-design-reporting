<?php

declare(strict_types=1);

namespace ArDesign\Reporting\Infrastructure\Repository;

use ArDesign\Reporting\Infrastructure\Database\Tables;

class AuditLogRepository
{
	private Tables $tables;

	public function __construct(Tables $tables)
	{
		$this->tables = $tables;
	}

	public function countAll(): int
	{
		global $wpdb;

		return (int) $wpdb->get_var("SELECT COUNT(*) FROM {$this->tables->auditLog()}");
	}
}

