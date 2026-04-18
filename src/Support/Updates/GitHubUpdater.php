<?php

declare(strict_types=1);

namespace ArDesign\Reporting\Support\Updates;

final class GitHubUpdater
{
	private const CACHE_TTL = 900;

	private string $repository_full_name;

	private string $plugin_basename;

	private string $current_version;

	public function __construct(string $repository_full_name, string $plugin_basename, string $current_version)
	{
		$this->repository_full_name = $repository_full_name;
		$this->plugin_basename      = $plugin_basename;
		$this->current_version      = $current_version;
	}

	public function register(): void
	{
		add_filter('pre_set_site_transient_update_plugins', array($this, 'injectUpdateData'));
		add_filter('plugins_api', array($this, 'injectPluginInfo'), 20, 3);
		add_action('upgrader_process_complete', array($this, 'clearCacheAfterUpgrade'), 10, 2);
	}

	/**
	 * @param object $transient
	 * @return object
	 */
	public function injectUpdateData($transient)
	{
		if (! is_object($transient) || ! isset($transient->checked) || ! is_array($transient->checked)) {
			return $transient;
		}

		$release = $this->getLatestRelease();

		if (empty($release)) {
			return $transient;
		}

		$latest_version = (string) ($release['version'] ?? '');
		$package_url    = (string) ($release['package_url'] ?? '');
		$details_url    = (string) ($release['details_url'] ?? '');

		if ('' === $latest_version || '' === $package_url || version_compare($latest_version, $this->current_version, '<=')) {
			return $transient;
		}

		$transient->response[$this->plugin_basename] = (object) array(
			'slug'        => 'ar-design-reporting',
			'plugin'      => $this->plugin_basename,
			'new_version' => $latest_version,
			'url'         => $details_url,
			'package'     => $package_url,
		);

		return $transient;
	}

	/**
	 * @param mixed $result
	 * @param mixed $action
	 * @param mixed $args
	 * @return mixed
	 */
	public function injectPluginInfo($result, $action, $args)
	{
		if ('plugin_information' !== $action || ! is_object($args) || ! isset($args->slug) || 'ar-design-reporting' !== $args->slug) {
			return $result;
		}

		$release = $this->getLatestRelease();
		$version = ! empty($release['version']) ? (string) $release['version'] : $this->current_version;
		$details = ! empty($release['details_url']) ? (string) $release['details_url'] : 'https://github.com/' . $this->repository_full_name;
		$body    = ! empty($release['body']) ? (string) $release['body'] : '';

		return (object) array(
			'name'          => 'Ar Design Reporting',
			'slug'          => 'ar-design-reporting',
			'version'       => $version,
			'author'        => '<a href="https://github.com/' . esc_attr($this->repository_full_name) . '">Arpad70</a>',
			'homepage'      => $details,
			'download_link' => (string) ($release['package_url'] ?? ''),
			'sections'      => array(
				'description' => __('HPOS-first admin reporting plugin pre WooCommerce.', 'ar-design-reporting'),
				'changelog'   => '' !== $body ? wp_kses_post(nl2br(esc_html($body))) : __('Changelog nie je dostupný.', 'ar-design-reporting'),
			),
		);
	}

	/**
	 * @return array<string, string>
	 */
	private function getLatestRelease(): array
	{
		$cached = get_transient($this->getCacheKey());

		if (is_array($cached) && isset($cached['version'])) {
			return $cached;
		}

		$request_url = sprintf('https://api.github.com/repos/%s/releases/latest', $this->repository_full_name);
		$response    = wp_remote_get(
			$request_url,
			array(
				'timeout' => 15,
				'headers' => array(
					'Accept'     => 'application/vnd.github+json',
					'User-Agent' => 'ar-design-reporting/' . $this->current_version,
				),
			)
		);

		if (is_wp_error($response)) {
			return array();
		}

		$status_code = (int) wp_remote_retrieve_response_code($response);

		if (200 !== $status_code) {
			return array();
		}

		$body = wp_remote_retrieve_body($response);
		$data = json_decode((string) $body, true);

		if (! is_array($data)) {
			return array();
		}

		$tag_name   = isset($data['tag_name']) ? (string) $data['tag_name'] : '';
		$version    = ltrim($tag_name, 'v');
		$package    = $this->extractZipAssetUrl($data);
		$details    = isset($data['html_url']) ? (string) $data['html_url'] : '';
		$changelog  = isset($data['body']) ? (string) $data['body'] : '';

		if ('' === $version || '' === $package) {
			return array();
		}

		$release = array(
			'version'     => $version,
			'package_url' => $package,
			'details_url' => $details,
			'body'        => $changelog,
		);

		set_transient($this->getCacheKey(), $release, self::CACHE_TTL);

		return $release;
	}

	/**
	 * @param mixed $upgrader
	 * @param mixed $options
	 */
	public function clearCacheAfterUpgrade($upgrader, $options): void
	{
		if (! is_array($options) || ! isset($options['type'], $options['action'])) {
			return;
		}

		if ('plugin' !== $options['type'] || 'update' !== $options['action']) {
			return;
		}

		$plugins = isset($options['plugins']) && is_array($options['plugins']) ? $options['plugins'] : array();

		if (in_array($this->plugin_basename, $plugins, true)) {
			delete_transient($this->getCacheKey());
		}
	}

	/**
	 * @param array<string, mixed> $release_data
	 */
	private function extractZipAssetUrl(array $release_data): string
	{
		$assets = isset($release_data['assets']) && is_array($release_data['assets']) ? $release_data['assets'] : array();
		$fallback_url = '';

		foreach ($assets as $asset) {
			if (! is_array($asset)) {
				continue;
			}

			$name = isset($asset['name']) ? (string) $asset['name'] : '';
			$url  = isset($asset['browser_download_url']) ? (string) $asset['browser_download_url'] : '';

			if ('' === $url || ! str_ends_with(strtolower($name), '.zip')) {
				continue;
			}

			if ('ar-design-reporting.zip' === strtolower($name)) {
				return $url;
			}

			if ('' === $fallback_url) {
				$fallback_url = $url;
			}
		}

		return $fallback_url;
	}

	private function getCacheKey(): string
	{
		return 'ard_reporting_github_release_data_' . md5($this->repository_full_name);
	}
}
