<?php
declare(strict_types=1);

/**
 * =============================================================================
 * PASSWORD HASHING & VERIFICATION
 * =============================================================================
 * Wraps PHP's native password functions.
 * 
 * WHY THIS EXISTS:
 * The comments indicate this was built for compatibility with a Node.js 
 * backend (bcryptjs). PHP's `password_hash()` and Node's `bcryptjs` both 
 * produce hashes with the same `$2y$` or `$2a$` prefix, meaning a password 
 * hashed in Node.js can be verified in PHP, and vice versa.
 * =============================================================================
 */

/**
 * Hashes a plaintext password using Bcrypt.
 * 
 * Cost 10 matches the default cost used by Node's bcryptjs.
 * Higher cost = more secure, but slower to calculate.
 */
function hash_password(string $plain): string
{
    return password_hash($plain, PASSWORD_BCRYPT, ['cost' => 10]);
}

/**
 * Verifies a plaintext password against a stored hash.
 * 
 * Handles timing attacks safely. It extracts the salt and algorithm from 
 * the provided hash and re-hashes the plain text to see if they match.
 */
function verify_password(string $plain, string $hash): bool
{
    return password_verify($plain, $hash);
}

/**
 * =============================================================================
 * SESSION HELPERS
 * =============================================================================
 */

/**
 * Starts an authenticated session for the given user (already shaped as
 * account_id / username / role / permissions) and returns it.
 */
function session_login(array $user): array
{
    session_regenerate_id(true); // prevents session fixation
    $_SESSION['user'] = $user;
    return $_SESSION['user'];
}

/**
 * Ends the current session and expires its cookie.
 */
function session_logout(): void
{
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
}