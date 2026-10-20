#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Crée (ou promeut) un administrateur depuis le terminal.
 * Il n'existe volontairement AUCUNE route web pour créer un admin.
 *
 * Usage : php backend/bin/create-admin.php
 */

use App\Core\{Auth, Database, Env, HttpException, Validator};

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require __DIR__ . '/../src/autoload.php';
Env::load(dirname(__DIR__) . '/.env');

function ask(string $label, bool $hidden = false): string
{
    echo $label;
    if ($hidden) {
        shell_exec('stty -echo');
    }
    $line = fgets(STDIN);
    if ($hidden) {
        shell_exec('stty echo');
        echo "\n";
    }
    return $line === false ? '' : rtrim($line, "\r\n");
}

$input = [
    'nom'          => ask('Nom complet : '),
    'email'        => ask('Email : '),
    'mot_de_passe' => ask('Mot de passe (10 caractères min., lettres + chiffres) : ', true),
];

try {
    $d = (new Validator($input))->text('nom', 2, 100)->email('email')->password('mot_de_passe')->validate();
} catch (HttpException $e) {
    fwrite(STDERR, "Erreur : " . json_encode($e->errors, JSON_UNESCAPED_UNICODE) . "\n");
    exit(1);
}

$existing = Database::one('SELECT id FROM utilisateur WHERE email = :e', ['e' => $d['email']]);
if ($existing !== null) {
    Database::run(
        "UPDATE utilisateur SET role = 'admin', actif = 1, mot_de_passe = :h WHERE id = :id",
        ['h' => Auth::hashPassword($d['mot_de_passe']), 'id' => $existing['id']]
    );
    echo "Compte existant promu administrateur (mot de passe mis à jour).\n";
} else {
    Database::run(
        "INSERT INTO utilisateur (nom, email, mot_de_passe, role) VALUES (:n, :e, :h, 'admin')",
        ['n' => $d['nom'], 'e' => $d['email'], 'h' => Auth::hashPassword($d['mot_de_passe'])]
    );
    echo "Administrateur créé.\n";
}
