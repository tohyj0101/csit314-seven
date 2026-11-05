<?php
declare(strict_types=1);

/**
 * =============================================================================
 * ENVIRONMENT VARIABLE LOADER
 * =============================================================================
 * Parses a standard `.env` file and loads its contents into the PHP 
 * environment (`putenv()` and `$_ENV`).
 * 
 * Why this exists: 
 * Allows the app to run in different environments (Local, CI/CD, Production) 
 * without hardcoding database credentials into the codebase. 
 * 
 * Example .env file:
 *   DB_HOST=localhost
 *   DB_NAME=csit314_seven
 *   DB_USER=postgres
 *   DB_PASSWORD=secret
 * =============================================================================
 */

function load_env(string $file): void
{
    // Gracefully exit if the .env file doesn't exist (e.g., in production where 
    // environment variables are injected directly by the server).
    if (!file_exists($file)) return;

    // Read the file line by line, ignoring empty lines
    foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);

        // Skip comments (lines starting with #) or lines without an equals sign
        if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) continue;

        // Split into Key and Value on the FIRST equals sign only
        [$k, $v] = explode('=', $line, 2);
        
        $k = trim($k);
        // Strip surrounding whitespace and quotes from the value
        $v = trim($v, " \t\"'");

        // Only set the variable if it hasn't already been set by the system. 
        // This allows real server environments (like Docker or CI) to 
        // override the local .env file.
        if (getenv($k) === false) {
            putenv("$k=$v");    // Make available via getenv()
            $_ENV[$k] = $v;     // Make available via $_ENV superglobal
        }
    }
}