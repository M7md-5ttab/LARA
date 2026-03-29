<?php

declare(strict_types=1);

if (!defined('PROJECT_ROOT')) {
    define('PROJECT_ROOT', __DIR__);
}

require_once PROJECT_ROOT . '/functions/Env.php';

Env::load(PROJECT_ROOT . '/.env');

spl_autoload_register(static function (string $className): void {
    $directories = [
        'database',
        'functions',
        'models',
        'repositories',
        'services',
    ];

    foreach ($directories as $directory) {
        $path = PROJECT_ROOT . '/' . $directory . '/' . $className . '.php';
        if (is_file($path)) {
            require_once $path;
            return;
        }
    }
});
