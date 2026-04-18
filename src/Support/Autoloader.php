<?php

declare(strict_types=1);

namespace ArDesign\Reporting\Support;

final class Autoloader
{
	private const PREFIX = 'ArDesign\\Reporting\\';

	public static function register(): void
	{
		spl_autoload_register(array(__CLASS__, 'autoload'));
	}

	private static function autoload(string $class): void
	{
		if (strpos($class, self::PREFIX) !== 0) {
			return;
		}

		$relative_class = substr($class, strlen(self::PREFIX));
		$relative_path  = str_replace('\\', DIRECTORY_SEPARATOR, $relative_class) . '.php';
		$file           = ARD_REPORTING_PATH . 'src/' . $relative_path;

		if (file_exists($file)) {
			require_once $file;
		}
	}
}
