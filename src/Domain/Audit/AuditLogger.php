<?php

declare(strict_types=1);

namespace ArDesign\Reporting\Domain\Audit;

use ArDesign\Reporting\Infrastructure\Database\Tables;

final class AuditLogger
{
	private Tables $tables;

	public function __construct(Tables $tables)
	{
		$this->tables = $tables;
	}

	/**
	 * @param array<string, mixed> $old_value
	 * @param array<string, mixed> $new_value
	 * @param array<string, mixed> $context
	 */
	public function log(
		string $event_type,
		string $entity_type,
		int $entity_id,
		?int $order_id = null,
		?int $actor_user_id = null,
		array $old_value = array(),
		array $new_value = array(),
		array $context = array()
	): void {
		global $wpdb;

		$wpdb->insert(
			$this->tables->auditLog(),
			array(
				'event_type'     => $event_type,
				'entity_type'    => $entity_type,
				'entity_id'      => $entity_id,
				'order_id'       => $order_id,
				'actor_user_id'  => $actor_user_id,
				'old_value_json' => wp_json_encode($old_value),
				'new_value_json' => wp_json_encode($new_value),
				'context_json'   => wp_json_encode($context),
				'created_at_gmt' => current_time('mysql', true),
			),
			array('%s', '%s', '%d', '%d', '%d', '%s', '%s', '%s', '%s')
		);
	}
}
