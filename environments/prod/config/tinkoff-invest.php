<?php

$credentials = require __DIR__ . '/credentials.php';

return [
    'secret_key' => $credentials['tinkoff_invest']['secret_key'],
    'account_id' => $credentials['tinkoff_invest']['account_id'],
    'app_name' => 'metaseller.tinkoff-robot-buyer',
];
