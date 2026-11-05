<?php
declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';

/**
 * =============================================================================
 * FRONT CONTROLLER (index.php)
 * =============================================================================
 * Acts as the entry point for all requests. 
 * Serves the SPA shell, handles health checks, and routes API requests.
 * 
 * [UPDATED] Added `json_error()` helper to ensure even fatal errors and 
 * 404s return the rich structured display payload expected by app.js.
 * =============================================================================
 */

/**
 * Helper to output a structured error payload for the frontend.
 * Matches the exact format of display_error() in controllers.php.
 */
function json_error(int $status, string $message): void
{
    $meta = [
        400 => ['title' => 'Invalid Input',     'severity' => 'warning',  'icon' => '⚠️'],
        401 => ['title' => 'Login Required',    'severity' => 'warning',  'icon' => '🔒'],
        403 => ['title' => 'Access Denied',     'severity' => 'error',    'icon' => '⛔'],
        404 => ['title' => 'Not Found',         'severity' => 'info',     'icon' => '🔍'],
        500 => ['title' => 'Server Error',      'severity' => 'critical', 'icon' => '💥'],
    ];
    $m = $meta[$status] ?? ['title' => 'Error', 'severity' => 'error', 'icon' => '❌'];
    $safeMessage = htmlspecialchars($message, ENT_QUOTES, 'UTF-8');

    http_response_code($status);
    header('Content-Type: application/json');
    
    echo json_encode([
        'error'    => $message,
        'message'  => $message,
        'title'    => $m['title'],
        'severity' => $m['severity'],
        'icon'     => $m['icon'],
        'status'   => $status,
        'display'  => true,
        'html'     =>
            '<div class="error-alert" data-severity="' . $m['severity'] . '" data-status="' . $status . '" role="alert">'
          .   '<span class="error-alert__icon" aria-hidden="true">' . $m['icon'] . '</span>'
          .   '<div class="error-alert__body">'
          .     '<strong class="error-alert__title">' . $m['title'] . '</strong>'
          .     '<p class="error-alert__message">' . $safeMessage . '</p>'
          .   '</div>'
          . '</div>',
    ]);
    exit;
}

$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

// =============================================================================
// 1. SPA SHELL
// =============================================================================
if ($uri === '/' || $uri === '/index.html') {
    header('Content-Type: text/html; charset=utf-8');
    readfile(__DIR__ . '/index.html');
    exit;
}

// =============================================================================
// 2. HEALTH CHECK (For CI/CD & Load Balancers)
// =============================================================================
if ($uri === '/health') {
    header('Content-Type: application/json');
    echo json_encode(['ok' => true]);
    exit;
}

// =============================================================================
// 3. ROUTE GUARDING
// =============================================================================
// Only /api/* goes to the router
if (strpos($uri, '/api/') !== 0) {
    // [UPDATED] Use the structured error format
    json_error(404, 'The requested URL was not found on this server.');
}

$apiPath = substr($uri, 4); // strip '/api'
if ($apiPath === '') $apiPath = '/';

// =============================================================================
// 4. API DISPATCH & FATAL ERROR HANDLING
// =============================================================================
try {
    // Delegate to the Boundary layer
    (require __DIR__ . '/../src/bce/boundary.php')->dispatch(
        $_SERVER['REQUEST_METHOD'],
        $apiPath
    );
} catch (Throwable $e) {
    // Log the actual error for developers
    error_log('[FATAL] ' . $e->getMessage() . "\n" . $e->getTraceAsString());
    
    // [UPDATED] Return the structured error format to the client
    // so the frontend can render a beautiful "Server Error" banner.
    json_error(500, 'An unexpected system error occurred. Please try again later.');
}