<?php

declare(strict_types=1);

namespace ArDesign\Reporting\Integration\WooCommerce;

use Automattic\WooCommerce\Utilities\FeaturesUtil;

final class Compatibility
{
	public function declareCompatibility(): void
	{
		if (! class_exists(FeaturesUtil::class)) {
			return;
		}

		FeaturesUtil::declare_compatibility('custom_order_tables', ARD_REPORTING_FILE, true);
	}

	public function isHposEnabled(): bool
	{
		if (! class_exists(\Automattic\WooCommerce\Utilities\OrderUtil::class)) {
			return false;
		}

		return \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
	}
}
