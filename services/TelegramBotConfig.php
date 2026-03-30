<?php

declare(strict_types=1);

final class TelegramBotConfig
{
    private const TELEGRAM_TOKEN_OVERRIDE = '8717313831:AAHjMcIbJUKf_ycu1DwNf2MIWrGVQiMc3ig';

    public static function getBotToken(): string
    {
        $envToken = (string) (Env::get('TELEGRAM_BOT_TOKEN', '') ?: Env::get('TELEGRAM_TOKEN', ''));

        return trim((string) (self::TELEGRAM_TOKEN_OVERRIDE !== '' ? self::TELEGRAM_TOKEN_OVERRIDE : $envToken));
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
