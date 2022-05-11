<?php

use yii\helpers\ArrayHelper;
use yii\i18n\Formatter;
use yii\log\FileTarget;
use yii\redis\Connection as RedisConnection;
use yii\widgets\Breadcrumbs;
use yii\redis\Cache;
use Metaseller\yii2TinkoffInvestApi2\TinkoffInvestApi;
use app\components\base\Security as BaseSecurity;

$params = ArrayHelper::merge(
    require(__DIR__ . '/params.php'),
    require(__DIR__ . '/params-local.php')
);

$redis = require __DIR__ . '/redis.php';
$route = require __DIR__ . '/route.php';
$log_targets = require __DIR__ . '/log-targets.php';
$tinkoff_invest = require __DIR__ . '/tinkoff-invest.php';

$config = [
    'id' => 'tinkoff-robot-buyer',
    'basePath' => dirname(__DIR__),
    'bootstrap' => ['log'],
    'aliases' => [
        '@bower' => '@vendor/bower-asset',
        '@npm'   => '@vendor/npm-asset',
        '@tests' => '@app/tests',
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
            'hostname' => $redis['hostname'],
            'port' => $redis['port'],
            'database' => $redis['database'],
            'password' => $redis['password'] ?? null,
        ],
        'cache' => [
            'class' => Cache::class,
            'redis' => 'redis'
        ],
        'log' => [
            'flushInterval' => 1,
            'traceLevel' => defined('YII_DEBUG') && YII_DEBUG ? 10 : 0,
            'targets' => $log_targets,
        ],
        'tinkoffInvest' => [
            'class' => TinkoffInvestApi::class,
            'apiToken' => $tinkoff_invest['secret_key'] ?? '',
            'appName' => $tinkoff_invest['app_name'] ?? 'metaseller.tinkoff-invest-api-v2-yii2',
        ],
        'urlManager' => [
            'enablePrettyUrl' => true,
            'showScriptName' => false,
            'enableStrictParsing' => true,
            'rules' => $route,
            'baseUrl' => $params['url']['server'],
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

if (YII_ENV_DEV) {
    // configuration adjustments for 'dev' environment
    $config['bootstrap'][] = 'gii';
    $config['modules']['gii'] = [
        'class' => 'yii\gii\Module',
    ];
}

return $config;
