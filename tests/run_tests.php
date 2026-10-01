<?php

declare(strict_types=1);

// Zero-dependency PSR-4 autoloader for running test suite
spl_autoload_register(function (string $class) {
    $prefix = 'EidCloud\\LipsyncAnalyzer\\';
    $baseDir = dirname(__DIR__) . '/src/';

    $len = strlen($prefix);
    if (strncmp($prefix, $class, $len) !== 0) {
        return;
    }

    $relativeClass = substr($class, $len);
    $file = $baseDir . str_replace('\\', '/', $relativeClass) . '.php';

    if (file_exists($file)) {
        require_once $file;
    }
});

require_once __DIR__ . '/LipsyncTest.php';

use EidCloud\LipsyncAnalyzer\Tests\LipsyncTest;

try {
    $suite = new LipsyncTest();
    $suite->runAll();
    exit(0);
} catch (Throwable $e) {
    echo "\n\033[1;31mTest Suite Failed:\033[0m " . $e->getMessage() . "\n";
    echo $e->getTraceAsString() . "\n";
    exit(1);
}
