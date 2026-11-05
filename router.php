<?php
/**
 * =============================================================================
 * PHP BUILT-IN DEVELOPMENT SERVER ROUTER
 * =============================================================================
 * This script is ONLY used during local development when you run:
 *   php -S localhost:8000 router.php
 * 
 * It mimics the behavior of a production web server (like Nginx or Apache):
 * 1. Serves static files (CSS, JS, images) directly from the `public/` folder.
 * 2. Routes all other requests to the front controller (`public/index.php`).
 * 
 * Without this file, the PHP built-in server would try to execute `app.js` 
 * as a PHP script, or fail to load your CSS files.
 * =============================================================================
 */

// 1. Extract the requested URL path (e.g., "/css/style.css" or "/api/users")
$url = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

// 2. Map the URL to a physical file inside the `public/` directory
$file = __DIR__ . '/public' . $url;

// 3. Serve Static Files
// We check that:
// - The URL is not just the root ("/")
// - The file actually exists (e.g., `public/css/style.css` is real)
// - It is a file, not a directory
if ($url !== '/' && file_exists($file) && !is_dir($file)) {
    
    // PHP's built-in server sometimes struggles with MIME types for static files.
    // We explicitly define them so the browser knows how to render CSS/JS.
    $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
    $mimes = [
        'css'   => 'text/css',
        'js'    => 'application/javascript',
        'html'  => 'text/html',
        'png'   => 'image/png',
        'jpg'   => 'image/jpeg',
        'jpeg'  => 'image/jpeg',
        'gif'   => 'image/gif',
        'svg'   => 'image/svg+xml',
        'ico'   => 'image/x-icon'
    ];
    
    // Set the correct Content-Type header
    if (isset($mimes[$ext])) {
        header('Content-Type: ' . $mimes[$ext]);
    }
    
    // Output the file contents and stop execution (bypasses index.php)
    readfile($file);
    exit;
}

// =============================================================================
// 4. FALLBACK TO FRONT CONTROLLER
// =============================================================================
// If the request wasn't a static file (e.g., it was "/api/auth/login" or "/"),
// we forward it to the main application entry point.
require __DIR__ . '/public/index.php';