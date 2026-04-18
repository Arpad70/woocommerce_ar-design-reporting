<?php

declare(strict_types=1);

namespace ArDesign\Reporting\Application;

use ArDesign\Reporting\Application\Emails\EmailReporter;
use ArDesign\Reporting\Application\Exports\ExportManager;
use ArDesign\Reporting\Application\Reports\DashboardQueryService;
use ArDesign\Reporting\Domain\Audit\AuditLogger;
use ArDesign\Reporting\Domain\Metrics\KpiCalculator;
use ArDesign\Reporting\Domain\Orders\OrderArchiveService;
use ArDesign\Reporting\Domain\Orders\OrderClassifier;
use ArDesign\Reporting\Domain\Processing\ProcessingService;
use ArDesign\Reporting\Infrastructure\Database\Migrator;
use ArDesign\Reporting\Infrastructure\Database\Schema;
use ArDesign\Reporting\Infrastructure\Database\Tables;
use ArDesign\Reporting\Infrastructure\Repository\AuditLogRepository;
use ArDesign\Reporting\Infrastructure\Repository\EmailReportRepository;
use ArDesign\Reporting\Infrastructure\Repository\OrderProcessingRepository;
use ArDesign\Reporting\Infrastructure\Scheduler\DigestScheduler;
use ArDesign\Reporting\Integration\WooCommerce\Compatibility;
use ArDesign\Reporting\Presentation\Admin\DashboardPage;
use ArDesign\Reporting\Presentation\Admin\Menu;
use ArDesign\Reporting\Presentation\Admin\WorkflowActions;
use ArDesign\Reporting\Support\Hooks\OrderArchiveHooks;
use ArDesign\Reporting\Support\Hooks\OrderHooks;
use ArDesign\Reporting\Support\Updates\GitHubUpdater;

final class ServiceProvider
{
	public static function register(Container $container): void
	{
		$container->set( Requirements::class, static fn (): Requirements => new Requirements() );
		$container->set( Tables::class, static fn (): Tables => new Tables() );
		$container->set( Schema::class, static fn ( Container $c ): Schema => new Schema( $c->get( Tables::class ) ) );
		$container->set( Migrator::class, static fn ( Container $c ): Migrator => new Migrator( $c->get( Tables::class ), $c->get( Schema::class ) ) );
		$container->set( OrderProcessingRepository::class, static fn ( Container $c ): OrderProcessingRepository => new OrderProcessingRepository( $c->get( Tables::class ) ) );
		$container->set( EmailReportRepository::class, static fn ( Container $c ): EmailReportRepository => new EmailReportRepository( $c->get( Tables::class ) ) );
		$container->set( AuditLogRepository::class, static fn ( Container $c ): AuditLogRepository => new AuditLogRepository( $c->get( Tables::class ) ) );
		$container->set( Compatibility::class, static fn (): Compatibility => new Compatibility() );
		$container->set( AuditLogger::class, static fn ( Container $c ): AuditLogger => new AuditLogger( $c->get( Tables::class ) ) );
		$container->set( OrderClassifier::class, static fn (): OrderClassifier => new OrderClassifier() );
		$container->set(
			OrderArchiveService::class,
			static fn ( Container $c ): OrderArchiveService => new OrderArchiveService(
				$c->get( Tables::class ),
				$c->get( AuditLogger::class )
			)
		);
		$container->set(
			ProcessingService::class,
			static fn ( Container $c ): ProcessingService => new ProcessingService(
				$c->get( OrderProcessingRepository::class ),
				$c->get( AuditLogger::class ),
				$c->get( OrderClassifier::class )
			)
		);
		$container->set(
			KpiCalculator::class,
			static fn ( Container $c ): KpiCalculator => new KpiCalculator(
				$c->get( OrderProcessingRepository::class ),
				$c->get( AuditLogRepository::class )
			)
		);
		$container->set(
			DashboardQueryService::class,
			static fn ( Container $c ): DashboardQueryService => new DashboardQueryService(
				$c->get( KpiCalculator::class ),
				$c->get( Tables::class ),
				$c->get( Migrator::class ),
				$c->get( Compatibility::class )
			)
		);
		$container->set( ExportManager::class, static fn ( Container $c ): ExportManager => new ExportManager( $c->get( OrderProcessingRepository::class ) ) );
		$container->set(
			EmailReporter::class,
			static fn ( Container $c ): EmailReporter => new EmailReporter(
				$c->get( EmailReportRepository::class ),
				$c->get( KpiCalculator::class )
			)
		);
		$container->set(
			DigestScheduler::class,
			static fn ( Container $c ): DigestScheduler => new DigestScheduler( $c->get( EmailReporter::class ) )
		);
		$container->set(
			GitHubUpdater::class,
			static fn (): GitHubUpdater => new GitHubUpdater(
				ARD_REPORTING_GITHUB_REPOSITORY,
				ARD_REPORTING_BASENAME,
				ARD_REPORTING_VERSION
			)
		);
		$container->set(
			'plugin.meta',
			static fn (): array => array(
				'version'    => ARD_REPORTING_VERSION,
				'db_version' => ARD_REPORTING_DB_VERSION,
			)
		);
		$container->set(
			DashboardPage::class,
			static fn ( Container $c ): DashboardPage => new DashboardPage(
				$c->get( DashboardQueryService::class ),
				$c->get( ExportManager::class ),
				$c->get( EmailReporter::class ),
				$c->get( ProcessingService::class ),
				$c->get( 'plugin.meta' )
			)
		);
		$container->set( Menu::class, static fn ( Container $c ): Menu => new Menu( $c->get( DashboardPage::class ) ) );
		$container->set(
			WorkflowActions::class,
			static fn ( Container $c ): WorkflowActions => new WorkflowActions(
				$c->get( ProcessingService::class ),
				$c->get( ExportManager::class ),
				$c->get( EmailReporter::class )
			)
		);
		$container->set( OrderHooks::class, static fn ( Container $c ): OrderHooks => new OrderHooks( $c->get( ProcessingService::class ) ) );
		$container->set(
			OrderArchiveHooks::class,
			static fn ( Container $c ): OrderArchiveHooks => new OrderArchiveHooks( $c->get( OrderArchiveService::class ) )
		);
	}
}
