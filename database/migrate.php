<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';

if (PHP_SAPI !== 'cli') {
    throw new RuntimeException('This script must be run from the command line.');
}

try {
    $runner = new MigrationRunner();
    $ranMigrations = $runner->runPending();

    if ($ranMigrations === []) {
        echo "No pending migrations.\n";
        exit(0);
    }

    foreach ($ranMigrations as $migrationName) {
        echo "Ran migration: {$migrationName}\n";
    }
} catch (Throwable $exception) {
    fwrite(STDERR, $exception->getMessage() . PHP_EOL);
    exit(1);
}
