<?php

namespace app\models;

use Exception;
use Metaseller\TinkoffInvestApi2\ModelTrait;
use yii\base\Model;

/**
 * Модель DTO кредов доступа к профилю T-Invest API
 *
 * Профиль - это символьный алиас профиля T-Invest API и токен доступа к API
 *
 * @property-read string $profileAlias Символьный алиас профиля
 * @property-read string $secretToken Токен доступа к T-Invest API
 * @property-read string $appName Имя приложения
 */
class TIProfile extends Model
{
    use ModelTrait;

    /**
     * @var string Символьный алиас профиля
     */
    protected $_profile_alias;

    /**
     * @var string Токен доступа к T-Invest API
     */
    protected $_secret_token;

    /**
     * @var string Имя приложения
     */
    protected $_app_name;

    /**
     * Метод-геттер Символьного алиаса профиля
     *
     * @return string Символьный алиас профиля
     */
    public function getProfileAlias(): string
    {
        return $this->_profile_alias;
    }

    /**
     * Метод-геттер Токена доступа к T-Invest API
     *
     * @return string Токен доступа к T-Invest API
     */
    public function getSecretToken(): string
    {
        return $this->_secret_token;
    }

    /**
     * Метод-геттер Имени приложения
     *
     * @return string Имя приложения
     */
    public function getAppName(): string
    {
        return $this->_app_name;
    }

    /**
     * Конструктор класса
     *
     * @param string|null $profile_alias Символьный алиас профиля
     * @param string|null $secret_token Токен доступа к T-Invest API
     * @param string|null $app_name Имя приложения
     * @param array $config Массив инициализации прочих параметров объекта
     *
     * @throws Exception
     */
    public function __construct(?string $profile_alias, ?string $secret_token, ?string $app_name, array $config = [])
    {
        if (empty($profile_alias) || empty($secret_token) || empty($app_name)) {
            throw new Exception('Profile config is incorrect');
        }

        $this->_profile_alias = $profile_alias;
        $this->_secret_token = $secret_token;
        $this->_app_name = $app_name;

        parent::__construct($config);
    }

    /**
     * Метод создания объекта DTO профиля
     *
     * @param string $profile_alias Символьный алиас профиля
     *
     * @return TIProfile Созданный объект класса
     *
     * @throws Exception
     */
    public static function create(string $profile_alias): TIProfile
    {
        $config = TIServices::TIConfig();

        foreach ($config['profiles'] ?? [] as $config_profile_alias => $profile_data) {
            if ($profile_alias === $config_profile_alias) {
                $secret_key = $profile_data['secret_key'] ?? null;
                $app_name = $config['app_name'] ?? 'metaseller.tinkoff-robot-buyer';

                return new static($config_profile_alias, $secret_key, $app_name);
            }
        }

        throw new Exception('Profile is not found');
    }
}
