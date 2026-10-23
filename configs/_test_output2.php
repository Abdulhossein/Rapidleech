<?php
define('RAPIDLEECH', 'yes');
ob_start();
require 'C:/xampp/htdocs/Rapidleech/configs/setup.php';
$out = ob_get_clean();
echo "SETUP OUTPUT LENGTH: " . strlen($out) . "\n";
echo "SETUP OUTPUT HEX: " . bin2hex($out) . "\n";
