<?php

declare(strict_types=1);

namespace ArDesign\Reporting\Domain\Orders;

final class OrderClassifier
{
	/**
	 * @return array{classification:string,is_kpi_included:bool,source:string}
	 */
	public function classify(int $order_id): array
	{
		$is_preorder = $this->isTruthyMeta(get_post_meta($order_id, '_ard_is_preorder', true));
		$is_custom   = $this->isTruthyMeta(get_post_meta($order_id, '_ard_is_custom_order', true));

		if ($is_preorder) {
			return array(
				'classification'   => 'preorder',
				'is_kpi_included'  => false,
				'source'           => 'order_meta',
			);
		}

		if ($is_custom) {
			return array(
				'classification'   => 'custom',
				'is_kpi_included'  => false,
				'source'           => 'order_meta',
			);
		}

		return array(
			'classification'   => 'standard',
			'is_kpi_included'  => true,
			'source'           => 'default',
		);
	}

	/**
	 * @param mixed $value
	 */
	private function isTruthyMeta($value): bool
	{
		if (is_bool($value)) {
			return $value;
		}

		if (is_int($value)) {
			return 1 === $value;
		}

		if (is_string($value)) {
			return in_array(strtolower(trim($value)), array('1', 'yes', 'true', 'on'), true);
		}

		return false;
	}
}
