<?php

declare(strict_types=1);

require_once __DIR__ . '/integration/WorkflowIntegrationTest.php';
require_once __DIR__ . '/integration/ExportFiltersIntegrationTest.php';

$tests = array(
	'workflow'      => 'run_workflow_integration_test',
	'exportFilters' => 'run_export_filters_integration_test',
);

$failures = 0;

foreach ($tests as $name => $callable) {
	try {
		$callable();
		echo "[OK] {$name}\n";
	} catch (Throwable $throwable) {
		$failures++;
		echo "[FAIL] {$name}: {$throwable->getMessage()}\n";
	}
}

exit($failures > 0 ? 1 : 0);

