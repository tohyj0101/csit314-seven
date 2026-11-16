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


/**
 * =============================================================================
 * AppError
 * =============================================================================
 * Thrown by the ENTITY layer when a business rule fails (invalid input,
 * not found, not the owner, duplicate...). It carries the HTTP status, the
 * user-facing message and optional extra context (e.g. ['field' => 'email']).
 * Router::dispatch() catches it and renders it with display_error().
 * =============================================================================
 */
class AppError extends RuntimeException
{
    public function __construct(public int $status, string $message, public array $context = [])
    {
        parent::__construct($message);
    }
}

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
            
            // Execute the Controller method. Entities signal business-rule failures
            // by throwing AppError (status + message); we turn that into the
            // structured JSON error here, so controllers never have to.
            try {
                $handler($params);
            } catch (AppError $e) {
                display_error($e->status, $e->getMessage(), $e->context);
            } catch (Throwable $e) {
                handle_error($e);
            }
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
 * Note: errors are rendered with `display_error()` (see below).
 */
function json_out(int $status, array $payload): void
{
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode($payload);
}

/**
 * Renders a structured JSON error (used by the router and the middleware guards).
 */
function display_error(int $status, string $message, array $context = []): void {
    $meta = [400 => ['title' => 'Invalid Input', 'severity' => 'warning', 'icon' => '⚠️'], 401 => ['title' => 'Login Required', 'severity' => 'warning', 'icon' => '🔒'], 403 => ['title' => 'Access Denied', 'severity' => 'error', 'icon' => '⛔'], 404 => ['title' => 'Not Found', 'severity' => 'info', 'icon' => '🔍'], 409 => ['title' => 'Conflict', 'severity' => 'warning', 'icon' => '⚡'], 422 => ['title' => 'Validation Failed', 'severity' => 'warning', 'icon' => '⚠️'], 500 => ['title' => 'Server Error', 'severity' => 'critical', 'icon' => '💥']];
    $m = $meta[$status] ?? ['title' => 'Error', 'severity' => 'error', 'icon' => '❌'];
    $safeMessage = htmlspecialchars($message, ENT_QUOTES, 'UTF-8');
    $payload = array_merge(['error' => $message, 'message' => $message, 'title' => $m['title'], 'severity' => $m['severity'], 'icon' => $m['icon'], 'status' => $status, 'display' => true, 'html' => '<div class="error-alert" data-severity="' . $m['severity'] . '" data-status="' . $status . '" role="alert"><span class="error-alert__icon" aria-hidden="true">' . $m['icon'] . '</span><div class="error-alert__body"><strong class="error-alert__title">' . $m['title'] . '</strong><p class="error-alert__message">' . $safeMessage . '</p></div></div>'], $context);
    json_out($status, $payload);
}

// DEBUG VERSION: Outputs exact error to Node console
function handle_error(Throwable $e, string $fallback = 'An unexpected system error occurred. Please try again later.'): void {
    json_out(500, [
        'error' => $e->getMessage(),
        'line' => $e->getLine(),
        'file' => basename($e->getFile())
    ]);
}