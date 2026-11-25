<?php

declare(strict_types=1);

namespace SMTT\CIF\Validacao\Bootstrap;

final class Autoloader
{
    public static function register(string $prefix, string $basePath): void
    {
        $basePath = rtrim($basePath, '/\\') . DIRECTORY_SEPARATOR;
        spl_autoload_register(static function (string $class) use ($prefix, $basePath): void {
            if (!str_starts_with($class, $prefix)) {
                return;
            }
            $relative = substr($class, strlen($prefix));
            $file = $basePath . str_replace('\\', DIRECTORY_SEPARATOR, $relative) . '.php';
            if (is_file($file)) {
                require_once $file;
            }
        });
    }

    private function __construct()
    {
    }
}
