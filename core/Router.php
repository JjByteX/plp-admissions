<?php
// ============================================================
// core/Router.php
// Minimal front-controller router
// Maps GET/POST /path → module file
// ============================================================

class Router
{
    private array $routes = [];

    public function get(string $path, string $handler): void
    {
        $this->routes['GET'][$path] = $handler;
    }

    public function post(string $path, string $handler): void
    {
        $this->routes['POST'][$path] = $handler;
    }

    public function dispatch(): void
    {
        $method = $_SERVER['REQUEST_METHOD'];
        $uri    = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

        // Strip the base path prefix (for XAMPP subdirectory installs)
        $base = parse_url(BASE_URL, PHP_URL_PATH);
        if ($base && str_starts_with($uri, $base)) {
            $uri = substr($uri, strlen($base));
        }

        $uri = '/' . ltrim($uri, '/');

        // Exact match first
        if (isset($this->routes[$method][$uri])) {
            $this->load($this->routes[$method][$uri]);
            return;
        }

        // Parameterised match  e.g. /documents/{id}
        foreach ($this->routes[$method] ?? [] as $pattern => $handler) {
            $regex = preg_replace('/\{[^}]+\}/', '([^/]+)', $pattern);
            $regex = '#^' . $regex . '$#';
            if (preg_match($regex, $uri, $matches)) {
                array_shift($matches);
                // Inject matched segments into $_GET
                preg_match_all('/\{([^}]+)\}/', $pattern, $keys);
                foreach ($keys[1] as $i => $key) {
                    $_GET[$key] = $matches[$i] ?? null;
                }
                $this->load($handler);
                return;
            }
        }

        // 404
        http_response_code(404);
        include VIEWS_PATH . '/404.php';
    }

    private function load(string $handler): void
    {
        $file = MODULES_PATH . '/' . ltrim($handler, '/') . '.php';
        if (!file_exists($file)) {
            error_log("Router: handler file not found: {$file}");
            http_response_code(500);
            exit('Handler not found.');
        }
        require $file;
    }
}
