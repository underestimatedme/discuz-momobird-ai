<?php

require __DIR__ . '/bootstrap.php';

$filters = array_slice($argv, 1);
$files = glob(__DIR__ . '/*Test.php');
sort($files);

foreach ($files as $file) {
    $base = basename($file, '.php');
    if ($filters && !in_array($base, $filters, true)) {
        continue;
    }
    require $file;
}

$passed = 0;
$failed = 0;

foreach ($GLOBALS['mb_tests'] as $test) {
    try {
        call_user_func($test[1]);
        echo "PASS: {$test[0]}\n";
        $passed++;
    } catch (Throwable $error) {
        echo "FAIL: {$test[0]}\n";
        echo '  ' . get_class($error) . ': ' . $error->getMessage() . "\n";
        $failed++;
    }
}

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed > 0 ? 1 : 0);
