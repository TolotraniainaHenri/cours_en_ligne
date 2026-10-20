<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;

/** Règles d'accès aux contenus : un étudiant n'accède à un cours que si son inscription est validée. */
final class Access
{
    public static function enrollmentStatus(int $userId, int $coursId): ?string
    {
        $row = Database::one(
            'SELECT statut FROM inscription WHERE id_user = :u AND id_cours = :c',
            ['u' => $userId, 'c' => $coursId]
        );
        return $row['statut'] ?? null;
    }

    /** @param array{id:int,role:string}|null $user */
    public static function canAccessCourse(?array $user, int $coursId): bool
    {
        if ($user === null) {
            return false;
        }
        if ($user['role'] === 'admin') {
            return true;
        }
        return self::enrollmentStatus((int) $user['id'], $coursId) === 'validee';
    }

    public static function courseIdOfLesson(int $lessonId): ?int
    {
        $row = Database::one(
            'SELECT m.id_cours FROM lecon l JOIN module m ON m.id = l.id_module WHERE l.id = :id',
            ['id' => $lessonId]
        );
        return $row === null ? null : (int) $row['id_cours'];
    }
}
