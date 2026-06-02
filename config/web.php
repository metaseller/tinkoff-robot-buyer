<?php

use yii\helpers\ArrayHelper;
use yii\i18n\Formatter;
use yii\log\FileTarget;
use yii\widgets\Breadcrumbs;

$params = ArrayHelper::merge(
    is_file(__DIR__ . '/web-params.php') ? require(__DIR__ . '/web-params.php') : [],
    is_file(__DIR__ . '/web-params-local.php') ? require(__DIR__ . '/web-params-local.php') : []
);

$log_targets = is_file(__DIR__ . '/log-targets.php') ? require(__DIR__ . '/log-targets.php') : [];
$route = is_file(__DIR__ . '/route.php') ? require(__DIR__ . '/route.php') : [];

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
            'cookieValidationKey' => $params['cookieValidationKey'] ?? 'default',
        ],
        'errorHandler' => [
            'errorAction' => 'site/error',
        ],
        'urlManager' => [
            'enablePrettyUrl' => true,
            'showScriptName' => false,
            'enableStrictParsing' => true,
            'rules' => $route,
        ],
    ],
    'params' => $params,
];

return $config;
