<?php

declare(strict_types=1);

namespace SMTT\CIF\Gestao\Bootstrap;

final class Autoloader
{
    public static function register(string $prefix, string $baseDir): void
    {
        $baseDir = rtrim($baseDir, '/\\') . DIRECTORY_SEPARATOR;

        spl_autoload_register(static function (string $class) use ($prefix, $baseDir): void {
            if (!str_starts_with($class, $prefix)) {
                return;
            }

            $relativeClass = substr($class, strlen($prefix));
            $relativePath = str_replace('\\', DIRECTORY_SEPARATOR, $relativeClass) . '.php';
            $file = $baseDir . $relativePath;

            if (is_file($file)) {
                require_once $file;
            }
        });
    }
}
