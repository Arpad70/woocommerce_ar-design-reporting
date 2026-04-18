<?php

declare(strict_types=1);

namespace ArDesign\Reporting\Presentation\Admin;

final class Menu
{
	private DashboardPage $dashboard_page;

	public function __construct(DashboardPage $dashboard_page)
	{
		$this->dashboard_page = $dashboard_page;
	}

	public function register(): void
	{
		$capability = current_user_can( 'manage_woocommerce' ) ? 'manage_woocommerce' : 'manage_options';

		add_menu_page(
			__('AR Design Reporting', 'ar-design-reporting'),
			__('AR Reporting', 'ar-design-reporting'),
			$capability,
			'ar-design-reporting',
			array($this->dashboard_page, 'render'),
			'dashicons-chart-bar',
			56
		);
	}
}
