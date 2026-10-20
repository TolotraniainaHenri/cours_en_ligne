<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\{Auth, Database, HttpException, Request, Response, Validator};
use App\Services\Access;

/**
 * Inscriptions : l'étudiant envoie une demande (en_attente),
 * un administrateur la valide ou la refuse (id_admin + date_validation sont alors renseignés).
 */
final class EnrollmentController
{
    /** POST /api/enrollments {id_cours} */
    public function store(Request $r): void
    {
        $d = (new Validator($r->json()))->integer('id_cours', 1, 4294967295)->validate();
        $coursId = $d['id_cours'];
        $userId = Auth::id();

        if (Database::one('SELECT id FROM cours WHERE id = :id AND publie = 1', ['id' => $coursId]) === null) {
            throw new HttpException(404, 'Cours introuvable.');
        }

        $existing = Access::enrollmentStatus($userId, $coursId);
        if ($existing === 'validee') {
            throw new HttpException(409, 'Vous êtes déjà inscrit à ce cours.');
        }
        if ($existing === 'en_attente') {
            throw new HttpException(409, 'Votre demande est déjà en attente de validation.');
        }

        try {
            if ($existing === null) {
                Database::run('INSERT INTO inscription (id_user, id_cours) VALUES (:u, :c)', ['u' => $userId, 'c' => $coursId]);
            } else { // refusee ou annulee : nouvelle demande
                Database::run(
                    "UPDATE inscription SET statut = 'en_attente', id_admin = NULL, date_validation = NULL, `date` = NOW()
                     WHERE id_user = :u AND id_cours = :c",
                    ['u' => $userId, 'c' => $coursId]
                );
            }
        } catch (\PDOException $e) {
            if ($e->getCode() === '23000') {
                throw new HttpException(409, 'Une demande existe déjà pour ce cours.');
            }
            throw $e;
        }

        Response::json(['message' => 'Demande envoyée. Un administrateur va la valider.', 'statut' => 'en_attente'], 201);
    }

    /** GET /api/enrollments/me */
    public function mine(Request $r): void
    {
        $rows = Database::all(
            'SELECT i.id_cours, c.titre AS cours_titre, c.langue, i.statut, i.`date`, i.date_validation
             FROM inscription i JOIN cours c ON c.id = i.id_cours
             WHERE i.id_user = :u ORDER BY i.`date` DESC',
            ['u' => Auth::id()]
        );
        Response::json(['inscriptions' => $rows]);
    }

    /** POST /api/enrollments/{id}/cancel  ({id} = id du cours) */
    public function cancel(Request $r): void
    {
        $st = Database::run(
            "UPDATE inscription SET statut = 'annulee'
             WHERE id_user = :u AND id_cours = :c AND statut IN ('en_attente', 'validee')",
            ['u' => Auth::id(), 'c' => (int) $r->param('id')]
        );
        if ($st->rowCount() === 0) {
            throw new HttpException(404, 'Aucune inscription active pour ce cours.');
        }
        Response::json(['message' => 'Inscription annulée.']);
    }

    /** GET /api/admin/enrollments?statut=en_attente&limit=50&offset=0 (admin) */
    public function adminIndex(Request $r): void
    {
        $statut = $r->query['statut'] ?? null;
        $allowed = ['en_attente', 'validee', 'refusee', 'annulee'];
        if ($statut !== null && !in_array($statut, $allowed, true)) {
            throw new HttpException(422, 'Paramètre invalide.', ['statut' => 'Valeurs permises : ' . implode(', ', $allowed) . '.']);
        }
        $limit = min(100, max(1, (int) ($r->query['limit'] ?? 50)));
        $offset = max(0, (int) ($r->query['offset'] ?? 0));

        $sql = 'SELECT i.id_user, i.id_cours, i.`date`, i.statut, i.date_validation, i.id_admin,
                       u.nom AS etudiant_nom, u.email AS etudiant_email, c.titre AS cours_titre
                FROM inscription i
                JOIN utilisateur u ON u.id = i.id_user
                JOIN cours c ON c.id = i.id_cours';
        $params = [];
        if ($statut !== null) {
            $sql .= ' WHERE i.statut = ?';
            $params[] = $statut;
        }
        $sql .= ' ORDER BY i.`date` DESC LIMIT ? OFFSET ?';
        $params[] = $limit;
        $params[] = $offset;

        Response::json(['inscriptions' => Database::all($sql, $params), 'limit' => $limit, 'offset' => $offset]);
    }

    /** PATCH /api/admin/enrollments/{id_user}/{id_cours} {statut: validee|refusee} (admin) */
    public function adminDecide(Request $r): void
    {
        $d = (new Validator($r->json()))->choice('statut', ['validee', 'refusee'])->validate();
        $userId = (int) $r->param('id_user');
        $coursId = (int) $r->param('id_cours');

        $st = Database::run(
            "UPDATE inscription SET statut = :s, id_admin = :a, date_validation = NOW()
             WHERE id_user = :u AND id_cours = :c AND statut = 'en_attente'",
            ['s' => $d['statut'], 'a' => Auth::id(), 'u' => $userId, 'c' => $coursId]
        );

        if ($st->rowCount() === 0) {
            $current = Access::enrollmentStatus($userId, $coursId);
            if ($current === null) {
                throw new HttpException(404, 'Inscription introuvable.');
            }
            throw new HttpException(409, "Cette demande n'est plus en attente (statut actuel : $current).");
        }
        Response::json(['message' => 'Inscription mise à jour.', 'statut' => $d['statut']]);
    }
}
