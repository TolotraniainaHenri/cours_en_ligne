<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\{Auth, Csrf, Database, Env, HttpException, Logger, Request, Response, Session, Validator};

final class AuthController
{
    /** GET /api/csrf : jeton à renvoyer dans l'en-tête X-CSRF-Token pour toute requête POST/PUT/PATCH/DELETE. */
    public function csrf(Request $r): void
    {
        Response::json(['csrf_token' => Csrf::token()]);
    }

    /** GET /api/auth/me */
    public function me(Request $r): void
    {
        Response::json(['user' => Auth::user(), 'csrf_token' => Csrf::token()]);
    }

    /** POST /api/auth/register : crée toujours un compte "etudiant" (jamais un admin). */
    public function register(Request $r): void
    {
        $d = (new Validator($r->json()))
            ->text('nom', 2, 100)
            ->email('email')
            ->password('mot_de_passe')
            ->validate();

        try {
            Database::run(
                "INSERT INTO utilisateur (nom, email, mot_de_passe, role) VALUES (:nom, :email, :mdp, 'etudiant')",
                ['nom' => $d['nom'], 'email' => $d['email'], 'mdp' => Auth::hashPassword($d['mot_de_passe'])]
            );
        } catch (\PDOException $e) {
            if ($e->getCode() === '23000') {
                throw new HttpException(409, 'Cet email est déjà utilisé.');
            }
            throw $e;
        }

        Response::json(['message' => 'Compte créé. Vous pouvez maintenant vous connecter.'], 201);
    }

    /** POST /api/auth/login */
    public function login(Request $r): void
    {
        $d = (new Validator($r->json()))
            ->email('email')
            ->secret('mot_de_passe', 1, 128)
            ->validate();

        $ip = $r->ip();
        $max = Env::int('LOGIN_MAX_ATTEMPTS', 5);
        $lockMinutes = Env::int('LOGIN_LOCK_MINUTES', 15);

        // --- Anti force brute : limite par email et par adresse IP ---
        $byEmail = (int) Database::run(
            'SELECT COUNT(*) FROM login_attempts WHERE email = :e AND created_at > (NOW() - INTERVAL :m MINUTE)',
            ['e' => $d['email'], 'm' => $lockMinutes]
        )->fetchColumn();
        $byIp = (int) Database::run(
            'SELECT COUNT(*) FROM login_attempts WHERE ip = :ip AND created_at > (NOW() - INTERVAL :m MINUTE)',
            ['ip' => $ip, 'm' => $lockMinutes]
        )->fetchColumn();

        if ($byEmail >= $max || $byIp >= $max * 4) {
            Logger::security('login_blocked', $ip, 'email=' . $d['email']);
            header('Retry-After: ' . ($lockMinutes * 60));
            throw new HttpException(429, "Trop de tentatives. Réessayez dans $lockMinutes minutes.");
        }

        $user = Database::one(
            'SELECT id, nom, email, mot_de_passe, role, actif FROM utilisateur WHERE email = :e',
            ['e' => $d['email']]
        );

        if ($user === null) {
            Auth::hashPassword($d['mot_de_passe']); // même durée de calcul : ne révèle pas si l'email existe
            $ok = false;
        } else {
            $ok = password_verify($d['mot_de_passe'], $user['mot_de_passe']);
        }

        if (!$ok) {
            Database::run('INSERT INTO login_attempts (email, ip) VALUES (:e, :ip)', ['e' => $d['email'], 'ip' => $ip]);
            if (random_int(1, 50) === 1) {
                Database::run('DELETE FROM login_attempts WHERE created_at < (NOW() - INTERVAL 1 DAY)');
            }
            Logger::security('login_failed', $ip, 'email=' . $d['email']);
            throw new HttpException(401, 'Email ou mot de passe incorrect.');
        }

        if (!$user['actif']) {
            throw new HttpException(403, 'Ce compte est désactivé.');
        }

        // Succès : on efface les échecs, on met à jour le hash si l'algorithme a évolué.
        Database::run('DELETE FROM login_attempts WHERE email = :e', ['e' => $d['email']]);
        if (Auth::needsRehash($user['mot_de_passe'])) {
            Database::run(
                'UPDATE utilisateur SET mot_de_passe = :h WHERE id = :id',
                ['h' => Auth::hashPassword($d['mot_de_passe']), 'id' => $user['id']]
            );
        }

        Session::login((int) $user['id']);
        Auth::forget();

        Response::json([
            'user' => [
                'id'    => (int) $user['id'],
                'nom'   => $user['nom'],
                'email' => $user['email'],
                'role'  => $user['role'],
            ],
            'csrf_token' => Csrf::token(),
        ]);
    }

    /** POST /api/auth/logout */
    public function logout(Request $r): void
    {
        Session::destroy();
        Auth::forget();
        Response::json(['message' => 'Vous êtes déconnecté.']);
    }
}
