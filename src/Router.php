<?php
declare(strict_types=1);

/**
 * =============================================================================
 * CUSTOM PHP ROUTER
 * =============================================================================
 * A lightweight routing engine that mimics Express.js. 
 * It matches HTTP methods and URLs, extracts dynamic parameters (like :id), 
 * and executes middleware before calling the controller.
 * =============================================================================
 */

class Router
{
    /** @var array<int, array{0:string,1:string,2:array,3:callable}> */
    private array $routes = [];

    // --- Route Registration Methods ---
    public function get(string $path, array $mw, callable $h): self    { $this->routes[] = ['GET',    $path, $mw, $h]; return $this; }
    public function post(string $path, array $mw, callable $h): self   { $this->routes[] = ['POST',   $path, $mw, $h]; return $this; }
    public function put(string $path, array $mw, callable $h): self    { $this->routes[] = ['PUT',    $path, $mw, $h]; return $this; }
    public function patch(string $path, array $mw, callable $h): self  { $this->routes[] = ['PATCH',  $path, $mw, $h]; return $this; }
    public function delete(string $path, array $mw, callable $h): self { $this->routes[] = ['DELETE', $path, $mw, $h]; return $this; }

    // --- Dispatcher ---
    public function dispatch(string $method, string $uri): void
    {
        // Normalize URI (remove trailing slashes to prevent routing bugs)
        $uri = rtrim($uri, '/');
        if ($uri === '') $uri = '/';

        foreach ($this->routes as [$m, $path, $mw, $handler]) {
            if ($m !== $method) continue;
            
            // Check if the URL matches the route pattern
            $params = $this->match($path, $uri);
            if ($params === null) continue;

            // Execute Middleware (e.g., auth_guard, role_guard)
            foreach ($mw as $fn) {
                // If middleware returns false, it means it already sent a 401/403 
                // response (via display_error) and we must abort routing immediately.
                if ($fn() === false) return; 
            }
            
            // Execute the Controller method
            $handler($params);
            return;
        }

        // [UPDATED] Route not found: Use the structured display_error helper
        // so the frontend renders a nice "Not Found" banner instead of a basic alert.
        display_error(404, 'The requested API endpoint does not exist.');
    }

    private function match(string $pattern, string $uri): ?array
    {
        // Convert Express-style route (e.g., /users/:id) into a Regex
        // E.g., /users/:id becomes /users/(?P<id>[^/]+)
        $regex = preg_replace('/:([a-zA-Z_][a-zA-Z0-9_]*)/', '(?P<$1>[^/]+)', $pattern);
        $regex = '#^' . $regex . '$#';
        
        if (preg_match($regex, $uri, $m)) {
            $params = [];
            foreach ($m as $k => $v) {
                // Only keep named captures (e.g., 'id' => '5')
                if (!is_int($k)) $params[$k] = $v;
            }
            return $params;
        }
        return null;
    }
}

/**
 * =============================================================================
 * GLOBAL HELPER FUNCTIONS
 * =============================================================================
 * These are used throughout the controllers and middleware.
 * =============================================================================
 */

/**
 * Reads and caches the JSON request body.
 * Since `php://input` can only be read once, we cache it in a static variable
 * so multiple controllers can call `req_body()` safely.
 */
function req_body(): array
{
    static $body = null;
    if ($body === null) {
        $raw = file_get_contents('php://input');
        $body = $raw ? (json_decode($raw, true) ?: []) : [];
    }
    return $body;
}

/**
 * Returns the currently logged-in user array from the session, or null.
 */
function session_user(): ?array
{
    return $_SESSION['user'] ?? null;
}

/**
 * Emits a JSON response with the given HTTP status code.
 * Note: For errors, controllers now use `display_error()` instead of `json_out()`.
 */
function json_out(int $status, array $payload): void
{
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode($payload);
}