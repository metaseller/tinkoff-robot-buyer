<?php

return [
    'auto_buy_etf' => file_exists(__DIR__ . '/strategy-auto-buy-etf.php') ? require(__DIR__ . '/strategy-auto-buy-etf.php') : [],
    'auto_rebalance' => file_exists(__DIR__ . '/strategy-auto-rebalance.php') ? require(__DIR__ . '/strategy-auto-rebalance.php') : [],
];
