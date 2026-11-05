<?php
declare(strict_types=1);

/**
 * =============================================================================
 * ROLE-BASED ACCESS CONTROL (RBAC) MIDDLEWARE
 * =============================================================================
 * Returns a closure that restricts access to specific User Roles.
 * 
 * PURPOSE:
 * Used on routes that only certain profiles should access. For example, 
 * only a 'User Admin' should be able to hit the Create User endpoint. 
 * 
 * USAGE:
 * $router->post('/api/users', [CreateUserAccController::class, 'create'], [
 *     role_guard('User Admin', 'Platform Manager')
 * ]);
 * =============================================================================
 */

function role_guard(string ...$roles): callable
{
    return function () use ($roles): bool {
        $u = session_user();
        
        // 1. Double-check authentication
        // (Even if auth_guard ran first, role_guard should be able to stand alone).
        if (!$u) {
            display_error(401, 'Not authenticated. Please log in to continue.');
            return false;
        }
        
        // 2. Check if the user's assigned role is in the allowed list
        if (!in_array($u['role'], $roles, true)) {
            // [UPDATED] Use display_error for a rich 403 Forbidden experience.
            // This triggers the "Access Denied" ⛔ banner on the frontend.
            display_error(403, 'Forbidden. You do not have the required permissions to access this resource.');
            return false;
        }
        
        // Access granted
        return true;
    };
}