<?php
declare(strict_types=1);

namespace App\Core;

/** Requête HTTP entrante (méthode, chemin, en-têtes, corps JSON, paramètres d'URL). */
final class Request
{
    private const MAX_BODY = 1_048_576; // 1 Mo

    /** @var array<string,string> */
    private array $params = [];
    private ?array $json = null;

    /**
     * @param array<string,mixed>  $query
     * @param array<string,string> $headers noms en minuscules
     */
    public function __construct(
        public readonly string $method,
        public readonly string $path,
        public readonly array $query,
        private readonly string $rawBody,
        private readonly array $headers
    ) {
    }

    public static function capture(): self
    {
        $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
        $uri = rawurldecode(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/');
        if ($uri === '/api' || str_starts_with($uri, '/api/')) {
            $uri = substr($uri, 4);
        }
        $path = '/' . trim($uri, '/');

        $headers = [];
        foreach ($_SERVER as $key => $value) {
            if (is_string($value) && str_starts_with($key, 'HTTP_')) {
                $headers[strtolower(str_replace('_', '-', substr($key, 5)))] = $value;
            }
        }
        if (isset($_SERVER['CONTENT_TYPE'])) {
            $headers['content-type'] = (string) $_SERVER['CONTENT_TYPE'];
        }

        $raw = (string) file_get_contents('php://input', false, null, 0, self::MAX_BODY + 1);
        return new self($method, $path, $_GET, $raw, $headers);
    }

    public function header(string $name, string $default = ''): string
    {
        return $this->headers[strtolower($name)] ?? $default;
    }

    /** Corps JSON décodé (tableau associatif). */
    public function json(): array
    {
        if ($this->json !== null) {
            return $this->json;
        }
        if ($this->rawBody === '') {
            return $this->json = [];
        }
        if (strlen($this->rawBody) > self::MAX_BODY) {
            throw new HttpException(413, 'Corps de requête trop volumineux.');
        }
        if (!str_contains(strtolower($this->header('content-type')), 'application/json')) {
            throw new HttpException(415, 'Content-Type application/json requis.');
        }
        try {
            $data = json_decode($this->rawBody, true, 16, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new HttpException(400, 'JSON invalide.');
        }
        if (!is_array($data)) {
            throw new HttpException(400, 'Un objet JSON est attendu.');
        }
        return $this->json = $data;
    }

    /** @param array<string,string> $params */
    public function setParams(array $params): void
    {
        $this->params = $params;
    }

    public function param(string $name): string
    {
        return $this->params[$name] ?? '';
    }

    public function ip(): string
    {
        // REMOTE_ADDR uniquement : X-Forwarded-For est falsifiable par le client.
        return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    }
}
