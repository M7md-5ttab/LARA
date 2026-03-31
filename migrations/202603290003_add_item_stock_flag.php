<?php

declare(strict_types=1);

return new class implements Migration
{
    public function getName(): string
    {
        return '202603290003_add_item_stock_flag';
    }

    public function up(PDO $pdo): void
    {
        $columnLookup = $pdo->query("SHOW COLUMNS FROM items LIKE 'is_out_of_stock'");
        if ($columnLookup === false || $columnLookup->fetch() !== false) {
            return;
        }

        $pdo->exec(
            'ALTER TABLE items
             ADD COLUMN is_out_of_stock TINYINT(1) NOT NULL DEFAULT 0
             AFTER base_price'
        );
    }
};
