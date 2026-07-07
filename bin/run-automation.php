#!/usr/bin/env php
<?php
declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

$args = $argv;
array_shift($args);

if (in_array('--help', $args, true)) {
    echo "Usage:\n";
    echo "  php bin/run-automation.php --run\n";
    echo "  php bin/run-automation.php --import=/path/to/targets.txt\n";
    exit(0);
}

foreach ($args as $arg) {
    if (str_starts_with($arg, '--import=')) {
        $path = substr($arg, strlen('--import='));
        $count = import_targets_from_file($path);
        echo "Imported {$count} target(s)\n";
        exit(0);
    }
}

if (!$args || in_array('--run', $args, true)) {
    set_time_limit(0);
    $run = run_automation('timer');
    echo 'Run ' . ($run['token'] ?? $run['id']) . ': ' . ($run['summary'] ?? 'complete') . "\n";
    exit(0);
}

fwrite(STDERR, "Unknown arguments. Use --help.\n");
exit(1);
