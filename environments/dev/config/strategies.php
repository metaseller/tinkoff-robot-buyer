<?php

return [
    'etf' => file_exists(__DIR__ . '/strategy-etf.php') ? require(__DIR__ . '/strategy-etf.php') : [],
    'manage' => file_exists(__DIR__ . '/strategy-manage.php') ? require(__DIR__ . '/strategy-manage.php') : [],
];
