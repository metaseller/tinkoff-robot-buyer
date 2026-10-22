<?php

require dirname(__DIR__) . '/vendor/autoload.php';
require dirname(__DIR__) . '/vendor/yiisoft/yii2/Yii.php';
$app = new yii\console\Application(require dirname(__DIR__) . '/config/console.php');
$key = 'migration-check:' . bin2hex(random_bytes(12));
try {
    $value = ['php' => PHP_VERSION, 'grpc' => phpversion('grpc')];
    if (!$app->cache->set($key, $value, 60) || $app->cache->get($key) !== $value) {
        throw new RuntimeException('Yii Redis cache round trip failed');
    }
    $app->redis->lpush($key . ':list', 'test');
    if ($app->redis->lrange($key . ':list', 0, -1) !== ['test']) {
        throw new RuntimeException('Redis list round trip failed');
    }
    echo 'Redis cache and list checks passed on PHP ' . PHP_VERSION . PHP_EOL;
} finally {
    $app->cache->delete($key);
    $app->redis->del($key . ':list');
}
