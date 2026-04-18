<?php

declare(strict_types=1);

namespace ArDesign\Reporting\Application;

final class Requirements
{
	public function hasWooCommerce(): bool
	{
		return class_exists('WooCommerce');
	}

	public function hasSupportedPhpVersion(): bool
	{
		return version_compare(PHP_VERSION, '8.0', '>=');
	}

	public function hasSupportedWordPressVersion(): bool
	{
		global $wp_version;

		return isset($wp_version) && version_compare((string) $wp_version, '6.7', '>=');
	}

	public function canBoot(): bool
	{
		return $this->hasWooCommerce() && $this->hasSupportedPhpVersion() && $this->hasSupportedWordPressVersion();
	}

	public function getFailureMessage(): string
	{
		if (! $this->hasSupportedPhpVersion()) {
			return __('Plugin AR Design Reporting vyžaduje PHP 8.0 nebo novější.', 'ar-design-reporting');
		}

		if (! $this->hasSupportedWordPressVersion()) {
			return __('Plugin AR Design Reporting vyžaduje WordPress 6.7 nebo novější.', 'ar-design-reporting');
		}

		if (! $this->hasWooCommerce()) {
			return __('Plugin AR Design Reporting vyžaduje aktivní WooCommerce.', 'ar-design-reporting');
		}

		return '';
	}
}
