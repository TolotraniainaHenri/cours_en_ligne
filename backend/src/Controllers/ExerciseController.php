<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\{Auth, Database, HttpException, Request, Response, Validator};
use App\Services\Access;

/** Exercices de grammaire : correction automatique côté serveur (la bonne réponse n'est jamais envoyée avant la tentative). */
final class ExerciseController
{
    /** GET /api/lessons/{id}/exercises */
    public function index(Request $r): void
    {
        $lessonId = (int) $r->param('id');
        $coursId = Access::courseIdOfLesson($lessonId);
        if ($coursId === null) {
            throw new HttpException(404, 'Leçon introuvable.');
        }
        if (!Access::canAccessCourse(Auth::user(), $coursId)) {
            throw new HttpException(403, 'Inscription validée requise pour ce cours.');
        }

        $rows = Database::all(
            'SELECT id, question, type, options FROM exercice WHERE id_lecon = :l ORDER BY id',
            ['l' => $lessonId]
        );
        foreach ($rows as &$row) {
            $row['options'] = $row['options'] !== null ? json_decode($row['options'], true) : null;
        }
        unset($row);

        Response::json(['exercices' => $rows]);
    }

    /** POST /api/exercises/{id}/attempts {reponse} */
    public function attempt(Request $r): void
    {
        $exoId = (int) $r->param('id');
        $d = (new Validator($r->json()))->text('reponse', 1, 255)->validate();

        $exo = Database::one(
            'SELECT e.id, e.bonne_reponse, e.explication, m.id_cours
             FROM exercice e
             JOIN lecon l ON l.id = e.id_lecon
             JOIN module m ON m.id = l.id_module
             WHERE e.id = :id',
            ['id' => $exoId]
        );
        if ($exo === null) {
            throw new HttpException(404, 'Exercice introuvable.');
        }
        if (!Access::canAccessCourse(Auth::user(), (int) $exo['id_cours'])) {
            throw new HttpException(403, 'Inscription validée requise pour ce cours.');
        }

        $correct = self::normalize($d['reponse']) === self::normalize($exo['bonne_reponse']);

        Database::run(
            'INSERT INTO tentative (id_user, id_exercice, reponse, correct) VALUES (:u, :e, :r, :c)',
            ['u' => Auth::id(), 'e' => $exoId, 'r' => $d['reponse'], 'c' => $correct ? 1 : 0]
        );

        Response::json([
            'correct'       => $correct,
            'bonne_reponse' => $exo['bonne_reponse'],
            'explication'   => $exo['explication'],
        ]);
    }

    /** Ignore la casse et les espaces en trop ; les accents restent significatifs (grammaire). */
    private static function normalize(string $s): string
    {
        $s = str_replace(["\u{2019}", "\u{2018}"], "'", $s);
        $s = preg_replace('/\s+/u', ' ', $s) ?? $s;
        return mb_strtolower(trim($s), 'UTF-8');
    }
}
