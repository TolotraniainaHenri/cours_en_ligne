<?php
declare(strict_types=1);

namespace App\Core;

/** Réponses JSON uniformes : {"...": ...} en succès, {"error": {"message", "details"}} en erreur. */
final class Response
{
    public static function json(mixed $data, int $status = 200): never
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        exit;
    }

    /** @param array<string,string> $details */
    public static function error(int $status, string $message, array $details = []): never
    {
        $error = ['message' => $message];
        if ($details !== []) {
            $error['details'] = $details;
        }
        self::json(['error' => $error], $status);
    }
}
