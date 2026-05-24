<?php
echo "<pre>";
echo "MYSQLHOST: " . getenv('MYSQLHOST') . "\n";
echo "MYSQLPORT: " . getenv('MYSQLPORT') . "\n";
echo "MYSQLDATABASE: " . getenv('MYSQLDATABASE') . "\n";
echo "MYSQLUSER: " . getenv('MYSQLUSER') . "\n";
echo "MYSQLPASSWORD: " . (getenv('MYSQLPASSWORD') ? '***set***' : 'NOT SET') . "\n";
echo "\nAll env vars:\n";
foreach ($_ENV as $k => $v) {
    if (stripos($k, 'mysql') !== false || stripos($k, 'db') !== false) {
        echo "$k = $v\n";
    }
}
echo "</pre>";
