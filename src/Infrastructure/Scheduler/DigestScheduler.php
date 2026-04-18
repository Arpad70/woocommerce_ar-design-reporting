<?php

declare(strict_types=1);

namespace ArDesign\Reporting\Infrastructure\Scheduler;

use ArDesign\Reporting\Application\Emails\EmailReporter;

final class DigestScheduler
{
	public const DAILY_EVENT  = 'ard_reporting_send_digest_daily';
	public const WEEKLY_EVENT = 'ard_reporting_send_digest_weekly';

	private EmailReporter $email_reporter;

	public function __construct( EmailReporter $email_reporter )
	{
		$this->email_reporter = $email_reporter;
	}

	public function register(): void
	{
		add_filter( 'cron_schedules', array( $this, 'registerCustomSchedules' ) );
		add_action( self::DAILY_EVENT, array( $this, 'handleDailyDigest' ) );
		add_action( self::WEEKLY_EVENT, array( $this, 'handleWeeklyDigest' ) );

		$this->ensureScheduled();
	}

	/**
	 * @param array<string, array<string, mixed>> $schedules
	 * @return array<string, array<string, mixed>>
	 */
	public function registerCustomSchedules( array $schedules ): array
	{
		if ( ! isset( $schedules['ard_weekly'] ) ) {
			$schedules['ard_weekly'] = array(
				'interval' => WEEK_IN_SECONDS,
				'display'  => __( 'Once weekly (AR Reporting)', 'ar-design-reporting' ),
			);
		}

		return $schedules;
	}

	public function ensureScheduled(): void
	{
		if ( ! wp_next_scheduled( self::DAILY_EVENT ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::DAILY_EVENT );
		}

		if ( ! wp_next_scheduled( self::WEEKLY_EVENT ) ) {
			wp_schedule_event( time() + DAY_IN_SECONDS, 'ard_weekly', self::WEEKLY_EVENT );
		}
	}

	public function unscheduleAll(): void
	{
		wp_clear_scheduled_hook( self::DAILY_EVENT );
		wp_clear_scheduled_hook( self::WEEKLY_EVENT );
	}

	public function handleDailyDigest(): void
	{
		$this->email_reporter->sendScheduledDigest( 'daily' );
	}

	public function handleWeeklyDigest(): void
	{
		$this->email_reporter->sendScheduledDigest( 'weekly' );
	}
}
