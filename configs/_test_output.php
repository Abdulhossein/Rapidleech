<?php
define('RAPIDLEECH', 'yes');
ob_start();
require 'C:/xampp/htdocs/Rapidleech/configs/accounts.php';
$out = ob_get_clean();
echo "OUTPUT LENGTH: " . strlen($out) . "\n";
echo "OUTPUT HEX: " . bin2hex(substr($out, 0, 60)) . "\n";
echo "OUTPUT TEXT: " . var_export(substr($out, 0, 100), true) . "\n";
