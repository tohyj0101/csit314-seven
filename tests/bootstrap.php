<?php
declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

// Start the PHP built-in server for integration tests
$url = 'http://localhost:8000/health';
$alreadyUp = false;
try {
    $ctx = stream_context_create(['http' => ['timeout' => 1]]);
    $r = @file_get_contents($url, false, $ctx);
    if ($r !== false) $alreadyUp = true;
} catch (Throwable $e) { /* ignore */ }

if (!$alreadyUp) {
    // Use PHP_BINARY to ensure we use the correct PHP executable
    $cmd = escapeshellarg(PHP_BINARY) . ' -S localhost:8000 ' . escapeshellarg(__DIR__ . '/../router.php');
    
    // Use sys_get_temp_dir() for Windows compatibility
    $logFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'php-server.log';
    
    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['file', $logFile, 'a'],
        2 => ['file', $logFile, 'a']
    ];
    
    $proc = proc_open($cmd, $descriptors, $pipes);
    if (!is_resource($proc)) {
        fwrite(STDERR, "Failed to start PHP dev server\n");
        exit(1);
    }
    register_shutdown_function(function () use ($proc) { proc_terminate($proc); });

    // Wait for server to be ready
    for ($i = 0; $i < 50; $i++) {
        usleep(100_000); // 100ms
        try {
            $ctx = stream_context_create(['http' => ['timeout' => 1]]);
            if (@file_get_contents($url, false, $ctx) !== false) {
                break;
            }
        } catch (Throwable $e) { /* keep trying */ }
    }
}