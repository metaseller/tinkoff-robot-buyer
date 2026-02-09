<?php

return [
    [
        'active' => true,

        'profile' => 'default',
        'account' => 'account1',

        'parking_money_etf' => 'LQDT',

        'bonds' => file_exists(__DIR__ . '/strategy-manage-bonds.php') ? require(__DIR__ . '/strategy-manage-bonds.php') : [],
        'shares' => file_exists(__DIR__ . '/strategy-manage-shares.php') ? require(__DIR__ . '/strategy-manage-shares.php') : [],
    ],
];
