<?php

declare(strict_types=1);

final class MigrationRunner
{
    private PDO $pdo;
    private string $migrationsDirectory;

    public function __construct(?PDO $pdo = null, ?string $migrationsDirectory = null)
    {
        $this->pdo = $pdo ?? Database::connection();
        $this->migrationsDirectory = $migrationsDirectory ?? (PROJECT_ROOT . '/migrations');
    }

    public function runPending(): array
    {
        $this->ensureMigrationsTable();

        $executed = $this->executedMigrations();
        $ran = [];

        foreach ($this->migrationFiles() as $filePath) {
            $migration = require $filePath;

            if (!$migration instanceof Migration) {
                throw new RuntimeException("Migration file must return a Migration instance: {$filePath}");
            }

            $migrationName = $migration->getName();
            if (isset($executed[$migrationName])) {
                continue;
            }

            $migration->up($this->pdo);
            $this->markAsExecuted($migrationName);
            $ran[] = $migrationName;
        }

        return $ran;
    }

    private function ensureMigrationsTable(): void
    {
        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS migrations (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                migration_name VARCHAR(255) NOT NULL UNIQUE,
                executed_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    }

    private function executedMigrations(): array
    {
        $statement = $this->pdo->query('SELECT migration_name FROM migrations');
        $executed = [];

        foreach ($statement->fetchAll() as $row) {
            $migrationName = (string) ($row['migration_name'] ?? '');
            if ($migrationName === '') {
                continue;
            }

            $executed[$migrationName] = true;
        }

        return $executed;
    }

    private function migrationFiles(): array
    {
        $files = glob($this->migrationsDirectory . '/*.php');
        if ($files === false) {
            throw new RuntimeException("Failed to read migrations directory: {$this->migrationsDirectory}");
        }

        sort($files);

        return $files;
    }

    private function markAsExecuted(string $migrationName): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO migrations (migration_name) VALUES (:migration_name)'
        );
        $statement->execute([
            'migration_name' => $migrationName,
        ]);
    }
}
