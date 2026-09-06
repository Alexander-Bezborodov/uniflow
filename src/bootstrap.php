<?php

declare(strict_types=1);

if (PHP_VERSION_ID < 70400 || PHP_INT_SIZE < 8) {
    throw new RuntimeException('Requires PHP 7.4+ (64-bit)');
}
spl_autoload_register(function (string $class): void {
    $prefix = 'UniFlow\\';
    if (strpos($class, $prefix) === 0) {
        $path = __DIR__ . '/' . substr($class, strlen($prefix)) . '.php';
        if (is_file($path)) {
            require $path;
        }
    }
});
