<?php

declare(strict_types=1);

return new class implements Migration
{
    public function getName(): string
    {
        return '202603280001_create_menu_tables';
    }

    public function up(PDO $pdo): void
    {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS menu_settings (
                id TINYINT UNSIGNED NOT NULL PRIMARY KEY,
                version INT UNSIGNED NOT NULL DEFAULT 1,
                all_filter_label VARCHAR(255) NOT NULL DEFAULT \'All\',
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS categories (
                id VARCHAR(32) NOT NULL PRIMARY KEY,
                label VARCHAR(255) NOT NULL,
                sort_order INT UNSIGNED NOT NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_categories_sort_order (sort_order)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS subcategories (
                id VARCHAR(32) NOT NULL PRIMARY KEY,
                category_id VARCHAR(32) NOT NULL,
                label VARCHAR(255) NOT NULL,
                sort_order INT UNSIGNED NOT NULL,
                filter_sort_order INT UNSIGNED NOT NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_subcategories_category_sort (category_id, sort_order),
                INDEX idx_subcategories_filter_sort (filter_sort_order),
                CONSTRAINT fk_subcategories_category
                    FOREIGN KEY (category_id) REFERENCES categories (id)
                    ON DELETE CASCADE
                    ON UPDATE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS items (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                subcategory_id VARCHAR(32) NOT NULL,
                name_ar VARCHAR(255) NOT NULL,
                name_en VARCHAR(255) NOT NULL,
                image_url VARCHAR(1024) NOT NULL DEFAULT \'\',
                base_price DECIMAL(10, 2) NOT NULL,
                sort_order INT UNSIGNED NOT NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_items_subcategory_sort (subcategory_id, sort_order),
                CONSTRAINT fk_items_subcategory
                    FOREIGN KEY (subcategory_id) REFERENCES subcategories (id)
                    ON DELETE CASCADE
                    ON UPDATE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS item_sizes (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                item_id BIGINT UNSIGNED NOT NULL,
                name_ar VARCHAR(255) NOT NULL,
                name_en VARCHAR(255) NOT NULL,
                price DECIMAL(10, 2) NOT NULL,
                sort_order INT UNSIGNED NOT NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_item_sizes_item_sort (item_id, sort_order),
                CONSTRAINT fk_item_sizes_item
                    FOREIGN KEY (item_id) REFERENCES items (id)
                    ON DELETE CASCADE
                    ON UPDATE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        $pdo->exec(
            'INSERT INTO menu_settings (id, version, all_filter_label)
             VALUES (1, 1, \'All\')
             ON DUPLICATE KEY UPDATE id = id'
        );
    }
};
