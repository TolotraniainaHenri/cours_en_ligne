<?php
declare(strict_types=1);

/**
 * Autoloader PSR-4 minimal : App\Core\Router -> src/Core/Router.php
 * (pas besoin de Composer pour ce projet).
 */
spl_autoload_register(static function (string $class): void {
    $prefix = 'App\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $file = __DIR__ . '/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_file($file)) {
        require $file;
    }
});
