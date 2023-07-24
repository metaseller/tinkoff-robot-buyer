<?php

use omnilight\scheduling\Schedule;

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

/** @var Schedule $schedule */

$strategy = require __DIR__ . '/tinkoff-buy-strategy.php';
$tinkoff_invest = require __DIR__ . '/tinkoff-invest.php';

foreach ($strategy['ETF'] ?? [] as $credential_alias => $strategy_data) {
    $credentials = $tinkoff_invest['credentials'][$credential_alias] ?? [];

    if (!$credentials) {
        continue;
    }

    foreach ($strategy_data as $account_alias => $account_strategy_data) {
        $account_id = $credentials['accounts_shortcuts'][$account_alias] ?? null;

        if (!$account_id) {
            continue;
        }

        foreach ($account_strategy_data as $ticker => $ticker_config) {
            $schedule->command('tinkoff-invest/increment-etf-trailing ' . $account_id . ' ' . $ticker . ' ' . $ticker_config['INCREMENT_VALUE'])
                ->everyNMinutes($ticker_config['INCREMENT_PERIOD'])
            ;

            $schedule->command('tinkoff-invest/buy-etf-trailing ' . $account_id . ' ' . $ticker . ' ' . $ticker_config['BUY_LOTS_BOTTOM_LIMIT'] . ' ' . $ticker_config['BUY_TRAILING_PERCENTAGE'])
                ->everyNMinutes($ticker_config['BUY_CHECK_PERIOD'])
            ;
        }
    }
}

$schedule->command('tinkoff-invest/operations iis')->everyNMinutes(1);
