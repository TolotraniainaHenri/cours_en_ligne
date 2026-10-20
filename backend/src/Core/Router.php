<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Routeur minimal.
 *  - Les paramètres d'URL {id} n'acceptent que des chiffres.
 *  - Toute requête qui modifie des données (POST/PUT/PATCH/DELETE) passe par la vérification CSRF.
 *  - Des "middlewares" (callables) peuvent être attachés à chaque route (auth, admin...).
 */
final class Router
{
    /** @var list<array{0:string,1:string,2:callable|array,3:array}> */
    private array $routes = [];

    public function add(string $method, string $pattern, callable|array $handler, array $middleware = []): void
    {
        $regex = '#^' . preg_replace('#\{([a-z_]+)\}#', '(?P<$1>[0-9]+)', $pattern) . '$#';
        $this->routes[] = [$method, $regex, $handler, $middleware];
    }

    public function get(string $p, callable|array $h, array $mw = []): void    { $this->add('GET', $p, $h, $mw); }
    public function post(string $p, callable|array $h, array $mw = []): void   { $this->add('POST', $p, $h, $mw); }
    public function put(string $p, callable|array $h, array $mw = []): void    { $this->add('PUT', $p, $h, $mw); }
    public function patch(string $p, callable|array $h, array $mw = []): void  { $this->add('PATCH', $p, $h, $mw); }
    public function delete(string $p, callable|array $h, array $mw = []): void { $this->add('DELETE', $p, $h, $mw); }

    public function dispatch(Request $request): void
    {
        $allowed = [];
        foreach ($this->routes as [$method, $regex, $handler, $middleware]) {
            if (!preg_match($regex, $request->path, $matches)) {
                continue;
            }
            if ($method !== $request->method) {
                $allowed[] = $method;
                continue;
            }

            $request->setParams(array_filter($matches, 'is_string', ARRAY_FILTER_USE_KEY));

            if (!in_array($request->method, ['GET', 'HEAD', 'OPTIONS'], true)) {
                Csrf::check($request);
            }
            foreach ($middleware as $mw) {
                $mw($request);
            }

            if (is_array($handler)) {
                [$class, $action] = $handler;
                (new $class())->$action($request);
            } else {
                $handler($request);
            }
            return;
        }

        if ($allowed !== []) {
            header('Allow: ' . implode(', ', array_unique($allowed)));
            throw new HttpException(405, 'Méthode non autorisée.');
        }
        throw new HttpException(404, 'Route introuvable.');
    }
}
