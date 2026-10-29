<?php

error_reporting(E_ALL);

// Plugin files refuse to run outside Discuz! (direct HTTP requests); the tests stand in for it.
define('IN_DISCUZ', true);

$GLOBALS['mb_tests'] = array();

function mb_test($name, $callback)
{
    $GLOBALS['mb_tests'][] = array($name, $callback);
}

function mb_assert($condition, $message)
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function mb_assert_same($expected, $actual, $message)
{
    if ($expected !== $actual) {
        throw new RuntimeException($message . "\nExpected: " . var_export($expected, true) . "\nActual: " . var_export($actual, true));
    }
}

function mb_assert_throws($callback, $className)
{
    try {
        $callback();
    } catch (Throwable $error) {
        if ($error instanceof $className) {
            return $error;
        }
        throw new RuntimeException('Expected ' . $className . ', got ' . get_class($error) . ': ' . $error->getMessage());
    }

    throw new RuntimeException('Expected ' . $className . ' to be thrown');
}

// MomoBird_Foo lives in momobird_ai/lib/Foo.php; autoloading keeps interfaces
// loaded before the classes that implement them.
spl_autoload_register(function ($class) {
    if (strpos($class, 'MomoBird_') !== 0) {
        return;
    }
    $file = dirname(__DIR__) . '/momobird_ai/lib/' . substr($class, 9) . '.php';
    if (is_file($file)) {
        require_once $file;
    }
});
