<?php
declare(strict_types=1);

/**
 * Standalone Zero-Dependency PSR-4 Autoloader for KHQR library.
 * Allows Bakong KHQR to work on any hosting without requiring composer/vendor directory.
 */
spl_autoload_register(function (string $class): void {
    $prefix = 'KHQR\\';
    $base_dir = __DIR__ . '/src/';

    $len = strlen($prefix);
    if (strncmp($prefix, $class, $len) !== 0) {
        return;
    }

    $relative_class = substr($class, $len);
    $file = $base_dir . str_replace('\\', '/', $relative_class) . '.php';

    if (file_exists($file)) {
        require_once $file;
    }
});
