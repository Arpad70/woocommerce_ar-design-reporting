<?php

declare(strict_types=1);

namespace ArDesign\Reporting\Application\Reports;

use ArDesign\Reporting\Domain\Metrics\KpiCalculator;
use ArDesign\Reporting\Infrastructure\Database\Migrator;
use ArDesign\Reporting\Infrastructure\Database\Tables;
use ArDesign\Reporting\Integration\WooCommerce\Compatibility;

final class DashboardQueryService
{
	private KpiCalculator $kpi_calculator;

	private Tables $tables;

	private Migrator $migrator;

	private Compatibility $compatibility;

	public function __construct(
		KpiCalculator $kpi_calculator,
		Tables $tables,
		Migrator $migrator,
		Compatibility $compatibility
	) {
		$this->kpi_calculator = $kpi_calculator;
		$this->tables         = $tables;
		$this->migrator       = $migrator;
		$this->compatibility  = $compatibility;
	}

	/**
	 * @return array<string, mixed>
	 */
	public function getDashboardData(): array
	{
		return array(
			'kpis'           => $this->kpi_calculator->getOverview(),
			'tables'         => $this->tables->all(),
			'missing_tables' => $this->migrator->getMissingTables(),
			'hpos_enabled'   => $this->compatibility->isHposEnabled(),
		);
	}
}
