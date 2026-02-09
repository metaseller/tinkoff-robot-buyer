<?php

return [
    [
        'active' => true,

        'profile' => 'default',
        'account' => 'iis2024',

        'parking_money_etf' => 'LQDT',

        'bonds' => file_exists(__DIR__ . '/strategy-auto-rebalance-bonds.php') ? require(__DIR__ . '/strategy-auto-rebalance-bonds.php') : [],
        'shares' => file_exists(__DIR__ . '/strategy-auto-rebalance-shares.php') ? require(__DIR__ . '/strategy-auto-rebalance-shares.php') : [],
    ],
];
