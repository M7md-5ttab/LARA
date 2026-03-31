<?php

declare(strict_types=1);

final class TelegramConversationStateRepository
{
    private PDO $pdo;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?? Database::connection();
    }

    public function getActiveState(string $chatId): ?array
    {
        return $this->getStateSnapshot($chatId)['state'];
    }

    public function getStateSnapshot(string $chatId): array
    {
        $normalizedChatId = $this->normalizeChatId($chatId);

        $statement = $this->pdo->prepare(
            'SELECT chat_id, state_key, context_json, expires_at, created_at, updated_at
             FROM telegram_conversation_states
             WHERE chat_id = :chat_id
             LIMIT 1'
        );
        $statement->execute([
            'chat_id' => $normalizedChatId,
        ]);

        $row = $statement->fetch();
        if (!is_array($row)) {
            return [
                'state' => null,
                'expired' => false,
                'expired_state_key' => null,
                'expired_context' => null,
            ];
        }

        if ($this->isExpired($row['expires_at'] ?? null)) {
            $mappedRow = $this->mapRow($row);
            $this->clearState($normalizedChatId);
            return [
                'state' => null,
                'expired' => true,
                'expired_state_key' => $mappedRow['state_key'] ?? null,
                'expired_context' => $mappedRow['context'] ?? null,
            ];
        }

        return [
            'state' => $this->mapRow($row),
            'expired' => false,
            'expired_state_key' => null,
            'expired_context' => null,
        ];
    }

    public function saveState(string $chatId, string $stateKey, array $context = [], ?string $expiresAt = null): array
    {
        $normalizedChatId = $this->normalizeChatId($chatId);
        $normalizedStateKey = $this->normalizeStateKey($stateKey);
        $encodedContext = $this->encodeContext($context);
        $normalizedExpiresAt = $this->normalizeDateTimeOrNull($expiresAt);

        $statement = $this->pdo->prepare(
            'INSERT INTO telegram_conversation_states (
                chat_id,
                state_key,
                context_json,
                expires_at,
                created_at,
                updated_at
             ) VALUES (
                :chat_id,
                :state_key,
                :context_json,
                :expires_at,
                NOW(),
                NOW()
             )
             ON DUPLICATE KEY UPDATE
                state_key = VALUES(state_key),
                context_json = VALUES(context_json),
                expires_at = VALUES(expires_at),
                updated_at = NOW()'
        );
        $statement->execute([
            'chat_id' => $normalizedChatId,
            'state_key' => $normalizedStateKey,
            'context_json' => $encodedContext,
            'expires_at' => $normalizedExpiresAt,
        ]);

        $state = $this->getActiveState($normalizedChatId);
        if ($state === null) {
            throw new RuntimeException('Failed to save Telegram conversation state.');
        }

        return $state;
    }

    public function clearState(string $chatId): void
    {
        $normalizedChatId = $this->normalizeChatId($chatId);

        $statement = $this->pdo->prepare(
            'DELETE FROM telegram_conversation_states
             WHERE chat_id = :chat_id'
        );
        $statement->execute([
            'chat_id' => $normalizedChatId,
        ]);
    }

    private function normalizeChatId(string $chatId): string
    {
        $normalized = trim($chatId);
        if ($normalized === '' || !preg_match('/^-?\d{5,20}$/', $normalized)) {
            throw new RuntimeException('Invalid Telegram chat id.');
        }

        return $normalized;
    }

    private function normalizeStateKey(string $stateKey): string
    {
        $normalized = trim($stateKey);
        if ($normalized === '') {
            throw new RuntimeException('Telegram conversation state key is required.');
        }

        if (!preg_match('/^[a-z0-9_.:-]{3,64}$/i', $normalized)) {
            throw new RuntimeException('Telegram conversation state key is invalid.');
        }

        return strtolower($normalized);
    }

    private function encodeContext(array $context): string
    {
        $encoded = json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($encoded)) {
            throw new RuntimeException('Failed to encode Telegram conversation context.');
        }

        return $encoded;
    }

    private function normalizeDateTimeOrNull(?string $value): ?string
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        $date = date_create_immutable($value);
        if (!$date instanceof DateTimeImmutable) {
            throw new RuntimeException('Invalid Telegram conversation expiration time.');
        }

        return $date->format('Y-m-d H:i:s');
    }

    private function mapRow(array $row): array
    {
        $context = [];
        $decoded = json_decode((string) ($row['context_json'] ?? ''), true);
        if (is_array($decoded)) {
            $context = $decoded;
        }

        return [
            'chat_id' => (string) ($row['chat_id'] ?? ''),
            'state_key' => (string) ($row['state_key'] ?? ''),
            'context' => $context,
            'expires_at' => $row['expires_at'] !== null ? (string) $row['expires_at'] : null,
            'created_at' => (string) ($row['created_at'] ?? ''),
            'updated_at' => (string) ($row['updated_at'] ?? ''),
        ];
    }

    private function isExpired(mixed $expiresAt): bool
    {
        if ($expiresAt === null || trim((string) $expiresAt) === '') {
            return false;
        }

        $timestamp = strtotime((string) $expiresAt);
        return $timestamp !== false && $timestamp <= time();
    }
}
