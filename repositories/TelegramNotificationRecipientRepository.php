<?php

declare(strict_types=1);

final class TelegramNotificationRecipientRepository
{
    private const PERMISSION_COLUMNS = [
        'can_receive_notifications',
        'can_use_pending',
        'can_use_prepared',
        'can_use_check',
        'can_use_help',
    ];
    private const COMMAND_PERMISSION_MAP = [
        'pending' => 'can_use_pending',
        'prepared' => 'can_use_prepared',
        'check' => 'can_use_check',
        'help' => 'can_use_help',
    ];

    private PDO $pdo;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?? Database::connection();
    }

    public function listRecipients(): array
    {
        $statement = $this->pdo->query(
            'SELECT id,
                    label,
                    chat_id,
                    is_active,
                    can_receive_notifications,
                    can_use_pending,
                    can_use_prepared,
                    can_use_check,
                    can_use_help,
                    created_at,
                    updated_at
             FROM telegram_notification_recipients
             ORDER BY is_active DESC, id DESC'
        );

        $rows = [];
        foreach ($statement->fetchAll() as $row) {
            if (!is_array($row)) {
                continue;
            }

            $rows[] = $this->mapRow($row);
        }

        return $rows;
    }

    public function listNotificationChatIds(): array
    {
        $statement = $this->pdo->query(
            'SELECT chat_id
             FROM telegram_notification_recipients
             WHERE is_active = 1
               AND can_receive_notifications = 1
             ORDER BY id ASC'
        );

        $chatIds = [];
        foreach ($statement->fetchAll() as $row) {
            $chatId = trim((string) ($row['chat_id'] ?? ''));
            if ($chatId === '') {
                continue;
            }

            $chatIds[] = $chatId;
        }

        return $chatIds;
    }

    public function listActiveChatIds(): array
    {
        return $this->listNotificationChatIds();
    }

    public function isActiveChatId(string $chatId): bool
    {
        return $this->findActiveRecipientByChatId($chatId) !== null;
    }

    public function canUseCommand(string $chatId, string $command): bool
    {
        $recipient = $this->findActiveRecipientByChatId($chatId);
        if ($recipient === null) {
            return false;
        }

        $permissionKey = self::COMMAND_PERMISSION_MAP[strtolower(trim($command))] ?? null;
        if ($permissionKey === null) {
            return false;
        }

        return ($recipient[$permissionKey] ?? false) === true;
    }

    public function getRecipientAccess(string $chatId): array
    {
        $recipient = $this->findActiveRecipientByChatId($chatId);
        if ($recipient !== null) {
            return $recipient;
        }

        return [
            'id' => 0,
            'label' => '',
            'chat_id' => $this->normalizeChatId($chatId),
            'is_active' => false,
            'can_receive_notifications' => false,
            'can_use_pending' => false,
            'can_use_prepared' => false,
            'can_use_check' => false,
            'can_use_help' => false,
            'available_commands' => [],
            'created_at' => '',
            'updated_at' => '',
        ];
    }

    public function findActiveRecipientByChatId(string $chatId): ?array
    {
        $normalizedChatId = $this->normalizeChatId($chatId);

        $statement = $this->pdo->prepare(
            'SELECT id,
                    label,
                    chat_id,
                    is_active,
                    can_receive_notifications,
                    can_use_pending,
                    can_use_prepared,
                    can_use_check,
                    can_use_help,
                    created_at,
                    updated_at
             FROM telegram_notification_recipients
             WHERE chat_id = :chat_id
               AND is_active = 1
             LIMIT 1'
        );
        $statement->execute([
            'chat_id' => $normalizedChatId,
        ]);

        $row = $statement->fetch();
        if (!is_array($row)) {
            return null;
        }

        return $this->mapRow($row);
    }

    public function createRecipient(string $chatId, string $label = '', array $permissions = []): array
    {
        $normalizedChatId = $this->normalizeChatId($chatId);
        $normalizedLabel = $this->normalizeLabel($label);
        $normalizedPermissions = $this->normalizePermissions($permissions, true);

        try {
            $statement = $this->pdo->prepare(
                'INSERT INTO telegram_notification_recipients (
                    label,
                    chat_id,
                    is_active,
                    can_receive_notifications,
                    can_use_pending,
                    can_use_prepared,
                    can_use_check,
                    can_use_help
                 ) VALUES (
                    :label,
                    :chat_id,
                    1,
                    :can_receive_notifications,
                    :can_use_pending,
                    :can_use_prepared,
                    :can_use_check,
                    :can_use_help
                 )'
            );
            $statement->execute([
                'label' => $normalizedLabel,
                'chat_id' => $normalizedChatId,
                'can_receive_notifications' => $normalizedPermissions['can_receive_notifications'] ? 1 : 0,
                'can_use_pending' => $normalizedPermissions['can_use_pending'] ? 1 : 0,
                'can_use_prepared' => $normalizedPermissions['can_use_prepared'] ? 1 : 0,
                'can_use_check' => $normalizedPermissions['can_use_check'] ? 1 : 0,
                'can_use_help' => $normalizedPermissions['can_use_help'] ? 1 : 0,
            ]);
        } catch (PDOException $exception) {
            if ($this->isDuplicateChatIdException($exception)) {
                throw new RuntimeException('This chat ID is already in the notification list.', 0, $exception);
            }

            throw $exception;
        }

        return $this->requireRecipientById((int) $this->pdo->lastInsertId());
    }

    public function updateRecipient(int $id, string $chatId, string $label, bool $isActive, array $permissions = []): array
    {
        $recipient = $this->requireRecipientById($id);
        $normalizedChatId = $this->normalizeChatId($chatId);
        $normalizedLabel = $this->normalizeLabel($label);
        $normalizedPermissions = $this->normalizePermissions($permissions, true);

        try {
            $statement = $this->pdo->prepare(
                'UPDATE telegram_notification_recipients
                 SET label = :label,
                     chat_id = :chat_id,
                     is_active = :is_active,
                     can_receive_notifications = :can_receive_notifications,
                     can_use_pending = :can_use_pending,
                     can_use_prepared = :can_use_prepared,
                     can_use_check = :can_use_check,
                     can_use_help = :can_use_help
                 WHERE id = :id'
            );
            $statement->execute([
                'id' => $recipient['id'],
                'label' => $normalizedLabel,
                'chat_id' => $normalizedChatId,
                'is_active' => $isActive ? 1 : 0,
                'can_receive_notifications' => $normalizedPermissions['can_receive_notifications'] ? 1 : 0,
                'can_use_pending' => $normalizedPermissions['can_use_pending'] ? 1 : 0,
                'can_use_prepared' => $normalizedPermissions['can_use_prepared'] ? 1 : 0,
                'can_use_check' => $normalizedPermissions['can_use_check'] ? 1 : 0,
                'can_use_help' => $normalizedPermissions['can_use_help'] ? 1 : 0,
            ]);
        } catch (PDOException $exception) {
            if ($this->isDuplicateChatIdException($exception)) {
                throw new RuntimeException('This chat ID is already in the notification list.', 0, $exception);
            }

            throw $exception;
        }

        return $this->requireRecipientById($recipient['id']);
    }

    public function deleteRecipient(int $id): void
    {
        $recipient = $this->requireRecipientById($id);

        $statement = $this->pdo->prepare(
            'DELETE FROM telegram_notification_recipients
             WHERE id = :id'
        );
        $statement->execute([
            'id' => $recipient['id'],
        ]);
    }

    private function requireRecipientById(int $id): array
    {
        if ($id <= 0) {
            throw new RuntimeException('Invalid recipient id.');
        }

        $statement = $this->pdo->prepare(
            'SELECT id,
                    label,
                    chat_id,
                    is_active,
                    can_receive_notifications,
                    can_use_pending,
                    can_use_prepared,
                    can_use_check,
                    can_use_help,
                    created_at,
                    updated_at
             FROM telegram_notification_recipients
             WHERE id = :id'
        );
        $statement->execute([
            'id' => $id,
        ]);

        $row = $statement->fetch();
        if (!is_array($row)) {
            throw new RuntimeException('Telegram recipient not found.');
        }

        return $this->mapRow($row);
    }

    private function mapRow(array $row): array
    {
        $recipient = [
            'id' => (int) ($row['id'] ?? 0),
            'label' => (string) ($row['label'] ?? ''),
            'chat_id' => (string) ($row['chat_id'] ?? ''),
            'is_active' => (int) ($row['is_active'] ?? 0) === 1,
            'can_receive_notifications' => (int) ($row['can_receive_notifications'] ?? 0) === 1,
            'can_use_pending' => (int) ($row['can_use_pending'] ?? 0) === 1,
            'can_use_prepared' => (int) ($row['can_use_prepared'] ?? 0) === 1,
            'can_use_check' => (int) ($row['can_use_check'] ?? 0) === 1,
            'can_use_help' => (int) ($row['can_use_help'] ?? 0) === 1,
            'created_at' => (string) ($row['created_at'] ?? ''),
            'updated_at' => (string) ($row['updated_at'] ?? ''),
        ];

        $recipient['available_commands'] = $this->extractAvailableCommands($recipient);

        return $recipient;
    }

    private function extractAvailableCommands(array $recipient): array
    {
        if (($recipient['is_active'] ?? false) !== true) {
            return [];
        }

        $commands = [];
        foreach (self::COMMAND_PERMISSION_MAP as $command => $permissionKey) {
            if (($recipient[$permissionKey] ?? false) === true) {
                $commands[] = $command;
            }
        }

        return $commands;
    }

    private function normalizePermissions(array $permissions, bool $defaultsEnabled): array
    {
        $defaults = $defaultsEnabled
            ? $this->defaultPermissions()
            : array_fill_keys(self::PERMISSION_COLUMNS, false);

        $normalized = [];
        foreach (self::PERMISSION_COLUMNS as $column) {
            if (array_key_exists($column, $permissions)) {
                $normalized[$column] = $this->normalizeBoolean($permissions[$column]);
                continue;
            }

            $normalized[$column] = (bool) ($defaults[$column] ?? false);
        }

        return $normalized;
    }

    private function defaultPermissions(): array
    {
        return [
            'can_receive_notifications' => true,
            'can_use_pending' => true,
            'can_use_prepared' => true,
            'can_use_check' => true,
            'can_use_help' => true,
        ];
    }

    private function normalizeBoolean(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        $normalized = strtolower(trim((string) $value));

        return in_array($normalized, ['1', 'true', 'yes', 'on'], true);
    }

    private function normalizeChatId(string $chatId): string
    {
        $normalized = trim($chatId);
        if ($normalized === '') {
            throw new RuntimeException('Chat ID is required.');
        }

        if (!preg_match('/^-?\d{5,20}$/', $normalized)) {
            throw new RuntimeException('Chat ID must be a Telegram numeric ID, for example 201508803316 or -1001234567890.');
        }

        return $normalized;
    }

    private function normalizeLabel(string $label): string
    {
        $normalized = trim(preg_replace('/\s+/', ' ', $label) ?? $label);
        $length = function_exists('mb_strlen') ? mb_strlen($normalized) : strlen($normalized);
        if ($length > 255) {
            throw new RuntimeException('Label must be 255 characters or fewer.');
        }

        return $normalized;
    }

    private function isDuplicateChatIdException(PDOException $exception): bool
    {
        return str_contains($exception->getMessage(), 'Duplicate entry')
            && str_contains($exception->getMessage(), 'chat_id');
    }
}
