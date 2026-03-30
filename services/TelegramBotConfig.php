<?php

declare(strict_types=1);

final class TelegramBotConfig
{
    public static function getBotToken(): string
    {
        $envToken = (string) (Env::get('TELEGRAM_BOT_TOKEN', '') ?: Env::get('TELEGRAM_TOKEN', ''));

        return trim($envToken);
    }

    public static function getBridgeSecret(): string
    {
        $token = self::getBotToken();
        if ($token === '') {
            return '';
        }

        return hash('sha256', 'lara-telegram-bridge|' . $token);
    }
}
