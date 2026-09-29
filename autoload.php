<?php

/**
 * Autoload do plugin (namespace GlpiPlugin\Checklistitens\... -> src/GlpiPlugin/Checklistitens/...),
 * sem depender de "composer install" no servidor. Incluído por setup.php e hook.php.
 */

spl_autoload_register(static function (string $class): void {
    $prefix = 'GlpiPlugin\\Checklistitens\\';

    if (strncmp($prefix, $class, strlen($prefix)) !== 0) {
        return;
    }

    $file = __DIR__ . '/src/' . str_replace('\\', '/', $class) . '.php';

    if (is_file($file)) {
        require $file;
    }
});
