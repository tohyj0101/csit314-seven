<?php
declare(strict_types=1);

/**
 * =============================================================================
 * APPLICATION BOOTSTRAPPER
 * =============================================================================
 * This file is the "glue" that ties the entire backend together. It is 
 * required by `index.php` at the very start of every request.
 * 
 * It handles:
 * 1. Loading all core classes (Config, Router, Middleware, BCE, Controllers).
 * 2. Loading environment variables from `.env`.
 * 3. Configuring error reporting (hiding errors from the UI to prevent leaks).
 * 4. Configuring and starting the secure PHP Session.
 * =============================================================================
 */

// 1. Load Configurations & Core Classes
// Order matters here: env/db must load before controllers try to use them.
require_once __DIR__ . '/config/env.php';
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/Router.php';
require_once __DIR__ . '/middleware/authGuard.php';
require_once __DIR__ . '/middleware/roleGuard.php';
require_once __DIR__ . '/bce/entities.php';
require_once __DIR__ . '/bce/controllers.php'; // <- Loads display_error()
require_once __DIR__ . '/bce/boundary.php';

// 2. Load Environment Variables
load_env(__DIR__ . '/../.env');

// 3. Error Handling Configuration
// We set display_errors to 0 so PHP doesn't leak file paths or database 
// credentials to the browser if something goes wrong. We log them instead 
// using error_log(), which is caught by index.php's try/catch block.
ini_set('display_errors', '0');
error_reporting(E_ALL);

// 4. Secure Session Configuration
// This mirrors the security settings of an Express.js (Node) application.
if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'httponly' => true,  // Prevents JavaScript (XSS) from stealing the session cookie
        'samesite' => 'Lax', // Helps prevent CSRF attacks by restricting cross-site requests
        'path'     => '/',   // Cookie is available across the whole domain
        // Note: 'secure' => true should be added in production if using HTTPS
    ]);
    session_name('PHPSESSID');
    session_start();
}