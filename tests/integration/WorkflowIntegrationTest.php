<?php

declare(strict_types=1);

use ArDesign\Reporting\Domain\Audit\AuditLogger;
use ArDesign\Reporting\Domain\Orders\OrderClassifier;
use ArDesign\Reporting\Domain\Processing\ProcessingService;
use ArDesign\Reporting\Infrastructure\Database\Tables;
use ArDesign\Reporting\Infrastructure\Repository\OrderProcessingRepository;

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/FakeWpdb.php';

/**
 * @throws RuntimeException
 */
function run_workflow_integration_test(): void
{
	global $wpdb;

	$wpdb                  = new FakeWpdb();
	$tables                = new Tables();
	$order_repository      = new OrderProcessingRepository($tables);
	$audit_logger          = new AuditLogger($tables);
	$order_classifier      = new OrderClassifier();
	$processing_service    = new ProcessingService($order_repository, $audit_logger, $order_classifier);
	$order_processing_table = $tables->orderProcessing();

	$processing_service->initializeOrder(1001);
	$processing_service->takeOverOrder(1001, 42);
	$processing_service->finishProcessing(1001, 42);
	$processing_service->completeFulfillment(1001, 84);

	$rows = $wpdb->getTableRows($order_processing_table);

	if (1 !== count($rows)) {
		throw new RuntimeException('Workflow test failed: expected exactly one workflow row.');
	}

	$row = $rows[0];

	if ('vybavena' !== ($row['status'] ?? null)) {
		throw new RuntimeException('Workflow test failed: expected final status "vybavena".');
	}

	if ((int) ($row['owner_user_id'] ?? 0) !== 84) {
		throw new RuntimeException('Workflow test failed: expected owner_user_id = 84.');
	}

	if (empty($row['started_at_gmt']) || empty($row['finished_at_gmt'])) {
		throw new RuntimeException('Workflow test failed: expected both started_at_gmt and finished_at_gmt.');
	}
}
