<?php
if (!defined('RAPIDLEECH')) {
	exit('<a href="/">Home</a>');
}

/**
 * Silent self-heal helpers: disk-fit prediction, retry bookkeeping,
 * stall detection and checksum verification.
 *
 * Every helper degrades silently: if prerequisites are missing
 * (functions disabled, files unwritable, ext not loaded) the feature
 * simply no-ops and never surfaces an error to the user.
 */
class RL_SelfHeal
{
	private static function stateFile()
	{
		return (defined('CONFIG_DIR') ? CONFIG_DIR : 'configs/') . 'selfheal_state.json';
	}

	private static function loadState()
	{
		$f = self::stateFile();
		if (!is_file($f)) {
			return array();
		}
		$d = @json_decode((string) @file_get_contents($f), true);
		return is_array($d) ? $d : array();
	}

	private static function saveState($d)
	{
		$f = self::stateFile();
		$fp = @fopen($f, 'c');
		if (!$fp) {
			return false;
		}
		$ok = false;
		if (@flock($fp, LOCK_EX)) {
			@ftruncate($fp, 0);
			$ok = @fwrite($fp, (string) @json_encode($d)) !== false;
			@flock($fp, LOCK_UN);
		}
		@fclose($fp);
		return $ok;
	}

	private static function enabled()
	{
		global $options;
		return !(isset($options['selfheal_enabled']) && $options['selfheal_enabled'] === false);
	}

	private static function maxRetries()
	{
		global $options;
		$n = isset($options['selfheal_max_retries']) ? (int) $options['selfheal_max_retries'] : 2;
		return max(0, min(5, $n));
	}

	/**
	 * Predict whether the file will fit on disk. Returns true when the
	 * check cannot be performed (silent degradation).
	 */
	public static function diskFit($expectedBytes)
	{
		if (!self::enabled() || !function_exists('disk_free_space')) {
			return true;
		}
		$free = @disk_free_space(defined('DOWNLOAD_DIR') ? DOWNLOAD_DIR : '.');
		if ($free === false || $free === null) {
			return true;
		}
		$need = (float) $expectedBytes * 1.05 + 1048576; // 5% headroom + 1MB
		if ($free < $need) {
			if (function_exists('rl_log')) {
				rl_log('WARN', 'Self-heal: insufficient disk space predicted', array('free' => (int) $free, 'need' => (int) $need));
			}
			return false;
		}
		return true;
	}

	/**
	 * Record a failed attempt for a download key. Returns the attempt
	 * number just recorded, or 0 when bookkeeping is unavailable.
	 */
	public static function recordFailure($key)
	{
		if (!self::enabled()) {
			return 0;
		}
		$state = self::loadState();
		$now = time();
		// Garbage collect entries older than 1 hour.
		foreach ($state as $k => $v) {
			if (!is_array($v) || (isset($v['t']) && $now - (int) $v['t'] > 3600)) {
				unset($state[$k]);
			}
		}
		$cur = isset($state[$key]['n']) ? (int) $state[$key]['n'] : 0;
		$state[$key] = array('n' => $cur + 1, 't' => $now);
		self::saveState($state);
		return $cur + 1;
	}

	/** Clear failure bookkeeping for a key (call on success). */
	public static function clearFailures($key)
	{
		$state = self::loadState();
		if (isset($state[$key])) {
			unset($state[$key]);
			self::saveState($state);
		}
	}

	/** Attempts already recorded for a key. */
	public static function attempts($key)
	{
		$state = self::loadState();
		return isset($state[$key]['n']) ? (int) $state[$key]['n'] : 0;
	}

	/**
	 * Decide whether a retry is allowed for a failed download key.
	 * Returns false when retries are exhausted or bookkeeping failed.
	 */
	public static function shouldRetry($key)
	{
		if (!self::enabled()) {
			return false;
		}
		$n = self::recordFailure($key);
		return $n <= self::maxRetries();
	}

	/**
	 * Verify a completed file against an expected SHA-256 checksum.
	 * Returns true when verification is disabled, unavailable or passes.
	 */
	public static function verifyChecksum($file, $expectedSha256)
	{
		global $options;
		if (empty($options['selfheal_verify_checksum']) || empty($expectedSha256)) {
			return true;
		}
		if (!function_exists('hash_file') || !is_file($file)) {
			return true; // silent skip
		}
		$got = @hash_file('sha256', $file);
		if ($got === false) {
			return true;
		}
		$ok = hash_equals(strtolower($expectedSha256), strtolower($got));
		if (!$ok && function_exists('rl_log')) {
			rl_log('WARN', 'Self-heal: checksum mismatch', array('file' => basename($file)));
		}
		return $ok;
	}

	/**
	 * Detect stalled downloads by comparing tracker progress against
	 * meta/chunk file mtimes. Returns array of stalled download ids.
	 */
	public static function detectStalled()
	{
		if (!self::enabled() || !function_exists('get_active_downloads')) {
			return array();
		}
		$stalled = array();
		$active = get_active_downloads();
		if (!is_array($active)) {
			return array();
		}
		$now = time();
		$dir = defined('DOWNLOAD_DIR') ? DOWNLOAD_DIR : 'files/';
		foreach ($active as $id => $d) {
			if (!is_array($d) || empty($d['filename'])) {
				continue;
			}
			$latest = 0;
			foreach (array($dir . '.' . $d['filename'] . '.meta') as $mf) {
				$mt = @filemtime($mf);
				if ($mt !== false && $mt > $latest) {
					$latest = $mt;
				}
			}
			foreach ((array) @glob($dir . '.chunk_*.tmp') as $cf) {
				$mt = @filemtime($cf);
				if ($mt !== false && $mt > $latest) {
					$latest = $mt;
				}
			}
			$started = isset($d['start_time']) ? (int) $d['start_time'] : 0;
			// Stalled: no file activity for 120s while download is young (< 10 min)
			if ($latest > 0 && $now - $latest > 120 && $now - $started < 600) {
				$stalled[] = $id;
			}
		}
		return $stalled;
	}

	/**
	 * Delete orphaned chunk/meta temp files left by crashed downloads.
	 * Files newer than 2 hours are always spared (active downloads touch
	 * them continuously). Silent on any failure.
	 */
	public static function cleanupOrphans()
	{
		if (!self::enabled()) {
			return 0;
		}
		$dir = defined('DOWNLOAD_DIR') ? DOWNLOAD_DIR : 'files/';
		if (!is_dir($dir)) {
			return 0;
		}
		$cut = time() - 7200;
		$removed = 0;
		foreach ((array) @glob($dir . '.chunk_*') as $f) {
			$mt = @filemtime($f);
			if ($mt !== false && $mt < $cut && @unlink($f)) {
				$removed++;
			}
		}
		return $removed;
	}
}
