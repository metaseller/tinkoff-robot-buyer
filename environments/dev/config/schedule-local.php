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

/**
 * @var omnilight\scheduling\Schedule $schedule
 * @var array $strategies
 */

$schedule->command('info/operations funds')->everyNMinutes(1);
$schedule->command('info/operations iis2021')->everyNMinutes(1);
$schedule->command('info/operations iis2024')->everyNMinutes(1);
