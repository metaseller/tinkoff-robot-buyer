<?php

return [
    'iis_strategy' => [
        'active' => true,

        'profile' => 'default',
        'account' => 'account3',

        'balance_control_accounts' => [
            'account2',
            'account3',
        ],

        'balance_shares_percentage' => 100 - (date('Y') - 1983),

        'parking_money_etf' => 'LQDT',
        'shares_money_limit' => 500000,

        'bonds' => file_exists(__DIR__ . '/strategy-manage-bonds.php') ? require(__DIR__ . '/strategy-manage-bonds.php') : [],
        'shares' => file_exists(__DIR__ . '/strategy-manage-shares.php') ? require(__DIR__ . '/strategy-manage-shares.php') : [],
    ],
];
