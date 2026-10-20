<?php
declare(strict_types=1);

namespace App\Core;

/** Erreur "métier" renvoyée au client avec un code HTTP précis. */
final class HttpException extends \RuntimeException
{
    /**
     * @param array<string,string> $errors détails par champ (validation)
     */
    public function __construct(
        public readonly int $status,
        string $message,
        public readonly array $errors = []
    ) {
        parent::__construct($message, $status);
    }
}
