<?php

namespace app\models;

use Metaseller\TinkoffInvestApi2\exceptions\ValidateException;
use Metaseller\TinkoffInvestApi2\providers\InstrumentsProvider;
use Metaseller\TinkoffInvestApi2\providers\MarketDataProvider;
use Metaseller\TinkoffInvestApi2\providers\PortfolioProvider;
use Metaseller\yii2TinkoffInvestApi2\TinkoffInvestApi;
use yii\base\Model;
use Yii;

/**
 *
 */
class TBankApi extends Model
{
    /**
     * @var TinkoffInvestApi[]
     */
    protected static $_api_client_by_account = [];

    /**
     * @var TinkoffInvestApi[]
     */
    protected static $_api_client_by_alias = [];

    /**
     * @var array
     */
    protected static $_providers_by_account = [];

    /**
     * @var array
     */
    protected static $_providers_by_alias = [];

    /**
     * @param string $account_id
     * @param bool $refresh
     *
     * @return TinkoffInvestApi
     *
     * @throws ValidateException
     */
    public static function clientByAccount(string $account_id, bool $refresh = false): TinkoffInvestApi
    {
        if ($refresh || empty(static::$_api_client_by_account[$account_id])) {
            static::$_api_client_by_account[$account_id] = static::tinkoffApiClientByAccountId($account_id);
        }

        return static::$_api_client_by_account[$account_id];
    }

    /**
     * @param string $credentials_alias
     * @param bool $refresh
     *
     * @return TinkoffInvestApi
     *
     * @throws ValidateException
     */
    public static function clientByAlias(string $credentials_alias, bool $refresh = false): TinkoffInvestApi
    {
        if ($refresh || empty(static::$_api_client_by_alias[$credentials_alias])) {
            static::$_api_client_by_alias[$credentials_alias] = static::tinkoffApiClientByAlias($credentials_alias);
        }

        return static::$_api_client_by_alias[$credentials_alias];
    }

    /**
     * @param string $account_id
     * @param bool $refresh
     * @return InstrumentsProvider
     * @throws ValidateException
     */
    public static function instrumentsForAccount(string $account_id, bool $refresh = false): InstrumentsProvider
    {
        if ($refresh || empty(static::$_providers_by_account[$account_id]['instruments'])) {
            static::$_providers_by_account[$account_id]['instruments'] = InstrumentsProvider::create(static::clientByAccount($account_id, $refresh));
        }

        return static::$_providers_by_account[$account_id]['instruments'];
    }

    /**
     * @param string $credentials_alias
     * @param bool $refresh
     * @return InstrumentsProvider
     * @throws ValidateException
     */
    public static function instrumentsForAlias(string $credentials_alias, bool $refresh = false): InstrumentsProvider
    {
        if ($refresh || empty(static::$_providers_by_alias[$credentials_alias]['instruments'])) {
            static::$_providers_by_alias[$credentials_alias]['instruments'] = InstrumentsProvider::create(static::clientByAlias($credentials_alias, $refresh));
        }

        return static::$_providers_by_alias[$credentials_alias]['instruments'];
    }

    /**
     * @param string $account_id
     * @param bool $refresh
     * @return MarketDataProvider
     * @throws ValidateException
     */
    public static function marketdataForAccount(string $account_id, bool $refresh = false): MarketDataProvider
    {
        if ($refresh || empty(static::$_providers_by_account[$account_id]['marketdata'])) {
            static::$_providers_by_account[$account_id]['marketdata'] = MarketDataProvider::create(static::clientByAccount($account_id, $refresh));
        }

        return static::$_providers_by_account[$account_id]['marketdata'];
    }

    /**
     * @param string $credentials_alias
     * @param bool $refresh
     * @return InstrumentsProvider
     * @throws ValidateException
     */
    public static function marketdataForAlias(string $credentials_alias, bool $refresh = false): MarketDataProvider
    {
        if ($refresh || empty(static::$_providers_by_alias[$credentials_alias]['marketdata'])) {
            static::$_providers_by_alias[$credentials_alias]['marketdata'] = MarketDataProvider::create(static::clientByAlias($credentials_alias, $refresh));
        }

        return static::$_providers_by_alias[$credentials_alias]['marketdata'];
    }

    /**
     * @param string $account_id
     * @param bool $refresh
     * @return PortfolioProvider
     * @throws ValidateException
     */
    public static function portfolioForAccount(string $account_id, bool $refresh = false): PortfolioProvider
    {
        if ($refresh || empty(static::$_providers_by_account[$account_id]['portfolio'])) {
            static::$_providers_by_account[$account_id]['portfolio'] = PortfolioProvider::create(static::clientByAccount($account_id, $refresh));
        }

        return static::$_providers_by_account[$account_id]['portfolio'];
    }

    /**
     * @param string $credentials_alias
     * @param bool $refresh
     * @return PortfolioProvider
     * @throws ValidateException
     */
    public static function portfolioForAlias(string $credentials_alias, bool $refresh = false): PortfolioProvider
    {
        if ($refresh || empty(static::$_providers_by_alias[$credentials_alias]['portfolio'])) {
            static::$_providers_by_alias[$credentials_alias]['portfolio'] = PortfolioProvider::create(static::clientByAlias($credentials_alias, $refresh));
        }

        return static::$_providers_by_alias[$credentials_alias]['portfolio'];
    }

    /**
     * Метод получения экземпляра клиента к tinkoffInvestApi по алиасу токена доступа
     *
     * @param string $credentials_alias Алиас токена доступа в файле {@see ./credentials.php}
     *
     * @return TinkoffInvestApi Экземпляр клиента к tinkoffInvestApi
     *
     * @throws ValidateException
     */
    protected static function tinkoffApiClientByAlias(string $credentials_alias = 'default'): TinkoffInvestApi
    {
        $credentials = Yii::$app->params['tinkoff_invest']['credentials'][$credentials_alias] ?? [];
        $token = $credentials['secret_key'] ?? null;

        if (!$token) {
            throw new ValidateException('Unknown credential alias');
        }

        return new TinkoffInvestApi([
            'apiToken' => $token,
            'appName' => Yii::$app->params['tinkoff_invest']['app_name'] ?? 'metaseller.tinkoff-invest-api-v2-yii2',
        ]);
    }

    /**
     * Метод получения экземпляра клиента к tinkoffInvestApi по идентификатору аккаунта
     *
     * @param string $account_id Идентификатор аккаунта
     *
     * @return TinkoffInvestApi Экземпляр клиента к tinkoffInvestApi
     *
     * @throws ValidateException
     */
    protected static function tinkoffApiClientByAccountId(string $account_id): TinkoffInvestApi
    {
        $credentials = Yii::$app->params['tinkoff_invest']['credentials'] ?? [];

        foreach ($credentials as $credentials_alias => $credentials_data) {
            if (($credentials_data['account_id'] ?? '') === $account_id) {
                return static::tinkoffApiClientByAlias($credentials_alias);
            }
        }

        foreach ($credentials as $credentials_alias => $credentials_data) {
            foreach ($credentials_data['accounts_shortcuts'] ?? [] as $shortcut_account_id)
                if ($shortcut_account_id === $account_id) {
                    return static::tinkoffApiClientByAlias($credentials_alias);
                }
        }

        throw new ValidateException('Account is not found in credentials config');
    }
}
