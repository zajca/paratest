--TEST--
Flaky phpt fixture - passes on second run
--FILE--
<?php
$counterFile = getenv('PARATEST_RETRY_PHPT_COUNTER_FILE');
$count = ($counterFile !== false && is_file($counterFile)) ? (int) file_get_contents($counterFile) : 0;
if ($counterFile !== false) {
    file_put_contents($counterFile, (string) ($count + 1));
}

if ($count < 1) {
    echo "FAIL";
} else {
    echo "OK";
}
?>
--EXPECT--
OK
