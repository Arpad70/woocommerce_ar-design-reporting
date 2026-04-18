<?php

declare(strict_types=1);

$plugin_root = dirname(__DIR__);
$version_file = $plugin_root . '/VERSION';
$plugin_file = $plugin_root . '/ar-design-reporting.php';

if (! file_exists($version_file) || ! file_exists($plugin_file)) {
	fwrite(STDERR, "Missing VERSION or plugin main file.\n");
	exit(1);
}

$version = trim((string) file_get_contents($version_file));
$plugin_source = (string) file_get_contents($plugin_file);

if ('' === $version) {
	fwrite(STDERR, "VERSION file is empty.\n");
	exit(1);
}

$errors = array();

if (! preg_match('/^\s*\*\s*Version:\s*' . preg_quote($version, '/') . '\s*$/mi', $plugin_source)) {
	$errors[] = 'Plugin header Version does not match VERSION file.';
}

if (! preg_match("/define\\(\\s*'ARD_REPORTING_VERSION'\\s*,\\s*'" . preg_quote($version, '/') . "'\\s*\\)/", $plugin_source)) {
	$errors[] = 'ARD_REPORTING_VERSION does not match VERSION file.';
}

if (! preg_match("/define\\(\\s*'ARD_REPORTING_DB_VERSION'\\s*,\\s*'" . preg_quote($version, '/') . "'\\s*\\)/", $plugin_source)) {
	$errors[] = 'ARD_REPORTING_DB_VERSION does not match VERSION file.';
}

if (! empty($errors)) {
	foreach ($errors as $error) {
		fwrite(STDERR, $error . "\n");
	}

	exit(1);
}

echo "Version consistency OK ({$version}).\n";

