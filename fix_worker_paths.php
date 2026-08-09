<?php
$file = 'vendor/bin/roadrunner-worker';
$c = file_get_contents($file);

// Add error suppression + disable WAMP OPcache output
$c = str_replace("<?php\n", "<?php\nini_set('display_errors', 'stderr');\nini_set('opcache.enable', '0');\nerror_reporting(E_ERROR);\n", $c);

// Fix broken paths (__DIR__ is vendor/bin, not vendor/laravel/octane/bin)
$c = str_replace(
    "__DIR__.'/../fixes/fix-symfony-dd.php'",
    "__DIR__.'/../laravel/octane/fixes/fix-symfony-dd.php'",
    $c
);
$c = str_replace(
    "__DIR__.'/bootstrap.php'",
    "__DIR__.'/../laravel/octane/bin/bootstrap.php'",
    $c
);

file_put_contents($file, $c);
echo "Patched. Verifying paths:\n";
preg_match_all("/require[^;]+;/", $c, $m);
foreach($m[0] as $r) echo $r . "\n";
