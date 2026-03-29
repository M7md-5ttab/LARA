<?php

declare(strict_types=1);

final class TelegramNotificationRecipientRepository
{
    private PDO $pdo;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?? Database::connection();
    }

    public function listRecipients(): array
    {
        $statement = $this->pdo->query(
            'SELECT id, label, chat_id, is_active, created_at, updated_at
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

    public function listActiveChatIds(): array
    {
        $statement = $this->pdo->query(
            'SELECT chat_id
             FROM telegram_notification_recipients
             WHERE is_active = 1
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

    public function createRecipient(string $chatId, string $label = ''): array
    {
        $normalizedChatId = $this->normalizeChatId($chatId);
        $normalizedLabel = $this->normalizeLabel($label);

        try {
            $statement = $this->pdo->prepare(
                'INSERT INTO telegram_notification_recipients (label, chat_id, is_active)
                 VALUES (:label, :chat_id, 1)'
            );
            $statement->execute([
                'label' => $normalizedLabel,
                'chat_id' => $normalizedChatId,
            ]);
        } catch (PDOException $exception) {
            if ($this->isDuplicateChatIdException($exception)) {
                throw new RuntimeException('This chat ID is already in the notification list.', 0, $exception);
            }

            throw $exception;
        }

        return $this->requireRecipientById((int) $this->pdo->lastInsertId());
    }

    public function updateRecipient(int $id, string $chatId, string $label, bool $isActive): array
    {
        $recipient = $this->requireRecipientById($id);
        $normalizedChatId = $this->normalizeChatId($chatId);
        $normalizedLabel = $this->normalizeLabel($label);

        try {
            $statement = $this->pdo->prepare(
                'UPDATE telegram_notification_recipients
                 SET label = :label,
                     chat_id = :chat_id,
                     is_active = :is_active
                 WHERE id = :id'
            );
            $statement->execute([
                'id' => $recipient['id'],
                'label' => $normalizedLabel,
                'chat_id' => $normalizedChatId,
                'is_active' => $isActive ? 1 : 0,
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
            'SELECT id, label, chat_id, is_active, created_at, updated_at
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
        return [
            'id' => (int) ($row['id'] ?? 0),
            'label' => (string) ($row['label'] ?? ''),
            'chat_id' => (string) ($row['chat_id'] ?? ''),
            'is_active' => (int) ($row['is_active'] ?? 0) === 1,
            'created_at' => (string) ($row['created_at'] ?? ''),
            'updated_at' => (string) ($row['updated_at'] ?? ''),
        ];
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
