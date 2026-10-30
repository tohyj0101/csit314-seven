<?php
declare(strict_types=1);

require_once __DIR__ . '/config/env.php';
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/Router.php';
require_once __DIR__ . '/middleware/authGuard.php';
require_once __DIR__ . '/middleware/roleGuard.php';
require_once __DIR__ . '/bce/entities.php';
require_once __DIR__ . '/bce/controllers.php';
require_once __DIR__ . '/bce/boundary.php';

load_env(__DIR__ . '/../.env');

// JSON error responses
ini_set('display_errors', '0');
error_reporting(E_ALL);

// Session with SameSite=Lax + HttpOnly (mirrors express-session config)
if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'httponly' => true,
        'samesite' => 'Lax',
        'path'     => '/',
    ]);
    session_name('PHPSESSID');
    session_start();
}