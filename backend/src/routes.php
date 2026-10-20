<?php
declare(strict_types=1);

use App\Controllers\{AuthController, CourseController, EnrollmentController, ExerciseController, ProgressController};
use App\Core\{Auth, Response, Router};

/**
 * Toutes les routes de l'API (préfixe /api ajouté par le serveur web).
 * Middlewares : $login = connexion obligatoire, $admin = rôle administrateur.
 */
return static function (Router $r): void {
    $login = [Auth::class, 'requireLogin'];
    $admin = [Auth::class, 'requireAdmin'];

    $r->get('/health', static fn () => Response::json(['status' => 'ok']));

    // --- Authentification ---
    $r->get('/csrf', [AuthController::class, 'csrf']);
    $r->post('/auth/register', [AuthController::class, 'register']);
    $r->post('/auth/login', [AuthController::class, 'login']);
    $r->post('/auth/logout', [AuthController::class, 'logout']);
    $r->get('/auth/me', [AuthController::class, 'me']);

    // --- Catalogue (lecture publique, écriture admin) ---
    $r->get('/courses', [CourseController::class, 'index']);
    $r->get('/courses/{id}', [CourseController::class, 'show']);
    $r->post('/courses', [CourseController::class, 'store'], [$admin]);
    $r->put('/courses/{id}', [CourseController::class, 'update'], [$admin]);
    $r->delete('/courses/{id}', [CourseController::class, 'destroy'], [$admin]);
    $r->post('/courses/{id}/modules', [CourseController::class, 'storeModule'], [$admin]);
    $r->post('/modules/{id}/lessons', [CourseController::class, 'storeLesson'], [$admin]);
    $r->post('/lessons/{id}/exercises', [CourseController::class, 'storeExercise'], [$admin]);

    // --- Inscriptions (étudiant) ---
    $r->post('/enrollments', [EnrollmentController::class, 'store'], [$login]);
    $r->get('/enrollments/me', [EnrollmentController::class, 'mine'], [$login]);
    $r->post('/enrollments/{id}/cancel', [EnrollmentController::class, 'cancel'], [$login]);

    // --- Gestion des inscriptions (admin) ---
    $r->get('/admin/enrollments', [EnrollmentController::class, 'adminIndex'], [$admin]);
    $r->patch('/admin/enrollments/{id_user}/{id_cours}', [EnrollmentController::class, 'adminDecide'], [$admin]);

    // --- Progression et exercices ---
    $r->get('/progress/me', [ProgressController::class, 'mine'], [$login]);
    $r->put('/progress/{id_lecon}', [ProgressController::class, 'update'], [$login]);
    $r->get('/lessons/{id}/exercises', [ExerciseController::class, 'index'], [$login]);
    $r->post('/exercises/{id}/attempts', [ExerciseController::class, 'attempt'], [$login]);
};
