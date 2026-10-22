<?php

error_reporting(E_ALL);
defined('YII_DEBUG') or define('YII_DEBUG', true);
defined('YII_ENV') or define('YII_ENV', 'test');
require dirname(__DIR__) . '/vendor/autoload.php';
require dirname(__DIR__) . '/vendor/yiisoft/yii2/Yii.php';

// Deliberately do not load local profiles, tokens, schedules or Telegram config.
new yii\console\Application([
    'id' => 'robot-tests',
    'basePath' => dirname(__DIR__),
    'components' => [
        'cache' => ['class' => yii\caching\ArrayCache::class],
        'security' => ['class' => app\components\base\Security::class],
    ],
    'params' => ['t-invest' => [
        'app_name' => 'robot.offline-tests',
        'profiles' => ['test' => [
            'secret_key' => 'offline-test-token',
            'accounts' => ['test-account' => '123456'],
        ]],
    ]],
]);
