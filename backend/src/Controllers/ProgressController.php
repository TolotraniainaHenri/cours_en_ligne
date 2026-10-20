<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\{Auth, Database, HttpException, Request, Response, Validator};
use App\Services\Access;

final class ProgressController
{
    /** GET /api/progress/me */
    public function mine(Request $r): void
    {
        $rows = Database::all(
            'SELECT p.id_lecon, l.titre AS lecon_titre, m.id_cours, p.pourcentage, p.terminee, p.date_maj
             FROM progression p
             JOIN lecon l ON l.id = p.id_lecon
             JOIN module m ON m.id = l.id_module
             WHERE p.id_user = :u ORDER BY p.date_maj DESC',
            ['u' => Auth::id()]
        );
        Response::json(['progression' => $rows]);
    }

    /** PUT /api/progress/{id_lecon} {pourcentage: 0..100} : crée ou met à jour. */
    public function update(Request $r): void
    {
        $lessonId = (int) $r->param('id_lecon');
        $d = (new Validator($r->json()))->integer('pourcentage', 0, 100)->validate();

        $coursId = Access::courseIdOfLesson($lessonId);
        if ($coursId === null) {
            throw new HttpException(404, 'Leçon introuvable.');
        }
        if (!Access::canAccessCourse(Auth::user(), $coursId)) {
            throw new HttpException(403, 'Inscription validée requise pour ce cours.');
        }

        $pct = $d['pourcentage'];
        $done = $pct === 100 ? 1 : 0;
        Database::run(
            'INSERT INTO progression (id_user, id_lecon, pourcentage, terminee) VALUES (:u, :l, :p, :t)
             ON DUPLICATE KEY UPDATE pourcentage = :p2, terminee = :t2',
            ['u' => Auth::id(), 'l' => $lessonId, 'p' => $pct, 't' => $done, 'p2' => $pct, 't2' => $done]
        );
        Response::json(['id_lecon' => $lessonId, 'pourcentage' => $pct, 'terminee' => (bool) $done]);
    }
}
