<?php
declare(strict_types=1);

/**
 * =============================================================================
 * PHPUNIT BOOTSTRAPPER (Test Environment)
 * =============================================================================
 * This file runs once before the entire test suite executes. 
 * Its primary job is to ensure the PHP built-in web server is running on 
 * port 8000, so the integration tests in AllTest.php have something to 
 * send HTTP requests to.
 * =============================================================================
 */

require __DIR__ . '/../src/bootstrap.php';

// Start the PHP built-in server for integration tests
$url = 'http://localhost:8000/health';
$alreadyUp = false;

// 1. Check if a server is already running (e.g., if the dev started one 
// manually in another terminal). This prevents "Address already in use" errors.
try {
    $ctx = stream_context_create(['http' => ['timeout' => 1]]);
    $r = @file_get_contents($url, false, $ctx);
    if ($r !== false) $alreadyUp = true;
} catch (Throwable $e) { /* ignore */ }

// 2. If no server is running, spin one up programmatically.
if (!$alreadyUp) {
    // Use PHP_BINARY to ensure we use the exact same PHP executable running PHPUnit.
    // We point it to router.php (the API entry point).
    $cmd = escapeshellarg(PHP_BINARY) . ' -S localhost:8000 ' . escapeshellarg(__DIR__ . '/../router.php');
    
    // Use sys_get_temp_dir() for cross-platform compatibility (works on Windows, Mac, Linux)
    $logFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'php-server.log';
    
    // Define standard I/O pipes. Output and errors are redirected to a log file.
    $descriptors = [
        0 => ['pipe', 'r'], // stdin
        1 => ['file', $logFile, 'a'], // stdout
        2 => ['file', $logFile, 'a']  // stderr
    ];
    
    // Start the server process
    $proc = proc_open($cmd, $descriptors, $pipes);
    if (!is_resource($proc)) {
        fwrite(STDERR, "Failed to start PHP dev server\n");
        exit(1);
    }
    
    // Ensure the server process is killed when the test suite finishes (or crashes)
    register_shutdown_function(function () use ($proc) { proc_terminate($proc); });

    // 3. Poll the health endpoint until the server is ready to accept connections.
    // We check 50 times, waiting 100ms between each check (total max wait: 5 seconds).
    for ($i = 0; $i < 50; $i++) {
        usleep(100_000); // 100ms
        try {
            $ctx = stream_context_create(['http' => ['timeout' => 1]]);
            if (@file_get_contents($url, false, $ctx) !== false) {
                break; // Server is awake!
            }
        } catch (Throwable $e) { /* keep trying */ }
    }
}