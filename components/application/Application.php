<?php

use Metaseller\yii2TinkoffInvestApi2\TinkoffInvestApi;
use yii\base\Application as YiiBaseApplication;
use yii\BaseYii;
use yii\console\Application as YiiConsoleApplication;
use yii\web\Application as YiiWebApplication;
use app\components\base\Security as BaseSecurity;
use yii\redis\Cache;
use app\components\web\Session;
use yii\redis\Connection as RedisConnection;

/**
 * Базовый класс Yii
 *
 * Используется лишь для целей расширения описания типов свойств приложения (phpDoc для phpStorm)
 *
 * @see https://github.com/samdark/yii2-cookbook/blob/master/book/ide-autocompletion.md
 */
class Yii extends BaseYii
{
    /**
     * @var BaseApplication|WebApplication|ConsoleApplication Инстанс приложения
     */
    public static $app;
}

/**
 * Модель веб приложения
 *
 * Используется лишь для целей расширения описания типов свойств веб приложения (phpDoc для phpStorm)
 *
 * @property-read Session $session
 * @property-read User $user
 */
class WebApplication extends YiiWebApplication
{
}

/**
 * Модель консольного приложения
 *
 * Используется для целей расширения описания типов свойств консольного приложения (phpDoc для phpStorm)
 */
class ConsoleApplication extends YiiConsoleApplication
{
}

/**
 * Базовый класс приложения
 *
 * Используется для целей расширения описания типов свойств общих для консольного и веб приложений (phpDoc для phpStorm)
 *
 * @property-read RedisConnection $redis
 * @property-read Cache $cache
 * @property-read BaseSecurity $security
 * @property-read TinkoffInvestApi $tinkoffInvest
 */
abstract class BaseApplication extends YiiBaseApplication
{
}
