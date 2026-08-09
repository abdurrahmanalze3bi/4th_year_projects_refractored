<?php
$file = 'vendor/bin/roadrunner-worker';
$c = file_get_contents($file);

// Inject APP_BASE_PATH before the first require
$inject = <<<'PHP'

// Windows fix: RoadRunner doesn't pass APP_BASE_PATH env to worker
if (empty($_ENV['APP_BASE_PATH']) && empty($_SERVER['APP_BASE_PATH'])) {
    $_ENV['APP_BASE_PATH'] = dirname(dirname(__DIR__));
    $_SERVER['APP_BASE_PATH'] = $_ENV['APP_BASE_PATH'];
}

PHP;

$c = str_replace(
    "require __DIR__.'/../laravel/octane/fixes/fix-symfony-dd.php';",
    $inject . "require __DIR__.'/../laravel/octane/fixes/fix-symfony-dd.php';",
    $c
);

file_put_contents($file, $c);
echo "Patched. APP_BASE_PATH will resolve to: " . dirname(dirname(realpath('vendor/bin'))) . "\n";
