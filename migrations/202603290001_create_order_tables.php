<?php

declare(strict_types=1);

return new class implements Migration
{
    public function getName(): string
    {
        return '202603290001_create_order_tables';
    }

    public function up(PDO $pdo): void
    {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS order_counters (
                id TINYINT UNSIGNED NOT NULL PRIMARY KEY,
                next_serial INT UNSIGNED NOT NULL DEFAULT 0,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS orders (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                serial_number INT UNSIGNED NOT NULL UNIQUE,
                access_token CHAR(64) NOT NULL,
                status VARCHAR(24) NOT NULL,
                customer_name VARCHAR(255) NOT NULL,
                address TEXT NOT NULL,
                phone_primary VARCHAR(64) NOT NULL,
                phone_secondary VARCHAR(64) NULL,
                cancel_reason TEXT NULL,
                total_amount DECIMAL(10, 2) NOT NULL,
                ordered_at DATETIME NOT NULL,
                preparing_at DATETIME NULL,
                closed_at DATETIME NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_orders_status (status),
                INDEX idx_orders_ordered_at (ordered_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS order_items (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                order_id BIGINT UNSIGNED NOT NULL,
                item_name VARCHAR(512) NOT NULL,
                quantity INT UNSIGNED NOT NULL,
                unit_price DECIMAL(10, 2) NOT NULL,
                line_total DECIMAL(10, 2) NOT NULL,
                sort_order INT UNSIGNED NOT NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_order_items_order_sort (order_id, sort_order),
                CONSTRAINT fk_order_items_order
                    FOREIGN KEY (order_id) REFERENCES orders (id)
                    ON DELETE CASCADE
                    ON UPDATE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        $pdo->exec(
            'INSERT INTO order_counters (id, next_serial)
             VALUES (1, 0)
             ON DUPLICATE KEY UPDATE id = id'
        );
    }
};
