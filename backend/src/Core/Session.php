<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Gestion centralisée et sécurisée de la session.
 *
 *  - Cookie : HttpOnly (illisible par JavaScript), Secure (HTTPS), SameSite.
 *  - Mode strict : un identifiant de session inventé par un attaquant est refusé.
 *  - Fixation de session : l'identifiant est régénéré à la connexion.
 *  - Expiration par inactivité ET durée maximale absolue.
 *  - Liaison au navigateur (hash du User-Agent) : une session volée sur un autre navigateur est rejetée.
 *  - Stockage en base (révocable) avec nettoyage automatique des sessions expirées.
 */
final class Session
{
    public static function start(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }

        $idle = Env::int('SESSION_IDLE_TIMEOUT', 1800);

        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        ini_set('session.use_trans_sid', '0');
        ini_set('session.cookie_httponly', '1');
        ini_set('session.gc_maxlifetime', (string) $idle);
        // Sur Ubuntu gc_probability vaut 0 (nettoyage par cron pour les fichiers) : on l'active pour la base.
        ini_set('session.gc_probability', '1');
        ini_set('session.gc_divisor', '100');

        session_name((string) Env::get('SESSION_NAME', 'FORMSESSID'));
        session_set_cookie_params([
            'lifetime' => 0,                                   // cookie de session : disparaît à la fermeture
            'path'     => '/',
            'domain'   => '',
            'secure'   => Env::bool('SESSION_SECURE', false),  // true en production (HTTPS)
            'httponly' => true,
            'samesite' => (string) Env::get('SESSION_SAMESITE', 'Lax'),
        ]);

        session_set_save_handler(new DatabaseSessionHandler(Database::pdo()), true);
        session_start();

        self::enforcePolicies($idle);
    }

    /** Connexion : nouvel identifiant de session (anti fixation) + nouveau jeton CSRF. */
    public static function login(int $userId): void
    {
        session_regenerate_id(true);
        $now = time();
        $_SESSION = [
            'user_id'       => $userId,
            'ua'            => self::fingerprint(),
            'created_at'    => $now,
            'last_activity' => $now,
        ];
        Csrf::rotate();
    }

    /** Déconnexion : vide la session, la supprime en base et efface le cookie. */
    public static function destroy(): void
    {
        $_SESSION = [];
        $p = session_get_cookie_params();
        setcookie(session_name(), '', [
            'expires'  => time() - 3600,
            'path'     => $p['path'],
            'domain'   => $p['domain'],
            'secure'   => $p['secure'],
            'httponly' => $p['httponly'],
            'samesite' => $p['samesite'],
        ]);
        session_destroy();
    }

    /** Remplace la session courante par une session anonyme neuve (expiration, compte désactivé...). */
    public static function reset(): void
    {
        $_SESSION = [];
        session_destroy();
        session_id(session_create_id());
        session_start();
        $_SESSION['last_activity'] = time();
    }

    private static function fingerprint(): string
    {
        return hash('sha256', $_SERVER['HTTP_USER_AGENT'] ?? '');
    }

    private static function enforcePolicies(int $idle): void
    {
        $now = time();

        if (isset($_SESSION['user_id'])) {
            $absolute = Env::int('SESSION_ABSOLUTE_TIMEOUT', 28800);
            $expired = ($now - (int) ($_SESSION['last_activity'] ?? 0)) > $idle
                || ($now - (int) ($_SESSION['created_at'] ?? 0)) > $absolute;
            $stolen = !hash_equals((string) ($_SESSION['ua'] ?? ''), self::fingerprint());

            if ($expired || $stolen) {
                if ($stolen) {
                    Logger::security('session_fingerprint_mismatch', $_SERVER['REMOTE_ADDR'] ?? '-');
                }
                self::reset();
                return;
            }
        }

        $_SESSION['last_activity'] = $now;
    }
}
