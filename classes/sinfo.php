<?php
if (! isset ( $servername ) || $servername == "") {
	$theservername = $_SERVER ['SERVER_NAME'];
} else {
	$theservername = $servername;
}
if (! isset ( $customos ) || $customos == "") {
	$osname = checkos ();
} else {
	$os = "nocpu";
	$osname = $customos;
}
if (php_sapi_name () == "apache2handler") {
	$httpapp = "Apache";
} else {
	$httpapp = php_sapi_name ();
}
function checkos() {
	if (substr ( PHP_OS, 0, 3 ) == "WIN") {
		$osType = winosname ();
		$osbuild = php_uname ( 'v' );
		$os = "windows";
	} elseif (PHP_OS == "FreeBSD") {
		$os = "nocpu";
		$osType = "FreeBSD";
		$osbuild = php_uname ( 'r' );
	} elseif (PHP_OS == "Darwin") {
		$os = "nocpu";
		$osType = "Apple OS X";
		$osbuild = php_uname ( 'r' );
	} elseif (PHP_OS == "Linux") {
		$os = "linux";
		$osType = "Linux";
		$osbuild = php_uname ( 'r' );
	} else {
		$os = "nocpu";
		$osType = "Unknown OS";
		$osbuild = php_uname ( 'r' );
	}
	return $osType;
}
function winosname() {
	$wUnameB = php_uname('v');
	if (preg_match ('@build (\d+)(?: \(([\w ]+)\))?@i', $wUnameB, $build)) $wUnameB = $build[1];
	if (!empty($build[2])) return $build[2];

	$wUnameBM = trim(php_uname('r'));
	if ($wUnameBM == '5.0' && ($wUnameB == '2195')) {
		$wVer = 'Windows 2000';
	}
	if ($wUnameBM == '5.1' && ($wUnameB == '2600')) {
		$wVer = 'Windows XP';
	}
	if ($wUnameBM == '5.2' && ($wUnameB == '3790')) {
		$wVer = 'Windows Server 2003';
	}
	if ($wUnameBM == '6.0' && ($wUnameB == '6000')) {
		$wVer = 'Windows Vista';
	}
	if ($wUnameBM == '6.0' && ($wUnameB == '6001')) {
		$wVer = 'Windows Vista SP1';
	}
	return $wVer;
}
if (PHP_OS == "WINNT") {
	$os = "windows";
	$osbuild = php_uname ( 'v' );
} elseif (PHP_OS == "Linux") {
	$os = "linux";
	$osbuild = php_uname ( 'r' );
} else {
	$os = "nocpu";
	$osbuild = php_uname ( 'r' );
}
function ZahlenFormatieren($Wert) {
	if ($Wert > 1099511627776) {
		$Wert = number_format ( $Wert / 1099511627776, 2, ".", "," ) . " TB";
	} elseif ($Wert > 1073741824) {
		$Wert = number_format ( $Wert / 1073741824, 2, ".", "," ) . " GB";
	} elseif ($Wert > 1048576) {
		$Wert = number_format ( $Wert / 1048576, 2, ".", "," ) . " MB";
	} elseif ($Wert > 1024) {
		$Wert = number_format ( $Wert / 1024, 2, ".", "," ) . " kB";
	} else {
		$Wert = number_format ( $Wert, 2, ".", "," ) . " Bytes";
	}
	
	return $Wert;
}
function fl_cpu_bar_html($pct) {
	$pct = max(0, min(100, (float)$pct));
	$cls = $pct >= 85 ? 'fl-cpu-high' : ($pct >= 60 ? 'fl-cpu-warn' : 'fl-cpu-ok');
	if (defined('TEMPLATE_DIR') && basename(TEMPLATE_DIR) == 'flavor') {
		return '<div class="fl-bar-track"><div class="fl-bar-fill ' . $cls . '" id="cpubarfill" style="width:' . round($pct, 2) . '%"></div></div>';
	}
	if (extension_loaded('gd') && function_exists('gd_info')) {
		return '<img src="' . CLASS_DIR . 'bar.php?rating=' . round($pct, 2) . '" border="0" name="cpupercent" id="cpupercent" alt="" />';
	}
	return '';
}
function fl_cpu_history_add($pct) {
	$histFile = CONFIG_DIR . 'cpu_history.json';
	$hist = array();
	if (is_file($histFile)) {
		$hist = @json_decode(@file_get_contents($histFile), true);
		if (!is_array($hist)) $hist = array();
	}
	$hist[] = array(round((float)$pct, 2), time());
	while (count($hist) > 30) array_shift($hist);
	$fp = @fopen($histFile, 'c');
	if ($fp) {
		@flock($fp, LOCK_EX);
		@ftruncate($fp, 0);
		@fwrite($fp, json_encode(array_values($hist)));
		@flock($fp, LOCK_UN);
		@fclose($fp);
	}
	return array_values($hist);
}
$frei = disk_free_space ( "./" );
$insgesamt = disk_total_space ( "./" );
$belegt = $insgesamt - $frei;
$prozent_belegt = 100 * $belegt / $insgesamt;
	if ($os == "windows") {
		$cpu_string = lang(136).': ';
		$cpu_load = false;
		// 1) COM/WMI (fastest, when the extension is enabled)
		if (class_exists('COM')) {
			try {
				$wmi = new COM ( "Winmgmts://" );
				$cpus = $wmi->execquery ( "SELECT * FROM Win32_Processor" );
				$cpu_load = 0;
				$count = 0;
				foreach ( $cpus as $cpu ) {
					$cpu_load += $cpu->loadpercentage;
					$count++;
				}
				if ($count > 0) $cpu_load /= $count; else $cpu_load = false;
			} catch ( Exception $e ) {
				$cpu_load = false;
			}
		}
		// 2) PowerShell CIM query (works on modern Windows where wmic was removed)
		if ($cpu_load === false) {
			$ps_out = @shell_exec('powershell -NoProfile -Command "(Get-CimInstance Win32_Processor | Measure-Object -Property LoadPercentage -Average).Average" 2>NUL');
			if ($ps_out !== null && is_numeric(trim($ps_out))) $cpu_load = (float)trim($ps_out);
		}
		// 3) wmic fallback (older Windows)
		if ($cpu_load === false) {
			$wmic_out = @shell_exec('wmic cpu get loadpercentage 2>NUL');
			if ($wmic_out !== null && preg_match('/(\d+)/', $wmic_out, $m)) $cpu_load = (float)$m[1];
		}
		if ($cpu_load !== false) {
			$cpulast = round($cpu_load, 2);
			$cpu_string .= "<span id='cpuload'>" . round ( $cpu_load, "0" ) . "</span>%<br />";
			$cpu_string .= fl_cpu_bar_html($cpu_load) . '<br />';
		} else {
			$cpu_string = lang(136).': ' . lang(137) . '<br />';
		}
	} elseif ($os == "linux") {
		function getStat($_statPath) {
			if (trim ( $_statPath ) == '') {
				$_statPath = '/proc/stat';
			}
			
			ob_start ();
			@readfile($_statPath);
			$stat = ob_get_contents ();
			ob_end_clean ();
			
			if (substr ( $stat, 0, 3 ) == 'cpu') {
				$parts = explode ( " ", preg_replace ( "!cpu +!", "", $stat ) );
			} else {
				return false;
			}
			
			$return = array ();
			$return ['user'] = $parts [0];
			$return ['nice'] = $parts [1];
			$return ['system'] = $parts [2];
			$return ['idle'] = $parts [3];
			return $return;
		}
		
		function getCpuUsage($_statPath = '/proc/stat') {
			$time1 = getStat ( $_statPath );
			if (!$time1) return -1;
			sleep ( 1 );
			$time2 = getStat ( $_statPath );
			
			$delta = array ();
			
			foreach ( $time1 as $k => $v ) {
				$delta [$k] = $time2 [$k] - $v;
			}
			
			$deltaTotal = array_sum ( $delta );
			$percentages = array ();
			
			foreach ( $delta as $k => $v ) {
				$percentages [$k] = @round ( $v / $deltaTotal * 100, 2 );
			}
			return $percentages;
		}
		$cpu_string = '';
		if (($cpu = getCpuUsage()) === -1) { $cpu_string = -1; }
		else {
			$cpulast = 100 - $cpu ['idle'];
			$cpu_string .= lang(136).": <span id='cpuload'>" . round ( $cpulast, "0" ) . "</span>%<br />";
			$cpu_string .= fl_cpu_bar_html($cpulast) . '<br />';
		}
	} elseif ($os == "nocpu") {
		$cpu_string = '';
	} else {
		$cpu_string = lang(136).'<br />';
		$cpu_string .= lang(136).": ".lang(137)."<br />";
	}
require(TEMPLATE_DIR.'/sinfo.php');
?>
