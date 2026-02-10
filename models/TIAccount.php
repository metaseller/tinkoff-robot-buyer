<?php

namespace app\models;

use Exception;
use Metaseller\TinkoffInvestApi2\ModelTrait;
use yii\base\Model;

/**
 * Модель DTO кредов аккаунта (портфеля) T-Invest API
 *
 * Аккаунт - это символьный алиас портфеля и идентификатор портфеля в рамках профиля T-Invest
 *
 * @property-read TIProfile $profile DTO кредов профиля
 * @property-read string $accountId Идентификатор аккаунта (портфеля)
 * @property-read string $accountAlias Символьный алиас аккаунта (портфеля)
 */
class TIAccount extends Model
{
    use ModelTrait;

    /**
     * @var TIProfile DTO кредов профиля
     */
    protected $_profile;

    /**
     * @var string Идентификатор аккаунта (портфеля)
     */
    protected $_account_id;

    /**
     * @var string Символьный алиас аккаунта (портфеля)
     */
    protected $_account_alias;

    /**
     * Метод-геттер DTO кредов профиля
     *
     * @return TIProfile DTO кредов профиля
     */
    public function getProfile(): TIProfile
    {
        return $this->_profile;
    }

    /**
     * Метод-геттер Идентификатора аккаунта (портфеля)
     *
     * @return string Идентификатор аккаунта (портфеля)
     */
    public function getAccountId(): string
    {
        return $this->_account_id;
    }

    /**
     * Метод-геттер Символьного алиаса аккаунта (портфеля)
     *
     * @return string Символьный алиас аккаунта (портфеля)
     */
    public function getAccountAlias(): string
    {
        return $this->_account_alias;
    }

    /**
     * Конструктор
     *
     * @param TIProfile $profile DTO кредов профиля
     * @param string|null $account_id Идентификатор аккаунта (портфеля)
     * @param string|null $account_alias Символьный алиас аккаунта (портфеля)
     * @param array $config Массив инициализации прочих параметров объекта
     *
     * @throws Exception
     */
    public function __construct(TIProfile $profile, ?string $account_id, ?string $account_alias, array $config = [])
    {
        if (!$account_id || !$account_alias) {
            throw new Exception('Account (portfolio) config is incorrect');
        }

        parent::__construct($config);
    }

    /**
     * Метод создания объекта DTO кредов аккаунта (портфеля)
     *
     * @param string $account Символьный алиас или идентификатор аккаунта (портфеля)
     *
     * @return TIAccount Созданный объект класса
     *
     * @throws Exception
     */
    public static function create(string $account): TIAccount
    {
        $config = TIServices::TIConfig();
        $is_alias = !ctype_digit($account);

        foreach ($config['profiles'] ?? [] as $config_profile_alias => $profile_data) {
            foreach ($profile_data['accounts'] ?? [] as $config_account_alias => $config_account_id) {
                if ($account === ($is_alias ? $config_account_alias : $config_account_id)) {
                    $profile = TIProfile::create($config_account_alias);

                    return new static($profile, $config_account_id, $config_account_alias);
                }
            }
        }

        throw new Exception('Account is not found');
    }
}
