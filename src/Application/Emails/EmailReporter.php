<?php

declare(strict_types=1);

namespace ArDesign\Reporting\Application\Emails;

use ArDesign\Reporting\Domain\Metrics\KpiCalculator;
use ArDesign\Reporting\Infrastructure\Repository\EmailReportRepository;

final class EmailReporter
{
	private EmailReportRepository $email_report_repository;

	private KpiCalculator $kpi_calculator;

	public function __construct( EmailReportRepository $email_report_repository, KpiCalculator $kpi_calculator )
	{
		$this->email_report_repository = $email_report_repository;
		$this->kpi_calculator = $kpi_calculator;
	}

	/**
	 * @return array<string, string>
	 */
	public function describeDigest(): array
	{
		return array(
			'type'      => 'scheduled_digest',
			'frequency' => 'daily_or_weekly',
			'content'   => 'kpi_summary_and_export_link',
		);
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	public function getConfigurations(): array
	{
		return $this->email_report_repository->listConfigurations();
	}

	public function saveConfiguration( string $email, string $schedule_key, bool $is_active ): bool
	{
		$email = sanitize_email( $email );

		if ( ! is_email( $email ) ) {
			return false;
		}

		$schedule_key = $this->normalizeScheduleKey( $schedule_key );
		$existing_id  = $this->email_report_repository->findIdByRecipientAndSchedule( $email, $schedule_key );

		if ( $existing_id > 0 ) {
			return $this->email_report_repository->updateConfigurationById( $existing_id, $email, $schedule_key, $is_active );
		}

		return $this->email_report_repository->insertConfiguration( $email, $schedule_key, $is_active );
	}

	public function sendScheduledDigest( string $schedule_key ): int
	{
		$schedule_key = $this->normalizeScheduleKey( $schedule_key );
		$reports      = $this->email_report_repository->listActiveBySchedule( $schedule_key );

		if ( ! is_array( $reports ) || empty( $reports ) ) {
			return 0;
		}

		$kpis      = $this->kpi_calculator->getOverview();
		$admin_url = admin_url( 'admin.php?page=ar-design-reporting' );
		$site_name = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		$subject   = sprintf(
			/* translators: %1$s: site name, %2$s: digest schedule */
			__( '[%1$s] AR Reporting digest (%2$s)', 'ar-design-reporting' ),
			$site_name,
			$schedule_key
		);
		$sent      = 0;

		foreach ( $reports as $report ) {
			$email = isset( $report['recipient_email'] ) ? sanitize_email( (string) $report['recipient_email'] ) : '';

			if ( ! is_email( $email ) ) {
				continue;
			}

			$message = $this->buildDigestMessage( $kpis, $schedule_key, $admin_url );
			$ok      = wp_mail( $email, $subject, $message );

			if ( $ok ) {
				$sent++;
				$this->email_report_repository->updateLastSentAt( (int) $report['id'], current_time( 'mysql', true ) );
			}
		}

		return $sent;
	}

	private function normalizeScheduleKey( string $schedule_key ): string
	{
		$schedule_key = sanitize_key( $schedule_key );

		if ( ! in_array( $schedule_key, array( 'daily', 'weekly' ), true ) ) {
			return 'daily';
		}

		return $schedule_key;
	}

	/**
	 * @param array<string, int|float> $kpis
	 */
	private function buildDigestMessage( array $kpis, string $schedule_key, string $admin_url ): string
	{
		$lines   = array();
		$lines[] = sprintf(
			/* translators: %s: schedule key */
			__( 'AR Design Reporting digest (%s)', 'ar-design-reporting' ),
			$schedule_key
		);
		$lines[] = gmdate( 'Y-m-d H:i:s' ) . ' GMT';
		$lines[] = '';
		$lines[] = __( 'KPI prehľad:', 'ar-design-reporting' );
		$lines[] = sprintf( '- %s: %d', __( 'Všetky sledované objednávky', 'ar-design-reporting' ), (int) ( $kpis['total_orders'] ?? 0 ) );
		$lines[] = sprintf( '- %s: %d', __( 'Objednávky započítané do KPI', 'ar-design-reporting' ), (int) ( $kpis['kpi_orders'] ?? 0 ) );
		$lines[] = sprintf( '- %s: %d', __( 'Dokončené objednávky', 'ar-design-reporting' ), (int) ( $kpis['completed'] ?? 0 ) );
		$lines[] = sprintf( '- %s: %d', __( 'Auditné udalosti', 'ar-design-reporting' ), (int) ( $kpis['audit_events'] ?? 0 ) );
		$lines[] = '';
		$lines[] = __( 'Dashboard:', 'ar-design-reporting' ) . ' ' . $admin_url;

		return implode( "\n", $lines );
	}
}
