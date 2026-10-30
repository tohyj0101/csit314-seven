<?php
declare(strict_types=1);

class Router
{
    /** @var array<int, array{0:string,1:string,2:array,3:callable}> */
    private array $routes = [];

    public function get(string $path, array $mw, callable $h): self    { $this->routes[] = ['GET',    $path, $mw, $h]; return $this; }
    public function post(string $path, array $mw, callable $h): self   { $this->routes[] = ['POST',   $path, $mw, $h]; return $this; }
    public function put(string $path, array $mw, callable $h): self    { $this->routes[] = ['PUT',    $path, $mw, $h]; return $this; }
    public function patch(string $path, array $mw, callable $h): self  { $this->routes[] = ['PATCH',  $path, $mw, $h]; return $this; }
    public function delete(string $path, array $mw, callable $h): self { $this->routes[] = ['DELETE', $path, $mw, $h]; return $this; }

    public function dispatch(string $method, string $uri): void
    {
        $uri = rtrim($uri, '/');
        if ($uri === '') $uri = '/';

        foreach ($this->routes as [$m, $path, $mw, $handler]) {
            if ($m !== $method) continue;
            $params = $this->match($path, $uri);
            if ($params === null) continue;

            foreach ($mw as $fn) {
                if ($fn() === false) return; // middleware already sent response
            }
            $handler($params);
            return;
        }

        http_response_code(404);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Not found']);
    }

    private function match(string $pattern, string $uri): ?array
    {
        $regex = preg_replace('/:([a-zA-Z_][a-zA-Z0-9_]*)/', '(?P<$1>[^/]+)', $pattern);
        $regex = '#^' . $regex . '$#';
        if (preg_match($regex, $uri, $m)) {
            $params = [];
            foreach ($m as $k => $v) {
                if (!is_int($k)) $params[$k] = $v;
            }
            return $params;
        }
        return null;
    }
}

/** Helpers used by controllers to read request state. */
function req_body(): array
{
    static $body = null;
    if ($body === null) {
        $raw = file_get_contents('php://input');
        $body = $raw ? (json_decode($raw, true) ?: []) : [];
    }
    return $body;
}

function session_user(): ?array
{
    return $_SESSION['user'] ?? null;
}

function json_out(int $status, array $payload): void
{
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode($payload);
}