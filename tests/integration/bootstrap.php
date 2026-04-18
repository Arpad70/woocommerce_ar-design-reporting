<?php

declare(strict_types=1);

if (! defined('ARRAY_A')) {
	define('ARRAY_A', 'ARRAY_A');
}

if (! defined('ARD_REPORTING_PATH')) {
	define('ARD_REPORTING_PATH', dirname(__DIR__, 2) . '/');
}

require_once ARD_REPORTING_PATH . 'bootstrap/autoload.php';

\ArDesign\Reporting\Support\Autoloader::register();

if (! function_exists('__')) {
	function __(string $text): string
	{
		return $text;
	}
}

if (! function_exists('sanitize_key')) {
	function sanitize_key(string $key): string
	{
		$key = strtolower($key);

		return (string) preg_replace('/[^a-z0-9_\-]/', '', $key);
	}
}

if (! function_exists('sanitize_text_field')) {
	function sanitize_text_field(string $text): string
	{
		return trim($text);
	}
}

if (! function_exists('current_time')) {
	function current_time(string $type, bool $gmt = false): string
	{
		if ('mysql' === $type) {
			return '2026-04-18 10:00:00';
		}

		return '0';
	}
}

if (! function_exists('get_current_user_id')) {
	function get_current_user_id(): int
	{
		return 777;
	}
}

if (! function_exists('get_option')) {
	/**
	 * @param mixed $default
	 * @return mixed
	 */
	function get_option(string $option, $default = false)
	{
		return $default;
	}
}

if (! function_exists('get_post_meta')) {
	/**
	 * @param string|int $key
	 * @param bool $single
	 * @return mixed
	 */
	function get_post_meta(int $post_id, $key = '', bool $single = false)
	{
		return '';
	}
}

if (! function_exists('wp_json_encode')) {
	/**
	 * @param mixed $value
	 */
	function wp_json_encode($value): string|false
	{
		return json_encode($value);
	}
}
