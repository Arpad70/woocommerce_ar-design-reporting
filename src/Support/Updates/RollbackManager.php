<?php

declare(strict_types=1);

namespace ArDesign\Reporting\Support\Updates;

final class RollbackManager
{
	private const BACKUP_DIR = 'ard-reporting-backups';

	private string $plugin_basename;

	private string $plugin_root;

	private bool $backup_created = false;

	public function __construct(string $plugin_basename, string $plugin_root)
	{
		$this->plugin_basename = $plugin_basename;
		$this->plugin_root     = untrailingslashit($plugin_root);
	}

	public function register(): void
	{
		add_filter('upgrader_pre_install', array($this, 'createBackupBeforeInstall'), 10, 2);
		add_filter('upgrader_install_package_result', array($this, 'rollbackOnInstallFailure'), 10, 2);
	}

	/**
	 * @param mixed $response
	 * @param mixed $hook_extra
	 * @return mixed
	 */
	public function createBackupBeforeInstall($response, $hook_extra)
	{
		if (! $this->isCurrentPluginUpdate($hook_extra)) {
			return $response;
		}

		if (! $this->prepareFilesystem()) {
			return $response;
		}

		$backup_target = $this->getBackupPath();
		$this->removeDirectory($backup_target);

		if (! wp_mkdir_p(dirname($backup_target))) {
			return $response;
		}

		if (! $this->copyDirectory($this->plugin_root, $backup_target)) {
			return $response;
		}

		$this->backup_created = true;

		return $response;
	}

	/**
	 * @param mixed $result
	 * @param mixed $hook_extra
	 * @return mixed
	 */
	public function rollbackOnInstallFailure($result, $hook_extra)
	{
		if (! $this->isCurrentPluginUpdate($hook_extra) || ! $this->backup_created) {
			return $result;
		}

		if (! is_wp_error($result)) {
			return $result;
		}

		if (! $this->prepareFilesystem()) {
			return $result;
		}

		$backup_target = $this->getBackupPath();

		if (! is_dir($backup_target)) {
			return $result;
		}

		$this->removeDirectory($this->plugin_root);

		if (! $this->copyDirectory($backup_target, $this->plugin_root)) {
			return $result;
		}

		return new \WP_Error(
			'ard_reporting_rollback_performed',
			__(
				'Aktualizácia AR Design Reporting zlyhala. Predchádzajúca verzia bola automaticky obnovená zo zálohy.',
				'ar-design-reporting'
			)
		);
	}

	/**
	 * @param mixed $hook_extra
	 */
	private function isCurrentPluginUpdate($hook_extra): bool
	{
		if (! is_array($hook_extra)) {
			return false;
		}

		if ('plugin' !== ($hook_extra['type'] ?? '')) {
			return false;
		}

		if ('update' !== ($hook_extra['action'] ?? '')) {
			return false;
		}

		$plugins = isset($hook_extra['plugins']) && is_array($hook_extra['plugins']) ? $hook_extra['plugins'] : array();

		return in_array($this->plugin_basename, $plugins, true);
	}

	private function prepareFilesystem(): bool
	{
		require_once ABSPATH . 'wp-admin/includes/file.php';

		if (! WP_Filesystem()) {
			return false;
		}

		return true;
	}

	private function getBackupPath(): string
	{
		$uploads = wp_upload_dir();
		$base    = isset($uploads['basedir']) ? (string) $uploads['basedir'] : WP_CONTENT_DIR . '/uploads';

		return untrailingslashit($base) . '/' . self::BACKUP_DIR . '/latest';
	}

	private function copyDirectory(string $source, string $destination): bool
	{
		require_once ABSPATH . 'wp-admin/includes/file.php';

		$result = copy_dir($source, $destination);

		return ! is_wp_error($result);
	}

	private function removeDirectory(string $path): void
	{
		if (! is_dir($path)) {
			return;
		}

		$items = scandir($path);

		if (! is_array($items)) {
			return;
		}

		foreach ($items as $item) {
			if ('.' === $item || '..' === $item) {
				continue;
			}

			$target = $path . DIRECTORY_SEPARATOR . $item;

			if (is_dir($target)) {
				$this->removeDirectory($target);
				continue;
			}

			@unlink($target);
		}

		@rmdir($path);
	}
}
