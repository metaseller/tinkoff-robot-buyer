<?php

/**
 * Конфигурация циклических фоновых процессов
 *
 * Пример записи для cron:
 *  <pre>
 *      * * * * * cd /var/www/tinkoff_robot_buyer && sudo -u www-data php yii app-schedule/run --scheduleFile=@app/config/schedule.php 1>>runtime/logs/scheduler.log 2>&1
 *  </pre>
 *
 * @see https://github.com/omnilight/yii2-scheduling
 */

/** @var \omnilight\scheduling\Schedule $schedule */

$strategies = is_file(__DIR__ . '/strategies.php') ? require(__DIR__ . '/strategies.php') : [];

if (is_file(__DIR__ . '/schedule-strategy-etf.php')) {
    include (__DIR__ . '/schedule-strategy-etf.php');
}

if (is_file(__DIR__ . '/schedule-strategy-manage.php')) {
    include (__DIR__ . '/schedule-strategy-manage.php');
}

if (is_file(__DIR__ . '/schedule-local.php')) {
    include (__DIR__ . '/schedule-local.php');
}
