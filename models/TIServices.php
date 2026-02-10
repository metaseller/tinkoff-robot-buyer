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
class TIServices extends Model
{
    /**
     * @var TinkoffInvestApi[] Кеш созданных клиентов
     */
    protected static $_api_clients = [];

    /**
     * @var array Кеш созданных провайдеров
     */
    protected static $_providers = [];

    /**
     * Получение массива настроек профилей T-Invest API из файла конфига
     *
     * @return array Массив настроек профилей T-Invest API
     */
    public static function TIConfig(): array
    {
        return Yii::$app->params['t-invest'] ?? [];
    }

    /**
     * Метод получения экземпляра клиента к T-Invest Api по DTO кредов профиля
     *
     * По умолчанию экземпляр получается в режиме синглтона на каждый профиль
     *
     * @param TIProfile $profile DTO кредов профиля
     * @param bool $refresh Флаг необходимости обновить ранее полученный экземпляр клиента
     *
     * @return TinkoffInvestApi Экземпляр клиента к T-Invest Api
     *
     * @throws Exception
     */
    public static function apiClient(TIProfile $profile, bool $refresh = false): TinkoffInvestApi
    {
        $alias = $profile->profileAlias;

        if ($refresh || empty(static::$_api_clients[$alias])) {
            static::$_api_clients[$alias] = new TinkoffInvestApi([
                'apiToken' => $profile->secretToken,
                'appName' => $profile->appName,
            ]);
        }

        return static::$_api_clients[$alias];
    }

    /**
     * Метод получения провайдера {@link InstrumentsProvider} для аккаунта
     *
     * По умолчанию экземпляр получается в режиме синглтона на каждый аккаунт
     *
     * @param TIProfile $profile DTO кредов профиля
     * @param bool $refresh Флаг необходимости обновить ранее полученный экземпляр клиента
     *
     * @return InstrumentsProvider Созданный провайдер
     *
     * @throws Exception
     */
    public static function instruments(TIProfile $profile, bool $refresh = false): InstrumentsProvider
    {
        $alias = $profile->profileAlias;

        if ($refresh || empty(static::$_providers[$alias]['instruments'])) {
            static::$_providers[$alias]['instruments'] = InstrumentsProvider::create(static::apiClient($profile, $refresh));
        }

        return static::$_providers[$alias]['instruments'];
    }

    /**
     * Метод получения провайдера {@link MarketDataProvider} для аккаунта
     *
     * По умолчанию экземпляр получается в режиме синглтона на каждый аккаунт
     *
     * @param TIProfile $profile DTO кредов профиля
     * @param bool $refresh Флаг необходимости обновить ранее полученный экземпляр клиента
     *
     * @return MarketDataProvider Созданный провайдер
     *
     * @throws Exception
     */
    public static function marketdata(TIProfile $profile, bool $refresh = false): MarketDataProvider
    {
        $alias = $profile->profileAlias;

        if ($refresh || empty(static::$_providers[$alias]['marketdata'])) {
            static::$_providers[$alias]['marketdata'] = MarketDataProvider::create(static::apiClient($profile, $refresh));
        }

        return static::$_providers[$alias]['marketdata'];
    }

    /**
     * Метод получения провайдера {@link PortfolioProvider} для аккаунта
     *
     * По умолчанию экземпляр получается в режиме синглтона на каждый аккаунт
     *
     * @param TIProfile $profile DTO кредов профиля
     * @param bool $refresh Флаг необходимости обновить ранее полученный экземпляр клиента
     *
     * @return PortfolioProvider Созданный провайдер
     *
     * @throws Exception
     */
    public static function portfolio(TIProfile $profile, bool $refresh = false): PortfolioProvider
    {
        $alias = $profile->profileAlias;

        if ($refresh || empty(static::$_providers[$alias]['portfolio'])) {
            static::$_providers[$alias]['portfolio'] = PortfolioProvider::create(static::apiClient($profile, $refresh));
        }

        return static::$_providers[$alias]['portfolio'];
    }
}
