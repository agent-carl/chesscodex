<?php
declare(strict_types=1);

/**
 * Tiny router. Patterns are either exact strings or regex (auto-detected by
 * leading "#"). Handlers receive captured groups as arguments. First match
 * wins; if nothing matches, the not-found callback fires.
 */
final class Router
{
    /** @var array<int, array{pattern:string, handler:callable}> */
    private array $routes = [];
    /** @var callable */
    private $notFound;

    public function add(string $pattern, callable $handler): void
    {
        $this->routes[] = ['pattern' => $pattern, 'handler' => $handler];
    }

    public function setNotFound(callable $handler): void
    {
        $this->notFound = $handler;
    }

    /** Whether some route (other than not-found) takes $path. */
    public function matches(string $path): bool
    {
        foreach ($this->routes as $r) {
            $p = $r['pattern'];
            if ($p[0] !== '#' ? $p === $path : preg_match($p, $path) === 1) return true;
        }
        return false;
    }

    public function dispatch(string $path): void
    {
        foreach ($this->routes as $r) {
            $p = $r['pattern'];
            if ($p[0] !== '#') {
                if ($p === $path) { $r['handler'](); return; }
                continue;
            }
            if (preg_match($p, $path, $m)) {
                $r['handler'](...array_slice($m, 1));
                return;
            }
        }
        if ($this->notFound) ($this->notFound)();
    }
}
