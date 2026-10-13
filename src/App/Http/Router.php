<?php

declare(strict_types=1);

namespace TransformerX\App\Http;

/** Maps "METHOD /path/{id}" to handlers. {name} matches digits only. */
final class Router
{
    /** @var list<array{0: string, 1: string, 2: callable}> */
    private array $routes = [];

    public function add(string $method, string $pattern, callable $handler): void
    {
        $regex = '#^' . preg_replace('#\{(\w+)\}#', '(?P<$1>\d{1,10})', $pattern) . '$#';
        $this->routes[] = [$method, $regex, $handler];
    }

    public function dispatch(Request $request): Response
    {
        $pathMatched = false;
        foreach ($this->routes as [$method, $regex, $handler]) {
            if (!preg_match($regex, $request->path, $m)) {
                continue;
            }
            $pathMatched = true;
            if ($method !== $request->method) {
                continue;
            }
            $request->params = array_filter($m, 'is_string', ARRAY_FILTER_USE_KEY);
            return $handler($request);
        }
        throw $pathMatched ? new HttpError(405, 'method_not_allowed', 'Method not allowed.') : HttpError::notFound();
    }
}
