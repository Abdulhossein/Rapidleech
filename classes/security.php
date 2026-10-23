<?php
/**
 * Security helpers: TOTP 2FA, login lockout, Turnstile captcha.
 * Every feature degrades silently when its prerequisites are missing
 * (no config keys, no extensions, no outbound network) so the script
 * keeps working on shared/cPanel hosting without user-visible errors.
 */
if (!defined('RAPIDLEECH')) {
	require(dirname(__FILE__) . '/../deny.php');
	exit;
}

/**
 * Pure-PHP TOTP (RFC 6238) — no extensions required.
 */
class RL_TOTP {
	public static function base32_decode($b32) {
		$alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
		$b32 = strtoupper(preg_replace('/[^A-Za-z2-7]/', '', $b32));
		if ($b32 === '') return false;
		$bits = '';
		for ($i = 0, $n = strlen($b32); $i < $n; $i++) {
			$pos = strpos($alphabet, $b32[$i]);
			if ($pos === false) return false;
			$bits .= str_pad(decbin($pos), 5, '0', STR_PAD_LEFT);
		}
		$out = '';
		foreach (str_split($bits, 8) as $byte) {
			if (strlen($byte) === 8) $out .= chr(bindec($byte));
		}
		return $out;
	}

	public static function code($secret, $timeSlice = null, $digits = 6, $period = 30) {
		$key = self::base32_decode($secret);
		if ($key === false || $key === '') return false;
		if ($timeSlice === null) $timeSlice = floor(time() / $period);
		$bin = pack('N*', 0) . pack('N*', $timeSlice);
		$hash = hash_hmac('sha1', $bin, $key, true);
		$offset = ord(substr($hash, -1)) & 0x0F;
		$code = ((ord($hash[$offset]) & 0x7F) << 24)
			| ((ord($hash[$offset + 1]) & 0xFF) << 16)
			| ((ord($hash[$offset + 2]) & 0xFF) << 8)
			| (ord($hash[$offset + 3]) & 0xFF);
		return str_pad((string)($code % pow(10, $digits)), $digits, '0', STR_PAD_LEFT);
	}

	/** Verify a code allowing ±1 time-step clock drift. */
	public static function verify($secret, $code) {
		if (!is_string($code) || !preg_match('/^\d{6}$/', trim($code))) return false;
		$code = trim($code);
		$slice = floor(time() / 30);
		foreach (array($slice - 1, $slice, $slice + 1) as $s) {
			$expected = self::code($secret, $s);
			if ($expected !== false && hash_equals($expected, $code)) return true;
		}
		return false;
	}

	/** Generate a random base32 secret (for setup). */
	public static function generate_secret($length = 32) {
		$alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
		$out = '';
		for ($i = 0; $i < $length; $i++) $out .= $alphabet[random_int(0, 31)];
		return $out;
	}
}

/**
 * Flat-file login lockout with exponential backoff.
 * State lives in configs/login_attempts.json; failures are silent.
 */
class RL_Lockout {
	protected static function file() { return CONFIG_DIR . 'login_attempts.json'; }

	public static function check($key) {
		$data = self::load();
		$rec = isset($data[$key]) ? $data[$key] : null;
		if (!$rec || !is_array($rec)) return true;
		$now = time();
		$attempts = isset($rec['attempts']) ? (int)$rec['attempts'] : 0;
		$last = isset($rec['last']) ? (int)$rec['last'] : 0;
		if ($attempts < 3) return true;
		// Exponential backoff: 2^(attempts-3) minutes, capped at 1 hour
		$lockSeconds = min(3600, pow(2, $attempts - 3) * 60);
		return ($now - $last) >= $lockSeconds;
	}

	public static function fail($key) {
		$data = self::load();
		$rec = isset($data[$key]) && is_array($data[$key]) ? $data[$key] : array('attempts' => 0, 'last' => 0);
		$rec['attempts'] = (int)$rec['attempts'] + 1;
		$rec['last'] = time();
		$data[$key] = $rec;
		self::save($data);
	}

	public static function reset($key) {
		$data = self::load();
		if (isset($data[$key])) {
			unset($data[$key]);
			self::save($data);
		}
	}

	/** Housekeeping: drop records older than 24h. */
	public static function gc() {
		$data = self::load();
		$now = time();
		$changed = false;
		foreach ($data as $key => $rec) {
			if (!is_array($rec) || ($now - (int)$rec['last']) > 86400) {
				unset($data[$key]);
				$changed = true;
			}
		}
		if ($changed) self::save($data);
	}

	protected static function load() {
		$content = @file_get_contents(self::file());
		$data = $content ? @json_decode($content, true) : null;
		return is_array($data) ? $data : array();
	}

	protected static function save($data) {
		$fp = @fopen(self::file(), 'c');
		if (!$fp) return;
		@flock($fp, LOCK_EX);
		@ftruncate($fp, 0);
		@fwrite($fp, json_encode($data));
		@flock($fp, LOCK_UN);
		@fclose($fp);
	}
}

/**
 * Cloudflare Turnstile (optional). Inert when keys are not configured
 * or the verification endpoint is unreachable (fails open silently).
 */
class RL_Turnstile {
	public static function enabled() {
		global $options;
		return !empty($options['turnstile_sitekey']) && !empty($options['turnstile_secret']);
	}

	public static function widget() {
		if (!self::enabled()) return '';
		$sitekey = htmlspecialchars($GLOBALS['options']['turnstile_sitekey'], ENT_QUOTES, 'UTF-8');
		$html = '<div class="cf-turnstile" data-sitekey="' . $sitekey . '" data-theme="auto"></div>';
		$html .= '<script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script>';
		return $html;
	}

	/** Verify the turnstile response token. Fails open (returns true) when disabled or unreachable. */
	public static function verify() {
		if (!self::enabled()) return true;
		$token = '';
		if (!empty($_POST['cf-turnstile-response']) && is_string($_POST['cf-turnstile-response'])) {
			$token = $_POST['cf-turnstile-response'];
		}
		if ($token === '') return false;
		$ch = @curl_init('https://challenges.cloudflare.com/turnstile/v0/siteverify');
		if (!$ch) return true; // no curl on host — fail open silently
		@curl_setopt_array($ch, array(
			CURLOPT_POST => true,
			CURLOPT_POSTFIELDS => http_build_query(array(
				'secret' => $GLOBALS['options']['turnstile_secret'],
				'response' => $token,
				'remoteip' => isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '',
			)),
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_TIMEOUT => 5,
			CURLOPT_CONNECTTIMEOUT => 3,
		));
		$body = @curl_exec($ch);
		$errno = curl_errno($ch);
		@curl_close($ch);
		if ($body === false || $errno) return true; // network issue — fail open
		$data = @json_decode($body, true);
		return !empty($data['success']);
	}
}

/**
 * Client IP helper shared by lockout/logging.
 */
function rl_client_ip() {
	$ip = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '0.0.0.0';
	// Behind a trusted reverse proxy, use the first forwarded IP
	if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
		$parts = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
		$candidate = trim($parts[0]);
		if (filter_var($candidate, FILTER_VALIDATE_IP)) $ip = $candidate;
	}
	return $ip;
}
