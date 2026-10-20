<?php
declare(strict_types=1);

namespace App\Core;

/** Journal d'erreurs dans backend/storage/logs/app.log (jamais montré au client). */
final class Logger
{
    public static function error(string $message, ?\Throwable $e = null): void
    {
        $line = sprintf("[%s] %s", date('c'), $message);
        if ($e !== null) {
            $line .= sprintf(' | %s: %s @ %s:%d', get_class($e), $e->getMessage(), $e->getFile(), $e->getLine());
        }
        $file = dirname(__DIR__, 2) . '/storage/logs/app.log';
        @file_put_contents($file, $line . "\n", FILE_APPEND | LOCK_EX);
    }

    public static function security(string $event, string $ip, string $detail = ''): void
    {
        self::error(sprintf('SECURITY %s ip=%s %s', $event, $ip, $detail));
    }
}
