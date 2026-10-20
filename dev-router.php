<?php
declare(strict_types=1);

/**
 * Routeur pour le serveur de DÉVELOPPEMENT uniquement (php -S).
 *   /api/...  -> backend/public/index.php
 *   le reste  -> fichiers statiques du dossier frontend/
 * En production, c'est Nginx qui joue ce rôle (voir deploy/nginx.conf).
 */

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';

if ($path === '/api' || str_starts_with($path, '/api/')) {
    require __DIR__ . '/backend/public/index.php';
    return true;
}

return false; // le serveur intégré sert frontend/ (option -t frontend)
