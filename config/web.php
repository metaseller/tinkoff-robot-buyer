<?php

use yii\helpers\ArrayHelper;
use app\components\web\Session;
use yii\i18n\Formatter;
use yii\log\FileTarget;
use yii\widgets\Breadcrumbs;
use yii\redis\Connection as RedisConnection;
use yii\redis\Cache;

$params = ArrayHelper::merge(
    require(__DIR__ . '/params.php'),
    require(__DIR__ . '/params-local.php')
);

$log_targets = require __DIR__ . '/log-targets.php';
$route = require __DIR__ . '/route.php';
$redis = require __DIR__ . '/redis.php';

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
    'components' => [
        'request' => [
            // !!! insert a secret key in the following (if it is empty) - this is required by cookie validation
            'cookieValidationKey' => 'PJVi05drmQkr9RbOuL_Zwi7hUwKObpVf',
        ],
        'redis' => [
            'class' => RedisConnection::class,
            'hostname' => $redis['hostname'],
            'port' => $redis['port'],
            'database' => $redis['database'],
            'password' => $redis['password'] ?? null,
        ],
        'session' => [
            'class' => Session::class,
            'redis' => 'redis',
        ],
        'cache' => [
            'class' => Cache::class,
            'redis' => 'redis',
        ],
        'errorHandler' => [
            'errorAction' => 'site/error',
        ],
        'log' => [
            'flushInterval' => 1,
            'traceLevel' => defined('YII_DEBUG') && YII_DEBUG ? 10 : 0,
            'targets' => $log_targets,
        ],
        'urlManager' => [
            'enablePrettyUrl' => true,
            'showScriptName' => false,
            'enableStrictParsing' => true,
            'rules' => $route,
        ],
        'user' => [
            'identityClass' => User,
            'enableAutoLogin' => true,
        ],
    ],
    'params' => $params,
];

if (YII_ENV_DEV) {
    // configuration adjustments for 'dev' environment
    $config['bootstrap'][] = 'debug';
    $config['modules']['debug'] = [
        'class' => 'yii\debug\Module',
        // uncomment the following to add your IP if you are not connecting from localhost.
        //'allowedIPs' => ['127.0.0.1', '::1'],
    ];

    $config['bootstrap'][] = 'gii';
    $config['modules']['gii'] = [
        'class' => 'yii\gii\Module',
        // uncomment the following to add your IP if you are not connecting from localhost.
        //'allowedIPs' => ['127.0.0.1', '::1'],
    ];
}

return $config;
