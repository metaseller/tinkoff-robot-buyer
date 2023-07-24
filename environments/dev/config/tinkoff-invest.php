<?php

$credentials = require __DIR__ . '/credentials.php';

return [
    'app_name' => 'metaseller.tinkoff-robot-buyer',
    'credentials' => $credentials['tinkoff_invest'] ?? [],
];
