<?php
declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';

$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

// SPA shell
if ($uri === '/' || $uri === '/index.html') {
    header('Content-Type: text/html; charset=utf-8');
    readfile(__DIR__ . '/index.html');
    exit;
}

// Health check
if ($uri === '/health') {
    header('Content-Type: application/json');
    echo json_encode(['ok' => true]);
    exit;
}

// Only /api/* goes to the router
if (strpos($uri, '/api/') !== 0) {
    http_response_code(404);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Not found']);
    exit;
}

$apiPath = substr($uri, 4); // strip '/api'
if ($apiPath === '') $apiPath = '/';

try {
    (require __DIR__ . '/../src/bce/boundary.php')->dispatch(
        $_SERVER['REQUEST_METHOD'],
        $apiPath
    );
} catch (Throwable $e) {
    error_log('[FATAL] ' . $e->getMessage() . "\n" . $e->getTraceAsString());
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'An unexpected system error occurred. Please try again later.']);
}