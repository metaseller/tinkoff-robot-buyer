<?php

/**
 * Конфигурация циклических фоновых процессов для обеспечения Стратегии 2 (Автоматическая ребалансировка портфеля)
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

foreach ($strategies['manage'] ?? [] as $strategy_name => $task) {
    if (!($task['active'] ?? false)) {
        continue;
    }

    $profile = $task['profile'] ?? null;
    $account = $task['account'] ?? null;

    $bonds = $task['bonds'] ?? [];
    $shares = $task['shares'] ?? [];
    $parking_money_etf = $task['parking_money_etf'] ?? null;

    if (empty($profile) || empty($account)) {
        continue;
    }

    $schedule->command('rebalance/balance-state ' . escapeshellarg((string) $strategy_name) . ' 1')->dailyAt('07:45');
    $schedule->command('rebalance/balance-state ' . escapeshellarg((string) $strategy_name) . ' 0')->dailyAt('10:45');
    $schedule->command('rebalance/balance-state ' . escapeshellarg((string) $strategy_name) . ' 0')->dailyAt('13:45');

    $schedule->command('rebalance/shares-state ' . escapeshellarg((string) $strategy_name))->dailyAt('07:50');

    if ($shares) {
        $schedule->command('rebalance/processing ' . escapeshellarg((string) $strategy_name))->everyNMinutes(5);
    } elseif ($bonds) {
        $schedule->command('rebalance/auto-buy-bonds ' . escapeshellarg((string) $strategy_name))->everyNMinutes(5);
    }

    if ($parking_money_etf) {
        $schedule->command('rebalance/park-free-money ' . escapeshellarg((string) $account) . ' etf ' . escapeshellarg((string) $parking_money_etf))->dailyAt('15:48');
        $schedule->command('rebalance/park-free-money ' . escapeshellarg((string) $account) . ' etf ' . escapeshellarg((string) $parking_money_etf))->dailyAt('16:05');
        $schedule->command('rebalance/park-free-money ' . escapeshellarg((string) $account) . ' etf ' . escapeshellarg((string) $parking_money_etf))->dailyAt('16:15');
    }
}
