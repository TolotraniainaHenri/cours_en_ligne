<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\{Auth, Database, HttpException, Request, Response, Validator};
use App\Services\Access;

final class CourseController
{
    /** GET /api/courses?langue=fr|en */
    public function index(Request $r): void
    {
        $langue = $r->query['langue'] ?? null;
        if ($langue !== null && !in_array($langue, ['fr', 'en'], true)) {
            throw new HttpException(422, 'Paramètre invalide.', ['langue' => 'Valeurs permises : fr, en.']);
        }

        $sql = 'SELECT c.id, c.titre, c.description, c.langue, c.publie,
                       (SELECT COUNT(*) FROM module m WHERE m.id_cours = c.id) AS nb_modules
                FROM cours c WHERE ' . (Auth::isAdmin() ? '1 = 1' : 'c.publie = 1');
        $params = [];
        if ($langue !== null) {
            $sql .= ' AND c.langue = :l';
            $params['l'] = $langue;
        }
        Response::json(['cours' => Database::all($sql . ' ORDER BY c.titre', $params)]);
    }

    /** GET /api/courses/{id} : cours + modules + leçons. Les liens de contenu exigent une inscription validée. */
    public function show(Request $r): void
    {
        $id = (int) $r->param('id');
        $cours = Database::one(
            'SELECT id, titre, description, langue, publie FROM cours WHERE id = :id',
            ['id' => $id]
        );
        if ($cours === null || (!$cours['publie'] && !Auth::isAdmin())) {
            throw new HttpException(404, 'Cours introuvable.');
        }

        $user = Auth::user();
        $statut = $user !== null ? Access::enrollmentStatus((int) $user['id'], $id) : null;
        $acces = $user !== null && ($user['role'] === 'admin' || $statut === 'validee');

        $modules = Database::all('SELECT id, titre, ordre FROM module WHERE id_cours = :id ORDER BY ordre', ['id' => $id]);
        $lecons = Database::all(
            'SELECT l.id, l.id_module, l.titre, l.duree, l.url_contenu
             FROM lecon l JOIN module m ON m.id = l.id_module
             WHERE m.id_cours = :id ORDER BY m.ordre, l.id',
            ['id' => $id]
        );

        $parModule = [];
        foreach ($lecons as $lecon) {
            if (!$acces) {
                unset($lecon['url_contenu']);
            }
            $parModule[$lecon['id_module']][] = $lecon;
        }
        foreach ($modules as &$module) {
            $module['lecons'] = $parModule[$module['id']] ?? [];
        }
        unset($module);

        Response::json([
            'cours'              => $cours,
            'inscription_statut' => $statut,
            'acces'              => $acces,
            'modules'            => $modules,
        ]);
    }

    /** POST /api/courses (admin) */
    public function store(Request $r): void
    {
        $d = $this->validateCourse($r);
        Database::run(
            'INSERT INTO cours (titre, description, langue, publie) VALUES (:t, :d, :l, :p)',
            ['t' => $d['titre'], 'd' => $d['description'], 'l' => $d['langue'], 'p' => $d['publie'] ?? 1]
        );
        Response::json(['id' => Database::lastInsertId(), 'message' => 'Cours créé.'], 201);
    }

    /** PUT /api/courses/{id} (admin) */
    public function update(Request $r): void
    {
        $id = (int) $r->param('id');
        $this->mustExist('cours', $id, 'Cours introuvable.');
        $d = $this->validateCourse($r);
        Database::run(
            'UPDATE cours SET titre = :t, description = :d, langue = :l, publie = :p WHERE id = :id',
            ['t' => $d['titre'], 'd' => $d['description'], 'l' => $d['langue'], 'p' => $d['publie'] ?? 1, 'id' => $id]
        );
        Response::json(['message' => 'Cours mis à jour.']);
    }

    /** DELETE /api/courses/{id} (admin) : supprime aussi modules, leçons, exercices et inscriptions (CASCADE). */
    public function destroy(Request $r): void
    {
        $id = (int) $r->param('id');
        $this->mustExist('cours', $id, 'Cours introuvable.');
        Database::run('DELETE FROM cours WHERE id = :id', ['id' => $id]);
        Response::json(['message' => 'Cours supprimé.']);
    }

    /** POST /api/courses/{id}/modules (admin) */
    public function storeModule(Request $r): void
    {
        $coursId = (int) $r->param('id');
        $this->mustExist('cours', $coursId, 'Cours introuvable.');
        $d = (new Validator($r->json()))->text('titre', 2, 200)->integer('ordre', 1, 65535)->validate();

        try {
            Database::run(
                'INSERT INTO module (id_cours, titre, ordre) VALUES (:c, :t, :o)',
                ['c' => $coursId, 't' => $d['titre'], 'o' => $d['ordre']]
            );
        } catch (\PDOException $e) {
            if ($e->getCode() === '23000') {
                throw new HttpException(409, 'Un module utilise déjà cet ordre dans ce cours.');
            }
            throw $e;
        }
        Response::json(['id' => Database::lastInsertId(), 'message' => 'Module créé.'], 201);
    }

    /** POST /api/modules/{id}/lessons (admin) */
    public function storeLesson(Request $r): void
    {
        $moduleId = (int) $r->param('id');
        $this->mustExist('module', $moduleId, 'Module introuvable.');
        $d = (new Validator($r->json()))
            ->text('titre', 2, 200)
            ->url('url_contenu')
            ->integer('duree', 1, 1000, false)
            ->validate();

        Database::run(
            'INSERT INTO lecon (id_module, titre, url_contenu, duree) VALUES (:m, :t, :u, :d)',
            ['m' => $moduleId, 't' => $d['titre'], 'u' => $d['url_contenu'], 'd' => $d['duree']]
        );
        Response::json(['id' => Database::lastInsertId(), 'message' => 'Leçon créée.'], 201);
    }

    /** POST /api/lessons/{id}/exercises (admin) */
    public function storeExercise(Request $r): void
    {
        $lessonId = (int) $r->param('id');
        $this->mustExist('lecon', $lessonId, 'Leçon introuvable.');
        $body = $r->json();

        $v = (new Validator($body))
            ->text('question', 3, 2000)
            ->choice('type', ['qcm', 'texte_a_trous', 'reponse_courte'])
            ->text('bonne_reponse', 1, 255)
            ->text('explication', 0, 2000, false);
        $d = $v->validate();

        $options = null;
        if ($d['type'] === 'qcm') {
            $list = $body['options'] ?? null;
            $valid = is_array($list) && array_is_list($list) && count($list) >= 2 && count($list) <= 8;
            if ($valid) {
                foreach ($list as $o) {
                    if (!is_string($o) || trim($o) === '' || mb_strlen($o) > 255) {
                        $valid = false;
                        break;
                    }
                }
            }
            if (!$valid) {
                throw new HttpException(422, 'Données invalides.', ['options' => 'Un QCM exige une liste de 2 à 8 choix (textes non vides).']);
            }
            if (!in_array($d['bonne_reponse'], $list, true)) {
                throw new HttpException(422, 'Données invalides.', ['bonne_reponse' => 'La bonne réponse doit figurer parmi les options.']);
            }
            $options = json_encode(array_values($list), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        }

        Database::run(
            'INSERT INTO exercice (id_lecon, question, type, options, bonne_reponse, explication)
             VALUES (:l, :q, :t, :o, :b, :e)',
            ['l' => $lessonId, 'q' => $d['question'], 't' => $d['type'], 'o' => $options, 'b' => $d['bonne_reponse'], 'e' => $d['explication']]
        );
        Response::json(['id' => Database::lastInsertId(), 'message' => 'Exercice créé.'], 201);
    }

    /** @return array{titre:string,description:?string,langue:string,publie:?int} */
    private function validateCourse(Request $r): array
    {
        return (new Validator($r->json()))
            ->text('titre', 2, 200)
            ->text('description', 0, 5000, false)
            ->choice('langue', ['fr', 'en'])
            ->integer('publie', 0, 1, false)
            ->validate();
    }

    private function mustExist(string $table, int $id, string $message): void
    {
        // $table vient du code (jamais de l'utilisateur) : liste blanche stricte.
        if (!in_array($table, ['cours', 'module', 'lecon'], true)) {
            throw new \LogicException('Table non autorisée.');
        }
        if (Database::one("SELECT id FROM `$table` WHERE id = :id", ['id' => $id]) === null) {
            throw new HttpException(404, $message);
        }
    }
}
