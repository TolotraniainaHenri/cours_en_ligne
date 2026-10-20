<?php
declare(strict_types=1);

/**
 * Point d'entrée unique de l'API (front controller).
 * Seul le dossier backend/public/ est exposé par le serveur web : le code, le .env
 * et les logs restent inaccessibles depuis Internet.
 */

use App\Core\{Env, HttpException, Logger, Request, Response, Router, Session};

require __DIR__ . '/../src/autoload.php';

$root = dirname(__DIR__);
Env::load($root . '/.env');
$debug = Env::bool('APP_DEBUG', false);

// Les erreurs PHP vont dans un fichier, jamais dans la réponse.
error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');
ini_set('error_log', $root . '/storage/logs/php-error.log');

// En-têtes de sécurité de l'API.
header_remove('X-Powered-By');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: no-referrer');
header('Cache-Control: no-store');
header("Content-Security-Policy: default-src 'none'; frame-ancestors 'none'");

try {
    Session::start();

    $router = new Router();
    (require $root . '/src/routes.php')($router);
    $router->dispatch(Request::capture());
} catch (HttpException $e) {
    Response::error($e->status, $e->getMessage(), $e->errors);
} catch (Throwable $e) {
    Logger::error('Erreur non gérée', $e);
    Response::error(500, $debug ? $e->getMessage() : 'Erreur interne du serveur.');
}
