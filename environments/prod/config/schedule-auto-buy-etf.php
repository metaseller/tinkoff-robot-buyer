<?php

/**
 * Конфигурация циклических фоновых процессов для обеспечения Стратегии 1 (Автоматическая покупка ETF)
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

foreach ($strategies['auto_buy_etf'] ?? [] as $task) {
    if (!($task['active'] ?? false)) {
        continue;
    }

    $profile = $task['profile'] ?? null;
    $account = $task['account'] ?? null;
    $etfs = $task['etfs'] ?? [];

    if (empty($profile) || empty($account) || empty($etfs)) {
        continue;
    }

    foreach ($etfs as $ticker => $ticker_config) {
        $schedule->command('auto-buy-etf/increment-etf-trailing ' . $account . ' ' . $ticker . ' ' . $ticker_config['INCREMENT_VALUE'])
            ->everyNMinutes($ticker_config['INCREMENT_PERIOD']);

        $schedule->command('auto-buy-etf/buy-etf-trailing ' . $account . ' ' . $ticker . ' ' . $ticker_config['BUY_LOTS_BOTTOM_LIMIT'] . ' ' . $ticker_config['BUY_TRAILING_PERCENTAGE'])
            ->everyNMinutes($ticker_config['BUY_CHECK_PERIOD']);
    }
}
