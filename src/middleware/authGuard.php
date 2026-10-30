<?php
declare(strict_types=1);

/**
 * Returns a closure suitable for the Router's middleware array.
 * Mirrors Node's authGuard.js.
 */
function auth_guard(): callable
{
    return function (): bool {
        if (session_user()) return true;
        json_out(401, ['error' => 'Not authenticated']);
        return false;
    };
}