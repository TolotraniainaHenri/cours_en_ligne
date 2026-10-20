<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Identité de l'utilisateur courant.
 * Le rôle et l'état du compte sont relus en base à chaque requête : un admin rétrogradé
 * ou un compte désactivé perd ses droits immédiatement, sans attendre l'expiration de la session.
 */
final class Auth
{
    private static ?array $user = null;
    private static bool $loaded = false;

    public static function user(): ?array
    {
        if (self::$loaded) {
            return self::$user;
        }
        self::$loaded = true;

        $id = $_SESSION['user_id'] ?? null;
        if (!is_int($id)) {
            return null;
        }
        $row = Database::one('SELECT id, nom, email, role, actif FROM utilisateur WHERE id = :id', ['id' => $id]);
        if ($row === null || !$row['actif']) {
            Session::reset();
            return null;
        }
        unset($row['actif']);
        return self::$user = $row;
    }

    /** À appeler après login/logout pour relire l'identité. */
    public static function forget(): void
    {
        self::$user = null;
        self::$loaded = false;
    }

    public static function id(): int
    {
        return (int) (self::user()['id'] ?? 0);
    }

    public static function isAdmin(): bool
    {
        return (self::user()['role'] ?? '') === 'admin';
    }

    /** Middleware : connexion obligatoire. */
    public static function requireLogin(Request $request): void
    {
        if (self::user() === null) {
            throw new HttpException(401, 'Authentification requise.');
        }
    }

    /** Middleware : réservé aux administrateurs. */
    public static function requireAdmin(Request $request): void
    {
        self::requireLogin($request);
        if (!self::isAdmin()) {
            Logger::security('forbidden_admin_route', $request->ip(), 'user=' . self::id() . ' path=' . $request->path);
            throw new HttpException(403, 'Accès réservé aux administrateurs.');
        }
    }

    public static function hashPassword(string $password): string
    {
        return password_hash($password, self::algo());
    }

    public static function needsRehash(string $hash): bool
    {
        return password_needs_rehash($hash, self::algo());
    }

    private static function algo(): string
    {
        return defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_DEFAULT;
    }
}
