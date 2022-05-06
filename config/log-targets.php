<?php

use app\components\log\LogstashTarget;
use yii\web\HttpException;

$categories = [
    'debug' => true,

    'calculate_stat_analysis',
    'calculate_tech_analysis',

    'telegram_bot',
    'tinkoff_invest',

    'strategy',
];

$targets = [
    [
        'class' => LogstashTarget::class,
        'levels' => ['error', 'warning'],
        'logVars' => ['_FILES', '_COOKIE', '_SESSION', '_SERVER'],
        'except' => [HttpException::class . ':404'],
    ],
    [
        'class' => LogstashTarget::class,
        'categories' => [HttpException::class . ':404'],
        'levels' => ['error', 'warning'],
        'logVars' => ['_FILES', '_COOKIE', '_SESSION', '_SERVER'],
        'logFile' => '@runtime/logs/404.log',
    ],
];

foreach ($categories as $category => $important) {
    if (is_int($category) && is_string($important)) {
        $category = $important;
        $important = false;
    }

    $targets[] = [
        'class' => LogstashTarget::class,
        'categories' => [$category],
        'levels' => ['info', 'warning', 'error'],
        'logVars' => [],
        'logFile' => "@runtime/logs/$category.log",
        'exportInterval' => $important ? 1 : 1000,
    ];
}

return $targets;
