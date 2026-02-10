<?php

namespace app\models;

use Exception;
use Metaseller\TinkoffInvestApi2\ModelTrait;
use SonkoDmitry\Yii\TelegramBot\Component as TelegramBotApi;
use Yii;
use yii\base\Model;

/**
 * Модель получения клиента Telegram Bot API и чата для указанного аккаунта (портфеля)
 */
class TelegramBot extends Model
{
    use ModelTrait;

    /**
     * @var TelegramBotApi[] Созданные экземпляры клиентов к Telegram Bot Api
     */
    protected static $_clients = [];

    /**
     * @return array
     */
    protected static function telegramBotsConfig(): array
    {
        return Yii::$app->params['telegram'] ?? [];
    }

    /**
     * Метод получения клиента к Telegram Bot Api и идентификатор чата по аккаунту
     *
     * @param string $account
     * @param bool $refresh Флаг необходимости обновить ранее полученный экземпляр клиента
     *
     * @return array|null[] Массив вида
     *  <pre>
     *     [{@link TelegramBotApi} $client, (string) $chat_id]
     *  </pre>
     *
     * @throws Exception
     */
    public static function create(string $account, bool $refresh = false): array
    {
        $account = TIAccount::create($account);

        $telegram_bot_client = null;

        if (!$refresh && !empty(static::$_clients[$account->accountAlias])) {
            $telegram_bot_client = static::$_clients[$account->accountAlias];
        }

        foreach (static::telegramBotsConfig() as $account_alias => $bot_config) {
            if ($account_alias === $account->accountAlias) {
                $telegram_bot_token = $bot_config['token'] ?? null;
                $chat_id = $bot_config['chat_id'] ?? null;

                if ($telegram_bot_token && $chat_id) {
                    $telegram_bot_client = $telegram_bot_client ?? (new TelegramBotApi(['apiToken' => $telegram_bot_token]));
                    static::$_clients[$account->accountAlias] = $telegram_bot_client;

                    return [$telegram_bot_client, $chat_id];
                }

                break;
            }
        }

        return [null, null];
    }

    /**
     * Метод отправки сообщения в Telegram чат, если он настроен для портфеля в конфигах
     *
     * @param string $account Алиас или идентификатор аккаунта
     * @param string $text Текст к отправке
     * @param string $parse_mode Режим отправки
     *
     * @throws Exception
     */
    public static function notifyTelegram(string $account, string $text, string $parse_mode = 'Markdown'): void
    {
        try {
            list($bot, $chat_id) = static::create($account);

            if (!$bot || !$chat_id) {
                return;
            }

            $bot->sendMessage($chat_id, $text, $parse_mode);
        } catch (Throwable $e2) {
        }
    }
}
