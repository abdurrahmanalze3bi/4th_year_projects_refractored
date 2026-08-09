<?php
echo "Patching Octane for Windows...\n";

$patches = [
    [
        'file' => 'vendor/laravel/octane/src/PosixExtension.php',
        'find' => 'return posix_kill($processId, $signal);',
        'replace' => 'if (function_exists(\'posix_kill\')) { return posix_kill($processId, $signal); }
        if ($signal === 0) { exec("tasklist /FI \"PID eq {$processId}\" 2>NUL", $out); return count($out) > 2; }
        exec("taskkill /PID {$processId} /F 2>NUL"); return true;',
    ],
];

foreach ($patches as $p) {
    $c = file_get_contents($p['file']);
    if (strpos($c, $p['find']) !== false) {
        file_put_contents($p['file'], str_replace($p['find'], $p['replace'], $c));
        echo "Patched: {$p['file']}\n";
    } else {
        echo "Already patched: {$p['file']}\n";
    }
}
echo "Done.\n";
