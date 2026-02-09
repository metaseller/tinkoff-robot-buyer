<?php

namespace app\models;

use Exception;
use Metaseller\TinkoffInvestApi2\providers\InstrumentsProvider;
use Metaseller\TinkoffInvestApi2\providers\MarketDataProvider;
use Metaseller\TinkoffInvestApi2\providers\PortfolioProvider;
use Metaseller\yii2TinkoffInvestApi2\TinkoffInvestApi;
use yii\base\Model;
use Yii;

/**
 * Модель-агрегатор доступов к сервисам Т-Инвестиций через библиотеку metaseller/tinkoff-invest-api-v2-php
 *
 * @see https://github.com/metaseller/tinkoff-invest-api-v2-php
 * @see https://github.com/metaseller/tinkoff-invest-api-v2-yii2
 */
class TInvestServices extends Model
{
    /**
     * @var TinkoffInvestApi[] Кеш созданных клиентов
     */
    protected static $_api_client_by_account = [];

    /**
     * @var array Кеш созданных провайдеров
     */
    protected static $_providers_by_account = [];

    /**
     * Получение массива настроек профилей T-Invest API из файла конфига
     *
     * @return array Массив настроек профилей T-Invest API
     */
    protected static function profilesConfig(): array
    {
        return Yii::$app->params['t-invest']['profiles'] ?? [];
    }

    /**
     * Метод получения данных аккаунта по алиасу или идентификатору портфеля (аккаунта)
     *
     * @param string $account Алиас портфеля или идентификатор портфеля
     *
     * @return array Массив, вида
     *  <pre>
     *      [
     *          'profile' => (string) $profile,
     *          'secret_key' => (string) $secret_key,
     *          'account_id' => (string) $account_id,
     *          'account_alias' => (string) $account_alias,
     *      ]
     *  </pre>
     *
     * @throws Exception
     */
    protected static function accountData(string $account): array
    {
        $is_alias = !ctype_digit($account);

        foreach (static::profilesConfig() as $profile_name => $profile_data) {
            foreach ($profile_data['accounts'] ?? [] as $account_alias => $account_id) {
                if ($account === ($is_alias ? $account_alias : $account_id)) {
                    $secret_key = $profile_data['secret_key'] ?? null;

                    if (empty($profile_name) || empty($account_id) || empty($secret_key) || empty($account_alias)) {
                        throw new Exception('Incorrect profile configuration');
                    }

                    return [
                        'profile' => $profile_name,
                        'secret_key' => $secret_key,
                        'account_id' => $account_id,
                        'account_alias' => $account_alias,
                    ];
                }
            }
        }

        throw new Exception('Account is not found');
    }

    /**
     * Метод получения данных профиля по имени профиля
     *
     * @param string $profile Имя профиля
     *
     * @return array Массив, вида
     *  <pre>
     *      [
     *          'profile' => (string) $profile_name,
     *          'secret_key' => (string) $secret_key,
     *          'account_id' => (string) $account_id,
     *          'account_alias' => (string) $account_alias,
     *      ]
     *  </pre>
     *
     * @throws Exception
     */
    protected static function profileData(string $profile): array
    {
        foreach (static::profilesConfig() as $profile_name => $profile_data) {
            if ($profile_name === $profile) {
                if ($default_account_id = $profile_data['default_account_id'] ?? null) {
                    return static::accountData($default_account_id);
                }

                break;
            }
        }

        throw new Exception('Profile is not found');
    }

    /**
     * Метод получения экземпляра клиента к T-Invest Api по имени профиля
     *
     * @param string $profile Имя профиля аккаунта
     *
     * @return TinkoffInvestApi Экземпляр клиента к T-Invest Api
     *
     * @throws Exception
     */
    public static function createTinkoffApiClient(string $profile = 'default'): TinkoffInvestApi
    {
        $account_data = static::profileData($profile);

        $token = $account_data['secret_key'] ?? null;

        if (!$token) {
            throw new Exception('T-Invest secret key is not found');
        }

        $profiles_config = static::profilesConfig();

        return new TinkoffInvestApi([
            'apiToken' => $token,
            'appName' => $profiles_config['app_name'] ?? 'metaseller.tinkoff-robot-buyer',
        ]);
    }

    /**
     * Метод получения экземпляра клиента к T-Invest Api по портфелю (аккаунту)
     *
     * По умолчанию экземпляр получается в режиме синглтона на каждый аккаунт
     *
     * @param string $account Алиас или идентификатор портфеля
     * @param bool $refresh Флаг необходимости обновить ранее полученный экземпляр клиента
     *
     * @return TinkoffInvestApi Экземпляр клиента к T-Invest Api
     *
     * @throws Exception
     */
    public static function client(string $account, bool $refresh = false): TinkoffInvestApi
    {
        $account_data = static::accountData($account);
        $account_id = $account_data['account_id'];
        $profile = $account_data['profile'];

        if ($refresh || empty(static::$_api_client_by_account[$account_id])) {
            static::$_api_client_by_account[$account_id] = static::createTinkoffApiClient($profile);
        }

        return static::$_api_client_by_account[$account_id];
    }

    /**
     * Метод получения провайдера {@link InstrumentsProvider} для аккаунта
     *
     * По умолчанию экземпляр получается в режиме синглтона на каждый аккаунт
     *
     * @param string $account Алиас или идентификатор портфеля
     * @param bool $refresh Флаг необходимости обновить ранее полученный экземпляр клиента
     *
     * @return InstrumentsProvider Созданный провайдер
     *
     * @throws Exception
     */
    public static function instruments(string $account, bool $refresh = false): InstrumentsProvider
    {
        $account_data = static::accountData($account);
        $account_id = $account_data['account_id'];

        if ($refresh || empty(static::$_providers_by_account[$account_id]['instruments'])) {
            static::$_providers_by_account[$account_id]['instruments'] = InstrumentsProvider::create(static::client($account_id, $refresh));
        }

        return static::$_providers_by_account[$account_id]['instruments'];
    }

    /**
     * Метод получения провайдера {@link MarketDataProvider} для аккаунта
     *
     * По умолчанию экземпляр получается в режиме синглтона на каждый аккаунт
     *
     * @param string $account Алиас или идентификатор портфеля
     * @param bool $refresh Флаг необходимости обновить ранее полученный экземпляр клиента
     *
     * @return MarketDataProvider Созданный провайдер
     *
     * @throws Exception
     */
    public static function marketdata(string $account, bool $refresh = false): MarketDataProvider
    {
        $account_data = static::accountData($account);
        $account_id = $account_data['account_id'];

        if ($refresh || empty(static::$_providers_by_account[$account_id]['marketdata'])) {
            static::$_providers_by_account[$account_id]['marketdata'] = MarketDataProvider::create(static::client($account_id, $refresh));
        }

        return static::$_providers_by_account[$account_id]['marketdata'];
    }

    /**
     * Метод получения провайдера {@link PortfolioProvider} для аккаунта
     *
     * По умолчанию экземпляр получается в режиме синглтона на каждый аккаунт
     *
     * @param string $account Алиас или идентификатор портфеля
     * @param bool $refresh Флаг необходимости обновить ранее полученный экземпляр клиента
     *
     * @return PortfolioProvider Созданный провайдер
     *
     * @throws Exception
     */
    public static function portfolio(string $account, bool $refresh = false): PortfolioProvider
    {
        $account_data = static::accountData($account);
        $account_id = $account_data['account_id'];

        if ($refresh || empty(static::$_providers_by_account[$account_id]['portfolio'])) {
            static::$_providers_by_account[$account_id]['portfolio'] = PortfolioProvider::create(static::client($account_id, $refresh));
        }

        return static::$_providers_by_account[$account_id]['portfolio'];
    }
}
