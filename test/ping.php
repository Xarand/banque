<?php
echo "OK ping.php\n";
echo "__FILE__ = ", __FILE__, "\n";
echo "SCRIPT_FILENAME = ", ($_SERVER['SCRIPT_FILENAME'] ?? ''), "\n";