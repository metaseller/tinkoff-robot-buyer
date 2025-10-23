<?php

$tinkoff_invest = require __DIR__ . '/tinkoff-invest.php';

return [
    'adminEmail' => 'admin@example.com',
    'senderEmail' => 'noreply@example.com',
    'senderName' => 'Example.com mailer',

    'tinkoff_invest' => $tinkoff_invest,
    'auto_buy_bonds' => require __DIR__ . '/tinkoff-buy-bonds.php',

    'telegram' => require __DIR__ . '/telegram.php'
];
