<?php

return [
    't-invest-profiles' => file_exists(__DIR__ . '/t-invest-profiles.php') ? require(__DIR__ . '/t-invest-profiles.php') : [],
    'strategies' => file_exists(__DIR__ . '/strategies.php') ? require(__DIR__ . '/strategies.php') : [],
    'telegram' => file_exists(__DIR__ . '/telegram.php') ? require(__DIR__ . '/telegram.php') : [],
];
