<?php

declare(strict_types=1);

use ArDesign\Reporting\Application\Exports\ExportManager;
use ArDesign\Reporting\Infrastructure\Database\Tables;
use ArDesign\Reporting\Infrastructure\Repository\OrderProcessingRepository;

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/FakeWpdb.php';

/**
 * @throws RuntimeException
 */
function run_export_filters_integration_test(): void
{
	global $wpdb;

	$wpdb            = new FakeWpdb();
	$tables          = new Tables();
	$order_repository = new OrderProcessingRepository($tables);
	$export_manager  = new ExportManager($order_repository);

	$normalized = $export_manager->normalizeFilters(
		array(
			'status'         => 'BAD-STATUS',
			'classification' => 'unknown',
			'date_from'      => '2026-04-20',
			'date_to'        => '2026-04-10',
		)
	);

	if ('' !== $normalized['status']) {
		throw new RuntimeException('Export filters test failed: invalid status should normalize to empty string.');
	}

	if ('' !== $normalized['classification']) {
		throw new RuntimeException('Export filters test failed: invalid classification should normalize to empty string.');
	}

	if ('2026-04-10' !== $normalized['date_from'] || '2026-04-20' !== $normalized['date_to']) {
		throw new RuntimeException('Export filters test failed: date range should be auto-swapped when from > to.');
	}

	$valid = $export_manager->normalizeFilters(
		array(
			'status'         => 'processing',
			'classification' => 'standard',
			'date_from'      => '2026-04-01',
			'date_to'        => '2026-04-30',
		)
	);

	if ('processing' !== $valid['status'] || 'standard' !== $valid['classification']) {
		throw new RuntimeException('Export filters test failed: valid filters should be kept unchanged.');
	}
}

