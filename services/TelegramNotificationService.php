<?php

declare(strict_types=1);

final class TelegramNotificationService
{
    private TelegramNotificationRecipientRepository $recipientRepository;

    public function __construct(?TelegramNotificationRecipientRepository $recipientRepository = null)
    {
        $this->recipientRepository = $recipientRepository ?? new TelegramNotificationRecipientRepository();
    }

    public function notifyNewOrder(Order $order, string $baseUrl): array
    {
        $chatIds = $this->recipientRepository->listNotificationChatIds();
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

        $messageHtml = $this->buildNewOrderMessage($order);
        $results = [];

        foreach ($chatIds as $chatId) {
            try {
                $payload = [
                    'chat_id' => $chatId,
                    'text' => $messageHtml,
                    'parse_mode' => 'HTML',
                    'disable_web_page_preview' => true,
                ];
                $replyMarkup = $this->buildOrderReplyMarkup($order);
                if ($replyMarkup !== null) {
                    $payload['reply_markup'] = $replyMarkup;
                }
                $result = $this->telegramApi('sendMessage', $payload);

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
        return TelegramBotConfig::getBotToken();
    }

    private function buildNewOrderMessage(Order $order): string
    {
        $lines = [
            '<b>🆕 طلب جديد</b>',
            '',
            '<b>رقم الطلب:</b> <code>#' . $this->escapeHtml($order->serial) . '</code>',
            '<b>الحالة:</b> <b>' . $this->escapeHtml($this->statusLabelArabic($order->status)) . '</b>',
            '<b>وقت الطلب:</b> ' . $this->escapeHtml($this->formatDateTimeArabic($order->ordered_at)),
            '<b>الإجمالي:</b> <b>' . $this->escapeHtml($this->formatMoneyArabic($order->total_amount)) . '</b>',
            '',
            '<b>👤 بيانات العميل</b>',
            '<b>الاسم:</b> ' . $this->escapeHtml($order->customer_name),
            '<b>الهاتف 1:</b> <code>' . $this->escapeHtml($order->phone_primary) . '</code>',
            '<b>الهاتف 2:</b> <code>' . $this->escapeHtml($order->phone_secondary ?? 'غير متوفر') . '</code>',
            '',
            '<b>📍 العنوان</b>',
            $this->escapeHtml(trim($order->address) !== '' ? $order->address : 'غير متوفر'),
            '',
            '<b>🧾 الأصناف</b>',
        ];

        $items = array_slice($order->items, 0, 8);
        foreach ($items as $item) {
            if (!$item instanceof OrderItem) {
                continue;
            }

            $lines[] = '• '
                . $this->escapeHtml($item->name)
                . ' × ' . $this->escapeHtml((string) $item->quantity)
                . ' - ' . $this->escapeHtml($this->formatMoneyArabic($item->line_total));
        }

        if (count($order->items) > count($items)) {
            $lines[] = '• ويوجد ' . $this->escapeHtml((string) (count($order->items) - count($items))) . ' صنف إضافي';
        }

        return $this->rtl(implode("\n", $lines));
    }

    private function buildOrderReplyMarkup(Order $order): ?array
    {
        $serial = trim((string) $order->serial);
        if ($serial === '') {
            return null;
        }

        if ($order->status === OrderService::STATUS_PENDING) {
            return [
                'inline_keyboard' => [
                    [
                        ['text' => '🧑‍🍳 تجهيز الطلب', 'callback_data' => 'p1:' . $serial],
                        ['text' => '❌ إلغاء الطلب', 'callback_data' => 'c1:' . $serial],
                    ],
                ],
            ];
        }

        if ($order->status === OrderService::STATUS_PREPARING) {
            return [
                'inline_keyboard' => [
                    [
                        ['text' => '✅ تم التسليم', 'callback_data' => 'd1:' . $serial],
                        ['text' => '❌ إلغاء الطلب', 'callback_data' => 'c1:' . $serial],
                    ],
                ],
            ];
        }

        return null;
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

    private function formatMoneyArabic(int|float $amount): string
    {
        return number_format((float) $amount, 2, '.', '') . ' ج.م';
    }

    private function statusLabelArabic(string $status): string
    {
        return match ($status) {
            OrderService::STATUS_PENDING => 'معلق',
            OrderService::STATUS_PREPARING => 'قيد التحضير',
            OrderService::STATUS_DELIVERED => 'تم التسليم',
            OrderService::STATUS_CANCELLED => 'ملغي',
            default => 'غير معروف',
        };
    }

    private function formatDateTimeArabic(?string $value): string
    {
        if ($value === null || trim($value) === '') {
            return 'غير متوفر';
        }

        $timestamp = strtotime($value);
        if ($timestamp === false) {
            return $value;
        }

        return str_replace(['AM', 'PM'], ['ص', 'م'], date('Y-m-d h:i A', $timestamp));
    }

    private function rtl(string $text): string
    {
        $lines = preg_split("/\r\n|\r|\n/", $text) ?: [$text];
        $rtlLines = [];

        foreach ($lines as $line) {
            $rtlLines[] = "\u{200F}" . $line;
        }

        return implode("\n", $rtlLines);
    }
}
