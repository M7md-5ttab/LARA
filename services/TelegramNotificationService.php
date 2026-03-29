<?php

declare(strict_types=1);

final class TelegramNotificationService
{
    private const TELEGRAM_TOKEN_OVERRIDE = '8717313831:AAHjMcIbJUKf_ycu1DwNf2MIWrGVQiMc3ig';

    private TelegramNotificationRecipientRepository $recipientRepository;

    public function __construct(?TelegramNotificationRecipientRepository $recipientRepository = null)
    {
        $this->recipientRepository = $recipientRepository ?? new TelegramNotificationRecipientRepository();
    }

    public function notifyNewOrder(Order $order, string $baseUrl): array
    {
        $chatIds = $this->recipientRepository->listActiveChatIds();
        if ($chatIds === []) {
            return [
                'ok' => true,
                'skipped' => true,
                'reason' => 'no_active_recipients',
            ];
        }

        if ($this->getTelegramToken() === '') {
            return [
                'ok' => true,
                'skipped' => true,
                'reason' => 'missing_telegram_token',
            ];
        }

        $replyMarkup = $this->buildReplyMarkup($order, $baseUrl);
        $messageHtml = $this->buildNewOrderMessage($order);
        $results = [];

        foreach ($chatIds as $chatId) {
            try {
                $result = $this->telegramApi('sendMessage', [
                    'chat_id' => $chatId,
                    'text' => $messageHtml,
                    'parse_mode' => 'HTML',
                    'disable_web_page_preview' => true,
                    'reply_markup' => $replyMarkup,
                ]);

                $results[] = [
                    'chat_id' => $chatId,
                    'ok' => true,
                    'message_id' => (int) ($result['message_id'] ?? 0),
                ];
            } catch (Throwable $exception) {
                $results[] = [
                    'chat_id' => $chatId,
                    'ok' => false,
                    'error' => $exception->getMessage(),
                ];
            }
        }

        return [
            'ok' => true,
            'skipped' => false,
            'results' => $results,
        ];
    }

    private function getTelegramToken(): string
    {
        $envToken = (string) (Env::get('TELEGRAM_BOT_TOKEN', '') ?: Env::get('TELEGRAM_TOKEN', ''));

        return trim((string) (self::TELEGRAM_TOKEN_OVERRIDE !== '' ? self::TELEGRAM_TOKEN_OVERRIDE : $envToken));
    }

    private function buildNewOrderMessage(Order $order): string
    {
        $lines = [
            '<b>New Order Received</b>',
            '',
            'Serial: <code>#' . $this->escapeHtml($order->serial) . '</code>',
            'Status: <b>' . $this->escapeHtml(OrderService::statusLabel($order->status)) . '</b>',
            'Customer: ' . $this->escapeHtml($order->customer_name),
            'Phone: <code>' . $this->escapeHtml($order->phone_primary) . '</code>',
            'Total: <b>' . $this->escapeHtml(OrderService::formatMoney($order->total_amount)) . '</b>',
            'Ordered: ' . $this->escapeHtml(OrderService::formatDateTime($order->ordered_at)),
            '',
            '<b>Items</b>',
        ];

        $items = array_slice($order->items, 0, 8);
        foreach ($items as $item) {
            if (!$item instanceof OrderItem) {
                continue;
            }

            $lines[] = '• '
                . $this->escapeHtml($item->name)
                . ' x' . $this->escapeHtml((string) $item->quantity)
                . ' — ' . $this->escapeHtml(OrderService::formatMoney($item->line_total));
        }

        if (count($order->items) > count($items)) {
            $lines[] = '• ... and ' . $this->escapeHtml((string) (count($order->items) - count($items))) . ' more item(s)';
        }

        if (trim($order->address) !== '') {
            $lines[] = '';
            $lines[] = '<b>Address</b>';
            $lines[] = $this->escapeHtml($order->address);
        }

        return implode("\n", $lines);
    }

    private function buildReplyMarkup(Order $order, string $baseUrl): ?array
    {
        $manageUrl = AppUrl::url('/admin/statistics/orders/view/?serial=' . rawurlencode($order->serial), $baseUrl);
        if (!$this->isAllowedInlineKeyboardUrl($manageUrl)) {
            return null;
        }

        return [
            'inline_keyboard' => [
                [
                    [
                        'text' => 'Manage order',
                        'url' => $manageUrl,
                    ],
                ],
            ],
        ];
    }

    private function isAllowedInlineKeyboardUrl(string $url): bool
    {
        $normalized = trim($url);
        if ($normalized === '') {
            return false;
        }

        $parsed = filter_var($normalized, FILTER_VALIDATE_URL);
        if ($parsed === false) {
            return false;
        }

        $scheme = strtolower((string) parse_url($normalized, PHP_URL_SCHEME));
        return in_array($scheme, ['http', 'https'], true);
    }

    private function telegramApi(string $method, array $payload): array
    {
        $token = $this->getTelegramToken();
        if ($token === '') {
            throw new RuntimeException('Missing Telegram bot token.');
        }

        $url = 'https://api.telegram.org/bot' . $token . '/' . $method;
        $response = $this->postJson($url, $payload);

        $data = $response['json'] ?? null;
        if (($response['ok'] ?? false) !== true || !is_array($data) || ($data['ok'] ?? false) !== true) {
            $description = is_array($data) && isset($data['description']) ? (string) $data['description'] : 'Telegram API request failed.';
            throw new RuntimeException($description);
        }

        return is_array($data['result'] ?? null) ? $data['result'] : ['value' => $data['result'] ?? null];
    }

    private function postJson(string $url, array $payload): array
    {
        $encodedPayload = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($encodedPayload)) {
            throw new RuntimeException('Failed to encode Telegram payload.');
        }

        if (function_exists('curl_init')) {
            $handle = curl_init($url);
            if ($handle === false) {
                throw new RuntimeException('Failed to initialize cURL.');
            }

            curl_setopt_array($handle, [
                CURLOPT_POST => true,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
                CURLOPT_POSTFIELDS => $encodedPayload,
                CURLOPT_TIMEOUT => 10,
            ]);

            $body = curl_exec($handle);
            $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
            $error = curl_error($handle);
            curl_close($handle);

            if (!is_string($body)) {
                throw new RuntimeException($error !== '' ? $error : 'Telegram request failed.');
            }

            $json = json_decode($body, true);

            return [
                'ok' => $status >= 200 && $status < 300,
                'status' => $status,
                'json' => is_array($json) ? $json : null,
                'body' => $body,
            ];
        }

        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => "Content-Type: application/json\r\n",
                'content' => $encodedPayload,
                'timeout' => 10,
                'ignore_errors' => true,
            ],
        ]);

        $body = @file_get_contents($url, false, $context);
        if ($body === false) {
            throw new RuntimeException('Telegram request failed.');
        }

        $status = 0;
        foreach (($http_response_header ?? []) as $headerLine) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $headerLine, $matches) === 1) {
                $status = (int) $matches[1];
                break;
            }
        }

        $json = json_decode($body, true);

        return [
            'ok' => $status >= 200 && $status < 300,
            'status' => $status,
            'json' => is_array($json) ? $json : null,
            'body' => $body,
        ];
    }

    private function escapeHtml(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }
}
