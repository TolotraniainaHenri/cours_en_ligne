<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Protection CSRF : un jeton aléatoire est lié à la session et doit être renvoyé
 * dans l'en-tête X-CSRF-Token pour toute requête qui modifie des données.
 * En plus, si l'en-tête Origin est présent, il doit correspondre à l'hôte du site.
 */
final class Csrf
{
    public static function token(): string
    {
        if (empty($_SESSION['csrf']) || !is_string($_SESSION['csrf'])) {
            $_SESSION['csrf'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf'];
    }

    public static function rotate(): string
    {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
        return $_SESSION['csrf'];
    }

    public static function check(Request $request): void
    {
        $origin = $request->header('origin');
        if ($origin !== '') {
            $parts = parse_url($origin);
            $originHost = strtolower(($parts['host'] ?? '') . (isset($parts['port']) ? ':' . $parts['port'] : ''));
            if ($originHost !== strtolower($request->header('host'))) {
                Logger::security('csrf_origin_mismatch', $request->ip(), $origin);
                throw new HttpException(403, 'Origine de la requête non autorisée.');
            }
        }

        $expected = $_SESSION['csrf'] ?? '';
        $sent = $request->header('x-csrf-token');
        if (!is_string($expected) || $expected === '' || $sent === '' || !hash_equals($expected, $sent)) {
            throw new HttpException(403, 'Jeton CSRF invalide ou manquant.');
        }
    }
}
