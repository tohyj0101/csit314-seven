<?php
declare(strict_types=1);

/**
 * =============================================================================
 * AUTHENTICATION MIDDLEWARE
 * =============================================================================
 * Returns a closure suitable for the Router's middleware array.
 * 
 * PURPOSE:
 * This acts as a "bouncer" for protected API routes. Before the router 
 * executes a controller (like `ViewUserAccController`), it runs this guard. 
 * If there is no active session, it halts execution and returns a 401.
 * 
 * WHY A CLOSURE?
 * The Router expects a callable. By returning a function, we can pass it 
 * directly into the route definition (e.g., `['middleware' => [auth_guard()]]`).
 * =============================================================================
 */

function auth_guard(): callable
{
    return function (): bool {
        // Check if a valid user session exists
        if (session_user()) {
            return true; // Allow the request to proceed to the controller
        }
        
        // [UPDATED] Use display_error for a rich frontend UI experience.
        // This sends the 401 status, the title "Login Required", and the 🔒 icon.
        display_error(401, 'Not authenticated. Please log in to continue.');
        
        // Return false to tell the Router to stop executing the route
        return false;
    };
}