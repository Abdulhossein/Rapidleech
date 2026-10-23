<?php
if (!defined('RAPIDLEECH')) {
	exit('<a href="/">RapidLeech</a>');
}

/**
 * Download deduplication: concurrent requests for the same link join the
 * first (leader) download instead of re-downloading the file.
 *
 * Silent by design: any failure falls through to a normal download.
 */
class RL_Dedup
{
	/** Max seconds a joiner waits for the leader before downloading on its own. */
	const JOIN_TIMEOUT = 900;

	/**
	 * Normalize a link for comparison: scheme-insensitive, strip credentials,
	 * query and fragment, lowercase host, collapse duplicate slashes in path.
	 */
	public static function normalizeLink($link)
	{
		$link = trim((string)$link);
		if ($link === '') {
			return '';
		}
		$u = @parse_url($link);
		if (!is_array($u) || empty($u['host'])) {
			return md5($link);
		}
		$host = strtolower($u['host']);
		$path = isset($u['path']) ? preg_replace('#/{2,}#', '/', $u['path']) : '';
		// Trailing slash is not significant for file paths
		$path = ($path !== '/' && $path !== '') ? rtrim($path, '/') : $path;
		return md5($host . '|' . $path);
	}

	/**
	 * Find an active download of the same link (excluding our own id).
	 * Returns the tracker entry or null.
	 */
	public static function findActive($link, $excludeId = '')
	{
		if (!function_exists('get_active_downloads')) {
			return null;
		}
		$norm = self::normalizeLink($link);
		if ($norm === '') {
			return null;
		}
		$active = get_active_downloads();
		if (!is_array($active)) {
			return null;
		}
		foreach ($active as $id => $entry) {
			if ($id === $excludeId || !is_array($entry) || empty($entry['link'])) {
				continue;
			}
			if (self::normalizeLink($entry['link']) === $norm) {
				return $entry;
			}
		}
		return null;
	}

	/**
	 * Wait for the leader download to finish. Returns:
	 *   array('status' => 'lost')   (leader left the tracker)
	 *   array('status' => 'failed')
	 *   array('status' => 'timeout')
	 *
	 * Note: the tracker signals completion by removing the entry
	 * (complete_download unsets it), so 'lost' covers both success and death.
	 */
	public static function waitForLeader($leaderId, $deadline)
	{
		if (!function_exists('get_active_downloads')) {
			return array('status' => 'timeout');
		}
		while (microtime(true) < $deadline) {
			$active = get_active_downloads();
			if (!is_array($active) || !isset($active[$leaderId])) {
				// Leader left the tracker: either finished or died.
				return array('status' => 'lost');
			}
			$entry = $active[$leaderId];
			if (!empty($entry['status']) && $entry['status'] === 'failed') {
				return array('leader' => $entry, 'status' => 'failed');
			}
			usleep(500000);
		}
		return array('status' => 'timeout');
	}

	/**
	 * Try to attach the joiner to a finished leader download.
	 * Locates the finished file (tracker filename first, then files.lst by
	 * link) and verifies it exists. Does NOT write files.lst — the caller
	 * registers the file under the joiner's own owner token.
	 * Returns array('file' => path, 'size' => human size) or false.
	 */
	public static function attachToLeader($leader, $link)
	{
		if (!is_array($leader) || !function_exists('bytesToKbOrMbOrGb')) {
			return false;
		}
		$dir = defined('DOWNLOAD_DIR') ? DOWNLOAD_DIR : 'files/';

		// 1. Tracker-registered filename
		if (!empty($leader['filename'])) {
			$path = $dir . basename($leader['filename']);
			$size = self::usableFile($path);
			if ($size !== false) {
				return array('file' => $path, 'size' => bytesToKbOrMbOrGb($size));
			}
		}

		// 2. files.lst fallback (final name may differ after extension fix)
		$norm = self::normalizeLink($link);
		if ($norm === '' || !is_file(CONFIG_DIR . 'files.lst')) {
			return false;
		}
		$lines = @file(CONFIG_DIR . 'files.lst', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
		if (!is_array($lines)) {
			return false;
		}
		$best = false;
		foreach ($lines as $line) {
			$entry = @unserialize($line);
			if (!is_array($entry) || empty($entry['name']) || empty($entry['link'])) {
				continue;
			}
			if (self::normalizeLink($entry['link']) !== $norm) {
				continue;
			}
			$size = self::usableFile($entry['name']);
			if ($size !== false && ($best === false || (int)$entry['date'] > $best['date'])) {
				$best = array('file' => $entry['name'], 'size' => bytesToKbOrMbOrGb($size), 'date' => (int)$entry['date']);
			}
		}
		return $best !== false ? array('file' => $best['file'], 'size' => $best['size']) : false;
	}

	/** Return file size if the path is an existing, readable, non-empty file. */
	private static function usableFile($path)
	{
		if (!is_string($path) || $path === '' || !is_file($path)) {
			return false;
		}
		$size = @filesize($path);
		return ($size === false || $size <= 0) ? false : $size;
	}
}
