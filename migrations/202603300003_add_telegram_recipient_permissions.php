<?php

declare(strict_types=1);

return new class implements Migration
{
    public function getName(): string
    {
        return '202603300003_add_telegram_recipient_permissions';
    }

    public function up(PDO $pdo): void
    {
        $tableLookup = $pdo->query("SHOW TABLES LIKE 'telegram_notification_recipients'");
        if ($tableLookup === false || $tableLookup->fetch() === false) {
            return;
        }

        $columns = [
            'can_receive_notifications' => 'ALTER TABLE telegram_notification_recipients ADD COLUMN can_receive_notifications TINYINT(1) NOT NULL DEFAULT 1 AFTER is_active',
            'can_use_pending' => 'ALTER TABLE telegram_notification_recipients ADD COLUMN can_use_pending TINYINT(1) NOT NULL DEFAULT 1 AFTER can_receive_notifications',
            'can_use_prepared' => 'ALTER TABLE telegram_notification_recipients ADD COLUMN can_use_prepared TINYINT(1) NOT NULL DEFAULT 1 AFTER can_use_pending',
            'can_use_check' => 'ALTER TABLE telegram_notification_recipients ADD COLUMN can_use_check TINYINT(1) NOT NULL DEFAULT 1 AFTER can_use_prepared',
            'can_use_help' => 'ALTER TABLE telegram_notification_recipients ADD COLUMN can_use_help TINYINT(1) NOT NULL DEFAULT 1 AFTER can_use_check',
        ];

        foreach ($columns as $columnName => $sql) {
            $columnLookup = $pdo->query("SHOW COLUMNS FROM telegram_notification_recipients LIKE '{$columnName}'");
            if ($columnLookup !== false && $columnLookup->fetch() === false) {
                $pdo->exec($sql);
            }
        }
    }
};
