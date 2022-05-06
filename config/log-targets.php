<?php

use yii\log\FileTarget;
use yii\web\HttpException;

$categories = [
    'debug' => true,

    'tinkoff_invest',
    'tinkoff_invest_strategy',
];

$targets = [
    [
        'class' => FileTarget::class,
        'levels' => ['error', 'warning'],
        'logVars' => ['_FILES', '_COOKIE', '_SESSION', '_SERVER'],
        'except' => [HttpException::class . ':404'],
    ],
    [
        'class' => FileTarget::class,
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
        'class' => FileTarget::class,
        'categories' => [$category],
        'levels' => ['info', 'warning', 'error'],
        'logVars' => [],
        'logFile' => "@runtime/logs/$category.log",
        'exportInterval' => $important ? 1 : 1000,
    ];
}

return $targets;
