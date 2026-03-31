<?php

declare(strict_types=1);

return new class implements Migration
{
    public function getName(): string
    {
        return '202603300002_create_telegram_conversation_states_table';
    }

    public function up(PDO $pdo): void
    {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS telegram_conversation_states (
                chat_id VARCHAR(32) NOT NULL PRIMARY KEY,
                state_key VARCHAR(64) NOT NULL,
                context_json LONGTEXT NULL,
                expires_at DATETIME NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_telegram_conversation_states_expires_at (expires_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    }
};
