<?php

declare(strict_types=1);

return new class implements Migration
{
    public function getName(): string
    {
        return '202603300001_add_order_delivery_details';
    }

    public function up(PDO $pdo): void
    {
        $tableLookup = $pdo->query("SHOW TABLES LIKE 'orders'");
        if ($tableLookup === false || $tableLookup->fetch() === false) {
            return;
        }

        $columnLookup = $pdo->query("SHOW COLUMNS FROM orders LIKE 'delivered_by'");
        if ($columnLookup !== false && $columnLookup->fetch() === false) {
            $pdo->exec(
                'ALTER TABLE orders
                 ADD COLUMN delivered_by VARCHAR(255) NULL
                 AFTER phone_secondary'
            );
        }

        $pdo->exec(
            "UPDATE orders
             SET status = 'delivered'
             WHERE status = 'received'"
        );
    }
};
