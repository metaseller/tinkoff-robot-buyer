<?php

use yii\helpers\ArrayHelper;
use yii\i18n\Formatter;
use yii\log\FileTarget;
use yii\redis\Connection as RedisConnection;
use yii\widgets\Breadcrumbs;
use yii\redis\Cache;
use app\components\base\Security as BaseSecurity;

$params = ArrayHelper::merge(
    is_file(__DIR__ . '/console-params.php') ? require(__DIR__ . '/console-params.php') : [],
    is_file(__DIR__ . '/console-params-local.php') ? require(__DIR__ . '/console-params-local.php') : []
);

$log_targets = is_file(__DIR__ . '/log-targets.php') ? require(__DIR__ . '/log-targets.php') : [];

$redis = is_file(__DIR__ . '/redis.php') ? require(__DIR__ . '/redis.php') : [];
$route = is_file(__DIR__ . '/route.php') ? require(__DIR__ . '/route.php') : [];

$log_targets = is_file(__DIR__ . '/log-targets.php') ? require(__DIR__ . '/log-targets.php') : [];

$config = [
    'id' => 'tinkoff-robot-buyer',
    'basePath' => dirname(__DIR__),
    'bootstrap' => ['log'],
    'aliases' => [
        '@bower' => '@vendor/bower-asset',
        '@npm'   => '@vendor/npm-asset',
    ],
    'container' => [
        'definitions' => [
            Breadcrumbs::class => ['homeLink' => false],
            FileTarget::class => ['fileMode' => 0664],
            Formatter::class => ['nullDisplay' => '&mdash;'],
        ]
    ],
    'controllerNamespace' => 'app\commands',
    'components' => [
        'redis' => [
            'class' => RedisConnection::class,
            'hostname' => $redis['hostname'] ?? '',
            'port' => $redis['port']  ?? '',
            'database' => $redis['database']  ?? '',
            'password' => $redis['password'] ?? null,
        ],
        'cache' => [
            'class' => Cache::class,
            'redis' => 'redis'
        ],
        'log' => [
            'flushInterval' => 1,
            'traceLevel' => defined('YII_DEBUG') && YII_DEBUG ? 10 : 3,
            'targets' => $log_targets,
        ],
        'security' => [
            'class' => BaseSecurity::class
        ],
    ],
    'params' => $params,
    'modules' => [
    ],
    'controllerMap' => [
    ],
    'timeZone' => 'UTC'
];

return $config;
